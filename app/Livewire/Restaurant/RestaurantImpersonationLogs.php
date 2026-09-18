<?php

namespace App\Livewire\Restaurant;

use App\Models\RestaurantImpersonationLog;
use Livewire\Component;
use Livewire\WithPagination;

class RestaurantImpersonationLogs extends Component
{
    use WithPagination;

    public ?int $restaurantId = null;

    public bool $compact = false;

    public string $search = '';

    public bool $showSessionModal = false;

    public ?RestaurantImpersonationLog $selectedSession = null;

    public function mount(?int $restaurantId = null, bool $compact = false): void
    {
        $this->restaurantId = $restaurantId;
        $this->compact = $compact;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function viewSession(int $sessionId): void
    {
        $this->selectedSession = RestaurantImpersonationLog::with([
            'restaurant',
            'actions' => fn ($query) => $query->latest('created_at'),
        ])->findOrFail($sessionId);

        $this->showSessionModal = true;
    }

    public function closeSessionModal(): void
    {
        $this->showSessionModal = false;
        $this->selectedSession = null;
    }

    public function render()
    {
        $sessions = RestaurantImpersonationLog::query()
            ->with('restaurant')
            ->when($this->restaurantId, fn ($query) => $query->where('restaurant_id', $this->restaurantId))
            ->when($this->search !== '', function ($query) {
                $query->where(function ($inner) {
                    $inner->where('superadmin_name', 'like', '%' . $this->search . '%')
                        ->orWhere('superadmin_email', 'like', '%' . $this->search . '%')
                        ->orWhere('impersonated_user_name', 'like', '%' . $this->search . '%')
                        ->orWhere('impersonated_user_email', 'like', '%' . $this->search . '%')
                        ->orWhereHas('restaurant', fn ($restaurantQuery) => $restaurantQuery->where('name', 'like', '%' . $this->search . '%'));
                });
            })
            ->latest('started_at')
            ->paginate($this->compact ? 10 : 15);

        return view('livewire.restaurant.restaurant-impersonation-logs', [
            'sessions' => $sessions,
        ]);
    }
}
