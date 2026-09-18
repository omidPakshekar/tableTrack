<?php

namespace App\Livewire\QrCode;

use App\Helper\Files;
use App\Models\Area;
use Livewire\Component;
use App\Models\Table;
use App\Models\FileStorage;

class QrCodes extends Component
{
    public function downloadQrCode($tableCode, $branchId)
    {
        $filename = 'qrcode-' . $branchId . '-' . str()->slug($tableCode, '-', (auth()->user() ? auth()->user()->locale : 'en')) . '.png';

        $file = FileStorage::where('filename', $filename)->first();

        return download_local_s3($file, 'qrcodes/' . $filename);
    }

    public function downloadBranchQrCode()
    {
        $branch = branch();

        $filename = 'qrcode-branch-' . $branch->id . '-' . $branch->restaurant->id . '.png';

        $file = FileStorage::where('filename', $filename)->first();

        return download_local_s3($file, 'qrcodes/' . $filename);
    }

    public function generateQrCode($tableId = null)
    {
        if ($tableId) {
            $table = Table::find($tableId);
        } else {
            $table = branch();
        }

        $table->generateQrCode();

        $this->redirect(route('qrcodes.index'));
    }

    /**
     * Flat list of QR entries for Alpine (selection, modal, bulk print).
     *
     * @param  \Illuminate\Support\Collection<int, Area>  $areasWithTables
     * @return list<array<string, mixed>>
     */
    private function buildPrintableQrItems($areasWithTables): array
    {
        $items = [];
        $restaurant = restaurant();
        $branch = branch();

        if ($branch->qRCodeUrl) {
            $items[] = [
                'id' => 'branch-' . $branch->id,
                'kind' => 'branch',
                'label' => $branch->name ?: __('menu.qrCodes'),
                'subtitle' => __('modules.table.branchMenuQr'),
                'image_url' => $branch->qRCodeUrl,
                'visit_url' => route('table_order', [$restaurant->id])
                    . '?branch=' . $branch->unique_hash
                    . '&hash=' . $restaurant->hash
                    . '&from_qr=1',
                'table_id' => null,
                'table_code' => null,
                'branch_id' => $branch->id,
            ];
        }

        foreach ($areasWithTables as $area) {
            foreach ($area->tables as $table) {
                if (empty($table->qRCodeUrl)) {
                    continue;
                }

                $items[] = [
                    'id' => 'table-' . $table->id,
                    'kind' => 'table',
                    'label' => $table->table_code,
                    'subtitle' => $area->area_name,
                    'area_id' => $area->id,
                    'image_url' => $table->qRCodeUrl,
                    'visit_url' => route('table_order', [$table->hash]) . '?hash=' . $restaurant->hash,
                    'table_id' => $table->id,
                    'table_code' => $table->table_code,
                    'branch_id' => $table->branch_id,
                    'available_status' => $table->available_status,
                    'status' => $table->status,
                    'seating_capacity' => $table->seating_capacity,
                ];
            }
        }

        return $items;
    }

    public function render()
    {
        $areasWithTables = Area::with('tables')->get();

        return view('livewire.qr-code.qr-codes', [
            'tables' => $areasWithTables,
            'areas' => $areasWithTables,
            'printableQrItems' => $this->buildPrintableQrItems($areasWithTables),
            'restaurantName' => restaurant()->name,
        ]);
    }
}
