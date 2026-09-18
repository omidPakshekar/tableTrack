<?php

namespace App\Livewire\Reservations;

use App\Models\Reservation;
use Livewire\Component;

class ReservationCard extends Component
{
    public $reservation;
    public $tableReservation;
    public $showTableModal = false;
    public $reservationStatus;

    public function mount()
    {
        $this->reservationStatus = $this->reservation->reservation_status;
    }

    public function assignTable($reservationId)
    {
        $this->tableReservation = Reservation::find($reservationId);
        $this->showTableModal = true;
    }  

    public function changeTable($reservationId)
    {
        $this->tableReservation = Reservation::find($reservationId);
        $this->showTableModal = true;
    }

    public function editReservation()
    {
        $this->dispatch('openEditReservation', reservationId: $this->reservation->id);
    }

    public function updatedReservationStatus($status)
    {
        $previousStatus = $this->reservation->reservation_status;
        $this->reservation->update(['reservation_status' => $status]);

        if ($status === 'Cancelled' && $this->reservation->table_id) {
            $this->reservation->table->update(['available_status' => 'available']);
            $this->reservation->update(['table_id' => null]);
        }

        // Dispatch event for status update (especially for cancelled status)
        if ($previousStatus !== $status) {
            \App\Events\ReservationStatusUpdated::dispatch($this->reservation->fresh(), $previousStatus);
        }
    }

    public function render()
    {
        return view('livewire.reservations.reservation-card');
    }
}
