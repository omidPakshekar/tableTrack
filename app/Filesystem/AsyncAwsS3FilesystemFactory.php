<?php

namespace App\Filesystem;

use AsyncAws\SimpleS3\SimpleS3Client;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Arr;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\AsyncAwsS3\PortableVisibilityConverter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter;
use League\Flysystem\Visibility;

class AsyncAwsS3FilesystemFactory
{
    /**
     * Build a Laravel filesystem disk using AsyncAws S3 (replaces aws/aws-sdk-php).
     */
    public static function create(array $config): FilesystemAdapter
    {
        $config = self::formatConfig($config);

        $root = (string) ($config['root'] ?? '');

        $visibility = new PortableVisibilityConverter(
            $config['visibility'] ?? Visibility::PUBLIC
        );

        $client = self::createClient($config);

        $adapter = new AsyncAwsS3Adapter(
            $client,
            $config['bucket'],
            $root,
            $visibility,
            null,
            $config['options'] ?? [],
        );

        $driver = self::createFlysystem($adapter, $config);

        $filesystem = new FilesystemAdapter($driver, $adapter, $config);

        $filesystem->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options = []) => $driver->temporaryUrl($path, $expiration, $options)
        );

        return $filesystem;
    }

    protected static function formatConfig(array $config): array
    {
        return Arr::except($config, ['token']);
    }

    protected static function createClient(array $config): SimpleS3Client
    {
        $clientConfig = [];

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $clientConfig['accessKeyId'] = $config['key'];
            $clientConfig['accessKeySecret'] = $config['secret'];
        }

        if (! empty($config['region'])) {
            $clientConfig['region'] = $config['region'];
        }

        if (! empty($config['endpoint'])) {
            $clientConfig['endpoint'] = $config['endpoint'];
        }

        if ($config['use_path_style_endpoint'] ?? false) {
            $clientConfig['pathStyleEndpoint'] = true;
        }

        return new SimpleS3Client($clientConfig);
    }

    protected static function createFlysystem(FlysystemAdapter $adapter, array $config): Flysystem
    {
        if ($config['read-only'] ?? false) {
            $adapter = new ReadOnlyFilesystemAdapter($adapter);
        }

        if (! empty($config['prefix'])) {
            $adapter = new PathPrefixedAdapter($adapter, $config['prefix']);
        }

        if (str_contains($config['endpoint'] ?? '', 'r2.cloudflarestorage.com')) {
            $config['retain_visibility'] = false;
        }

        return new Flysystem($adapter, Arr::only($config, [
            'directory_visibility',
            'disable_asserts',
            'retain_visibility',
            'temporary_url',
            'url',
            'visibility',
        ]));
    }
}
