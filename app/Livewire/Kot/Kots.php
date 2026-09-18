<?php

namespace App\Livewire\Kot;

use Carbon\Carbon;
use App\Models\Kot;
use App\Models\KotItem;
use App\Models\OrderItem;
use Livewire\Component;
use App\Models\KotPlace;
use App\Models\KotSetting;
use Livewire\Attributes\On;
use App\Models\KotCancelReason;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Illuminate\Support\Facades\DB;

class Kots extends Component
{
    use LivewireAlert;

    protected $listeners = ['refreshKots' => 'refreshKots'];

    public function refreshKots(): void
    {
        $this->kotsGridKey = (int) (microtime(true) * 1000);
    }
    public $filterOrders;
    public $dateRangeType;
    public $startDate;
    public $endDate;
    public $kotSettings;
    public $confirmDeleteKotModal = false;
    public $cancelReasons;
    public $kot;
    public $cancelReasonText;
    public $cancelReason;
    public $selectedCancelKotId;
    public $kotPlace;
    public $showAllKitchens = false;
    public $selectedKitchen = '';
    public $search = '';
    public $confirmDeleteKotItemModal = false;
    public $selectedCancelKotItemId;
    public $cancelItemReason;
    public $cancelItemReasonText;
    public $perPage = 20;
    public $hasMore = false;
    public $isLoadingMore = false;
    public $hasPendingDefault = true;
    /** Used so child KotCards get fresh props after Pusher/refresh (avoids stale item count). */
    public $kotsGridKey = 0;

    /** Avoid playing new-order sound on initial page load (polling fallback). */
    public bool $kotBoardCountPrimed = false;

    /** KOT ids that already triggered the new-order sound this session. */
    public array $kotSoundAnnouncedIds = [];

    public function getSelectedKotItemProperty()
    {
        if (!$this->selectedCancelKotItemId) {
            return null;
        }

        return KotItem::with(['menuItem', 'menuItemVariation', 'modifierOptions'])
            ->find($this->selectedCancelKotItemId);
    }

    public function mount($kotPlace = null, $showAllKitchens = false)
    {
        $tz = timezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        // Load date range type from cookie
        $this->kotSettings = KotSetting::first();
        $this->dateRangeType = request()->cookie('kots_date_range_type', 'today');
        $this->hasPendingDefault = $this->computePendingDefault();
        $this->filterOrders = $this->hasPendingDefault ? 'pending_confirmation' : 'in_kitchen';
        $this->startDate = Carbon::now($tz)->startOfWeek()->displayDate($dateFormat);
        $this->endDate = Carbon::now($tz)->endOfWeek()->displayDate($dateFormat);
        $this->cancelReasons = KotCancelReason::where('cancel_kot', true)->get();
        $this->showAllKitchens = $showAllKitchens;

        if ($this->showAllKitchens) {
            // For all kitchens view, don't set a specific kotPlace
            $this->kotPlace = null;
        } elseif (!in_array('Kitchen', restaurant_modules())) {
            $this->kotPlace = KotPlace::with('printerSetting')->first();
        } else {
            $this->kotPlace = $kotPlace;
        }

        $this->setDateRange();
    }

    private function resetPerPage(): void
    {
        $this->perPage = 20;
    }


    public function setDateRange()
    {
        $tz = timezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        switch ($this->dateRangeType) {
            case 'today':
                $this->startDate = Carbon::now($tz)->startOfDay()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->startOfDay()->displayDate($dateFormat);
                break;

            case 'currentWeek':
                $this->startDate = Carbon::now($tz)->startOfWeek()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->endOfWeek()->displayDate($dateFormat);
                break;

            case 'lastWeek':
                $this->startDate = Carbon::now($tz)->subWeek()->startOfWeek()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->subWeek()->endOfWeek()->displayDate($dateFormat);
                break;

            case 'last7Days':
                $this->startDate = Carbon::now($tz)->subDays(7)->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->startOfDay()->displayDate($dateFormat);
                break;

            case 'currentMonth':
                $this->startDate = Carbon::now($tz)->startOfMonth()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->startOfDay()->displayDate($dateFormat);
                break;

            case 'lastMonth':
                $this->startDate = Carbon::now($tz)->subMonth()->startOfMonth()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->subMonth()->endOfMonth()->displayDate($dateFormat);
                break;

            case 'currentYear':
                $this->startDate = Carbon::now($tz)->startOfYear()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->startOfDay()->displayDate($dateFormat);
                break;

            case 'lastYear':
                $this->startDate = Carbon::now($tz)->subYear()->startOfYear()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->subYear()->endOfYear()->displayDate($dateFormat);
                break;

            default:
                $this->startDate = Carbon::now($tz)->startOfWeek()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->endOfWeek()->displayDate($dateFormat);
                break;
        }

        $this->resetPerPage();
    }

