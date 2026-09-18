<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PLACED = 'placed';
    case CONFIRMED = 'confirmed';
    case PREPARING = 'preparing';
    case FOOD_READY = 'food_ready';
    case READY_FOR_PICKUP = 'ready_for_pickup';
    case PICKED_UP = 'picked_up'; // Delivery person picked up the order
    case OUT_FOR_DELIVERY = 'out_for_delivery'; // Order is being delivered
    case REACHED_DESTINATION = 'reached_destination'; // Delivery reached destination
    case SERVED = 'served'; // Order served at table (for dine-in)
    case DELIVERED = 'delivered'; // Order delivered to the customer
    case CANCELLED = 'cancelled'; // Order cancelled
    case COMPLETED = 'completed'; // Order completed

    public function label(): string
    {
        return match ($this) {
            self::PLACED => 'Order Placed',
            self::CONFIRMED => 'Order Confirmed',
            self::PREPARING => 'Order Preparing',
            self::FOOD_READY => 'Food is Ready',
            self::READY_FOR_PICKUP => 'Order is Ready for Pickup',
            self::PICKED_UP => 'Picked Up by Delivery Person',
            self::OUT_FOR_DELIVERY => 'Order is Out for Delivery',
            self::REACHED_DESTINATION => 'Reached Destination',
            self::SERVED => 'Order Served',
            self::DELIVERED => 'Delivered',
            self::CANCELLED => 'Order Cancelled',
            self::COMPLETED => 'Order Completed',
        };
    }

    /**
     * Get the translated label for the order status.
     *
     * @return string
     */
    public function translatedLabel(): string
    {
        return __('modules.order.' . $this->label());
    }

    /**
     * Tailwind classes for status badges (light + dark mode). Each case uses a distinct hue.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::PLACED => 'bg-yellow-100 text-yellow-800 border-yellow-300 dark:bg-yellow-900/40 dark:text-yellow-200 dark:border-yellow-600',
            self::CONFIRMED => 'bg-indigo-100 text-indigo-800 border-indigo-300 dark:bg-indigo-900/40 dark:text-indigo-200 dark:border-indigo-600',
            self::PREPARING => 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900/40 dark:text-purple-200 dark:border-purple-600',
            self::FOOD_READY => 'bg-lime-100 text-lime-800 border-lime-300 dark:bg-lime-900/40 dark:text-lime-200 dark:border-lime-600',
            self::READY_FOR_PICKUP => 'bg-cyan-100 text-cyan-800 border-cyan-300 dark:bg-cyan-900/40 dark:text-cyan-200 dark:border-cyan-600',
            self::PICKED_UP => 'bg-orange-100 text-orange-800 border-orange-300 dark:bg-orange-900/40 dark:text-orange-200 dark:border-orange-600',
            self::OUT_FOR_DELIVERY => 'bg-blue-100 text-blue-800 border-blue-300 dark:bg-blue-900/40 dark:text-blue-200 dark:border-blue-600',
            self::REACHED_DESTINATION => 'bg-fuchsia-100 text-fuchsia-800 border-fuchsia-300 dark:bg-fuchsia-900/40 dark:text-fuchsia-200 dark:border-fuchsia-600',
            self::SERVED => 'bg-green-100 text-green-800 border-green-300 dark:bg-green-900/40 dark:text-green-200 dark:border-green-600',
            self::DELIVERED => 'bg-teal-100 text-teal-800 border-teal-300 dark:bg-teal-900/40 dark:text-teal-200 dark:border-teal-600',
            self::COMPLETED => 'bg-emerald-100 text-emerald-800 border-emerald-300 dark:bg-emerald-900/40 dark:text-emerald-200 dark:border-emerald-600',
            self::CANCELLED => 'bg-red-100 text-red-800 border-red-300 dark:bg-red-900/40 dark:text-red-200 dark:border-red-600',
        };
    }

    /**
     * Badge classes for a stored order_status string (unknown values fall back to neutral gray).
     */
    public static function badgeClassesFor(?string $value): string
    {
        $status = $value !== null && $value !== '' ? self::tryFrom($value) : null;

        return $status?->badgeClasses()
            ?? 'bg-gray-100 text-gray-800 border-gray-300 dark:bg-gray-700/60 dark:text-gray-200 dark:border-gray-500';
    }

    /**
     * Text/icon color for compact status indicators (e.g. order list dot).
     */
    public function iconColorClasses(): string
    {
        return match ($this) {
            self::PLACED => 'text-yellow-500 dark:text-yellow-300',
            self::CONFIRMED => 'text-indigo-500 dark:text-indigo-300',
            self::PREPARING => 'text-purple-500 dark:text-purple-300',
            self::FOOD_READY => 'text-lime-500 dark:text-lime-300',
            self::READY_FOR_PICKUP => 'text-cyan-500 dark:text-cyan-300',
            self::PICKED_UP => 'text-orange-500 dark:text-orange-300',
            self::OUT_FOR_DELIVERY => 'text-blue-500 dark:text-blue-300',
            self::REACHED_DESTINATION => 'text-fuchsia-500 dark:text-fuchsia-300',
            self::SERVED => 'text-green-500 dark:text-green-300',
            self::DELIVERED => 'text-teal-500 dark:text-teal-300',
            self::COMPLETED => 'text-emerald-600 dark:text-emerald-300',
            self::CANCELLED => 'text-red-500 dark:text-red-300',
        };
    }

    public static function iconColorClassesFor(?string $value): string
    {
        $status = $value !== null && $value !== '' ? self::tryFrom($value) : null;

        return $status?->iconColorClasses() ?? 'text-gray-500 dark:text-gray-400';
    }

    /**
     * Neutral styling for progress steps after the current one (not yet reached).
     */
    public static function progressStepUpcomingClasses(): string
    {
        return 'border-2 box-border origin-center bg-gray-100 border-gray-200 text-gray-400 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-500 [&_.order-status-step-svg]:opacity-70';
    }

    /**
     * Progress step circle classes (current / completed / upcoming).
     * Subtle fills + darker icons so SVGs stay visible at small sizes; current step scales gently.
     * Steps after the current use a single disabled gray style (not per-status color).
     */
    public function progressStepIconClasses(bool $isCompleted, bool $isCurrent, bool $isNext = false): string
    {
        $base = 'border-2 box-border origin-center';
        $currentPulse = ' animate-order-status-step-current motion-reduce:animate-none shadow-md';

        if ($isCurrent) {
            return match ($this) {
                self::PLACED => $base.' bg-yellow-100 border-yellow-300 text-yellow-800 dark:bg-yellow-950/50 dark:border-yellow-700 dark:text-yellow-200 ring-2 ring-yellow-300/70 dark:ring-yellow-600/40'.$currentPulse,
                self::CONFIRMED => $base.' bg-indigo-100 border-indigo-300 text-indigo-800 dark:bg-indigo-950/50 dark:border-indigo-700 dark:text-indigo-200 ring-2 ring-indigo-300/70 dark:ring-indigo-600/40'.$currentPulse,
                self::PREPARING => $base.' bg-purple-100 border-purple-300 text-purple-800 dark:bg-purple-950/50 dark:border-purple-700 dark:text-purple-200 ring-2 ring-purple-300/70 dark:ring-purple-600/40'.$currentPulse,
                self::FOOD_READY => $base.' bg-lime-100 border-lime-400 text-lime-900 dark:bg-lime-950/50 dark:border-lime-600 dark:text-lime-200 ring-2 ring-lime-400/80 dark:ring-lime-500/50'.$currentPulse,
                self::READY_FOR_PICKUP => $base.' bg-cyan-100 border-cyan-300 text-cyan-800 dark:bg-cyan-950/50 dark:border-cyan-700 dark:text-cyan-200 ring-2 ring-cyan-300/70 dark:ring-cyan-600/40'.$currentPulse,
                self::PICKED_UP => $base.' bg-orange-100 border-orange-300 text-orange-800 dark:bg-orange-950/50 dark:border-orange-700 dark:text-orange-200 ring-2 ring-orange-300/70 dark:ring-orange-600/40'.$currentPulse,
                self::OUT_FOR_DELIVERY => $base.' bg-blue-100 border-blue-300 text-blue-800 dark:bg-blue-950/50 dark:border-blue-700 dark:text-blue-200 ring-2 ring-blue-300/70 dark:ring-blue-600/40'.$currentPulse,
                self::REACHED_DESTINATION => $base.' bg-fuchsia-100 border-fuchsia-300 text-fuchsia-800 dark:bg-fuchsia-950/50 dark:border-fuchsia-700 dark:text-fuchsia-200 ring-2 ring-fuchsia-300/70 dark:ring-fuchsia-600/40'.$currentPulse,
                self::SERVED => $base.' bg-green-100 border-green-300 text-green-800 dark:bg-green-950/50 dark:border-green-700 dark:text-green-200 ring-2 ring-green-300/70 dark:ring-green-600/40'.$currentPulse,
                self::DELIVERED => $base.' bg-teal-100 border-teal-300 text-teal-800 dark:bg-teal-950/50 dark:border-teal-700 dark:text-teal-200 ring-2 ring-teal-300/70 dark:ring-teal-600/40'.$currentPulse,
                self::COMPLETED => $base.' bg-emerald-100 border-emerald-300 text-emerald-800 dark:bg-emerald-950/50 dark:border-emerald-700 dark:text-emerald-200 ring-2 ring-emerald-300/70 dark:ring-emerald-600/40'.$currentPulse,
                self::CANCELLED => $base.' bg-red-100 border-red-300 text-red-800 dark:bg-red-950/50 dark:border-red-700 dark:text-red-200 ring-2 ring-red-300/70 dark:ring-red-600/40'.$currentPulse,
            };
        }

        if ($isCompleted) {
            return match ($this) {
                self::PLACED => $base.' bg-yellow-50 border-yellow-200 text-yellow-700 dark:bg-yellow-950/30 dark:border-yellow-800 dark:text-yellow-300',
                self::CONFIRMED => $base.' bg-indigo-50 border-indigo-200 text-indigo-700 dark:bg-indigo-950/30 dark:border-indigo-800 dark:text-indigo-300',
                self::PREPARING => $base.' bg-purple-50 border-purple-200 text-purple-700 dark:bg-purple-950/30 dark:border-purple-800 dark:text-purple-300',
                self::FOOD_READY => $base.' bg-lime-50 border-lime-300 text-lime-800 dark:bg-lime-950/40 dark:border-lime-700 dark:text-lime-300',
                self::READY_FOR_PICKUP => $base.' bg-cyan-50 border-cyan-200 text-cyan-700 dark:bg-cyan-950/30 dark:border-cyan-800 dark:text-cyan-300',
                self::PICKED_UP => $base.' bg-orange-50 border-orange-200 text-orange-700 dark:bg-orange-950/30 dark:border-orange-800 dark:text-orange-300',
                self::OUT_FOR_DELIVERY => $base.' bg-blue-50 border-blue-200 text-blue-700 dark:bg-blue-950/30 dark:border-blue-800 dark:text-blue-300',
                self::REACHED_DESTINATION => $base.' bg-fuchsia-50 border-fuchsia-200 text-fuchsia-700 dark:bg-fuchsia-950/30 dark:border-fuchsia-800 dark:text-fuchsia-300',
                self::SERVED => $base.' bg-green-50 border-green-200 text-green-700 dark:bg-green-950/30 dark:border-green-800 dark:text-green-300',
                self::DELIVERED => $base.' bg-teal-50 border-teal-200 text-teal-700 dark:bg-teal-950/30 dark:border-teal-800 dark:text-teal-300',
                self::COMPLETED => $base.' bg-emerald-50 border-emerald-200 text-emerald-700 dark:bg-emerald-950/30 dark:border-emerald-800 dark:text-emerald-300',
                self::CANCELLED => $base.' bg-red-50 border-red-200 text-red-700 dark:bg-red-950/30 dark:border-red-800 dark:text-red-300',
            };
        }

        return self::progressStepUpcomingClasses();
    }

    /**
     * Fulfilment finished (excluding cancelled).
     *
     * @return list<string>
     */
    public static function fulfilledProgressValues(): array
    {
        return [self::DELIVERED->value, self::SERVED->value, self::COMPLETED->value];
    }

    /**
     * Do not overwrite order_status from KOT aggregation when already at a terminal progress state.
     *
     * @return list<string>
     */
    public static function terminalProgressValues(): array
    {
        return [...self::fulfilledProgressValues(), self::CANCELLED->value];
    }

    /**
     * Progress steps shown in order detail / POS for an order type.
     * Null / unknown types use the dine-in (default) steps.
     *
     * @return list<string>
     */
    public static function progressStepsFor(?string $orderType): array
    {
        return match ($orderType) {
            'delivery' => [
                self::PLACED->value,
                self::CONFIRMED->value,
                self::PREPARING->value,
                self::FOOD_READY->value,
                self::PICKED_UP->value,
                self::OUT_FOR_DELIVERY->value,
                self::REACHED_DESTINATION->value,
                self::DELIVERED->value,
                self::COMPLETED->value,
            ],
            'pickup' => [
                self::PLACED->value,
                self::CONFIRMED->value,
                self::PREPARING->value,
                self::READY_FOR_PICKUP->value,
                self::DELIVERED->value,
                self::COMPLETED->value,
            ],
            default => [
                self::PLACED->value,
                self::CONFIRMED->value,
                self::PREPARING->value,
                self::FOOD_READY->value,
                self::SERVED->value,
                self::COMPLETED->value,
            ],
        };
    }

    /**
     * Map a stored status to the step used in the progress UI for this order type.
     */
    public static function progressStatusForOrderType(string $status, ?string $orderType): string
    {
        if ($orderType === 'delivery' && $status === self::READY_FOR_PICKUP->value) {
            return self::FOOD_READY->value;
        }

        if ($orderType === 'pickup' && $status === self::FOOD_READY->value) {
            return self::READY_FOR_PICKUP->value;
        }

        return $status;
    }

    /**
     * Index of the current status in the progress UI (0-based).
     */
    public static function progressIndex(string $status, ?string $orderType): int
    {
        $statuses = self::progressStepsFor($orderType);
        $mappedStatus = self::progressStatusForOrderType($status, $orderType);
        $index = array_search($mappedStatus, $statuses, true);

        return $index !== false ? $index : 0;
    }

    /**
     * Get the SVG icon for the order status.
     *
     * @return string
     */
    public function icon(): string
    {
        $svgClass = 'order-status-step-svg block h-[0.875rem] w-[0.875rem] shrink-0 sm:h-4 sm:w-4 text-current';

        return match ($this) {
            self::PLACED => '<svg class="'.$svgClass.'" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M20.75 3v19a.75.75 0 0 1-1.173.619l-2.495-1.703-2.359 1.693a.75.75 0 0 1-.931-.045L12 20.997l-1.792 1.567a.75.75 0 0 1-.931.045l-2.359-1.693-2.495 1.703A.75.75 0 0 1 3.25 22V3c0-.966.783-1.75 1.75-1.75h14c.967 0 1.75.784 1.75 1.75M8 10.75h8a.75.75 0 0 0 0-1.5H8a.75.75 0 0 0 0 1.5m0-4h8a.75.75 0 0 0 0-1.5H8a.75.75 0 0 0 0 1.5m0 8h4a.75.75 0 0 0 0-1.5H8a.75.75 0 0 0 0 1.5"/></svg>',

            self::CONFIRMED => '<svg class="'.$svgClass.'" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',

            self::PREPARING => '<svg class="'.$svgClass.'" fill="currentColor" viewBox="0 0 512.733 512.733" aria-hidden="true"><path d="M512.371 256.358c0-23.952-3.309-47.671-9.835-70.498l-2.192-7.667c11.532-37.679 6.067-63.539-13.263-113.163-30.678-70.33-83.166-82.781-123.565-41.213C196.251-54.851-1.227 71.315.372 256.36c-1.619 179.914 186.101 305.902 351.999 237.447v-43.995c-139.83 72.063-314.096-35.413-311.998-193.457C38.006 104.118 202.432-4.215 341.228 57.667c-13.5 26.719-23.289 69.026-22.857 81.823.765 45.535 19.524 83.248 74 92.841v280.026h40V232.349c15.317-2.328 27.338-7.28 36.761-13.44a218 218 0 0 1 3.23 36.424l.009.024V393.91c26.04-40.715 40-88.059 40-137.552m-100-62.562c-54.163 1.312-67.892-44.008-38.052-112.372 23.692-54.079 52.185-54.534 76.118-.352 23.809 56.101 27.098 112.439-38.066 112.724m-176-117.438h40v200h-152v-40h112z"/></svg>',

            // Crop viewBox above y≈211 so the tray rim path does not align with the progress connector line.
            self::FOOD_READY => '<svg class="'.$svgClass.'" fill="currentColor" viewBox="0 32 512 168" preserveAspectRatio="xMidYMid meet" aria-hidden="true"><path d="M271 32.313V15c0-8.291-6.709-15-15-15s-15 6.709-15 15v16.692C141.226 38.557 84.692 112.788 67.101 181h378.448C426.286 99.904 356.214 38.91 271 32.313M497 211H15c-8.315 0-15.022 6.887-15 15.203.009 3.373.251 6.689.709 9.933C3.6 256.694 22.89 271 43.647 271h424.624c20.757 0 40.074-14.268 43.008-34.816a72 72 0 0 0 .721-9.963c.024-8.322-6.678-15.221-15-15.221m-64.459 90h-46.688a60 60 0 0 0-42.427 17.574l-48.638 48.638A30 30 0 0 1 273.573 376h-81.464a5.337 5.337 0 0 1-2.719-9.93s54.518-32.3 80.068-47.474c.646-2.195 1.785-4.281 1.967-6.581.249-3.135-.244-6.962-1.366-11.016H166.467c-9.717 0-19.305 2.276-27.874 6.861a967 967 0 0 0-31.274 17.499c-24.712 14.429-33.223 34.805-43.066 58.403-6.68 16.04-14.268 34.219-28.652 55.796-9.053 13.521 4.497 24.653 21.665 38.76C86.869 502.65 99.042 512 107.011 512c3.82 0 6.667-1.397 10.003-4.808C133.856 489.972 158.05 481 182.135 481h58.63c29.707 0 58.169-12.671 78.018-34.717L438.52 314.506A8.076 8.076 0 0 0 432.541 301"/></svg>',

            self::READY_FOR_PICKUP => '<svg class="'.$svgClass.'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.3 3.3 0 0 0-3.3-3.3h-4.4A3.3 3.3 0 0 0 4.75 6v4.5m11 0h-7.5m7.5 0v7.5a3 3 0 0 1-3 3h-7.5a3 3 0 0 1-3-3v-7.5m11 0H9.75"/></svg>',

            self::PICKED_UP => '<svg class="'.$svgClass.'" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 13h2v6h14v-6h2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zm16-8h-2V3a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v2H5a2 2 0 0 0-2 2v4h18V7a2 2 0 0 0-2-2M9 5h6v2H9z"/></svg>',

            self::REACHED_DESTINATION => '<svg class="'.$svgClass.'" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C8.14 2 5 5.14 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.86-3.14-7-7-7m0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5"/></svg>',

            self::OUT_FOR_DELIVERY => '<svg class="'.$svgClass.'" fill="currentColor" viewBox="0 0 100 100" aria-hidden="true"><path d="M73.333 40h-10L41.666 28.333 30 46.666v6.666h13.604l6.667 20h7.027l-8.89-26.666H37.902l6.061-9.524 17.69 9.524h11.68z"/><path d="m76.667 40-3.334-13.334H65l-1.667 6.667h6.514l1.969 7.878zM58.333 18.333A8.336 8.336 0 0 1 50 26.666c-4.604 0-8.334-3.73-8.334-8.333S45.396 10 50 10a8.336 8.336 0 0 1 8.333 8.333M10 30v23.332h20V30zm13.334 16.666h-6.668v-10h6.668z"/><path d="M76.667 51.666V40H70v11.666c0 3.666-1.341 9.35-2.981 12.631L60 78.332H46.666l-6.666-20H20c-5.522 0-10 4.477-10 10v10h29.639L41.86 85h22.261l3.333-6.668H90v-6.666c0-7.812-5.091-14.883-13.333-20m-60.001 20v-3.334A3.337 3.337 0 0 1 20 65h15.195l2.222 6.666zm54.122 0 2.193-4.389c1.12-2.238 2.09-5.188 2.758-8.166 3.561 2.715 7.594 7.012 7.594 12.555z"/><path d="M73.333 46.666h10v6.666h-10zM10 13.333V10h23.334v5zm6.666 10V20h20v5zM66.667 80c0 5.523 4.476 10 10 10 5.521 0 10-4.477 10-10 0-1.83-.527-3.523-1.387-5H68.054c-.86 1.477-1.387 3.17-1.387 5m10-3.334a3.333 3.333 0 1 1 0 6.666A3.33 3.33 0 0 1 73.333 80a3.33 3.33 0 0 1 3.334-3.334M15 80c0 5.523 4.477 10 10 10 5.521 0 10-4.477 10-10 0-1.83-.527-3.523-1.387-5H16.387C15.527 76.477 15 78.17 15 80m10-3.334a3.333 3.333 0 1 1 0 6.666A3.33 3.33 0 0 1 21.666 80 3.33 3.33 0 0 1 25 76.666"/></svg>',

            self::DELIVERED, self::SERVED => '<svg class="'.$svgClass.'" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>',

            self::CANCELLED => '<svg class="'.$svgClass.'" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>',

            self::COMPLETED => '<svg class="'.$svgClass.'" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',

            default => '<svg class="'.$svgClass.'" fill="currentColor" viewBox="0 0 1024 1024" aria-hidden="true"><path d="M128 352.576V352a288 288 0 0 1 491.072-204.224 192 192 0 0 1 274.24 204.48 64 64 0 0 1 57.216 74.24C921.6 600.512 850.048 710.656 736 756.992V800a96 96 0 0 1-96 96H384a96 96 0 0 1-96-96v-43.008c-114.048-46.336-185.6-156.48-214.528-330.496A64 64 0 0 1 128 352.64zm64-.576h64a160 160 0 0 1 320 0h64a224 224 0 0 0-448 0m128 0h192a96 96 0 0 0-192 0m439.424 0h68.544A128.256 128.256 0 0 0 704 192c-15.36 0-29.952 2.688-43.52 7.616 11.328 18.176 20.672 37.76 27.84 58.304A64.128 64.128 0 0 1 759.424 352M672 768H352v32a32 32 0 0 0 32 32h256a32 32 0 0 0 32-32zm-342.528-64h365.056c101.504-32.64 165.76-124.928 192.896-288H136.576c27.136 163.072 91.392 255.36 192.896 288"/></svg>',
        };
    }

    
}
