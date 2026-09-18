<?php

namespace App\Livewire\Shop;

use App\Models\Reservation;
use Livewire\Component;

class Bookings extends Component
{

    public $bookings;
    public $dateFormat;
    public $timeFormat;
    public $restaurant;

    public function mount()
    {
        if (is_null(customer())) {

            if (module_enabled('Subdomain')) {
                return $this->redirect('/');
            }

            return $this->redirect(route('home'));
        }

        $this->bookings = Reservation::with('customer', 'table.area', 'area')->where('customer_id', customer()->id)
            ->orderBy('id', 'desc')
            ->get();
        
        // Set date and time formats
        $this->dateFormat = $this->restaurant?->date_format ?? dateFormat();
        $this->timeFormat = $this->restaurant?->time_format ?? timeFormat();
    }

    public function render()
    {
        return view('livewire.shop.bookings');
    }
}
