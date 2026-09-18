<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

use App\Models\Kot;
use App\Http\Controllers\KotController;
use App\Models\Printer;
use App\Helper\Files;

class ViewPngController extends Controller
{
    // Render your Blade view (with data) inside a capture wrapper

    // Preview KOT for image capture
    public function previewKot($id, $kotPlaceid = null)
    {
        $kot = Kot::with('items', 'order.waiter', 'table')->findOrFail($id);
        $kotPlaceid = $kotPlaceid ?? 1;

        $printerSetting = $kot->kotPlace?->printerSetting;

        $content = (new KotController($id))->printKot($id, $kotPlaceid, Printer::getPrintWidth($printerSetting), true)->render();

        return view('capture-kot', compact('content', 'kot'));
    }

    // Save KOT image specifically
    public function storeKot(Request $request)
    {
        $data = $request->validate([
            'image_base64' => 'required|string',   // data:image/png;base64,...
            'kot_id'       => 'required|integer',  // KOT ID
            'width'        => 'nullable|integer',  // final width (px) e.g. 512 or 384
            'mono'         => 'nullable|boolean',  // pure B/W for thermal
            'filename'     => 'nullable|string|max:255', // unique per-print filename from print job
        ]);

        return $this->storeImage($data, 'kot', $data['kot_id'], $data['filename'] ?? null);
    }

    // Save Order image specifically
    public function storeOrder(Request $request)
    {
        $data = $request->validate([
            'image_base64' => 'required|string',   // data:image/png;base64,...
            'order_id'     => 'required|integer',  // Order ID
            'width'        => 'nullable|integer',  // final width (px) e.g. 512 or 384
            'mono'         => 'nullable|boolean',  // pure B/W for thermal
            'filename'     => 'nullable|string|max:255', // unique per-print filename from print job
        ]);

        return $this->storeImage($data, 'order', $data['order_id'], $data['filename'] ?? null);
    }

    // Save Cash Register report image (X/Z reports)
    public function storeReport(Request $request)
    {
        $data = $request->validate([
            'image_base64' => 'required|string',   // data:image/png;base64,
            'session_id'   => 'required|integer',  // Cash register session ID
            'report_type'  => 'required|string',   // x-report | z-report
            'width'        => 'nullable|integer',  // final width (px)
            'mono'         => 'nullable|boolean',  // pure B/W for thermal
            'report_content' => 'nullable|string', // Original HTML content for browser print
            'filename'     => 'nullable|string|max:255',
        ]);

        // Store report content temporarily in cache for browser print (only if CashRegister module exists)
        if (!empty($data['report_content']) && function_exists('module_enabled') && module_enabled('CashRegister')) {
            try {
                $cacheKey = 'report_content_' . $data['session_id'] . '_' . strtolower($data['report_type']);
                \Illuminate\Support\Facades\Cache::put($cacheKey, $data['report_content'], now()->addMinutes(5));
            } catch (\Exception $e) {
                // Silently fail if module is not available or cache fails
                \Illuminate\Support\Facades\Log::warning('Failed to cache report content: ' . $e->getMessage());
            }
        }

        $type = strtolower($data['report_type']) === 'z-report' ? 'z-report' : 'x-report';
        return $this->storeImage($data, $type, $data['session_id'], $data['filename'] ?? null);
    }

    /**
     * Common method to store images (KOT or Order)
     *
     * @param array $data
     * @param string $type
     * @param int $id
     * @param string|null $filename
     * @return \Illuminate\Http\JsonResponse
     */
    private function storeImage(array $data, string $type, int $id, ?string $filename = null)
    {
        try {
            // Process base64 image data
            $binary = $this->processBase64Image($data['image_base64']);

            if ($binary === false) {
                return response()->json(['ok' => false, 'message' => 'Invalid base64'], 422);
            }

            // Create and process image
            $img = $this->createImage($binary, $data['mono'] ?? false);


            // Store image file
            $path = $this->storeImageFile($img, $type, $id, $filename);


            return response()->json([
                'ok'   => true,
                'url'  => $this->getImageUrl($path),
                'path' => $path,
                'w'    => $img->width(),
                'h'    => $img->height(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'ok' => false,
                'message' => 'Failed to process image'
            ], 500);
        }
    }

    /**
     * Process base64 image data
     *
     * @param string $imageBase64
     * @return string|false
     */
    private function processBase64Image(string $imageBase64)
    {
        // Strip prefix if it's a data URL
        $base64 = str_starts_with($imageBase64, 'data:image/')
            ? substr($imageBase64, strpos($imageBase64, ',') + 1)
            : $imageBase64;

        return base64_decode($base64, true);
    }

    /**
     * Create and process image
     *
     * @param string $binary
     * @param bool $mono
     * @return \Intervention\Image\Image
     */
    private function createImage(string $binary, bool $mono = false)
    {

        // Use Intervention Image v2: use \Intervention\Image\ImageManagerStatic::make()
        $img = \Intervention\Image\ImageManagerStatic::make($binary);


        if ($mono) {
            $img = $img->greyscale(); // high-contrast mono for thermal printing
        }

        return $img;
    }

    /**
     * Store image file to storage
     *
     * @param \Intervention\Image\Image $img
     * @param string $type
     * @param int $id
     * @param string|null $filename
     * @return string
     */
    private function storeImageFile($img, string $type, int $id, ?string $filename = null): string
    {
        $dir = 'print';
        Files::createDirectoryIfNotExist($dir);

        $name = $this->resolveSafeFilename($type, $id, $filename);
        $path = $dir . '/' . $name;

        // Save under public/user-uploads/{dir}/{name}
        $fullPath = public_path(Files::UPLOAD_FOLDER . '/' . $path);

        // First delete old file if exists
        if (\Illuminate\Support\Facades\File::exists($fullPath)) {
            \Illuminate\Support\Facades\File::delete($fullPath);
        }

        // Use encode('png') instead of toPng() for GD driver compatibility
        $pngData = $img->encode('png')->getEncoded();

        \Illuminate\Support\Facades\File::put($fullPath, $pngData);

        return $path;
    }

    /**
     * Accept a unique per-print filename from the client when it matches the expected
     * pattern for this type/id; otherwise generate a new unique name.
     */
    private function resolveSafeFilename(string $type, int $id, ?string $filename): string
    {
        $type = strtolower($type);
        $allowedTypes = ['kot', 'order', 'x-report', 'z-report'];

        if (! in_array($type, $allowedTypes, true)) {
            throw new \InvalidArgumentException('Invalid print image type');
        }

        if (is_string($filename) && $filename !== '') {
            // Basename only — reject path traversal.
            $filename = basename(str_replace('\\', '/', $filename));

            // order-123-67a1b2c3d4e5f.png (also allow legacy order-123.png)
            $pattern = '/^' . preg_quote($type, '/') . '\-' . $id . '(?:-[A-Za-z0-9]+)?\.png$/';
            if (preg_match($pattern, $filename)) {
                return $filename;
            }

            throw new \InvalidArgumentException('Invalid print image filename');
        }

        return $type . '-' . $id . '-' . uniqid('', false) . '.png';
    }

    /**
     * Get public URL for the stored image, with a 2 second delay
     *
     * @param string $path
     * @return string
     */
    private function getImageUrl(string $path): string
    {
        // Clean URL without query string — desktop app needs a real .png/.pdf extension.
        return asset(Files::UPLOAD_FOLDER . '/' . $path);
    }
}
