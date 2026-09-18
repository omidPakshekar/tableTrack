<?php

namespace App\Models;

use App\Traits\HasBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Models\BaseModel;

class ReceiptSetting extends BaseModel
{
    use HasBranch;
    use HasFactory;
    protected $guarded = ['id'];
    protected $casts = [
        'receipt_languages' => 'array',
    ];

    protected $appends = [
        'payment_qr_code_url',
    ];

    public function paymentQrCodeUrl(): Attribute
    {
        return Attribute::get(function (): string {
            return $this->payment_qr_code ? asset_url_local_s3('payment_qr_code/' . $this->payment_qr_code) : '';
        });
    }

    public function displayFooterText(): string
    {
        $plain = self::footerTextToPlain($this->footer_text);

        return filled($plain) ? $plain : __('messages.thankYouVisit');
    }

    public static function footerTextToPlain(?string $html): string
    {
        if (! filled($html)) {
            return '';
        }

        $text = preg_replace('/<\/div>\s*<div>/i', "\n", (string) $html);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);

        return trim($text);
    }
}
