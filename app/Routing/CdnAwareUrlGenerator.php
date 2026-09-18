<?php

namespace App\Routing;

use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Str;

class CdnAwareUrlGenerator extends UrlGenerator
{
    /**
     * Generate the URL to an application asset.
     * Only Vite build assets (path starting with "build/") use CDN; images and other assets use app URL.
     *
     * @param  string  $path
     * @param  bool|null  $secure
     * @return string
     */
    public function asset($path, $secure = null)
    {
        if ($this->isValidUrl($path)) {
            return $path;
        }

        $path = ltrim($path, '/');
        $assetUrl = config('app.asset_url');

        if ($assetUrl && (Str::startsWith($path, 'build/') || Str::startsWith($path, 'vendor/'))) {
            return rtrim($assetUrl, '/') . '/' . $path;
        }

        return parent::asset($path, $secure);
    }
}
