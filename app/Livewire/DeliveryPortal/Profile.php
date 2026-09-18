<?php

namespace App\Livewire\DeliveryPortal;

use App\Models\Country;
use App\Models\DeliveryExecutive;
use App\Models\OrderCashCollection;
use App\Models\User;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class Profile extends Component
{
    use LivewireAlert;

    public $fullName;
    public $email;
    public $phone;
    public $phoneCode;
    public $phoneCodeDetected = false;
    public $availabilityStatus = 1;
    public $phoneCodeSearch = '';
    public $phoneCodeIsOpen = false;
    public $allPhoneCodes;
    public $filteredPhoneCodes;

    public function mount()
    {
        $executive = delivery_executive();

        $this->fullName = $executive->name;
        $this->email = $executive->email;
        $this->phone = $executive->phone;
        $this->phoneCode = $executive->phone_code;
        $this->availabilityStatus = (int) ($executive->is_online ?? 0);

        $this->allPhoneCodes = collect(Country::pluck('phonecode')->unique()->filter()->values());
        $this->filteredPhoneCodes = $this->allPhoneCodes;

        $detectedPhoneCode = (new User())->getPhoneCodeFromIp();
        $this->phoneCodeDetected = empty($this->phoneCode) && !empty($detectedPhoneCode);
        if ($this->phoneCodeDetected) {
            $this->phoneCode = $detectedPhoneCode;
        }
    }

    public function updatedPhoneCodeIsOpen($value)
    {
        if (!$value) {
            $this->reset(['phoneCodeSearch']);
            $this->updatedPhoneCodeSearch();
        }
    }

    public function updatedPhoneCodeSearch()
    {
        $this->filteredPhoneCodes = $this->allPhoneCodes->filter(function ($phonecode) {
            return str_contains($phonecode, $this->phoneCodeSearch);
        })->values();
    }

    public function selectPhoneCode($phonecode)
    {
        $this->phoneCode = $phonecode;
        $this->phoneCodeIsOpen = false;
        $this->phoneCodeSearch = '';
        $this->updatedPhoneCodeSearch();
    }

    public function submitForm()
    {
        $executive = delivery_executive();

        $this->validate([
            'fullName' => 'required|string|max:255',
            'email' => 'required|email|unique:delivery_executives,email,' . $executive->id,
            'phoneCode' => 'required',
            'phone' => 'required|regex:/^[0-9\s]{5,20}$/|unique:delivery_executives,phone,' . $executive->id,
            'availabilityStatus' => 'required|in:0,1',
        ]);

        DeliveryExecutive::where('id', $executive->id)->update([
            'name' => $this->fullName,
            'email' => strtolower($this->email),
            'phone' => $this->phone,
            'phone_code' => $this->phoneCode,
            'is_online' => (int) $this->availabilityStatus,
        ]);

        $freshExecutive = DeliveryExecutive::with('branch.restaurant.currency')->find($executive->id);

        session([
            'delivery_executive' => $freshExecutive,
            'delivery_executive_id' => $freshExecutive->id,
        ]);

        $this->dispatch('deliveryExecutiveProfileUpdated');

        session()->flash('status', __('messages.profileUpdated'));
        return redirect()->route('delivery.dashboard');
    }

    public function render()
    {
        return view('livewire.delivery-portal.profile', [
            'phonecodes' => $this->filteredPhoneCodes,
        ]);
    }
}
