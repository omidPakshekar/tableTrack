<?php

namespace App\Livewire\Settings;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\RefundReason;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class RefundReasonSettings extends Component
{
    use LivewireAlert;
    public $reasons;
    public $showAddRefundReasonModal = false;
    public $showEditRefundReasonModal = false;
    public $showDeleteModal = false;
    public $reasonToEdit = null;
    public $reasonToDelete = null;

    public function mount()
    {
        $this->loadReasons();
    }

    public function loadReasons()
    {
        $this->reasons = RefundReason::with('translations')->latest()->get();
    }

    public function showAddRefundReason()
    {
        $this->showAddRefundReasonModal = true;
    }

    public function editReason($reasonId)
    {
        $this->reasonToEdit = RefundReason::with('translations')->find($reasonId);
        $this->showEditRefundReasonModal = true;
    }

    public function confirmDelete($reasonId)
    {
        $this->reasonToDelete = $reasonId;
        $this->showDeleteModal = true;
    }

    public function deleteReason()
    {
        $reason = RefundReason::find($this->reasonToDelete);
        if ($reason) {
            $reason->delete();
            $this->loadReasons();
            $this->alert('success', __('messages.reasonDeleted'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);

            $this->showDeleteModal = false;
            $this->reasonToDelete = null;
        }
    }

    public function closeModal()
    {
        $this->showAddRefundReasonModal = false;
        $this->showEditRefundReasonModal = false;
        $this->reasonToEdit = null;
    }

    #[On('hideAddRefundReason')]
    public function hideAddRefundReason()
    {
        $this->showAddRefundReasonModal = false;
        $this->loadReasons(); // Refresh the list after adding
    }

    #[On('hideEditRefundReason')]
    public function hideEditRefundReason()
    {
        $this->showEditRefundReasonModal = false;
        $this->reasonToEdit = null;
        $this->loadReasons(); // Refresh the list after editing
    }

    public function render()
    {
        return view('livewire.settings.refund-reason-settings');
    }
}
