<?php

namespace App\Livewire\Menu;

use App\Models\Menu;
use App\Models\ItemCategory;
use App\Services\MenuItemExportService;
use Livewire\Component;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Illuminate\Support\Facades\Log;

class ExportMenuItems extends Component
{
    use LivewireAlert;

    public $showAdvancedExport = false;

    // Export options
    public $format = 'csv';

    // Filters
    public $category_id = '';
    public $menu_id = '';
    public $status = '';
    public $type = '';
    public $start_date = '';
    public $end_date = '';

    // Loading state
    public $isExporting = false;
    public $recordCount = 0;

    // Available options (cached)
    public $categories = [];
    public $menus = [];

    // Static type labels - no need for dynamic translation here since it's only for filter display
    protected $typeLabels = [
        'veg' => 'Veg',
        'non-veg' => 'Non Veg',
        'egg' => 'Egg',
        'drink' => 'Drink',
        'halal' => 'Halal',
        'other' => 'Other',
    ];

    protected $statusLabels = [
        '1' => 'Available',
        '0' => 'Unavailable',
    ];

    protected $exportService;

    public function boot(MenuItemExportService $exportService)
    {
        $this->exportService = $exportService;
    }

    public function mount()
    {
        // Cache categories and menus with minimal columns for performance
        $this->categories = ItemCategory::select('id', 'category_name')->get();
        $this->menus = Menu::select('id', 'menu_name')->get();
    }

    // Computed property for types to avoid storing in public array
    public function getTypesProperty()
    {
        return $this->typeLabels;
    }

    // Computed property for statuses
    public function getStatusesProperty()
    {
        return $this->statusLabels;
    }

    // Single lifecycle hook for all filter updates - more efficient
    public function updated($propertyName)
    {
        // Only update count when filters change and advanced export is shown
        if ($this->showAdvancedExport && in_array($propertyName, [
            'category_id', 'menu_id', 'status', 'type', 'start_date', 'end_date'
        ])) {
            $this->updateRecordCount();
        }
    }

    public function updateRecordCount()
    {
        $filters = $this->getFilters();
        $this->recordCount = $this->exportService->countRecords($filters);
    }

    public function toggleAdvancedExport()
    {
        $this->showAdvancedExport = !$this->showAdvancedExport;

        if ($this->showAdvancedExport) {
            $this->updateRecordCount();
        }
    }

    public function quickExport()
    {
        if (!user_can('Export Menu Item')) {
            $this->alert('error', __('messages.permissionDenied'));
            return;
        }

        $this->isExporting = true;

        try {
            $result = $this->exportService->export([], 'csv', user()->id);

            if (is_array($result) && isset($result['queued'])) {
                $this->alert('success', $result['message'], [
                    'toast' => true,
                    'position' => 'top-end',
                    'timer' => 5000,
                ]);
            } else {
                return redirect()->route('menu-items.export.direct', [
                    'filters' => base64_encode(json_encode([])),
                    'format' => 'csv'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Quick export failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->alert('error', __('messages.exportFailed') . ': ' . $e->getMessage());
        } finally {
            $this->isExporting = false;
        }
    }

    public function advancedExport()
    {
        if (!user_can('Export Menu Item')) {
            $this->alert('error', __('messages.permissionDenied'));
            return;
        }

        if ($this->start_date && $this->end_date && strtotime($this->start_date) > strtotime($this->end_date)) {
            $this->alert('error', __('messages.invalidDateRange'));
            return;
        }

        $this->isExporting = true;

        try {
            $filters = $this->getFilters();
            $result = $this->exportService->export($filters, $this->format, user()->id);

            if (is_array($result) && isset($result['queued'])) {
                $this->alert('success', $result['message'], [
                    'toast' => true,
                    'position' => 'top-end',
                    'timer' => 5000,
                ]);
            } else {
                return redirect()->route('menu-items.export.direct', [
                    'filters' => base64_encode(json_encode($filters)),
                    'format' => $this->format
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Advanced export failed', ['error' => $e->getMessage(), 'filters' => $filters ?? [], 'trace' => $e->getTraceAsString()]);
            $this->alert('error', __('messages.exportFailed') . ': ' . $e->getMessage());
        } finally {
            $this->isExporting = false;
        }
    }

    protected function getFilters(): array
    {
        // Simpler array_filter - removes null and empty string values
        return array_filter([
            'category_id' => $this->category_id ?: null,
            'menu_id' => $this->menu_id ?: null,
            'status' => $this->status !== '' ? $this->status : null,
            'type' => $this->type ?: null,
            'start_date' => $this->start_date ?: null,
            'end_date' => $this->end_date ?: null,
        ]);
    }

    public function resetFilters()
    {
        $this->category_id = '';
        $this->menu_id = '';
        $this->status = '';
        $this->type = '';
        $this->start_date = '';
        $this->end_date = '';
        $this->format = 'csv';
        $this->recordCount = 0;
    }

    public function render()
    {
        return view('livewire.menu.export-menu-items');
    }
}