    #[On('setStartDate')]
    public function setStartDate($start)
    {
        $this->startDate = $start;
        $this->resetPerPage();
    }

    #[On('setEndDate')]
    public function setEndDate($end)
    {
        $this->endDate = $end;
        $this->resetPerPage();
    }

    #[On('showCancelKotModal')]
    public function showCancelKotModal($id = null)
    {
        $this->confirmDeleteKotModal = true;
        $this->selectedCancelKotId = $id;
    }

    #[On('showCancelKotItemModal')]
    public function showCancelKotItemModal($id)
    {
        if (!user_can('Delete Item After KOT')) {
            $this->alert('error', __('messages.permissionDenied'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close'),
            ]);

            return;
        }

        $this->confirmDeleteKotItemModal = true;
        $this->selectedCancelKotItemId = $id;

        // Reset form fields
        $this->cancelItemReason = null;
        $this->cancelItemReasonText = null;
    }
    public function updatedDateRangeType($value)
    {
        cookie()->queue(cookie('kots_date_range_type', $value, 60 * 24 * 30)); // 30 days
        $this->resetPerPage();
    }

    public function updatedFilterOrders(): void
    {
        $this->resetPerPage();
    }

    public function updatedSelectedKitchen(): void
    {
        $this->resetPerPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPerPage();
    }

    public function loadMore(): void
    {
        if ($this->isLoadingMore || !$this->hasMore) {
            return;
        }

        $this->isLoadingMore = true;
        $this->perPage += 20;
    }

    public function deleteKot($id)
    {
        // Validate that a cancel reason is provided
        if (!$this->cancelReason && !$this->cancelReasonText) {
            $this->alert('error', __('modules.settings.cancelReasonRequired'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close'),
            ]);
            return;
        }

        // If "Other" is selected, custom reason text is mandatory
        if ($this->cancelReason) {
            $selectedReason = KotCancelReason::find($this->cancelReason);
            if ($selectedReason && $selectedReason->isOtherReason() && !$this->cancelReasonText) {
                $this->alert('error', __('modules.settings.customReasonRequired'), [
                    'toast' => true,
                    'position' => 'top-end',
                    'showCancelButton' => false,
                    'cancelButtonText' => __('app.close'),
                ]);
                return;
            }
        }

        $kot = Kot::findOrFail($id);
        $order = $kot->order;
        $kotCounts = $order->kot()->whereNot('status', 'cancelled')->count();

        // Update cancel reason info
        $kot->cancel_reason_id = $this->cancelReason;
        $kot->cancel_reason_text = $this->cancelReasonText;
        $kot->status = 'cancelled';
        $kot->save();


        // If this is the only KOT in the order, cancel the order
        if ($kotCounts === 1) {
            $order->status = 'canceled';
            $order->order_status = 'cancelled';
            $order->cancelled_by = auth()->id();
            $order->cancel_reason_id = $kot->cancel_reason_id;
            $order->cancel_reason_text = $kot->cancel_reason_text;
            $order->cancel_time = now();
            $order->save();

            if ($order->table) {
                $order->table->update(['available_status' => 'available']);
            }
        } else {
            // Recalculate order totals if order is not cancelled
            $this->recalculateOrderTotals($order);
        }

        // Optional: soft delete kot or destroy it
        // Kot::destroy($id); // if using force delete

        $this->confirmDeleteKotModal = false;

        $this->reset(['cancelReason', 'cancelReasonText', 'selectedCancelKotId']);

        $this->dispatch('refreshKots');

        // Dispatch event to refresh POS component if it's viewing this order
        $this->dispatch('refreshPosOrder', orderId: $order->id);
    }

