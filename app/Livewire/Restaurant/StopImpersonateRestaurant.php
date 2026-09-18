<?php

namespace App\Livewire\Restaurant;

use App\Models\Restaurant;
use App\Services\RestaurantImpersonationLogService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class StopImpersonateRestaurant extends Component
{
    public bool $menuStyle = false;

    public function mount(bool $menuStyle = false): void
    {
        $this->menuStyle = $menuStyle;
    }

    public function stopImpersonate()
    {
        $userId = session('impersonate_user_id');
        $restaurantId = session('impersonate_restaurant_id');
        $logId = session('impersonate_log_id');
        $restaurant = Restaurant::findOrFail($restaurantId);

        if ($logId) {
            app(RestaurantImpersonationLogService::class)->endSession((int) $logId);
        }

        session()->flush();
        Auth::logout();

        session(['stop_impersonate' => $userId]);
        Auth::loginUsingId($userId);

        $redirect = route('superadmin.restaurants.show', $restaurant->hash);

        return $this->redirect($redirect);
    }

    public function render()
    {
        return view('livewire.restaurant.stop-impersonate-restaurant');
    }

}
