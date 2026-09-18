<?php

namespace App\Traits;

use Exception;
use App\Models\Kot;
use App\Models\Order;
use App\Models\KotPlace;
use App\Models\MultipleOrder;
use Illuminate\Support\Facades\Log;
use App\Models\Printer as PrinterSettings;
use App\Models\PrintJob;
use App\Events\PrintJobCreated;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\KotController;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Helper\Files;
use Illuminate\Support\Facades\File;

trait PrinterSetting
{
    protected $printer;
    protected $charPerLine;
    protected $indentSize;
    protected $htmlContent = null;
    protected $imagePath = null;
    protected $imageFilename = null;
    protected $printerSetting;


    public function handleKotPrint($kotId, $kotPlaceId = null, $alsoPrintOrder = false)
    {
        $kotPlace = KotPlace::findOrFail($kotPlaceId);
        $printerSetting = $this->getActivePrinter($kotPlace->printer_id);
        $this->printerSetting = $printerSetting;


        $kotPlaceId = $kotPlaceId ?? 1;
        $width = $this->getPrintWidth(); // 80mm for fullWidth approach
        $thermal = true;
        // Generate the KOT content using KotController to avoid duplication
        $content = (new KotController())->printKot($kotId, $kotPlaceId, $width, $thermal, $this->checkGeneratePdf())->render();

        if ($this->checkGeneratePdf()) {
            // PDF is written sync — create the job only after the file exists.
            $this->imageFilename = $this->makeUniquePrintFilename('kot', $kotId, 'pdf');
            $this->generateKotPdf($kotId, $content, $this->imageFilename);
            $this->executeKotPrint($kotId, $kotPlaceId, $alsoPrintOrder);
        } else {
            // PNG is captured async in the browser. Use a unique filename and create the
            // print job first so the desktop app waits for THIS file (never an old shared path).
            $this->imageFilename = $this->makeUniquePrintFilename('kot', $kotId, 'png');
            $this->executeKotPrint($kotId, $kotPlaceId, $alsoPrintOrder);
            $this->generateKotImage($kotId, $kotPlaceId, $content, $this->imageFilename);
        }
    }

    /**
     * Generate KOT image using html-to-image JavaScript approach
     */
    public function generateKotImage($kotId, $kotPlaceId = null, $content, ?string $filename = null)
    {
        //  Log::info("generateKotImage called for KOT ID: {$kotId}, Place ID: {$kotPlaceId}");

        try {
            $filename = $filename ?: $this->makeUniquePrintFilename('kot', $kotId, 'png');

            // Use html-to-image approach by dispatching a JavaScript event
            // This will trigger the image capture in the frontend
            $this->dispatch('saveKotImageFromPrint', $kotId, $kotPlaceId, $content, $filename);

            // Log success
            // Log::info("KOT image save event dispatched for KOT ID: {$kotId}");
        } catch (\Exception $e) {
            // Log::error("Failed to dispatch KOT image save event: " . $e->getMessage());
            // Log::error("Stack trace: " . $e->getTraceAsString());
            // Don't throw exception to avoid breaking the print process
        }
    }

    private function generateKotPdf($kotId, $content, ?string $filename = null)
    {
        $width = $this->getPrintWidth();
        // Calculate paper width in points (1mm ≈ 2.83 points)
        $paperWidthInPoints = $width * 2.83;
        // Dynamic height based on KOT content to avoid awkward page breaks
        $paperHeightInPoints = $this->estimateKotReceiptHeight($kotId);

        $filename = $filename ?: $this->makeUniquePrintFilename('kot', $kotId, 'pdf');

        $pdf = Pdf::loadHTML($content)
            ->setPaper([0, 0, $paperWidthInPoints, $paperHeightInPoints], 'portrait');
        $fullPath = public_path(Files::UPLOAD_FOLDER . '/' . 'print/' . $filename);
        $pdf->save($fullPath);
    }

