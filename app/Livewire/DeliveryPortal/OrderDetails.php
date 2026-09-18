<?php

namespace App\Livewire\DeliveryPortal;

use App\Enums\OrderStatus;
use App\Models\DeliveryExecutive;
use App\Models\Order;
use App\Services\OrderCashCollectionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;
use InvalidArgumentException;

class OrderDetails extends Component
{
    use LivewireAlert;

    public $restaurant;
    public $orderUuid;
    public $order;
    public $dateFormat;
    public $timeFormat;
    public $showDeliveryConfirmationModal = false;

    public function mount()
    {
        $this->dateFormat = $this->restaurant->date_format ?? dateFormat();
        $this->timeFormat = $this->restaurant->time_format ?? timeFormat();

        $this->loadOrder();
    }

    public function updateDeliveryStatus(string $status): void
    {
        $service = app(OrderCashCollectionService::class);

        $allowedTransitions = [
            OrderStatus::PICKED_UP->value => [
                OrderStatus::CONFIRMED->value,
                OrderStatus::PREPARING->value,
                OrderStatus::FOOD_READY->value,
                OrderStatus::READY_FOR_PICKUP->value,
            ],
            OrderStatus::OUT_FOR_DELIVERY->value => [
                OrderStatus::PICKED_UP->value,
            ],
            OrderStatus::REACHED_DESTINATION->value => [
                OrderStatus::PICKED_UP->value,
                OrderStatus::OUT_FOR_DELIVERY->value,
            ],
            OrderStatus::DELIVERED->value => [
                OrderStatus::OUT_FOR_DELIVERY->value,
                OrderStatus::REACHED_DESTINATION->value,
            ],
        ];

        if (!isset($allowedTransitions[$status])) {
            $this->alert('error', __('messages.invalidRequest'));
            return;
        }

        $currentStatus = $this->order->order_status->value;

        if (!in_array($currentStatus, $allowedTransitions[$status], true)) {
            $this->alert('error', __('messages.invalidRequest'));
            return;
        }

        if ($status === OrderStatus::DELIVERED->value && $service->requiresCashCollection($this->order) && !$service->hasRecordedCollection($this->order)) {
            $this->showDeliveryConfirmationModal = true;
            return;
        }

        $this->applyDeliveryStatusUpdate($status);
    }

    public function confirmCollectedAndDeliver(): void
    {
        $this->loadOrder();

        $service = app(OrderCashCollectionService::class);

        if (!$service->requiresCashCollection($this->order)) {
            $this->showDeliveryConfirmationModal = false;
            $this->applyDeliveryStatusUpdate(OrderStatus::DELIVERED->value);
            return;
        }

        try {
            $service->recordCollection(
                $this->order,
                'collected',
                $service->calculateExpectedAmount($this->order)
            );
        } catch (InvalidArgumentException $exception) {
            $this->showDeliveryConfirmationModal = false;
            $this->loadOrder();
            $this->alert('error', $exception->getMessage());
            return;
        }

        $this->showDeliveryConfirmationModal = false;
        $this->applyDeliveryStatusUpdate(OrderStatus::DELIVERED->value);
    }

    public function closeDeliveryConfirmationModal(): void
    {
        $this->showDeliveryConfirmationModal = false;
    }

    private function applyDeliveryStatusUpdate(string $status): void
    {
        $update = ['order_status' => $status];

        if ($status === OrderStatus::PICKED_UP->value && is_null($this->order->delivery_started_at)) {
            $update['delivery_started_at'] = Carbon::now();
        }

        if ($status === OrderStatus::DELIVERED->value) {
            $update['delivered_at'] = Carbon::now();
        }

        $this->order->update($update);

        if ($status === OrderStatus::DELIVERED->value) {
            $executive = delivery_executive();

            if ($executive) {
                $executive->update(['status' => DeliveryExecutive::STATUS_ACTIVE]);
                session(['delivery_executive' => $executive->fresh()]);
            }
        }

        $this->loadOrder();

        $this->alert('success', __('messages.statusUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
        ]);
    }

