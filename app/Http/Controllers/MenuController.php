<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    /**
     * Download Menu PDF via signed URL (used in emails).
     */
    public function downloadPdf($restaurant)
    {
        // Resolve restaurant by ID (route model binding might fail for signed URLs)
        $restaurantModel = Restaurant::find($restaurant);
        
        if (!$restaurantModel) {
            abort(404, 'Restaurant not found');
        }

        try {
            $pdfContent = $this->getMenuPdfContent($restaurantModel->id);
            $fileName = 'menu-' . now()->format('Y-m-d') . '.pdf';

            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            ]);
        } catch (\Exception $e) {
            abort(500, 'Failed to generate PDF: ' . $e->getMessage());
        }
    }


    public function index()
    {
        abort_if(!in_array('Menu', restaurant_modules()), 403);
        abort_if((!user_can('Show Menu')), 403);

        return view('menu.index');
    }

    public function unifiedSort()
    {
        abort_if(!in_array('Menu', restaurant_modules()), 403);
        abort_if((!user_can('Show Menu')), 403);

        return view('menu.unified_sort');
    }

    /**
     * Get PDF content as string for email attachment
     * Returns all active menus and menu items
     */
    public function getMenuPdfContent($restaurantId = null)
    {
        $restaurant = $restaurantId ? Restaurant::find($restaurantId) : restaurant();
        
        if (!$restaurant) {
            throw new \Exception('Restaurant not found');
        }

        $branchIds = $restaurant->branches->pluck('id')->toArray();
        
        if (empty($branchIds)) {
            // If no branches, return empty PDF or handle gracefully
            $menus = collect();
        } else {
            // Get all active menus with their active menu items from all branches
            $menus = Menu::whereIn('branch_id', $branchIds)
                ->with(['items' => function($query) {
                    $query->withoutGlobalScope(\App\Scopes\AvailableMenuItemScope::class)
                        ->where('is_available', true)
                        ->with(['category', 'variations'])
                        ->orderBy('item_category_id')
                        ->orderBy('sort_order');
                }])
                ->get()
                ->filter(function($menu) {
                    return $menu->items->isNotEmpty();
                });
        }

        // Generate PDF
        $pdf = Pdf::loadView('menu.print-pdf', [
            'menus' => $menus,
            'settings' => $restaurant,
        ]);

        // Set paper size to A4
        $pdf->setPaper('A4', 'portrait');

        return $pdf->output();
    }
}