    /**
     * Estimate KOT receipt height in points based on content (items, modifiers).
     * Used so the PDF page height fits content and avoids unnecessary pagination.
     */
    private function estimateKotReceiptHeight($kotId): float
    {
        $baseHeight = 320;   // header, KOT info, table header, footer
        $perItemHeight = 52; // one line item (name + qty + price)
        $perModifierHeight = 22;
        $minHeight = 500;
        $maxHeight = 4500;

        $kot = Kot::with(['items.modifierOptions'])->find($kotId);
        if (!$kot) {
            return max($minHeight, $baseHeight);
        }

        $contentHeight = $baseHeight;
        foreach ($kot->items ?? [] as $item) {
            $contentHeight += $perItemHeight;
            $modifierCount = $item->modifierOptions ? $item->modifierOptions->count() : 0;
            if ($modifierCount > 0) {
                $contentHeight += $perModifierHeight * $modifierCount;
            }
        }

        return (float) max($minHeight, min(ceil($contentHeight), $maxHeight));
    }

    /**
     * Execute the original KOT print logic
     */
    private function executeKotPrint($kotId, $kotPlaceId = null, $alsoPrintOrder = false)
    {
        $kotPlace = KotPlace::findOrFail($kotPlaceId);
        $printerSetting = $this->getActivePrinter($kotPlace->printer_id);
        $this->printerSetting = $printerSetting;

        if (!$printerSetting) {
            throw new \Exception(__('messages.noActiveKotPrinterConfigured'));
        }


        $kot = Kot::with('items', 'order.waiter', 'table')->find($kotId);

        if (empty($this->imageFilename)) {
            $this->imageFilename = $this->checkGeneratePdf()
                ? $this->makeUniquePrintFilename('kot', $kotId, 'pdf')
                : $this->makeUniquePrintFilename('kot', $kotId, 'png');
        }

        $this->createPrintJobRecord($kot->branch_id, $kot->branch->restaurant_id);

        if ($alsoPrintOrder) {
            $kot = Kot::findOrFail($kotId);
            // Clear so the order print path allocates its own unique filename.
            $this->imageFilename = null;
            $this->handleOrderPrint($kot->order_id);
        }
    }

    private function loadKotWithRelations($kotId)
    {
        return Kot::with([
            'items.menuItem',
            'items.menuItemVariation',
            'items.modifierOptions',
            'order.table',
            'order.customer',
            'order.waiter',
            'order.items.menuItem',
            'order.items.menuItemVariation',
            'order.items.modifierOptions',
            'order.charges.charge',
            'order.taxes.tax',
            'order.payments'
        ])->findOrFail($kotId);
    }




    public function handleOrderPrint($orderId)
    {
        Log::info("handleOrderPrint called for Order ID: {$orderId}");

        // Load the order to verify what we're actually printing
        $order = Order::find($orderId);

        $orderPlace = MultipleOrder::first();
        $printerSetting = $this->getActivePrinter($orderPlace->printer_id);

        $this->printerSetting = $printerSetting;


        $width = $this->getPrintWidth(); // 80mm for fullWidth approach
        $thermal = true;

        // Always pass generateImage/forCapture=true so receipt HTML omits mobile print chrome
        // and avoids page-break-after (both PDF and PNG capture paths).
        $content = (new OrderController())->printOrder($orderId, $width, $thermal, true)->render();

        if ($this->checkGeneratePdf()) {
            // PDF is written sync — create the job only after the file exists.
            $this->imageFilename = $this->makeUniquePrintFilename('order', $orderId, 'pdf');
            $this->generateOrderPdf($orderId, $content, $this->imageFilename);
            $this->executeOrderPrint($orderId);
        } else {
            // PNG is captured async in the browser. Use a unique filename and create the
            // print job first so the desktop app waits for THIS file (never an old shared path).
            $this->imageFilename = $this->makeUniquePrintFilename('order', $orderId, 'png');
            $this->executeOrderPrint($orderId);
            $this->generateOrderImage($orderId, $content, $this->imageFilename);
        }
    }

