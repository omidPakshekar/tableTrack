<?php

namespace App\Livewire\Forms;

use App\Helper\Common;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ReservationSetting;
use App\Models\User;
use Carbon\Carbon;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;
use App\Services\RestaurantAvailabilityService;

class EditReservation extends Component
{
    use LivewireAlert;

    public $reservationId;
    public $date;
    public $period;
    public $numberOfGuests;
    public $slotType;
    public $specialRequest;
    public $availableTimeSlots = '';

    public $originalReservationDate = '';

    public $originalReservationTime = '';
    public $customerName;
    public $customerPhone;
    public $customerEmail;
    public $phoneCode;
    public $phoneCodeDetected = false;
    public $phoneCodeSearch = '';
    public $phoneCodeIsOpen = false;
    public $allPhoneCodes;
    public $filteredPhoneCodes;

    public $searchQuery = '';
    public $customerSearchResults = [];
    public $selectedCustomerId = null;

    public $timeSlots = [];

    public function mount($reservationId)
    {
        abort_if(!user_can('Update Reservation'), 403);

        $this->reservationId = $reservationId;

        $reservation = Reservation::with('customer')->findOrFail($reservationId);
        $tz = timezone();
        $reservationDateTime = Carbon::parse(
            $reservation->reservation_date_time->format('Y-m-d H:i:s'),
            $tz
        );

        $this->date = $reservationDateTime->displayDate();
        $this->slotType = $reservation->reservation_slot_type ?? 'Lunch';
        $this->numberOfGuests = $reservation->party_size;
        $this->availableTimeSlots = $reservationDateTime->format('H:i:s');
        $this->originalReservationDate = $reservationDateTime->format('Y-m-d');
        $this->originalReservationTime = $this->availableTimeSlots;
        $this->specialRequest = $reservation->special_requests;

        $customer = $reservation->customer;
        if ($customer) {
            $this->customerName = $customer->name;
            $this->customerPhone = $customer->phone;
            $this->customerEmail = $customer->email;
            $this->phoneCode = $customer->phone_code
                ?? restaurant()->country->phonecode
                ?? null;
        }

        $this->allPhoneCodes = collect(Country::pluck('phonecode')->unique()->filter()->values());
        $this->filteredPhoneCodes = $this->allPhoneCodes;

        if (!$this->phoneCode) {
            $detectedPhoneCode = (new User())->getPhoneCodeFromIp();
            $this->phoneCodeDetected = !empty($detectedPhoneCode);
            $this->phoneCode = $detectedPhoneCode
                ?? restaurant()->country->phonecode
                ?? $this->allPhoneCodes->first();
        }

        $this->loadAvailableTimeSlots();
    }

    public function updatedSearchQuery()
    {
        if (strlen($this->searchQuery) >= 2) {
            $this->customerSearchResults = $this->fetchCustomerResults();
        } else {
            $this->customerSearchResults = [];
        }
    }