    public function deleteKotItem($itemId)
    {
        if (!user_can('Delete Item After KOT')) {
            $this->alert('error', __('messages.permissionDenied'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close'),
            ]);

            return;
        }

        // Validate that a cancel reason is provided
        if (!$this->cancelItemReason && !$this->cancelItemReasonText) {
            $this->alert('error', __('modules.settings.cancelReasonRequired'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close'),
            ]);
            return;
        }

        // If "Other" is selected, custom reason text is mandatory
        if ($this->cancelItemReason) {
            $selectedReason = KotCancelReason::find($this->cancelItemReason);
            if ($selectedReason && $selectedReason->isOtherReason() && !$this->cancelItemReasonText) {
                $this->alert('error', __('modules.settings.customReasonRequired'), [
                    'toast' => true,
                    'position' => 'top-end',
                    'showCancelButton' => false,
                    'cancelButtonText' => __('app.close'),
                ]);
                return;
            }
        }

        $kotItem = KotItem::findOrFail($itemId);
        $kot = $kotItem->kot;
        $order = $kot->order;

        // Get the actual reason text from KotCancelReason model
        $cancelReasonText = null;
        if ($this->cancelItemReason) {
            $cancelReason = KotCancelReason::find($this->cancelItemReason);
            $cancelReasonText = $cancelReason ? $cancelReason->reason : null;
        }

        // Use custom text if provided, otherwise use the reason from the model
        $finalReasonText = $this->cancelItemReasonText ?: $cancelReasonText;

        // Update cancel reason info for the KOT item
        $kotItem->cancel_reason_id = $this->cancelItemReason;
        $kotItem->cancel_reason_text = $finalReasonText;
        $kotItem->status = 'cancelled';
        $kotItem->cancelled_by = auth()->id();

        $kotItem->save();

        // Handle corresponding order item if it exists
        $this->handleOrderItemCancellation($kotItem, $order);

        // Recalculate order totals
        $this->recalculateOrderTotals($order);

        // Check if all items in the KOT are now cancelled
        $totalItems = KotItem::where('kot_id', $kot->id)->count();
        $cancelledItems = KotItem::where('kot_id', $kot->id)->where('status', 'cancelled')->count();

        if ($totalItems === $cancelledItems) {
            // All items are cancelled, cancel the entire KOT
            $kot->cancel_reason_id = $this->cancelItemReason;
            $kot->cancel_reason_text = $finalReasonText;
            $kot->status = 'cancelled';
            $kot->save();

            // Check if this is the only KOT in the order
            $kotCounts = $order->kot()->whereNot('status', 'cancelled')->count();
            if ($kotCounts === 0) {
                $order->status = 'canceled';
                $order->order_status = 'cancelled';
                $order->cancelled_by = auth()->id();
                $order->cancel_reason_id = $kot->cancel_reason_id;
                $order->cancel_reason_text = $kot->cancel_reason_text;
                $order->save();

                if ($order->table) {
                    $order->table->update(['available_status' => 'available']);
                }
            }
        } else {
                   }

        $this->confirmDeleteKotItemModal = false;
        $this->reset(['cancelItemReason', 'cancelItemReasonText', 'selectedCancelKotItemId']);

        $this->alert('success', __('modules.order.kotItemCancelledSuccessfully'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close'),
        ]);

        $this->dispatch('refreshKots');

        // Dispatch event to refresh POS component if it's viewing this order
        $this->dispatch('refreshPosOrder', orderId: $order->id);

        // Reload the page after a short delay to show the success message
        $this->js('setTimeout(() => window.location.reload(), 500)');
    }

