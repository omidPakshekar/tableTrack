<?php

namespace App\Traits;

use App\Helper\Files;
use App\Helper\QrCodeGenerator;
use Symfony\Component\HttpFoundation\File\File;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Storage;

trait GeneratesQrCode
{
    public function createQrCode(string $qrUrl, ?string $label = null)
    {
        $fileName = $this->getQrCodeFileName();
        $filePath = public_path(Files::UPLOAD_FOLDER . '/qrcodes/' . $fileName);

        Files::createDirectoryIfNotExist('qrcodes');
        QrCodeGenerator::savePng($qrUrl, $filePath, $label);

        Files::fileStore(
            new File($filePath),
            'qrcodes',
            $fileName,
            uploaded: false,
            restaurantId: $this->getRestaurantId()
        );

        // Move file to cloud storage
        if (config('filesystems.default') !== 'local') {
            $contents = FileFacade::get($filePath);
            Storage::disk(config('filesystems.default'))->put('qrcodes/' . $fileName, $contents);
            // Delete local file
            unlink($filePath);
        }
    }

    abstract protected function getQrCodeFileName(): string;

    abstract protected function getRestaurantId(): int;
}