    public function fetchCustomerResults()
    {
        if (empty($this->searchQuery)) {
            return collect();
        }

        $safeTerm = Common::safeString($this->searchQuery);

        return Customer::where('restaurant_id', restaurant()->id)
            ->where(function ($query) use ($safeTerm) {
                $query->where('name', 'like', '%' . $safeTerm . '%')
                    ->orWhere('phone', 'like', '%' . $safeTerm . '%')
                    ->orWhere('email', 'like', '%' . $safeTerm . '%');
            })
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    public function selectCustomer($customerId)
    {
        $customer = Customer::find($customerId);

        if ($customer) {
            $this->selectedCustomerId = $customer->id;
            $this->customerName = $customer->name;
            $this->customerPhone = $customer->phone;
            $this->phoneCode = $customer->phone_code ?? restaurant()->phone_code ?? $this->allPhoneCodes->first();
            $this->customerEmail = $customer->email;
            $this->searchQuery = '';
            $this->customerSearchResults = [];
        }
    }

    public function clearCustomerSelection()
    {
        $this->selectedCustomerId = null;
        $this->customerName = '';
        $this->customerPhone = '';
        $this->customerEmail = '';
        $detectedPhoneCode = (new User())->getPhoneCodeFromIp();
        $this->phoneCodeDetected = !empty($detectedPhoneCode);
        $this->phoneCode = $detectedPhoneCode
            ?? restaurant()->phone_code
            ?? $this->allPhoneCodes->first();
        $this->searchQuery = '';
        $this->customerSearchResults = [];
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

    public function updatedDate()
    {
        $this->loadAvailableTimeSlots();
    }

    public function setReservationGuest($noOfGuests)
    {
        $minimumPartySize = restaurant() ? (restaurant()->minimum_party_size ?? 1) : 1;

        if ($noOfGuests < $minimumPartySize) {
            $this->addError('numberOfGuests', __('messages.minimumPartySizeRequired', ['size' => $minimumPartySize]));
            return;
        }

        $this->numberOfGuests = $noOfGuests;
    }

    public function setReservationSlotType($type)
    {
        $this->slotType = $type;
        $this->loadAvailableTimeSlots();
    }

    public function updatedSlotType()
    {
        $this->loadAvailableTimeSlots();
    }

    public function loadAvailableTimeSlots()
    {
        $this->timeSlots = [];

        if ($this->date && $this->slotType) {
            $dateFormat = dateFormat();
            $currentTimezone = timezone();

            try {
                $parsedDate = parseRestaurantDate($this->date, $dateFormat, $currentTimezone);
            } catch (\Exception $e) {
                $parsedDate = Carbon::parse($this->date, $currentTimezone);
            }

            $dayOfWeek = $parsedDate->format('l');
            $selectedDate = $parsedDate->format('Y-m-d');

            $now = Carbon::now($currentTimezone);
            $restaurant = restaurant();
            $disableSlotMinutes = $restaurant ? (int)($restaurant->disable_slot_minutes ?? 30) : 30;
            $currentTimeWithBuffer = $now->copy()->addMinutes($disableSlotMinutes);

            $settings = ReservationSetting::where('day_of_week', $dayOfWeek)
                ->where('slot_type', $this->slotType)
                ->where('available', 1)
                ->first();

            if ($settings) {
                $startTime = Carbon::parse($settings->time_slot_start);
                $endTime = Carbon::parse($settings->time_slot_end);
                $slotDifference = (int)$settings->time_slot_difference;

                while ($startTime->lte($endTime)) {
                    $slotTime = $startTime->format('H:i:s');
                    $slotDateTime = Carbon::parse("{$selectedDate} {$slotTime}", $currentTimezone);

                    $isToday = $selectedDate === $now->format('Y-m-d');
                    $isDisabled = false;

                    if ($isToday) {
                        $isDisabled = $slotDateTime->lte($currentTimeWithBuffer);
                    }

                    $this->timeSlots[] = [
                        'time' => $slotTime,
                        'disabled' => $isDisabled
                    ];

                    $startTime->addMinutes($slotDifference);
                }
            }
        }

        $this->syncSelectedTimeSlot();
    }

    protected function syncSelectedTimeSlot(): void
    {
        if (empty($this->availableTimeSlots)) {
            return;
        }

        $this->availableTimeSlots = $this->normalizeTimeString($this->availableTimeSlots);
        $selectedTime = $this->availableTimeSlots;
        $slotTimes = collect($this->timeSlots)->pluck('time')->map(fn ($time) => $this->normalizeTimeString($time));

        if (!$slotTimes->contains($selectedTime)) {
            $this->timeSlots[] = [
                'time' => $selectedTime,
                'disabled' => false,
            ];

            usort($this->timeSlots, fn ($a, $b) => strcmp($a['time'], $b['time']));
        }

        foreach ($this->timeSlots as $index => $slot) {
            if ($this->normalizeTimeString($slot['time']) === $selectedTime) {
                $this->timeSlots[$index]['disabled'] = false;
            }
        }
    }

    protected function normalizeTimeString(string $time): string
    {
        return Carbon::parse($time)->format('H:i:s');
    }

    public function selectTimeSlot(string $time): void
    {
        $normalizedTime = $this->normalizeTimeString($time);

        foreach ($this->timeSlots as $slot) {
            if ($this->normalizeTimeString($slot['time']) !== $normalizedTime) {
                continue;
            }

            if ($slot['disabled'] && $normalizedTime !== $this->originalReservationTime) {
                return;
            }

            break;
        }

        $this->availableTimeSlots = $normalizedTime;
        $this->resetErrorBag('availableTimeSlots');
    }

    public function isTimeSlotSelected(string $time): bool
    {
        if ($this->availableTimeSlots === '') {
            return false;
        }

        return $this->normalizeTimeString($time) === $this->normalizeTimeString($this->availableTimeSlots);
    }

    protected function buildReservationDateTimeForStorage(): string
    {
        $dateFormat = dateFormat();
        $currentTimezone = timezone();

        try {
            $parsedDate = parseRestaurantDate($this->date, $dateFormat, $currentTimezone);
        } catch (\Exception $e) {
            $parsedDate = Carbon::parse($this->date, $currentTimezone);
        }

        $dbDate = $parsedDate->format('Y-m-d');
        $slotTime = $this->normalizeTimeString($this->availableTimeSlots);

        return Carbon::parse("{$dbDate} {$slotTime}", $currentTimezone)->format('Y-m-d H:i:s');
    }

    public function updateReservation()
    {
        $availability = RestaurantAvailabilityService::getAvailability(restaurant(), branch(), null, 'reservation');
        if (!($availability['is_open'] ?? true)) {
            $this->alert('error', RestaurantAvailabilityService::getMessage($availability, restaurant()), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        $minimumPartySize = restaurant()->minimum_party_size ?? 1;

        $this->validate([
            'availableTimeSlots' => 'required',
            'customerName' => 'required',
            'numberOfGuests' => "required|integer|min:{$minimumPartySize}",
        ]);

        if ($this->availableTimeSlots) {
            $dateFormat = dateFormat();
            $currentTimezone = timezone();

            try {
                $parsedDate = parseRestaurantDate($this->date, $dateFormat, $currentTimezone);
            } catch (\Exception $e) {
                $parsedDate = Carbon::parse($this->date, $currentTimezone);
            }

            $selectedDate = $parsedDate->format('Y-m-d');
            $now = Carbon::now($currentTimezone);
            $restaurant = restaurant();
            $disableSlotMinutes = $restaurant ? (int)($restaurant->disable_slot_minutes ?? 30) : 30;
            $currentTimeWithBuffer = $now->copy()->addMinutes($disableSlotMinutes);

            $slotTime = $this->normalizeTimeString($this->availableTimeSlots);
            $slotDateTime = Carbon::parse("{$selectedDate} {$slotTime}", $currentTimezone);
            $isToday = $selectedDate === $now->format('Y-m-d');
            $isUnchangedSlot = $selectedDate === $this->originalReservationDate
                && $slotTime === $this->originalReservationTime;

            if ($isToday && $slotDateTime->lte($currentTimeWithBuffer) && !$isUnchangedSlot) {
                $this->addError('availableTimeSlots', __('messages.slotDisabled'));
                return;
            }
        }

        $reservation = Reservation::findOrFail($this->reservationId);
        $customer = $reservation->customer;

        if ($customer) {
            $customer->update([
                'name' => $this->customerName,
                'phone' => $this->customerPhone,
                'phone_code' => $this->phoneCode,
                'email' => $this->customerEmail
            ]);
        } elseif ($this->selectedCustomerId) {
            $customer = Customer::find($this->selectedCustomerId);
            $customer->update([
                'name' => $this->customerName,
                'phone' => $this->customerPhone,
                'phone_code' => $this->phoneCode,
                'email' => $this->customerEmail
            ]);
        } else {
            $existingCustomer = null;

            if (!empty($this->customerEmail)) {
                $existingCustomer = Customer::where('restaurant_id', restaurant()->id)
                    ->where('email', $this->customerEmail)
                    ->first();
            }

            if (!$existingCustomer && !empty($this->customerPhone)) {
                $existingCustomer = Customer::where('restaurant_id', restaurant()->id)
                    ->where('phone', $this->customerPhone)
                    ->first();
            }

            if ($existingCustomer) {
                $existingCustomer->update([
                    'name' => $this->customerName,
                    'phone' => $this->customerPhone,
                    'phone_code' => $this->phoneCode
                ]);
                $customer = $existingCustomer;
            } else {
                $customer = Customer::create([
                    'name' => $this->customerName,
                    'phone' => $this->customerPhone,
                    'phone_code' => $this->phoneCode,
                    'email' => $this->customerEmail,
                    'restaurant_id' => restaurant()->id
                ]);
            }
        }

        $reservation->update([
            'reservation_date_time' => $this->buildReservationDateTimeForStorage(),
            'customer_id' => $customer->id,
            'party_size' => $this->numberOfGuests,
            'reservation_slot_type' => $this->slotType,
            'special_requests' => $this->specialRequest,
            'slot_time_difference' => ReservationSetting::where('slot_type', $this->slotType)->first()?->time_slot_difference,
        ]);

        $this->alert('success', __('messages.reservationUpdated'), [
            'toast' => false,
            'position' => 'center',
            'showCancelButton' => true,
            'cancelButtonText' => __('app.close')
        ]);

        $this->dispatch('reservationUpdated');
    }

    public function render()
    {
        return view('livewire.forms.edit-reservation', [
            'phonecodes' => $this->filteredPhoneCodes,
            'searchResults' => $this->customerSearchResults,
        ]);
    }
}
