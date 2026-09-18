<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderType;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\LanguageSetting;
use App\Services\Shop\BrowseCartMutator;
use App\Traits\HasLanguageSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class ShopController extends Controller
{
    use HasLanguageSettings;
    /**
     * Constructor to handle language and RTL settings
     */
    public function __construct()
    {
        $this->applyLanguageSettings();
    }

    /**
     * Get the branch for the shop based on request or default to first branch
     */
    private function getShopBranch(Restaurant $restaurant): Branch
    {
        if (request()->filled('branch')) {
            $branchParam = request('branch');

            // Try to find by unique_hash first, then by ID
            $branch = Branch::withoutGlobalScopes()->where('unique_hash', $branchParam)->first();

            if (!$branch) {
                $branch = Branch::withoutGlobalScopes()->find($branchParam);
            }

            return $branch;
        }

        return $restaurant->branches->first();
    }

    /**
     * Get enabled package modules and features for the restaurant
     */
    private function getPackageModules(?Restaurant $restaurant): array
    {
        if (!$restaurant?->package) {
            return [];
        }

        $modules = $restaurant->package->modules->pluck('name')->toArray();
        $additionalFeatures = json_decode($restaurant->package->additional_features ?? '[]', true);

        return array_merge($modules, $additionalFeatures);
    }

    /**
     * Send reservation-intent visitors to the book-a-table flow (not the order menu).
     */
    private function redirectToBookTableIfRequested(
        Request $request,
        Restaurant $restaurant,
        Branch $shopBranch,
        array $packageModules,
    ): ?RedirectResponse {
        $wantsBooking = $request->boolean('book_table')
            || $request->query('intent') === 'book'
            || $request->query('intent') === 'reservation';

        if (! $wantsBooking) {
            return null;
        }

        if (! in_array('Table Reservation', $packageModules, true) || ! $restaurant->enable_customer_reservation) {
            return null;
        }

        return redirect()->to(
            route('book_a_table', ['hash' => $restaurant->hash]) . '?branch=' . $shopBranch->id
        );
    }

    /**
     * Show shopping cart page
     */
    public function cart(string $hash)
    {

        $restaurant = Restaurant::with('currency')->where('hash', $hash)->firstOrFail();
        $shopBranch = $this->getShopBranch($restaurant);

        $packageModules = $this->getPackageModules($restaurant);

        $this->redirectIfSubdomainIsEnabled($restaurant);

        if ($redirect = $this->redirectToBookTableIfRequested(request(), $restaurant, $shopBranch, $packageModules)) {
            return $redirect;
        }

        return view('shop.index', [
            'restaurant' => $restaurant,
            'shopBranch' => $shopBranch,
            'getTable' => $restaurant->table_required,
            'canCreateOrder' => in_array('Order', $packageModules)
        ]);
    }

    /**
     * Persist customer order type from the client-side menu (no Livewire round-trip).
     * The shop Cart component applies this on the next request via hydrate().
     */
    public function syncBrowseOrderType(Request $request, string $hash): JsonResponse
    {
        $restaurant = Restaurant::where('hash', $hash)->firstOrFail();

        $validated = $request->validate([
            'order_type_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer'],
        ]);

        $branch = Branch::withoutGlobalScopes()
            ->where('id', $validated['branch_id'])
            ->where('restaurant_id', $restaurant->id)
            ->firstOrFail();

        $orderTypeQuery = OrderType::query()
            ->where('id', $validated['order_type_id'])
            ->where('branch_id', $branch->id)
            ->where('enable_from_customer_site', true)
            ->availableForRestaurant()
            ->where('type', '!=', 'room_service')
            ->where('slug', '!=', 'room_service');

        if (session('shop_force_dine_in')) {
            $orderTypeQuery->where('type', 'dine_in');
        }

        $orderType = $orderTypeQuery->firstOrFail();

        session([
            'shop_browse_order_type_id' => $orderType->id,
            'shop_order_type_slug' => $orderType->slug,
        ]);

        return response()->json([
            'ok' => true,
            'slug' => $orderType->slug,
        ]);
    }

    /**
     * Add / increment / decrement simple cart lines from the JS client catalog (no Livewire $call).
     */
    public function browseCartMutate(Request $request, string $hash): JsonResponse
    {
        $validated = BrowseCartMutator::validateBrowseCartRequest($request);

        $restaurant = Restaurant::with(['currency', 'package.modules'])->where('hash', $hash)->firstOrFail();

        $branch = Branch::withoutGlobalScopes()
            ->where('id', $validated['branch_id'])
            ->where('restaurant_id', $restaurant->id)
            ->firstOrFail();

        $packageModules = $this->getPackageModules($restaurant);
        $canCreateOrder = in_array('Order', $packageModules, true);

        $cameFromQr = (bool) ($validated['came_from_qr'] ?? false);
        $lat = isset($validated['address_lat']) ? (float) $validated['address_lat'] : null;
        $lng = isset($validated['address_lng']) ? (float) $validated['address_lng'] : null;

        $result = BrowseCartMutator::mutate(
            $restaurant,
            $branch,
            $validated['action'],
            (int) $validated['menu_item_id'],
            $canCreateOrder,
            (bool) ($restaurant->allow_customer_orders ?? false),
            $cameFromQr,
            $lat,
            $lng,
        );

        $status = ($result['ok'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }

    /**
     * Show order success page
     */
    public function orderSuccess(string $uuid)
    {
        $order = Order::where('uuid', $uuid)->firstOrFail();

        $id = $order->id;

        $shopBranch = request()->filled('branch')
            ? Branch::withoutGlobalScopes()->find(request('branch'))
            : $order->branch;

        $restaurant = $order->branch->restaurant->loadMissing('euAllergenSetting');


        return view('shop.order_success', [
            'restaurant' => $restaurant,
            'id' => $id,
            'shopBranch' => $shopBranch,
            'deferredKotPrintOrderId' => session()->pull('shop_print_kot_order_id'),
        ]);
    }

    /**
     * Show table booking page
     */
    public function bookTable(string $hash)
    {
        $restaurant = Restaurant::with('currency')->where('hash', $hash)->firstOrFail();
        $shopBranch = $this->getShopBranch($restaurant);
        $packageModules = $this->getPackageModules($restaurant);

        $this->redirectIfSubdomainIsEnabled($restaurant);

        abort_if(!in_array('Table Reservation', $packageModules), 403);

        return view('shop.book_a_table', compact('restaurant', 'shopBranch'));
    }

    /**
     * Show user's bookings page
     */
    public function myBookings(string $hash)
    {
        $restaurant = Restaurant::with('currency')->where('hash', $hash)->firstOrFail();
        $shopBranch = $this->getShopBranch($restaurant);
        $packageModules = $this->getPackageModules($restaurant);

        $this->redirectIfSubdomainIsEnabled($restaurant);

        abort_if(!in_array('Table Reservation', $packageModules), 403);

        return view('shop.bookings', compact('restaurant', 'shopBranch'));
    }

    /**
     * Show user's addresses page
     */
    public function myAddresses(string $hash)
    {
        $restaurant = Restaurant::with('currency')->where('hash', $hash)->firstOrFail();
        $shopBranch = $this->getShopBranch($restaurant);

        $this->redirectIfSubdomainIsEnabled($restaurant);

        return view('shop.addresses', compact('restaurant', 'shopBranch'));
    }

    /**
     * Show user profile page
     */
    public function profile(string $hash)
    {
        $restaurant = Restaurant::with('currency')->where('hash', $hash)->firstOrFail();
        $shopBranch = $this->getShopBranch($restaurant);

        $this->redirectIfSubdomainIsEnabled($restaurant);

        return view('shop.profile', compact('restaurant', 'shopBranch'));
    }

    /**
     * Show user's orders page
     */
    public function myOrders(string $hash)
    {
        $restaurant = Restaurant::with('currency')->where('hash', $hash)->firstOrFail();
        $shopBranch = $this->getShopBranch($restaurant);

        $this->redirectIfSubdomainIsEnabled($restaurant);

        return view('shop.orders', compact('restaurant', 'shopBranch'));
    }

    /**
     * Show about page
     */
    public function about(string $hash)
    {
        $restaurant = Restaurant::with('currency')->where('hash', $hash)->firstOrFail();
        $shopBranch = $this->getShopBranch($restaurant);

        $this->redirectIfSubdomainIsEnabled($restaurant);

        return view('shop.about', compact('restaurant', 'shopBranch'));
    }

    /**
     * Show contact page
     */
    public function contact(string $hash)
    {
        $restaurant = Restaurant::with('currency')->where('hash', $hash)->firstOrFail();
        $shopBranch = $this->getShopBranch($restaurant);

        $this->redirectIfSubdomainIsEnabled($restaurant);

        return view('shop.contact', compact('restaurant', 'shopBranch'));
    }

    /**
     * Show table order page
     */
    public function tableOrder(string $hash)
    {

        $table = Table::where('hash', $hash)->first();

        if ($table) {
            $shopBranch = $table->branch;
            $restaurant = $table->branch->restaurant->load('currency');
            $getTable = false;
        } else {
            $restaurant = Restaurant::with('currency')->where('id', $hash)->firstOrFail();
            $shopBranch = $this->getShopBranch($restaurant);
            $hash = null;
            $getTable = (bool) $restaurant->table_required;
        }

        $this->redirectIfSubdomainIsEnabled($restaurant);

        $packageModules = $this->getPackageModules($restaurant);

        return view('shop.index', [
            'tableHash' => $hash,
            'restaurant' => $restaurant,
            'shopBranch' => $shopBranch,
            'getTable' => $getTable,
            'canCreateOrder' => in_array('Order', $packageModules)
        ]);
    }

    /**
     * Show hotel room service order page (room QR scan).
     */
    public function roomOrder(string $hash)
    {
        if (! function_exists('module_enabled') || ! module_enabled('Hotel')) {
            abort(404);
        }

        $room = \Modules\Hotel\Entities\Room::with(['branch.restaurant.currency', 'restaurant.currency'])
            ->where('hash', $hash)
            ->firstOrFail();

        $shopBranch = $room->branch;
        $restaurant = $room->restaurant ?? $shopBranch->restaurant;
        $restaurant->loadMissing('currency');

        if (! isHotelModuleEnabled($restaurant)) {
            abort(404);
        }

        $this->redirectIfSubdomainIsEnabled($restaurant);

        $packageModules = $this->getPackageModules($restaurant);

        return view('shop.index', [
            'roomHash' => $hash,
            'restaurant' => $restaurant,
            'shopBranch' => $shopBranch,
            'getTable' => false,
            'canCreateOrder' => in_array('Order', $packageModules) && in_array('Hotel', $packageModules),
        ]);
    }

    /**
     * Wi-Fi landing page for table QR codes (offline-first)
     */
    public function qrTableLanding(string $hash)
    {
        $table = Table::where('hash', $hash)->firstOrFail();
        $restaurant = $table->branch->restaurant->load('currency');

        $this->redirectIfSubdomainIsEnabled($restaurant);

        // If Wi-Fi sharing is disabled or not configured, show menu directly using tableOrder logic
        // This ensures it works offline without needing a redirect
        if (!$restaurant->show_wifi_icon || !$restaurant->wifi_name || !$restaurant->wifi_password) {
            return $this->tableOrder($hash);
        }

        // Build menu URL for redirect
        $menuUrl = route('table_order', [$hash]) . '?from_qr=1';

        return view('shop.wifi-landing', [
            'restaurant' => $restaurant,
            'tableHash' => $hash,
            'branchHash' => null,
            'menuUrl' => $menuUrl,
        ]);
    }

    /**
     * Redirect to subdomain if enabled
     */
    public function redirectIfSubdomainIsEnabled(Restaurant $restaurant): ?object
    {
        if (!module_enabled('Subdomain')) {
            return null;
        }

        $restaurantDomain = getRestaurantBySubDomain();

        if (is_null($restaurantDomain)) {
            return redirect()
                ->to('https://' . $restaurant->sub_domain . request()->getRequestUri())
                ->send();
        }

        return null;
    }
}