    /**
     * Generate Order image using html-to-image JavaScript approach
     */
    private function generateOrderImage($orderId, $content, ?string $filename = null)
    {
        Log::info("generateOrderImage called for Order ID: {$orderId}");

        try {
            $filename = $filename ?: $this->makeUniquePrintFilename('order', $orderId, 'png');

            // Regenerate content from the incoming order id as final source of truth.
            // This prevents accidental stale/mismatched HTML payloads being captured.
            try {
                $width = $this->getPrintWidth();
                $thermal = true;
                $content = (new OrderController())->printOrder(
                    $orderId,
                    $width,
                    $thermal,
                    true
                )->render();
            } catch (\Throwable $e) {
                Log::warning("Order image content regeneration failed for Order ID {$orderId}: " . $e->getMessage());
                // Fall back to provided $content if regeneration fails.
            }

            // Use html-to-image approach by dispatching a JavaScript event
            // This will trigger the image capture in the frontend
            $this->dispatch('saveOrderImageFromPrint', $orderId, $content, $filename);

            // Log success
            Log::info("Order image save event dispatched for Order ID: {$orderId}", [
                'filename' => $filename,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to dispatch Order image save event: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
            // Don't throw exception to avoid breaking the print process
        }
    }

    private function generateOrderPdf($orderId, $content, ?string $filename = null)
    {
        $width = $this->getPrintWidth();
        // Calculate paper width in points (1mm ≈ 2.83 points)
        $paperWidthInPoints = $width * 2.83;
        // Dynamic height based on order content to avoid awkward page breaks
        $paperHeightInPoints = $this->estimateOrderReceiptHeight($orderId);

        $filename = $filename ?: $this->makeUniquePrintFilename('order', $orderId, 'pdf');

        $pdf = Pdf::loadHTML($content)
            ->setPaper([0, 0, $paperWidthInPoints, $paperHeightInPoints], 'portrait');
        $fullPath = public_path(Files::UPLOAD_FOLDER . '/' . 'print/' . $filename);
        $pdf->save($fullPath);
    }

    /**
     * Estimate receipt height in points based on order content (items, modifiers, totals).
     * Used so the PDF page height fits content and avoids unnecessary pagination.
     */
    private function estimateOrderReceiptHeight($orderId): float
    {
        $baseHeight = 420;   // header, order info, table header, totals, footer
        $perItemHeight = 52; // one line item (name + qty + price)
        $perModifierHeight = 22;
        $perChargeOrTaxLine = 18;
        $minHeight = 600;
        $maxHeight = 4500;

        $order = Order::with(['items.modifierOptions', 'charges.charge', 'taxes.tax'])->find($orderId);
        if (!$order) {
            return max($minHeight, $baseHeight);
        }

        $contentHeight = $baseHeight;
        foreach ($order->items ?? [] as $item) {
            $contentHeight += $perItemHeight;
            $modifierCount = $item->modifierOptions ? $item->modifierOptions->count() : 0;
            if ($modifierCount > 0) {
                $contentHeight += $perModifierHeight * $modifierCount;
            }
        }
        $chargeLines = $order->charges ? $order->charges->count() : 0;
        $taxLines = $order->taxes ? $order->taxes->count() : 0;
        $contentHeight += ($chargeLines + $taxLines) * $perChargeOrTaxLine;

        return (float) max($minHeight, min(ceil($contentHeight), $maxHeight));
    }

    /**
     * Execute the original Order print logic
     */
    private function executeOrderPrint($orderId)
    {
        $orderPlace = MultipleOrder::first();
        $printerSetting = $this->getActivePrinter($orderPlace->printer_id);

        $this->printerSetting = $printerSetting;


        if (!$printerSetting) {
            throw new \Exception('No active order printer configured.');
        }


        $this->printOrderThermal($orderId);
    }

    public function printOrderThermal($orderId)
    {
        if (empty($this->imageFilename)) {
            $this->imageFilename = $this->checkGeneratePdf()
                ? $this->makeUniquePrintFilename('order', $orderId, 'pdf')
                : $this->makeUniquePrintFilename('order', $orderId, 'png');
        }

        $order = $this->loadOrderWithRelations($orderId);

        $this->createPrintJobRecord($order->branch_id, $order->branch->restaurant_id);
        $this->alert('success', __('modules.kot.print_success'));
    }

    /**
     * Unique per-print filename so desktop clients never reuse a stale shared path/cache.
     * Example: order-42-67a1b2c3d4e5f.png
     */
    private function makeUniquePrintFilename(string $prefix, int|string $id, string $extension): string
    {
        return sprintf('%s-%s-%s.%s', $prefix, $id, uniqid('', false), $extension);
    }

    private function loadOrderWithRelations($orderId)
    {
        return Order::with([
            'table',
            'customer',
            'waiter',
            'items.menuItem',
            'items.menuItemVariation',
            'items.modifierOptions',
            'charges.charge',
            'taxes.tax',
            'payments'
        ])->findOrFail($orderId);
    }

    private function getActivePrinter($printerId)
    {
        return PrinterSettings::where('is_active', 1)
            ->where('id', $printerId)
            ->first();
    }


    private function createPrintJobRecord($branchId = null, $restaurantId = null)
    {
        $printerSetting = $this->printerSetting;

        $printJob = PrintJob::create([
            'image_filename' => $this->imageFilename,
            'restaurant_id' => $restaurantId,
            'branch_id' => $branchId,
            'status' => 'pending',
            'printer_id' => $printerSetting->id ?? null,
        ]);

        // Dispatch event for print job creation
        event(new PrintJobCreated($printJob));


        return $printJob;
    }


    private function getPrintWidth()
    {

        return match ($this->printerSetting->print_format ?? 'thermal80mm') {
            'thermal56mm' => 56,
            'thermal112mm' => 112,
            default => 80,
        };
    }


    public function ifMobileDevice()
    {
        $isMobile = false;

        if (request()->header('User-Agent')) {
            $agent = strtolower(request()->header('User-Agent'));
            $isMobile = preg_match('/mobile|android|iphone|ipad|phone/i', $agent);
        }

        return $isMobile ?? false;
    }

    public function ifDesktopDevice()
    {
        return !$this->ifMobileDevice();
    }

    /**
     * Check if the application is running as a PWA (Progressive Web App)
     *
     * This checks for PWA indicators that can be detected server-side:
     * 1. Session variable set by frontend JavaScript
     * 2. Request header (X-PWA-Mode)
     * 3. Cookie (pwa_mode)
     * 4. User agent patterns for standalone mode
     *
     * @return bool
     */
    public function ifPWA()
    {


        // Check session variable (set by frontend when PWA is detected)
        if (session()->has('is_pwa') && session('is_pwa') === true) {
            return true;
        }

        // Check for custom header set by frontend
        if (
            request()->header('X-PWA-Mode') === 'standalone' ||
            request()->header('X-PWA-Mode') === 'true' ||
            request()->header('X-PWA') === 'true'
        ) {
            return true;
        }

        // Check for cookie set by frontend
        if (
            request()->cookie('pwa_mode') === 'true' ||
            request()->cookie('pwa_mode') === 'standalone'
        ) {
            return true;
        }

        // Check user agent for PWA indicators
        $userAgent = request()->header('User-Agent', '');
        if ($userAgent) {
            $agent = strtolower($userAgent);

            // Check for standalone mode indicators in user agent
            // Some browsers add indicators when in standalone mode
            if (preg_match('/standalone|pwa|webapp/i', $agent)) {
                return true;
            }

            // Check for Android app wrapper (when PWA is opened from home screen)
            if (preg_match('/wv|webview/i', $agent) && preg_match('/android/i', $agent)) {
                // This might indicate PWA mode, but could also be regular webview
                // So we'll be conservative and not return true here
            }
        }

        return false;
    }



    private function checkGeneratePdf()
    {
        return ($this->printerSetting->print_type == 'pdf' || $this->ifMobileDevice() || $this->ifPWA());
    }
}