    public function render()
    {
        $data = $this->fetchKots();

        $totalBoardKots = ($data['counts']['inKitchen'] ?? 0)
            + ($data['counts']['foodReady'] ?? 0)
            + ($data['counts']['pendingConfirmation'] ?? 0);

        $playSound = false;

        if (! pusherSettings()->is_enabled_pusher_broadcast) {
            if ($this->kotBoardCountPrimed) {
                if ($totalBoardKots > (int) session('kot_board_active_count', 0)) {
                    $playSound = true;
                }
            } else {
                $this->kotBoardCountPrimed = true;
            }
        }

        session(['kot_board_active_count' => $totalBoardKots]);

        return view('livewire.kot.kots', [
            'kots' => $data['kots'],
            'inKitchenCount' => $data['counts']['inKitchen'],
            'foodReadyCount' => $data['counts']['foodReady'],
            'servedCount' => $data['counts']['served'],
            'pendingConfirmationCount' => $data['counts']['pendingConfirmation'],
            'cancelledCount' => $data['counts']['cancelled'],
            'kotSettings' => $this->kotSettings,
            'cancelReasons' => $this->cancelReasons,
            'kitchens' => KotPlace::where('is_active', true)->get(),
            'showAllKitchens' => $this->showAllKitchens,
            'hasMore' => $this->hasMore,
            'hasPendingDefault' => $this->hasPendingDefault,
            'playSound' => $playSound,
        ]);
    }

    /**
     * Called from global Pusher JS when a KOT event arrives (only when this screen is open).
     * Toasts are handled by KotPusherListener (layout) on every page.
     */
    public function refreshKotsFromPusher(array $data = []): void
    {
        $this->playNewKotSoundIfNeeded($data);
        $this->dispatch('refreshKots')->self();
    }

    private function playNewKotSoundIfNeeded(array $data): void
    {
        $type = $data['type'] ?? null;
        if (! in_array($type, ['new_kot', 'updated'], true)) {
            return;
        }

        $kotId = isset($data['kot_id']) ? (int) $data['kot_id'] : 0;
        if ($kotId <= 0) {
            return;
        }

        $actorId = isset($data['updated_by_user_id']) ? (int) $data['updated_by_user_id'] : null;
        if ($actorId !== null && $actorId === (int) auth()->id()) {
            return;
        }

        if (in_array($kotId, $this->kotSoundAnnouncedIds, true)) {
            return;
        }

        $this->kotSoundAnnouncedIds[] = $kotId;
        if (count($this->kotSoundAnnouncedIds) > 100) {
            $this->kotSoundAnnouncedIds = array_slice($this->kotSoundAnnouncedIds, -100);
        }

        $url = asset('sound/new_order.wav');
        $this->js('try{new Audio(' . json_encode($url) . ').play()}catch(e){}');
    }

    private function fetchKots(): array
    {
        $tz = timezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        $start = parseRestaurantDate($this->startDate, $dateFormat, $tz)
            ->startOfDay()
            ->setTimezone('UTC')
            ->toDateTimeString();

        $end = parseRestaurantDate($this->endDate, $dateFormat, $tz)
            ->endOfDay()
                        ->addMinutes(5) // Add five minutes extra

            ->setTimezone('UTC')
            ->toDateTimeString();

        $baseQuery = $this->buildBaseKotsQuery($start, $end);

        // Counts before applying status filter
        $statusCounts = (clone $baseQuery)
            ->reorder()
            ->select('kots.status', DB::raw('COUNT(*) as total'))
            ->groupBy('kots.status')
            ->pluck('total', 'kots.status');

        // Apply status filter for the list
        switch ($this->filterOrders) {
            case 'in_kitchen':
                $baseQuery->where('kots.status', 'in_kitchen');
                break;
            case 'food_ready':
                $baseQuery->where('kots.status', 'food_ready');
                break;
            case 'served':
                $baseQuery->where('kots.status', 'served');
                break;
            case 'pending_confirmation':
                $baseQuery->where('kots.status', 'pending_confirmation');
                break;
            case 'cancelled':
                $baseQuery->where('kots.status', 'cancelled');
                break;
            default:
                // no additional filter
                break;
        }

        $kotsTotal = (clone $baseQuery)->count();
        $kots = $baseQuery->take($this->perPage)->get();

        $this->hasMore = $kotsTotal > $kots->count();
        $this->isLoadingMore = false;

        $inKitchen = ($this->hasPendingDefault)
            ? ($statusCounts['in_kitchen'] ?? 0)
            : (($statusCounts['in_kitchen'] ?? 0) + ($statusCounts['pending_confirmation'] ?? 0));

        return [
            'kots' => $kots,
            'counts' => [
                'inKitchen' => $inKitchen,
                'foodReady' => $statusCounts['food_ready'] ?? 0,
                'served' => $statusCounts['served'] ?? 0,
                'pendingConfirmation' => $statusCounts['pending_confirmation'] ?? 0,
                'cancelled' => $statusCounts['cancelled'] ?? 0,
            ],
        ];
    }