    public function getNavigationUrlProperty(): string
    {
        $destinationLat = $this->order?->customer?->latestDeliveryAddress?->lat ?? $this->order?->customer_lat;
        $destinationLng = $this->order?->customer?->latestDeliveryAddress?->lng ?? $this->order?->customer_lng;
        $executivePoint = $this->getLatestExecutiveCoordinates();
        $branchLat = $this->order?->branch?->lat;
        $branchLng = $this->order?->branch?->lng;

        if ($destinationLat && $destinationLng) {
            $destination = $destinationLat . ',' . $destinationLng;
            $query = 'https://www.google.com/maps/dir/?api=1&destination=' . $destination;

            // Route should be: executive latest location -> customer.
            if ($executivePoint['lat'] && $executivePoint['lng']) {
                $query .= '&origin=' . $executivePoint['lat'] . ',' . $executivePoint['lng'];
            } elseif ($branchLat && $branchLng) {
                // Fallback if executive live location is unavailable.
                $query .= '&origin=' . $branchLat . ',' . $branchLng;
            }

            return $query;
        }

        $address = $this->order?->customer?->latestDeliveryAddress?->address
            ?? $this->order?->delivery_address
            ?? $this->order?->customer?->delivery_address;

        if (filled($address)) {
            $originLat = $executivePoint['lat'] ?: $branchLat;
            $originLng = $executivePoint['lng'] ?: $branchLng;

            if ($originLat && $originLng) {
                return 'https://www.google.com/maps/dir/?api=1&origin=' . $originLat . ',' . $originLng
                    . '&destination=' . urlencode((string) $address);
            }
        }

        return 'https://www.google.com/maps/search/?api=1&query=' . urlencode((string) $address);
    }

    public function getBranchNavigationUrlProperty(): string
    {
        $executivePoint = $this->getLatestExecutiveCoordinates();
        $branchLat = $this->order?->branch?->lat;
        $branchLng = $this->order?->branch?->lng;
        $branchAddress = $this->order?->branch?->address;

        if ($branchLat && $branchLng) {
            $query = 'https://www.google.com/maps/dir/?api=1&destination=' . $branchLat . ',' . $branchLng;

            // Only set origin when executive latest location is available.
            if ($executivePoint['lat'] && $executivePoint['lng']) {
                $query .= '&origin=' . $executivePoint['lat'] . ',' . $executivePoint['lng'];
            }

            return $query;
        }

        if ($branchAddress) {
            $query = 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode((string) $branchAddress);

            if ($executivePoint['lat'] && $executivePoint['lng']) {
                $query .= '&origin=' . $executivePoint['lat'] . ',' . $executivePoint['lng'];
            }

            return $query;
        }

        return 'https://www.google.com/maps';
    }

    public function getGoBackUrlProperty(): string
    {
        $from = request()->query('from');
        if ($from === 'history') {
            return route('delivery.history');
        }

        if ($from === 'assigned') {
            return route('delivery.assigned-orders');
        }

        return in_array($this->order->order_status->value, OrderStatus::terminalProgressValues(), true)
            ? route('delivery.history')
            : route('delivery.assigned-orders');
    }

    public function getIsHistoryContextProperty(): bool
    {
        $from = request()->query('from');

        if ($from === 'history') {
            return true;
        }

        if ($from === 'assigned') {
            return false;
        }

        return in_array($this->order->order_status->value, OrderStatus::terminalProgressValues(), true);
    }

    private function getLatestExecutiveCoordinates(): array
    {
        $executive = delivery_executive();

        if ($executive && Schema::hasTable('delivery_executive_locations')) {
            $columns = Schema::getColumnListing('delivery_executive_locations');

            $latColumn = in_array('latitude', $columns, true) ? 'latitude' : (in_array('lat', $columns, true) ? 'lat' : null);
            $lngColumn = in_array('longitude', $columns, true) ? 'longitude' : (in_array('lng', $columns, true) ? 'lng' : null);
            $execColumn = in_array('delivery_executive_id', $columns, true) ? 'delivery_executive_id' : (in_array('executive_id', $columns, true) ? 'executive_id' : null);
            $orderColumn = in_array('order_id', $columns, true) ? 'order_id' : null;
            $hasId = in_array('id', $columns, true);

            if ($latColumn && $lngColumn && $execColumn) {
                $latestLocation = DB::table('delivery_executive_locations')
                    ->where($execColumn, $executive->id)
                    ->when($orderColumn, fn ($query) => $query->where($orderColumn, $this->order->id))
                    ->whereNotNull($latColumn)
                    ->whereNotNull($lngColumn)
                    ->when($hasId, fn ($query) => $query->orderByDesc('id'))
                    ->selectRaw("{$latColumn} as lat, {$lngColumn} as lng")
                    ->first();

                if ($latestLocation) {
                    return [
                        'lat' => $latestLocation->lat,
                        'lng' => $latestLocation->lng,
                    ];
                }
            }
        }

        return ['lat' => null, 'lng' => null];
    }

    private function loadOrder(): void
    {
        $executive = delivery_executive();

        $this->order = Order::withoutGlobalScopes()
            ->with(['items.menuItem', 'customer.latestDeliveryAddress', 'branch', 'payments', 'orderCashCollection'])
            ->where('uuid', $this->orderUuid)
            ->where('delivery_executive_id', $executive?->id)
            ->firstOrFail();

        app(OrderCashCollectionService::class)->syncForOrder($this->order);
        $this->order->load('orderCashCollection');
    }

    public function render()
    {
        return view('livewire.delivery-portal.order-details');
    }
}
