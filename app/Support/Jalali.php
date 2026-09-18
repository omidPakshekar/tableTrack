<?php

namespace App\Support;

use Carbon\Carbon;

class Jalali
{
    private const MONTHS = [
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند',
    ];

    private const MONTHS_SHORT = [
        1 => 'فرو',
        2 => 'ارد',
        3 => 'خرد',
        4 => 'تیر',
        5 => 'مرد',
        6 => 'شهر',
        7 => 'مهر',
        8 => 'آبا',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهم',
        12 => 'اسف',
    ];

    private const WEEKDAYS = [
        0 => 'یکشنبه',
        1 => 'دوشنبه',
        2 => 'سه‌شنبه',
        3 => 'چهارشنبه',
        4 => 'پنجشنبه',
        5 => 'جمعه',
        6 => 'شنبه',
    ];

    public function __construct(
        public int $year,
        public int $month,
        public int $day,
        private ?Carbon $source = null,
    ) {
    }

    public static function fromCarbon(Carbon $date): self
    {
        [$year, $month, $day] = self::toJalali(
            (int) $date->year,
            (int) $date->month,
            (int) $date->day
        );

        return new self($year, $month, $day, $date->copy());
    }

    public static function fromFormat(string $value, string $format): ?self
    {
        $parsed = self::parseFormat($value, $format);

        if ($parsed === null) {
            return null;
        }

        return new self($parsed['y'], $parsed['m'], $parsed['d']);
    }

    public function toCarbon(?string $timezone = null): Carbon
    {
        [$gy, $gm, $gd] = self::toGregorian($this->year, $this->month, $this->day);
        $timezone = $timezone ?? $this->source?->timezoneName ?? config('app.timezone');

        return Carbon::create($gy, $gm, $gd, 0, 0, 0, $timezone);
    }

    public function format(string $format, ?Carbon $timeSource = null): string
    {
        $timeSource ??= $this->source;

        $tokens = [
            'd' => str_pad((string) $this->day, 2, '0', STR_PAD_LEFT),
            'j' => (string) $this->day,
            'm' => str_pad((string) $this->month, 2, '0', STR_PAD_LEFT),
            'n' => (string) $this->month,
            'Y' => (string) $this->year,
            'y' => substr((string) $this->year, -2),
            'F' => self::MONTHS[$this->month] ?? '',
            'M' => self::MONTHS_SHORT[$this->month] ?? '',
        ];

        if ($timeSource) {
            $tokens['H'] = $timeSource->format('H');
            $tokens['i'] = $timeSource->format('i');
            $tokens['s'] = $timeSource->format('s');
            $tokens['A'] = $timeSource->format('A');
            $tokens['a'] = $timeSource->format('a');
            $tokens['g'] = $timeSource->format('g');
            $tokens['h'] = $timeSource->format('h');
            $tokens['l'] = self::WEEKDAYS[(int) $timeSource->dayOfWeek] ?? '';
        }

        $result = '';
        $length = strlen($format);

        for ($i = 0; $i < $length; $i++) {
            $char = $format[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $result .= $format[++$i];
                continue;
            }

            $result .= $tokens[$char] ?? $char;
        }

        return $result;
    }

    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $gDaysInMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd;

        for ($i = 0; $i < $gm - 1; $i++) {
            $days += $gDaysInMonth[$i];
        }

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $jm = 0;

        for ($i = 0; $i < 11 && $days >= $jDaysInMonth[$i]; $i++) {
            $days -= $jDaysInMonth[$i];
            $jm++;
        }

        return [$jy, $jm + 1, $days + 1];
    }

    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

        $jy -= 979;
        $days = (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4);

        for ($i = 0; $i < $jm - 1; $i++) {
            $days += $jDaysInMonth[$i];
        }

        $days += $jd - 1;
        $gy = 1600 + (400 * intdiv($days, 146097));
        $days %= 146097;

        $leap = true;

        if ($days >= 36525) {
            $days--;
            $gy += 100 * intdiv($days, 36524);
            $days %= 36524;

            if ($days >= 365) {
                $days++;
            } else {
                $leap = false;
            }
        }

        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days >= 366) {
            $leap = false;
            $days--;
            $gy += intdiv($days, 365);
            $days %= 365;
        }

        $gDaysInMonth = [31, ($leap ? 29 : 28), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;

        for ($i = 0; $i < 12 && $days >= $gDaysInMonth[$i]; $i++) {
            $days -= $gDaysInMonth[$i];
            $gm++;
        }

        return [$gy, $gm + 1, $days + 1];
    }

    private static function parseFormat(string $value, string $format): ?array
    {
        $separator = str_contains($format, '/') ? '/' : '-';
        $valueParts = preg_split('/[\/\-]/', trim($value)) ?: [];
        $formatParts = preg_split('/[\/\-]/', $format) ?: [];

        if (count($valueParts) !== count($formatParts)) {
            return null;
        }

        $year = null;
        $month = null;
        $day = null;

        foreach ($formatParts as $index => $part) {
            $number = (int) $valueParts[$index];

            if ($part === 'Y' || $part === 'y') {
                $year = $number;
            } elseif ($part === 'm' || $part === 'n') {
                $month = $number;
            } elseif ($part === 'd' || $part === 'j') {
                $day = $number;
            }
        }

        if (!$year || !$month || !$day) {
            return null;
        }

        return ['y' => $year, 'm' => $month, 'd' => $day];
    }
}
