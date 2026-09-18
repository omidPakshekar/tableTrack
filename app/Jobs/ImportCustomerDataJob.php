<?php

namespace App\Jobs;

use App\Imports\CustomerImport;
use App\Imports\CustomersImport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ImportCustomerDataJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    protected $filePath;
    protected $restaurantId;

    public function __construct($filePath, $restaurantId)
    {
        $this->filePath = $filePath;
        $this->restaurantId = $restaurantId;
    }

    public function handle()
    {
        if (!Storage::exists($this->filePath)) {

            return;
        }

        try {
            $import = new CustomerImport($this->restaurantId);
            Excel::import($import, Storage::path($this->filePath));
        } catch (\Exception $e) {
        } finally {
            // Clean up the uploaded file
            Storage::delete($this->filePath);
        }
    }

    public function failed(\Throwable $exception)
    {
        // Clean up the uploaded file even if job failed
        if (Storage::exists($this->filePath)) {
            Storage::delete($this->filePath);
        }
    }
}
