<?php

namespace App\Helper;

use ArPHP\I18N\Arabic;

/**
 * RTL glyph shaping for receipt/KOT/PDF print views (DomPDF and thermal lack bidi).
 */
class RtlPrintText
{
    /** @var list<string> */
    public const RTL_LOCALES = ['ar', 'fa', 'ur', 'he', 'ps', 'ku', 'sd', 'ckb'];

    private static ?Arabic $arabic = null;

    /**
     * Shape RTL text for print output when the renderer does not handle bidi.
     *
     * @param  string|null  $printingChoice  Pass "browserPopupPrint" to skip shaping; null/other modes shape RTL text.
     */
    public static function flip(string $text, ?string $language = null, ?string $printingChoice = null): string
    {
        if ($printingChoice === 'browserPopupPrint') {
            return $text;
        }

        if ($language === null || ! in_array($language, self::RTL_LOCALES, true)) {
            return $text;
        }

        return self::arabic()->utf8Glyphs($text);
    }

    private static function arabic(): Arabic
    {
        return self::$arabic ??= new Arabic();
    }

    /**
     * Reset cached instance (useful in long-running workers between jobs).
     */
    public static function reset(): void
    {
        self::$arabic = null;
    }
}
