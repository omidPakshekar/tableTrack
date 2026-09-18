<?php

namespace App\Jobs;

use App\Exports\MenuItemExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;

class MenuItemExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $filters;
    protected $format;
    protected $userId;
    protected $fileName;

    /**
     * Create a new job instance.
     */
    public function __construct(array $filters, string $format, int $userId)
    {
        $this->filters = $filters;
        $this->format = $format;
        $this->userId = $userId;
        $this->fileName = 'menu-items-export-' . Str::random(10) . '.' . $format;
    }

    public function handle(): void
    {
        try {
            Log::info('Menu items export started', [
                'user_id' => $this->userId,
                'filters' => $this->filters,
                'format' => $this->format,
            ]);

            $path = 'exports/' . $this->fileName;
            $writerType = $this->format === 'xlsx' ? \Maatwebsite\Excel\Excel::XLSX : \Maatwebsite\Excel\Excel::CSV;

            Excel::store(new MenuItemExport($this->filters), $path, 'local', $writerType);

            $downloadUrl = route('menu-items.export.download', ['file' => encrypt($this->fileName)]);

            Log::info('Menu items export completed', [
                'user_id' => $this->userId,
                'file_name' => $this->fileName,
                'file_path' => $path,
            ]);

            // Cache export metadata for 1 hour
            cache()->put(
                "export_{$this->userId}_{$this->fileName}",
                [
                    'file_name' => $this->fileName,
                    'download_url' => $downloadUrl,
                    'created_at' => now()->toDateTimeString(),
                ],
                3600 // Use seconds instead of Carbon instance for better performance
            );

        } catch (\Exception $e) {
            Log::error('Menu items export failed', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * The job failed to process.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Menu items export job failed', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}
