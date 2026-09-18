<?php

namespace App\Helper;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use RuntimeException;

class QrCodeGenerator
{
    private const int SIZE = 300;

    private const int MARGIN = 10;

    private const int LABEL_FONT_SIZE = 20;

    private const int LABEL_PADDING = 12;

    public static function savePng(string $data, string $filePath, ?string $label = null): void
    {
        $renderer = new GDLibRenderer(self::SIZE, self::MARGIN);
        $writer = new Writer($renderer);
        $png = $writer->writeString($data, 'UTF-8', ErrorCorrectionLevel::H());

        if ($label !== null && $label !== '') {
            $png = self::appendLabel($png, $label);
        }

        if (file_put_contents($filePath, $png) === false) {
            throw new RuntimeException("Failed to write QR code to {$filePath}");
        }
    }

    private static function appendLabel(string $qrPng, string $label): string
    {
        $qr = imagecreatefromstring($qrPng);

        if ($qr === false) {
            throw new RuntimeException('Failed to load QR image');
        }

        $fontPath = resource_path('fonts/qr-label.ttf');

        if (! is_file($fontPath)) {
            imagedestroy($qr);

            throw new RuntimeException('QR label font not found at ' . $fontPath);
        }

        $qrWidth = imagesx($qr);
        $qrHeight = imagesy($qr);
        $bbox = imagettfbbox(self::LABEL_FONT_SIZE, 0, $fontPath, $label);
        $textWidth = abs($bbox[2] - $bbox[0]);
        $textHeight = abs($bbox[7] - $bbox[1]);
        $labelAreaHeight = $textHeight + (self::LABEL_PADDING * 2);
        $canvasWidth = max($qrWidth, $textWidth + (self::LABEL_PADDING * 2));
        $canvasHeight = $qrHeight + $labelAreaHeight;

        $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $canvasWidth, $canvasHeight, $white);

        $qrX = (int) (($canvasWidth - $qrWidth) / 2);
        imagecopy($canvas, $qr, $qrX, 0, 0, 0, $qrWidth, $qrHeight);
        imagedestroy($qr);

        $black = imagecolorallocate($canvas, 0, 0, 0);
        $textX = (int) (($canvasWidth - $textWidth) / 2);
        $textY = $qrHeight + self::LABEL_PADDING + $textHeight;
        imagettftext($canvas, self::LABEL_FONT_SIZE, 0, $textX, $textY, $black, $fontPath, $label);

        ob_start();
        imagepng($canvas);
        $result = (string) ob_get_clean();
        imagedestroy($canvas);

        return $result;
    }
}
