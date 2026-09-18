<?php

namespace App\Livewire\Dashboard;

use App\Models\Restaurant;
use Livewire\Component;

class RestaurantCount extends Component
{

    public $orderCount;
    public $percentChange;
    
    public function mount()
    {
        $this->orderCount = Restaurant::whereDate('restaurants.created_at', '>=', now()->startOfDay()->toDateTimeString())->whereDate('restaurants.created_at', '<=', now()->endOfDay()->toDateTimeString())
            ->count();
        
        $yesterdayCount = Restaurant::whereDate('restaurants.created_at', '>=', now()->subDay()->startOfDay()->toDateTimeString())->whereDate('restaurants.created_at', '<=', now()->subDay()->endOfDay()->toDateTimeString())
            ->count();

        $this->percentChange = calculatePercentChange($this->orderCount, $yesterdayCount);

    }

    public function render()
    {
        return view('livewire.dashboard.restaurant-count');
    }

}
