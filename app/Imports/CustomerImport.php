<?php

namespace App\Imports;

use App\Models\Customer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CustomerImport implements ToCollection, WithHeadingRow, WithChunkReading, ShouldQueue, SkipsEmptyRows
{
    protected $restaurantId;
    protected $skippedCount = 0;
    protected $importedCount = 0;

    public function __construct($restaurantId)
    {
        $this->restaurantId = $restaurantId;
    }

    public function collection(Collection $rows)
    {


        foreach ($rows as $index => $row) {
            try {
                // Convert row to array for easier handling
                $rowData = $row->toArray();

                // Skip if required fields are empty
                if (empty($rowData['name']) && empty($rowData['phone']) && empty($rowData['email'])) {
                    $this->skippedCount++;
                    continue;
                }

                // Check for duplicate customer by phone or email
                $existingCustomer = Customer::where('restaurant_id', $this->restaurantId)
                                            ->where(function($query) use ($rowData) {
                                                $query->where('phone', $rowData['phone'] ?? null)
                                                    ->orWhere('email', $rowData['email'] ?? null);
                                            })
                                            ->first();

                // If customer already exists, skip this row and continue
                if ($existingCustomer) {
                    $this->skippedCount++;
                    continue;
                }

                // Create a new customer record
                $customer = Customer::create([
                    'name'        => $rowData['name'] ?? null,
                    'phone'       => $rowData['phone'] ?? null,
                    'email'       => $rowData['email'] ?? null,
                    'restaurant_id' => $this->restaurantId,
                ]);

                $this->importedCount++;

            } catch (\Exception $e) {
                $this->skippedCount++;
            }
        }

    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function getImportedCount(): int
    {
        return $this->importedCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

}






