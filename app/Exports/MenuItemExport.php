<?php

namespace App\Exports;

use App\Models\MenuItem;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use App\Scopes\AvailableMenuItemScope;
use App\Scopes\RestaurantScope;

class MenuItemExport implements WithMapping, FromQuery, WithHeadings, WithStyles, ShouldAutoSize
{
    use Exportable;

    protected $filters;
    protected $locale; // Cache locale to avoid repeated function calls

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
        $this->locale = app()->getLocale(); // Cache once instead of calling in each row
    }

    public function headings(): array
    {
        return [
            'ID',
            __('modules.menu.itemName'),
            __('modules.menu.description'),
            __('modules.menu.setPrice'),
            __('modules.menu.category'),
            __('modules.menu.menuCollection'),
            __('modules.menu.itemType'),
            __('modules.menu.status'),
            __('modules.menu.inStock'),
            __('modules.menu.preparationTime') . ' (min)',
            __('modules.menu.kitchenType'),
            __('modules.menu.showOnCustomerSite'),
            __('modules.menu.taxInclusive'),
            __('app.sortOrder'),
            __('modules.customer.createdDate'),
            __('modules.customer.updatedDate'),
        ];
    }

    public function map($menuItem): array
    {
        // Use cached locale instead of calling app()->getLocale() for each row
        $categoryName = $menuItem->category?->getTranslation('category_name', $this->locale) ?? 'N/A';
        $menuName = $menuItem->menu?->getTranslation('menu_name', $this->locale) ?? 'N/A';
        $kitchenName = $menuItem->kotPlace?->name ?? 'N/A';

        $status = $menuItem->is_available ? __('modules.menu.available') : __('modules.menu.unavailable');
        $inStockStatus = $menuItem->in_stock ? __('app.yes') : __('app.no');
        $showOnSite = $menuItem->show_on_customer_site ? __('app.yes') : __('app.no');
        $taxInclusive = $menuItem->tax_inclusive ? __('app.yes') : __('app.no');

        $typeLabels = [
            'veg' => __('modules.menu.typeVeg'),
            'non-veg' => __('modules.menu.typeNonVeg'),
            'egg' => __('modules.menu.typeEgg'),
            'drink' => __('modules.menu.typeDrink'),
            'halal' => __('modules.menu.typeHalal'),
            'other' => __('modules.menu.typeOther'),
        ];
        $type = $typeLabels[$menuItem->type] ?? $menuItem->type;

        return [
            $menuItem->id,
            $menuItem->item_name,
            $menuItem->description ?? '',
            $menuItem->price ? currency_format($menuItem->price, restaurant()->currency_id) : 'N/A',
            $categoryName,
            $menuName,
            $type,
            $status,
            $inStockStatus,
            $menuItem->preparation_time ?? 'N/A',
            $kitchenName,
            $showOnSite,
            $taxInclusive,
            $menuItem->sort_order ?? 0,
            $menuItem->created_at?->format('Y-m-d H:i:s') ?? 'N/A',
            $menuItem->updated_at?->format('Y-m-d H:i:s') ?? 'N/A',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold text with background color
            1 => [
                'font' => ['bold' => true, 'name' => 'Arial'],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'f5f5f5'],
                ]
            ],
        ];
    }

    public function query()
    {
        $query = MenuItem::withoutGlobalScope(AvailableMenuItemScope::class)
            ->withoutGlobalScope(RestaurantScope::class)
            ->select([
                'id', 'item_name', 'description', 'price', 'item_category_id', 'menu_id',
                'type', 'is_available', 'in_stock', 'preparation_time', 'kot_place_id',
                'show_on_customer_site', 'tax_inclusive', 'sort_order', 'created_at', 'updated_at'
            ])
            ->with([
                'category:id,category_name',
                'menu:id,menu_name',
                'kotPlace:id,name'
            ]);

        // Apply filters
        if (!empty($this->filters['category_id'])) {
            $query->where('item_category_id', $this->filters['category_id']);
        }

        if (!empty($this->filters['menu_id'])) {
            $query->where('menu_id', $this->filters['menu_id']);
        }

        if (isset($this->filters['status']) && $this->filters['status'] !== '') {
            $query->where('is_available', (bool)$this->filters['status']);
        }

        if (!empty($this->filters['type'])) {
            $query->where('type', $this->filters['type']);
        }

        if (!empty($this->filters['start_date'])) {
            $query->whereDate('created_at', '>=', $this->filters['start_date']);
        }

        if (!empty($this->filters['end_date'])) {
            $query->whereDate('created_at', '<=', $this->filters['end_date']);
        }

        return $query->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc');
    }
}
