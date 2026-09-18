<?php

namespace App\Http\Controllers;

use App\Models\Kot;
use App\Helper\Files;
use Illuminate\Support\Facades\Log;
use App\Models\KotPlace;
use App\Models\Printer;

class KotController extends Controller
{
    protected $connector;
    protected $printer;

    public function index()
    {
        abort_if(!in_array('KOT', restaurant_modules()), 303);
        abort_if((!user_can('Manage KOT')), 303);
        return view('kot.index');
    }

    public function printKot($id, $kotPlaceid = null, $width = 80, $thermal = false, $forPdf = false)
    {
        $kot = Kot::with('items', 'order.waiter', 'table')->find($id);
        $kotPlace = KotPlace::with('printerSetting')->find($kotPlaceid);
        
        // Get printer setting - check kotPlace first, then fallback to default
        $printerSetting = $kotPlace?->printerSetting;
        if (!$printerSetting || ($printerSetting && $printerSetting->is_active == 0)) {
            $printerSetting = Printer::where('is_default', true)->first();
        }
        
        $printingChoice = $printerSetting?->printing_choice ?? 'browserPopupPrint';

        // RTL labels: match invoice (order/print) — use default printer choice for flipText.
        // KOT place printer may be directPrint, but HTML is browser-rendered (popup or html-to-image).
        $defaultPrinter = Printer::where('is_default', true)->first();
        $rtlPrintingChoice = $defaultPrinter?->printing_choice ?? 'browserPopupPrint';

        $receiptLanguages = $kot->order->branch->receiptSetting->receipt_languages ?? ['en'];

        return view('pos.printKot', compact('kot', 'kotPlaceid', 'width', 'thermal', 'kotPlace', 'printingChoice', 'rtlPrintingChoice', 'forPdf', 'receiptLanguages'));
    }
}
