<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Exports\MenuItemExport;
use App\Jobs\MenuItemExportJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use App\Scopes\AvailableMenuItemScope;
use App\Scopes\RestaurantScope;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MenuItemExportService
{
    /**
     * Maximum records for direct export
     */
    const MAX_DIRECT_EXPORT = 5000;

    /**
     * Export menu items based on filters
     */
    public function export(array $filters, string $format = 'csv', int $userId): array|BinaryFileResponse
    {
        $count = $this->countRecords($filters);
        $this->logExportActivity($userId, $filters, $count);

        // Queue for large datasets, otherwise direct download
        return $count > self::MAX_DIRECT_EXPORT
            ? $this->queueExport($filters, $format, $userId)
            : $this->directExport($filters, $format);
    }

    /**
     * Count records based on filters
     */
    public function countRecords(array $filters): int
    {
        return $this->buildQuery($filters)->count();
    }

    /**
     * Build base query with filters
     */
    private function buildQuery(array $filters)
    {
        $query = MenuItem::withoutGlobalScope(AvailableMenuItemScope::class)
            ->withoutGlobalScope(RestaurantScope::class);

        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * Apply filters to query
     */
    protected function applyFilters($query, array $filters): void
    {
        // Early return if no filters
        if (empty($filters)) {
            return;
        }

        if (!empty($filters['category_id'])) {
            $query->where('item_category_id', $filters['category_id']);
        }

        if (!empty($filters['menu_id'])) {
            $query->where('menu_id', $filters['menu_id']);
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('is_available', (bool)$filters['status']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }
    }

    /**
     * Direct export for small datasets
     */
    public function directExport(array $filters, string $format): BinaryFileResponse
    {
        $fileName = 'menu-items-' . now()->format('Y-m-d-His') . '.' . $format;
        $writerType = $format === 'xlsx' ? \Maatwebsite\Excel\Excel::XLSX : \Maatwebsite\Excel\Excel::CSV;

        return Excel::download(new MenuItemExport($filters), $fileName, $writerType);
    }

    protected function queueExport(array $filters, string $format, int $userId): array
    {
        MenuItemExportJob::dispatch($filters, $format, $userId);

        return [
            'queued' => true,
            'message' => __('messages.exportQueued'),
        ];
    }

    protected function logExportActivity(int $userId, array $filters, int $recordCount): void
    {
        Log::info('Menu items export initiated', [
            'user_id' => $userId,
            'filters' => $filters,
            'record_count' => $recordCount,
            'timestamp' => now()->toDateTimeString(),
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Delete export file
     */
    public function deleteExportFile(string $fileName): bool
    {
        try {
            Storage::delete('exports/' . $fileName);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete export file', [
                'file_name' => $fileName,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
