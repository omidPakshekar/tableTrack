<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\MenuItemExportService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MenuItemController extends Controller
{

    public function index()
    {
        abort_if(!in_array('Menu Item', restaurant_modules()), 403);
        abort_if((!user_can('Show Menu Item')), 403);
        return view('menu_items.index');
    }

    public function bulkImport()
    {
        abort_if(!in_array('Menu Item', restaurant_modules()), 403);
        abort_if((!user_can('Create Menu Item')), 403);
        return view('menu_items.bulk-import');
    }

    public function create()
    {
        return view('menu_items.create');
    }

    public function edit($menuItemId)
    {
        return view('menu_items.edit', compact('menuItemId'));
    }

    public function exportPage()
    {
        abort_if(!in_array('Menu Item', restaurant_modules()), 403);
        abort_if(!user_can('Export Menu Item'), 403);
        return view('menu_items.export');
    }

    public function downloadExport(Request $request, MenuItemExportService $exportService)
    {
        abort_if(!in_array('Menu Item', restaurant_modules()), 403);
        abort_if(!user_can('Export Menu Item'), 403);

        try {
            $fileName = decrypt($request->file);
            $filePath = 'exports/' . $fileName;

            abort_unless(Storage::exists($filePath), 404, 'Export file not found or has expired.');

            $response = Storage::download($filePath, $fileName);

            // Schedule file cleanup after response is sent
            dispatch(function() use ($fileName, $exportService) {
                sleep(5);
                $exportService->deleteExportFile($fileName);
            })->afterResponse();

            return $response;

        } catch (\Exception $e) {
            Log::error('Export download failed', ['error' => $e->getMessage()]);
            abort(404, 'Invalid or expired export link.');
        }
    }

    public function exportDirect(Request $request, MenuItemExportService $exportService)
    {
        abort_if(!in_array('Menu Item', restaurant_modules()), 403);
        abort_if(!user_can('Export Menu Item'), 403);

        try {
            $filters = json_decode(base64_decode($request->filters), true) ?? [];
            $format = $request->format ?? 'csv';

            return $exportService->directExport($filters, $format);
        } catch (\Exception $e) {
            Log::error('Direct export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            abort(500, 'Export failed: ' . $e->getMessage());
        }
    }
}
