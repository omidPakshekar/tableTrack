<?php

namespace App\Livewire\Forms;

use Livewire\Component;
use App\Models\WaiterRequest;
use App\Models\Table;
use App\Events\ActiveWaiterRequestCreatedEvent;
use Illuminate\Support\Facades\RateLimiter;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class CallWaiterButton extends Component
{
    use LivewireAlert;

    private const COOLDOWN_SECONDS = 120;

    public $showConfirmation = false;
    public $notificationSent = false;
    public $tableNumber;
    public $tables;
    public $shopBranch;
    public $showTableSelection = false;
    public $table;
    public $initialTableNumber; // Store the original table number from QR code

    public function mount()
    {
        $this->initialTableNumber = $this->tableNumber; // Store initial value
        $this->tableNumber = $this->tableNumber;
        if ($this->tableNumber) {
            $this->table = Table::where('id', $this->tableNumber)->first();
        }
        $this->tables = Table::where('branch_id', $this->shopBranch->id)->get();
    }

    public function callWaiter()
    {
        if ($this->tableNumber && !$this->canCallWaiterFor((int) $this->tableNumber)) {
            $this->notifyWaiterCallBlocked((int) $this->tableNumber);

            return;
        }

        if (!$this->tableNumber) {
            $this->showTableSelection = true;
        } else {
            $this->showConfirmation = true;
        }
    }

    public function selectTable($tableId)
    {
        $tableId = (int) $tableId;

        if (!$this->canCallWaiterFor($tableId)) {
            $this->notifyWaiterCallBlocked($tableId);

            return;
        }

        $this->tableNumber = $tableId;
        $this->table = Table::where('id', $tableId)->first();

        $this->showTableSelection = false;
        $this->showConfirmation = true;
    }

    public function confirmCall()
    {
        if (!$this->tableNumber) {
            $this->showTableSelection = true;
            $this->alert('error', __('messages.tableNumberOrTableNotFound'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            return;
        }

        if (!$this->canCallWaiterFor((int) $this->tableNumber)) {
            $this->notifyWaiterCallBlocked((int) $this->tableNumber);
            $this->showConfirmation = false;

            return;
        }

        // Save request to database
        WaiterRequest::create([
            'table_id' => $this->tableNumber,
            'branch_id' => $this->shopBranch->id,
            'status' => 'pending',
        ]);

        RateLimiter::hit($this->rateLimitKeyFor($this->tableNumber), self::COOLDOWN_SECONDS);

        session([
            $this->sessionCooldownKeyFor($this->tableNumber) => now()->timestamp,
        ]);

        $this->showConfirmation = false;
        $this->notificationSent = true;

        $count = WaiterRequest::where('status', 'pending')->where('branch_id', $this->shopBranch->id)->distinct('table_id')->count();

        event(new ActiveWaiterRequestCreatedEvent($count));

        $this->dispatch('newWaiterRequest');
        $this->dispatch('waiterRequestCreated', ['count' => $count, 'table_id' => $this->tableNumber]);

        // If table was initially provided (from QR code), keep it. Otherwise, reset it.
        if ($this->initialTableNumber) {
            $this->tableNumber = $this->initialTableNumber;
            $this->table = Table::where('id', $this->initialTableNumber)->first();
        } else {
            $this->tableNumber = null;
            $this->table = null;
        }
    }

    public function getCanCallWaiterProperty(): bool
    {
        if (!$this->tableNumber) {
            return true;
        }

        return $this->canCallWaiterFor((int) $this->tableNumber);
    }

    private function canCallWaiterFor(int $tableId): bool
    {
        return !$this->hasPendingWaiterRequest($tableId) && !$this->isOnCooldown($tableId);
    }

    private function hasPendingWaiterRequest(int $tableId): bool
    {
        return WaiterRequest::where('table_id', $tableId)
            ->where('branch_id', $this->shopBranch->id)
            ->whereIn('status', ['pending', 'Pending'])
            ->exists();
    }

    private function isOnCooldown(int $tableId): bool
    {
        if (RateLimiter::tooManyAttempts($this->rateLimitKeyFor($tableId), 1)) {
            return true;
        }

        $sessionKey = $this->sessionCooldownKeyFor($tableId);
        $lastCallAt = session($sessionKey);

        if (!$lastCallAt) {
            return false;
        }

        return (now()->timestamp - (int) $lastCallAt) < self::COOLDOWN_SECONDS;
    }

    private function remainingCooldownSeconds(int $tableId): int
    {
        $rateLimitSeconds = RateLimiter::tooManyAttempts($this->rateLimitKeyFor($tableId), 1)
            ? RateLimiter::availableIn($this->rateLimitKeyFor($tableId))
            : 0;

        $sessionKey = $this->sessionCooldownKeyFor($tableId);
        $lastCallAt = session($sessionKey);
        $sessionSeconds = 0;

        if ($lastCallAt) {
            $elapsed = now()->timestamp - (int) $lastCallAt;
            $sessionSeconds = max(0, self::COOLDOWN_SECONDS - $elapsed);
        }

        return max($rateLimitSeconds, $sessionSeconds);
    }

    private function rateLimitKeyFor(int $tableId): string
    {
        return 'waiter-request:' . $this->shopBranch->id . ':' . $tableId . ':' . request()->ip();
    }

    private function sessionCooldownKeyFor(int $tableId): string
    {
        return 'waiter_request_last_' . $this->shopBranch->id . '_' . $tableId;
    }

    private function notifyWaiterCallBlocked(int $tableId): void
    {
        if ($this->hasPendingWaiterRequest($tableId)) {
            $this->alert('warning', __('messages.waiterRequestAlreadyPending'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            return;
        }

        $seconds = $this->remainingCooldownSeconds($tableId);
        $minutes = (int) ceil($seconds / 60);

        $this->alert('warning', __('messages.waiterRequestCooldown', [
            'time' => $minutes > 1
                ? __('messages.waiterRequestCooldownMinutes', ['minutes' => $minutes])
                : __('messages.waiterRequestCooldownSeconds', ['seconds' => max(1, $seconds)]),
        ]), [
            'toast' => true,
            'position' => 'top-end',
        ]);
    }

    public function cancelCall()
    {
        // If table was initially provided (from QR code), restore it. Otherwise, reset it.
        if ($this->initialTableNumber) {
            $this->tableNumber = $this->initialTableNumber;
            $this->table = Table::where('id', $this->initialTableNumber)->first();
        } else {
            $this->tableNumber = null;
            $this->table = null;
        }
        $this->showConfirmation = false;
        $this->showTableSelection = false;
    }

    public function isTableWaiterCallBlocked(int $tableId): bool
    {
        return !$this->canCallWaiterFor($tableId);
    }

    public function render()
    {
        return view('livewire.forms.call-waiter-button');
    }
}
