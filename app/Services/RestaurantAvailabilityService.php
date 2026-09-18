<?php

namespace App\Services;

use App\Models\BranchOperationalShift;
use Carbon\Carbon;

class RestaurantAvailabilityService
{
    /**
     * Check whether the current time falls inside a start/end time window.
     * Supports overnight windows (e.g. 22:00 to 04:00).
     */
    public static function isCurrentTimeWithinOperatingWindow(string $startTime, string $endTime, ?Carbon $currentTime = null): bool
    {
        $currentTime = $currentTime ? $currentTime->copy() : now();
        $timezone = $currentTime->getTimezone();

        $start = Carbon::parse($currentTime->toDateString() . ' ' . $startTime, $timezone);
        $end = Carbon::parse($currentTime->toDateString() . ' ' . $endTime, $timezone);

        // Overnight range: end time is on next day.
        if ($end->lessThanOrEqualTo($start)) {
            if ($currentTime->lessThan($start)) {
                $start->subDay();
            } else {
                $end->addDay();
            }
        }

        return $currentTime->betweenIncluded($start, $end);
    }

    /**
     * Determine if restaurant can accept new orders/payments right now.
     *
     * Returns:
     * - is_open: bool
     * - reason: manual_close|outside_manual_hours|outside_operating_hours|null
     * - mode: auto|manual
     */
    public static function getAvailability($restaurant = null, $branch = null, $dateTime = null, string $context = 'order'): array
    {
        $restaurant = $restaurant ?: restaurant();

        $respond = static function (bool $isOpen, string $mode, ?string $reason = null, array $extra = []): array {
            return array_merge([
                'is_open' => $isOpen,
                'reason' => $reason,
                'mode' => $mode,
            ], $extra);
        };

        if (!$restaurant) {
            return $respond(true, 'auto');
        }

        $timezone = $restaurant->timezone ?? 'UTC';
        $currentTime = $dateTime
            ? Carbon::parse($dateTime, $timezone)
            : Carbon::now($timezone);
        $mode = $restaurant->restaurant_open_close_mode ?? 'auto';

        if ((bool) ($restaurant->is_temporarily_closed ?? false)) {
            return $respond(false, $mode, 'manual_close');
        }

        if ($mode === 'manual') {
            $manualControlType = $restaurant->restaurant_manual_open_close_type ?? 'time';

            if ($manualControlType === 'toggle') {
                $isOpen = !(bool) ($restaurant->is_temporarily_closed ?? false);

                return $respond($isOpen, 'manual', $isOpen ? null : 'manual_close', [
                    'manual_control_type' => 'toggle',
                ]);
            }

            $manualOpenTime = $restaurant->manual_open_time;
            $manualCloseTime = $restaurant->manual_close_time;

            if (!$manualOpenTime || !$manualCloseTime) {
                return $respond(true, 'manual', null, [
                    'manual_control_type' => 'time',
                ]);
            }

            $isOpen = self::isCurrentTimeWithinOperatingWindow($manualOpenTime, $manualCloseTime, $currentTime);

            return $respond($isOpen, 'manual', $isOpen ? null : 'outside_manual_hours', [
                'manual_control_type' => 'time',
                'open_time' => $manualOpenTime,
                'close_time' => $manualCloseTime,
            ]);
        }

        $branch = $branch ?: branch() ?: $restaurant->branches()->withoutGlobalScopes()->first();
        if (!$branch) {
            return $respond(true, 'auto');
        }

        $hasActiveShift = BranchOperationalShift::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->exists();

        // Auto mode follows operational shifts only when the branch has active shifts configured.
        // If no shifts exist yet, keep the restaurant available to avoid blocking new clients by default.
        if (!$hasActiveShift) {
            return $respond(true, 'auto');
        }

        $isOpen = getShiftForOrder($currentTime, $branch->id) !== null;

        return $respond($isOpen, 'auto', $isOpen ? null : 'outside_operating_hours');
    }

    /**
     * Build a translated closed message for UI/API.
     */
    public static function getMessage(array $availability, $restaurant = null): string
    {
        $restaurant = $restaurant ?: restaurant();

        if (($availability['reason'] ?? null) === 'outside_manual_hours') {
            $timeFormat = $restaurant?->time_format ?? 'h:i A';
            $open = isset($availability['open_time']) ? Carbon::parse($availability['open_time'])->format($timeFormat) : '-';
            $close = isset($availability['close_time']) ? Carbon::parse($availability['close_time'])->format($timeFormat) : '-';

            return __('messages.restaurantOutsideManualHours', [
                'open' => $open,
                'close' => $close,
            ]);
        }

        if (($availability['reason'] ?? null) === 'outside_operating_hours') {
            return __('messages.restaurantOutsideOperatingHours');
        }

        return __('messages.restaurantTemporarilyClosed');
    }
}