    private function buildBaseKotsQuery(string $start, string $end)
    {
        if ($this->showAllKitchens) {
            $query = Kot::select('kots.*')
                ->withCount('items')
                ->orderBy('kots.id', 'desc')
                ->join('orders', 'kots.order_id', '=', 'orders.id')
                ->where('orders.date_time', '>=', $start)
                ->where('orders.date_time', '<=', $end)
                ->where('orders.status', '<>', 'draft')
                ->with([
                    'items.menuItem',
                    'order',
                    'order.waiter',
                    'order.table',
                    'order.orderType',
                    'items.menuItemVariation',
                    'items.modifierOptions',
                    'cancelReason'
                ]);

            if ($this->selectedKitchen) {
                $query = $query->whereHas('items.menuItem', function ($q) {
                    $q->where('kot_place_id', $this->selectedKitchen);
                });
            }

            if ($this->search) {
                $search = $this->search;
                $query = $query->where(function ($q) use ($search) {
                    $q->where('kots.kot_number', 'like', '%' . $search . '%')
                        ->orWhere('orders.order_number', 'like', '%' . $search . '%')
                        ->orWhereHas('order.waiter', function ($waiterQuery) use ($search) {
                            $waiterQuery->where('name', 'like', '%' . $search . '%');
                        })
                        ->orWhereHas('order.table', function ($tableQuery) use ($search) {
                            $tableQuery->where('table_code', 'like', '%' . $search . '%');
                        });
                });
            }

            if (user()->hasRole('Waiter_' . user()->restaurant_id)) {
                $query = $query->where('orders.waiter_id', user()->id);
            }

            return $query;
        }

        if (module_enabled('Kitchen') && in_array('Kitchen', restaurant_modules())) {
            $query = Kot::select('kots.*')
                ->withCount(['items' => function ($query) {
                    $query->whereHas('menuItem', function ($q) {
                        $q->where('kitchen_place_id', $this->kotPlace?->id)
                            ->orWhereNull('kitchen_place_id');
                    });
                }])
                ->orderBy('kots.id', 'desc')
                ->join('orders', 'kots.order_id', '=', 'orders.id')
                ->where('orders.date_time', '>=', $start)->where('orders.date_time', '<=', $end)
                ->where('orders.status', '<>', 'draft')
                ->whereHas('items.menuItem', function ($q) {
                    $q->where('kot_place_id', $this->kotPlace?->id);
                })
                ->with([
                    'items' => function ($query) {
                        $query->whereHas('menuItem', function ($q) {
                            $q->where('kot_place_id', $this->kotPlace?->id);
                        })->with(['menuItem', 'menuItemVariation', 'modifierOptions']);
                    },
                    'items.menuItem',
                    'order',
                    'order.waiter',
                    'order.table',
                    'order.orderType',
                    'items.menuItemVariation',
                    'items.modifierOptions',
                    'cancelReason'
                ]);

            if (user()->hasRole('Waiter_' . user()->restaurant_id)) {
                $query = $query->where('orders.waiter_id', user()->id);
            }

            return $query;
        }

        $query = Kot::select('kots.*')
            ->withCount('items')
            ->orderBy('kots.id', 'desc')
            ->join('orders', 'kots.order_id', '=', 'orders.id')
            ->where('orders.date_time', '>=', $start)
            ->where('orders.date_time', '<=', $end)
            ->where('orders.status', '<>', 'draft')
            ->with('items', 'items.menuItem', 'order', 'order.waiter', 'order.table', 'items.menuItemVariation', 'items.modifierOptions', 'cancelReason');

        if (user()->hasRole('Waiter_' . user()->restaurant_id)) {
            $query = $query->where('orders.waiter_id', user()->id);
        }

        return $query;
    }

