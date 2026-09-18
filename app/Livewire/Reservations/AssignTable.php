<?php

namespace App\Livewire\Reservations;

use App\Models\Area;
use App\Models\Reservation;
use Livewire\Attributes\On;
use Livewire\Component;

class AssignTable extends Component
{

    public $tables;
    public $reservations;
    public $reservation;
    public $capacityError;

    public function mount()
    {
        $this->capacityError = null;
        $this->reservation->loadMissing('area', 'table.area', 'customer');

        $this->tables = Area::with(['tables' => function ($query) {
            return $query->where('status', 'active');
        }])->get();

        $this->reservations = Reservation::whereDate('reservation_date_time', $this->reservation->reservation_date_time->toDateString())
            ->whereNotNull('table_id')
            ->get();
    }

    public function isTableAvailable($tableId)
    {
        // Check if there's already a reservation for this table at the same date and time
        $existingReservation = Reservation::where('table_id', $tableId)
            ->whereDate('reservation_date_time', $this->reservation->reservation_date_time->toDateString())
            ->whereTime('reservation_date_time', $this->reservation->reservation_date_time->format('H:i:s'))
            ->where('id', '!=', $this->reservation->id) // Exclude current reservation
            ->first();

        return $existingReservation === null;
    }

    public function getConflictingReservationInfo($tableId)
    {
        $existingReservation = Reservation::where('table_id', $tableId)
            ->whereDate('reservation_date_time', $this->reservation->reservation_date_time->toDateString())
            ->whereTime('reservation_date_time', $this->reservation->reservation_date_time->format('H:i:s'))
            ->where('id', '!=', $this->reservation->id)
            ->with('customer')
            ->first();

        if ($existingReservation) {
            return [
                'customer_name' => $existingReservation->customer->name,
                'party_size' => $existingReservation->party_size,
                'time' => $existingReservation->reservation_date_time->format('h:i A')
            ];
        }

        return null;
    }

    public function setReservationTable($table)
    {
        $this->capacityError = null;

        // Reuse tables loaded in mount() to avoid an extra DB query per click.
        $selectedTable = $this->tables
            ->flatMap(fn ($area) => $area->tables)
            ->firstWhere('id', $table);

        if (!$selectedTable) {
            return;
        }

        $pax = (int) ($this->reservation->party_size ?? 0);
        $capacity = (int) ($selectedTable->seating_capacity ?? 0);

        // Allow assigning when pax is equal to or less than seating capacity.
        if ($capacity > 0 && $pax > $capacity) {
            $this->capacityError = 'Seating capacity is less than the number of Pax';
            return;
        }

        // Check if table is available before assigning
        if (!$this->isTableAvailable($table)) {
            return; // Don't assign if table is not available
        }

        // Store previous table ID before updating
        $previousTableId = $this->reservation->table_id;

        // If reservation already has a table, we're changing it
        if ($this->reservation->table_id) {
            // Free up the old table
            $this->reservation->table->update(['available_status' => 'available']);
        }

        $this->reservation->update(['table_id' => $table]);
        
        // Dispatch event for table assignment
        \App\Events\ReservationTableAssigned::dispatch($this->reservation->fresh(), $previousTableId);
        
        $this->redirect(route('reservations.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.reservations.assign-table');
    }

}
