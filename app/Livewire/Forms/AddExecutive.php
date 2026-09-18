<?php

namespace App\Livewire\Forms;

use App\Models\Country;
use App\Models\DeliveryExecutive;
use App\Models\User;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;

class AddExecutive extends Component
{

    use LivewireAlert;

    public $memberName;
    public $memberEmail;
    public $memberPhone;
    public $status = DeliveryExecutive::STATUS_ACTIVE;
    public $availabilityStatus = 1;
    public $phoneCode;
    public $phoneCodeDetected = false;
    public $phoneCodeSearch = '';
    public $phoneCodeIsOpen = false;
    public $allPhoneCodes;
    public $filteredPhoneCodes;

    public function mount()
    {
        // Initialize phone codes
        $this->allPhoneCodes = collect(Country::pluck('phonecode')->unique()->filter()->values());
        $this->filteredPhoneCodes = $this->allPhoneCodes;

        $detectedPhoneCode = (new User())->getPhoneCodeFromIp();
        $this->phoneCodeDetected = !empty($detectedPhoneCode);
        $this->phoneCode = $detectedPhoneCode
            ?? restaurant()->country->phonecode
            ?? $this->allPhoneCodes->first();
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
        $this->validate([
            'memberName' => 'required',
            'memberEmail' => 'required|email|unique:delivery_executives,email',
            'phoneCode' => 'required',
            'memberPhone' => [
                'required',
                'regex:/^[0-9\s]{5,20}$/',
                'unique:delivery_executives,phone'
            ],
            'status' => 'required|in:' . DeliveryExecutive::STATUS_ACTIVE . ',' . DeliveryExecutive::STATUS_INACTIVE,
            'availabilityStatus' => 'required|in:0,1',
        ]);

        DeliveryExecutive::create([
            'name' => $this->memberName,
            'email' => strtolower($this->memberEmail),
            'phone' => $this->memberPhone,
            'phone_code' => $this->phoneCode,
            'status' => $this->status,
            'is_online' => (int) $this->availabilityStatus,
        ]);

        // Reset the value
        $this->memberName = '';
        $this->memberEmail = '';
        $this->memberPhone = '';
        $this->phoneCode = '';
        $this->status = DeliveryExecutive::STATUS_ACTIVE;
        $this->availabilityStatus = 1;

        $this->dispatch('hideAddStaff');
        $this->dispatch('closeAddExecutiveModal');

        cache()->forget('delivery_executives_' . restaurant()->id);

        $this->alert('success', __('messages.memberAdded'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function render()
    {
        return view('livewire.forms.add-executive', [
            'phonecodes' => $this->filteredPhoneCodes,
        ]);
    }

}
