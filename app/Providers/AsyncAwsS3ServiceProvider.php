<?php

namespace App\Providers;

use App\Filesystem\AsyncAwsS3FilesystemFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AsyncAwsS3ServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('s3', function ($app, array $config) {
            return AsyncAwsS3FilesystemFactory::create($config);
        });
    }
}
