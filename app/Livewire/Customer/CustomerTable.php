<?php

namespace App\Livewire\Customer;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

class CustomerTable extends Component
{
    use LivewireAlert;
    use WithPagination, WithoutUrlPagination;

    #[Reactive]
    public $search = '';
    public $customer;
    public $showEditCustomerModal = false;
    public $confirmDeleteCustomerModal = false;
    public $showCustomerOrderModal = false;
    public $showLoyaltyAccountModal = false;

    protected $listeners = ['refreshCustomers' => '$refresh', 'reloadPage' => '$refresh'];

    #[On('refreshCustomers')]
    public function refreshCustomers()
    {
        // Ensure pagination doesn't point to an empty page after changes.
        $this->resetPage();
    }

    public function showEditCustomer($id)
    {
        $this->customer = Customer::with(['addresses', 'latestDeliveryAddress'])->findOrFail($id);
        $this->showEditCustomerModal = true;
    }

    public function showDeleteCustomer($id)
    {
        $this->customer = Customer::findOrFail($id);
        $this->confirmDeleteCustomerModal = true;
    }

    public function showCustomerOrders($id)
    {
        $this->customer = Customer::findOrFail($id);
        $this->showCustomerOrderModal = true;
    }

    public function showLoyaltyAccount($id)
    {
        $this->customer = Customer::findOrFail($id);
        $this->showLoyaltyAccountModal = true;
    }

    public function deleteCustomer($id, $deleteOrder = false)
    {
        if ($deleteOrder) {
            Order::where('customer_id', $id)->delete();
        }

        Customer::destroy($id);

        $this->customer = null;
        $this->confirmDeleteCustomerModal = false;
        $this->dispatch('refreshOrders');

        $this->alert('success', __('messages.customerDeleted'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    #[On('hideEditCustomer')]
    public function hideEditCustomer()
    {
        $this->showEditCustomerModal = false;
        $this->customer = null;
    }

    public function render()
    {
        // Get restaurant ID - try multiple methods to ensure we get it
        $restaurant = restaurant();
        $restaurantId = null;
        
        if ($restaurant && is_object($restaurant)) {
            $restaurantId = $restaurant->id;
        } elseif (user() && user()->restaurant_id) {
            $restaurantId = user()->restaurant_id;
        } elseif (branch() && branch()->restaurant_id) {
            $restaurantId = branch()->restaurant_id;
        }
        
        // If still no restaurant ID, try to get from customers table (for current branch/restaurant)
        if (!$restaurantId) {
            $restaurantId = Customer::value('restaurant_id');
        }
        
        // Final fallback - use 1 if still no restaurant ID (for testing)
        // In production, this should be determined from user/branch context
        if (!$restaurantId) {
            $restaurantId = 1; // Fallback - adjust based on your setup
            Log::debug('CustomerTable: Could not determine restaurant_id, using fallback value: ' . $restaurantId);
        }

        // Build base query
        $baseQuery = Customer::query()
            ->withCount('orders')
            ->with(['orders', 'latestDeliveryAddress'])
            ->where(function($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('email', 'like', '%' . $this->search . '%')
                    ->orWhere('phone', 'like', '%' . $this->search . '%');
            })
            ->orderBy('id', 'desc');
        
        // Add loyalty points subquery directly (safe if module not enabled)
        if ($restaurantId && function_exists('module_enabled') && module_enabled('Loyalty')) {
            try {
                // Add loyalty points subquery directly without using trait
                $baseQuery->addSelect([
                    'loyalty_points' => DB::table('loyalty_accounts')
                        ->selectRaw('CAST(COALESCE(points_balance, 0) AS UNSIGNED)')
                        ->whereColumn('customer_id', 'customers.id')
                        ->where('restaurant_id', $restaurantId)
                        ->limit(1)
                ]);
            } catch (\Exception $e) {
                // Log error for debugging
                Log::error('Loyalty module error in CustomerTable: ' . $e->getMessage(), [
                    'restaurant_id' => $restaurantId,
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $query = $baseQuery->paginate(10);
        
        // Get loyalty settings to determine column name and type
        $loyaltyType = null;
        $enablePoints = false;
        $enableStamps = false;
        $loyaltyColumnName = null;
        
        if ($restaurantId && function_exists('module_enabled') && module_enabled('Loyalty')) {
            try {
                if (module_enabled('Loyalty')) {
                    $settings = \Modules\Loyalty\Entities\LoyaltySetting::getForRestaurant($restaurantId);
                    if ($settings && $settings->enabled) {
                        $loyaltyType = $settings->loyalty_type ?? 'points';
                        $enablePoints = $settings->enable_points ?? false;
                        $enableStamps = $settings->enable_stamps ?? false;
                        
                        // Determine column name based on loyalty type
                        if ($enablePoints && !$enableStamps) {
                            // Points only
                            $loyaltyColumnName = __('loyalty::app.pointsBalance');
                        } elseif ($enableStamps && !$enablePoints) {
                            // Stamps only
                            $loyaltyColumnName = __('loyalty::app.viewLoyaltyStamps');
                        } elseif ($enablePoints && $enableStamps) {
                            // Both points and stamps
                            $loyaltyColumnName = __('loyalty::app.viewLoyaltyReward');
                        }
                    }
                }
            } catch (\Exception $e) {
                // Silently fail
            }
        }

        return view('livewire.customer.customer-table', [
            'customers' => $query,
            'loyaltyType' => $loyaltyType,
            'enablePoints' => $enablePoints,
            'enableStamps' => $enableStamps,
            'loyaltyColumnName' => $loyaltyColumnName,
        ]);
    }
}
