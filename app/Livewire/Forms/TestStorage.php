<?php

namespace App\Livewire\Forms;

use App\Helper\Files;
use App\Models\StorageSetting;
use Illuminate\Support\Facades\Storage;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;
use Livewire\WithFileUploads;

class TestStorage extends Component
{
    use LivewireAlert, WithFileUploads;

    public $file;

    public $fileUrl;

    public ?string $uploadedFilename = null;

    public ?string $uploadedObjectKey = null;

    public ?string $storageDisk = null;

    public ?string $storageFilesystem = null;

    public ?string $storageBucket = null;

    public ?string $storageRegion = null;

    public ?string $storageEndpoint = null;

    public bool $isCloudStorage = false;

    public function submitForm(): void
    {
        $this->resetUploadResult();

        try {
            $dir = '/';
            $storedName = Files::uploadLocalOrS3($this->file, $dir);
            $objectKey = ltrim($dir, '/') !== ''
                ? trim($dir, '/') . '/' . $storedName
                : $storedName;

            $disk = (string) config('filesystems.default', 'local');
            $setting = StorageSetting::where('status', 'enabled')->first();

            $this->storageDisk = $disk;
            $this->storageFilesystem = $setting?->filesystem ?? 'local';
            $this->isCloudStorage = in_array($disk, StorageSetting::S3_COMPATIBLE_STORAGE, true);
            $this->uploadedFilename = $this->file->getClientOriginalName();
            $this->uploadedObjectKey = $objectKey;
            $this->fileUrl = asset_url_local_s3($objectKey);

            if ($this->isCloudStorage) {
                $this->storageBucket = config("filesystems.disks.{$disk}.bucket");
                $this->storageRegion = config("filesystems.disks.{$disk}.region");
                if ($disk === 'minio') {
                    $this->storageEndpoint = config('filesystems.disks.minio.endpoint');
                }
            }

            if (! Storage::disk($disk)->exists($objectKey)) {
                $this->addError('file_error', __('app.fileNotUploaded'));

                return;
            }
        } catch (\Exception $e) {
            $this->addError('file_error', $e->getMessage());

            return;
        }
    }

    public function storageProviderLabel(): string
    {
        $key = match ($this->storageFilesystem) {
            'aws_s3' => 'modules.settings.aws_s3',
            'digitalocean' => 'modules.settings.digitalocean',
            'wasabi' => 'modules.settings.wasabi',
            'minio' => 'modules.settings.minio',
            default => 'modules.settings.local',
        };

        return __($key);
    }

    private function resetUploadResult(): void
    {
        $this->fileUrl = null;
        $this->uploadedFilename = null;
        $this->uploadedObjectKey = null;
        $this->storageDisk = null;
        $this->storageFilesystem = null;
        $this->storageBucket = null;
        $this->storageRegion = null;
        $this->storageEndpoint = null;
        $this->isCloudStorage = false;
    }

    public function render()
    {
        return view('livewire.forms.test-storage');
    }
}