    /**
     * Handle order item cancellation when a KOT item is cancelled
     */
    private function handleOrderItemCancellation($kotItem, $order)
    {
        // Find corresponding order item by matching menu item, variation, quantity, and modifiers
        $orderItemQuery = OrderItem::where('order_id', $order->id)
            ->where('menu_item_id', $kotItem->menu_item_id)
            ->where('menu_item_variation_id', $kotItem->menu_item_variation_id)
            ->where('quantity', $kotItem->quantity);

        // If there's a linked order item, use it
        if ($kotItem->order_item_id) {
            $orderItem = OrderItem::find($kotItem->order_item_id);
        } else {
            // Find by matching criteria
            $orderItem = $orderItemQuery->first();
        }

        if ($orderItem) {
            // Mark the order item as cancelled or delete it
            // For now, we'll delete it to match the KOT item behavior
            $orderItem->delete();
        }
    }

    /**
     * Recalculate order totals based on remaining active KOT items
     */
    private function recalculateOrderTotals($order)
    {
        $subTotal = 0;
        $totalTaxAmount = 0;

        // Calculate subtotal from remaining active KOT items
        foreach ($order->kot as $kot) {
            foreach ($kot->items->where('status', '!=', 'cancelled') as $item) {
                $menuItemPrice = $item->menuItem->price ?? 0;
                $variationPrice = $item->menuItemVariation ? $item->menuItemVariation->price : 0;
                $basePrice = $variationPrice ?: $menuItemPrice;

                // Add modifier prices
                $modifierPrice = $item->modifierOptions->sum('price');
                $itemTotal = ($basePrice + $modifierPrice) * $item->quantity;

                $subTotal += $itemTotal;
            }
        }

        // Step 1: Calculate net = subtotal - regular discount - loyalty discount
        $regularDiscount = 0;
        $loyaltyDiscount = floatval($order->loyalty_discount_amount ?? 0);

        if ($order->discount_type === 'percent') {
            $regularDiscount = round(($subTotal * $order->discount_value) / 100, 2);
        } elseif ($order->discount_type === 'fixed') {
            $regularDiscount = min($order->discount_value, $subTotal);
        }

        $net = round($subTotal - $regularDiscount - $loyaltyDiscount, 2);

        // Step 2: Calculate service charges on net (or based on setting)
        $serviceTotal = 0;
        foreach ($order->extraCharges ?? [] as $charge) {
            if (method_exists($charge, 'getAmount')) {
                $serviceTotal += round($charge->getAmount($net), 2);
            }
        }

        // Step 3: Calculate tax_base based on setting
        $includeChargesInTaxBase = restaurant()->include_charges_in_tax_base ?? true;

        if ($includeChargesInTaxBase) {
            $taxBase = round($net + $serviceTotal, 2);
        } else {
            $taxBase = round($net, 2);
        }

        // Step 4: Calculate taxes on tax_base
        if ($order->tax_mode === 'item') {
            // For item-level tax, we need to recalculate from remaining order items
            $remainingOrderItems = OrderItem::where('order_id', $order->id)->get();
            $totalTaxAmount = round($remainingOrderItems->sum('tax_amount'), 2);
        } elseif ($order->tax_mode === 'order') {
            // For order-level tax, calculate from attached order taxes on tax_base
            $orderTaxes = $order->taxes()->with('tax')->get();
            foreach ($orderTaxes as $orderTax) {
                $percent = (float) ($orderTax->tax?->tax_percent ?? 0);
                if ($percent > 0) {
                    $totalTaxAmount += round(($percent / 100) * $taxBase, 2);
                }
            }
            $totalTaxAmount = round($totalTaxAmount, 2);
        }

        // Step 5: Calculate grand_total = tax_base + total_tax_amount + tip + delivery_fee
        $total = round($taxBase + $totalTaxAmount, 2);

        // Add tip and delivery fee to grand total
        if ($order->tip_amount > 0) {
            $total += round($order->tip_amount, 2);
        }

        if ($order->delivery_fee > 0) {
            $total += round($order->delivery_fee, 2);
        }

        // Update the order
        $order->update([
            'sub_total' => round($subTotal, 2),
            'total' => round($total, 2),
            'discount_amount' => $regularDiscount,
            'total_tax_amount' => $totalTaxAmount,
            'tax_base' => $taxBase,
        ]);
    }

    /**
     * Determine if any default status (POS or customer) starts in pending.
     */
    private function computePendingDefault(): bool
    {
        return in_array('pending', [
            $this->kotSettings->default_status_pos ?? 'pending',
            $this->kotSettings->default_status_customer ?? 'pending',
        ], true);
    }
}
