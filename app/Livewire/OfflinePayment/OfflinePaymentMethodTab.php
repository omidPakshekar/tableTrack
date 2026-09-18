<?php

namespace App\Livewire\OfflinePayment;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\OfflinePaymentMethod;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class OfflinePaymentMethodTab extends Component
{
    use WithPagination, LivewireAlert;

    public $name;
    public $description;
    public $status = 'active';
    public $methodId;
    public $showPaymentMethodForm = false;
    public $confirmDeleteModal = false;
    public $deleteId;

    protected function rules()
    {
        $rules = [
            'description' => 'nullable|string|max:1000',
            'status' => 'required|in:active,inactive',
        ];

        // Name is only required for new methods or non-system methods
        if (!$this->methodId) {
            $rules['name'] = 'required|string|max:255';
        } else {
            $restaurantId = restaurant() ? restaurant()->id : null;
            $method = OfflinePaymentMethod::where('id', $this->methodId)->where('restaurant_id', $restaurantId)->first();
            
            // Only require name if it's not a system method (cash/bank_transfer)
            if ($method && !in_array($method->name, ['cash', 'bank_transfer'])) {
                $rules['name'] = 'required|string|max:255';
            }
        }

        return $rules;
    }

    public function submitForm()
    {
        $this->validate($this->rules());

        $restaurantId = restaurant() ? restaurant()->id : null;

        if ($this->methodId) {
            $method = OfflinePaymentMethod::where('id', $this->methodId)->where('restaurant_id', $restaurantId)->firstOrFail();
            
            // For cash and bank_transfer, preserve the original name and only update description and status
            if (in_array($method->name, ['cash', 'bank_transfer'])) {
                $updateData = ['description' => $this->description, 'status' => $this->status];
            } else {
                $updateData = ['name' => $this->name, 'description' => $this->description, 'status' => $this->status];
            }
            
            $method->update($updateData);
        } else {
            $updateData = ['name' => $this->name, 'description' => $this->description, 'status' => $this->status, 'restaurant_id' => $restaurantId];
            OfflinePaymentMethod::create($updateData);
        }

        $this->alert('success', $this->methodId ? __('messages.offlinePaymentMethodUpdated') : __('messages.offlinePaymentMethodAdded'), [
            'toast' => true, 'position' => 'top-end'
        ]);

        $this->dispatch('offlinePaymentMethodUpdated');

        $this->resetForm();
    }


    public function addOfflinePayMethod()
    {
        $this->resetForm();
        $this->showPaymentMethodForm = true;
    }

    public function editPaymentMethod($id)
    {
        $restaurantId = restaurant() ? restaurant()->id : null;
        $paymentMethod = OfflinePaymentMethod::where('id', $id)->where('restaurant_id', $restaurantId)->firstOrFail();
            
        $this->methodId = $paymentMethod->id;
        $this->name = $paymentMethod->name;
        $this->description = $paymentMethod->description;
        $this->status = $paymentMethod->status;
        $this->showPaymentMethodForm = true;
    }

    public function confirmDelete($id)
    {
        // Prevent deletion of cash and bank_transfer methods
        $restaurantId = restaurant() ? restaurant()->id : null;
        $method = OfflinePaymentMethod::where('id', $id)
            ->where('restaurant_id', $restaurantId)
            ->first();
            
        if ($method && in_array($method->name, ['cash', 'bank_transfer'])) {
            $this->alert('error', 'Cannot delete system payment method (cash/bank transfer). This method is managed automatically.', [
                'toast' => true, 'position' => 'top-end'
            ]);
            return;
        }
        
        $this->deleteId = $id;
        $this->confirmDeleteModal = true;
    }

    public function delete()
    {
        $restaurantId = restaurant() ? restaurant()->id : null;
        $method = OfflinePaymentMethod::where('id', $this->deleteId)->where('restaurant_id', $restaurantId)->firstOrFail();
        $method->delete();
        
        $this->alert('success', __('messages.offlinePaymentMethodDeleted'), [
            'toast' => true, 'position' => 'top-end'
        ]);
        
        $this->dispatch('offlinePaymentMethodUpdated');
        
        $this->deleteId = null;
        $this->confirmDeleteModal = false;
    }

    private function resetForm()
    {
        $this->methodId = null;
        $this->name = $this->description = '';
        $this->status = 'active';
        $this->showPaymentMethodForm = false;
    }

    public function render()
    {
        $restaurantId = restaurant() ? restaurant()->id : null;
        $methods = OfflinePaymentMethod::where('restaurant_id', $restaurantId)->orderBy('created_at', 'desc')->paginate(10);
            
        return view('livewire.offline-payment.offline-payment-method-tab', compact('methods'));
    }
}
