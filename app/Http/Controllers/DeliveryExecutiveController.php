<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\DeliveryExecutive;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeliveryExecutiveController extends Controller
{

    public function index()
    {
        abort_if(!in_array('Delivery Executive', restaurant_modules()), 403);
        abort_if((!user_can('Show Delivery Executive')), 403);
        return view('delivery-executive.index');
    }

    public function cashMonitoring(): View
    {
        abort_if(!in_array('Delivery Executive', restaurant_modules()), 403);
        abort_if((!user_can('Show Delivery Executive')), 403);

        return view('delivery-executive.cash-monitoring');
    }

    public function show(DeliveryExecutive $deliveryExecutive)
    {
        abort_if(!in_array('Delivery Executive', restaurant_modules()), 403);
        abort_if((!user_can('Show Delivery Executive')), 403);

        $deliveryExecutive->load(['orders' => function ($query) {
            $query->latest('id');
        }]);

        return view('delivery-executive.show', [
            'customer' => $deliveryExecutive,
        ]);
    }

    public function trackingData(DeliveryExecutive $deliveryExecutive, Order $order): JsonResponse
    {
        abort_if(!in_array('Delivery Executive', restaurant_modules()), 403);
        abort_if((!user_can('Show Delivery Executive')), 403);

        $trackingEnabled = module_enabled('RestApi');
        abort_if(!$trackingEnabled, 404);

        abort_if((int) $order->delivery_executive_id !== (int) $deliveryExecutive->id, 404);
        abort_if($order->order_type !== 'delivery', 422, __('messages.invalidRequest'));

        $branch = $order->branch;
        $branchLocation = [
            'latitude' => $branch?->lat,
            'longitude' => $branch?->lng,
        ];

        $customerLocation = $this->getCustomerLocation($order);
        $executiveLocation = $this->getLatestExecutiveLocation($deliveryExecutive->id, (int) $order->id);
        $executivePath = $this->getExecutivePath($deliveryExecutive->id, (int) $order->id);

        if (
            is_null($branchLocation['latitude']) ||
            is_null($branchLocation['longitude']) ||
            is_null($customerLocation['latitude']) ||
            is_null($customerLocation['longitude']) ||
            is_null($executiveLocation['latitude']) ||
            is_null($executiveLocation['longitude'])
        ) {
            return response()->json([
                'message' => __('messages.trackingCoordinatesMissing'),
                'errors' => [
                    'location' => [__('messages.trackingCoordinatesMissing')],
                ],
            ], 422);
        }

        return response()->json([
            'order_id' => $order->id,
            'order_status' => is_object($order->order_status) ? $order->order_status->value : $order->order_status,
            'branch' => $branchLocation,
            'customer' => $customerLocation,
            'executive' => $executiveLocation,
            'executive_path' => $executivePath,
            'route' => [
                'origin' => $branchLocation,
                'destination' => $customerLocation,
            ],
        ]);
    }

    private function getCustomerLocation(Order $order): array
    {
        $fallback = [
            'latitude' => $order->customer_lat,
            'longitude' => $order->customer_lng,
        ];

        if (!Schema::hasTable('customer_addresses')) {
            return $fallback;
        }

        $columns = Schema::getColumnListing('customer_addresses');
        $latColumn = in_array('latitude', $columns, true) ? 'latitude' : (in_array('lat', $columns, true) ? 'lat' : null);
        $lngColumn = in_array('longitude', $columns, true) ? 'longitude' : (in_array('lng', $columns, true) ? 'lng' : null);

        if (!$latColumn || !$lngColumn || !$order->customer_id) {
            return $fallback;
        }

        $address = DB::table('customer_addresses')
            ->where('customer_id', $order->customer_id)
            ->whereNotNull($latColumn)
            ->whereNotNull($lngColumn)
            ->orderByDesc('id')
            ->selectRaw("{$latColumn} as latitude, {$lngColumn} as longitude")
            ->first();

        return [
            'latitude' => $address->latitude ?? $fallback['latitude'],
            'longitude' => $address->longitude ?? $fallback['longitude'],
        ];
    }

    private function getLatestExecutiveLocation(int $deliveryExecutiveId, ?int $orderId = null): array
    {
        if (!Schema::hasTable('delivery_executive_locations')) {
            return ['latitude' => null, 'longitude' => null, 'updated_at' => null];
        }

        $columns = Schema::getColumnListing('delivery_executive_locations');
        $latColumn = in_array('latitude', $columns, true) ? 'latitude' : (in_array('lat', $columns, true) ? 'lat' : null);
        $lngColumn = in_array('longitude', $columns, true) ? 'longitude' : (in_array('lng', $columns, true) ? 'lng' : null);
        $fkColumn = in_array('delivery_executive_id', $columns, true) ? 'delivery_executive_id' : (in_array('executive_id', $columns, true) ? 'executive_id' : null);
        $orderColumn = in_array('order_id', $columns, true) ? 'order_id' : null;
        $hasUpdatedAt = in_array('updated_at', $columns, true);
        $hasCreatedAt = in_array('created_at', $columns, true);
        $hasId = in_array('id', $columns, true);

        if (!$latColumn || !$lngColumn) {
            return ['latitude' => null, 'longitude' => null, 'updated_at' => null];
        }

        $query = DB::table('delivery_executive_locations')
            ->whereNotNull($latColumn)
            ->whereNotNull($lngColumn);

        if ($fkColumn) {
            $query->where($fkColumn, $deliveryExecutiveId);
        }

        if ($orderColumn && $orderId) {
            $query->where($orderColumn, $orderId);
        }

        // Always prioritize the latest inserted row for live tracking.
        if ($hasId) {
            $query->orderByDesc('id');
        } elseif ($hasUpdatedAt) {
            $query->orderByDesc('updated_at');
        } elseif ($hasCreatedAt) {
            $query->orderByDesc('created_at');
        }

        $latest = $query->selectRaw("{$latColumn} as latitude, {$lngColumn} as longitude")
            ->when($hasUpdatedAt, fn ($q) => $q->addSelect('updated_at'))
            ->when(!$hasUpdatedAt && $hasCreatedAt, fn ($q) => $q->addSelect('created_at as updated_at'))
            ->first();

        return [
            'latitude' => $latest->latitude ?? null,
            'longitude' => $latest->longitude ?? null,
            'updated_at' => $latest->updated_at ?? null,
        ];
    }

    private function getExecutivePath(int $deliveryExecutiveId, ?int $orderId = null): array
    {
        if (!Schema::hasTable('delivery_executive_locations')) {
            return [];
        }

        $columns = Schema::getColumnListing('delivery_executive_locations');
        $latColumn = in_array('latitude', $columns, true) ? 'latitude' : (in_array('lat', $columns, true) ? 'lat' : null);
        $lngColumn = in_array('longitude', $columns, true) ? 'longitude' : (in_array('lng', $columns, true) ? 'lng' : null);
        $fkColumn = in_array('delivery_executive_id', $columns, true) ? 'delivery_executive_id' : (in_array('executive_id', $columns, true) ? 'executive_id' : null);
        $orderColumn = in_array('order_id', $columns, true) ? 'order_id' : null;
        $hasUpdatedAt = in_array('updated_at', $columns, true);
        $hasCreatedAt = in_array('created_at', $columns, true);
        $hasId = in_array('id', $columns, true);

        if (!$latColumn || !$lngColumn) {
            return [];
        }

        $query = DB::table('delivery_executive_locations')
            ->whereNotNull($latColumn)
            ->whereNotNull($lngColumn);

        if ($fkColumn) {
            $query->where($fkColumn, $deliveryExecutiveId);
        }

        if ($orderColumn && $orderId) {
            $query->where($orderColumn, $orderId);
        }

        if ($hasId) {
            $query->orderBy('id');
        } elseif ($hasCreatedAt) {
            $query->orderBy('created_at');
        } elseif ($hasUpdatedAt) {
            $query->orderBy('updated_at');
        }

        $points = $query->selectRaw("{$latColumn} as latitude, {$lngColumn} as longitude")
            ->limit(500)
            ->get();

        return $points->map(function ($point) {
            return [
                'latitude' => $point->latitude,
                'longitude' => $point->longitude,
            ];
        })->values()->all();
    }
}
