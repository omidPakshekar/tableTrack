<?php

namespace App\Livewire\Shop;

use App\Events\ReservationReceived;
use App\Events\ReservationSubmitted;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Reservation;
use App\Models\ReservationSetting;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Log;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;
use App\Services\RestaurantAvailabilityService;

class BookATable extends Component
{
    use LivewireAlert;

    protected $listeners = ['setCustomer' => '$refresh'];

    public $date;
    public $numberOfGuests = 1;
    public $slotType;
    public $specialRequest;
    public $restaurant;
    public $availableTimeSlots = '';
    public $shopBranch;

    public $disabledDates = [];
    public $availableSlotTypes = [];
    public $disabledSlotTypes = [];
    public $timeSlots = [];

    public $selectedAreaId = '';

    public const SLOT_TYPES = ['Breakfast', 'Lunch', 'Dinner'];

    public function mount()
    {
        if (!$this->restaurant) {
            return $this->redirect(route('home'));
        }

        // Eager load branches
        $this->restaurant->loadMissing('branches');

        // Keep date in Y-m-d format for internal processing
        $this->date = $this->now()->format('Y-m-d');
        $this->slotType = $this->getDefaultSlotType();

        $this->shopBranch = $this->getBranch();

        $this->refreshAvailabilityData();
    }

    public function hydrate(): void
    {
        if ($normalized = $this->normalizeBookingDate($this->date)) {
            $this->date = $normalized;
        }
    }

    private function now()
    {
        return now()->timezone(timezone());
    }

    private function getDefaultSlotType()
    {
        $hour = $this->now()->format('H');
        return (intval($hour) >= 17) ? 'Dinner' : ((intval($hour) >= 12) ? 'Lunch' : 'Breakfast');
    }

    private function getBranch()
    {
        $branchId = request()->branch;
        if ($branchId && $branchId != '') {
            return Branch::find($branchId);
        }
        return $this->restaurant->branches->first();
    }

    private function getDayOfWeek($date = null)
    {
        $normalized = $this->normalizeBookingDate($date);

        return Carbon::parse($normalized ?? $this->now()->format('Y-m-d'))->format('l');
    }

    /**
     * Normalize booking date to Y-m-d for queries and storage.
     */
    protected function normalizeBookingDate(?string $date = null): ?string
    {
        $date = trim((string) ($date ?? $this->date));

        if ($date === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            try {
                return Carbon::parse($date)->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }

        $dateFormat = $this->restaurant->date_format ?? 'd-m-Y';

        try {
            return Carbon::createFromFormat($dateFormat, $date)->format('Y-m-d');
        } catch (\Exception $e) {
            try {
                return Carbon::parse($date)->format('Y-m-d');
            } catch (\Exception $e2) {
                return null;
            }
        }
    }

    private function refreshAvailabilityData()
    {
        $this->resetSelectedTimeSlot();
        $this->loadAvailabilityData();
        $this->loadAvailableTimeSlots();
    }

    private function resetSelectedTimeSlot(): void
    {
        $this->availableTimeSlots = '';
    }

    public function loadAvailabilityData()
    {
        $allSettings = ReservationSetting::where('branch_id', $this->shopBranch?->id)
            ->where('available', 1)
            ->get();

        $this->availableSlotTypes = [];
        foreach ($allSettings as $setting) {
            $this->availableSlotTypes[$setting->slot_type][] = $setting->day_of_week;
        }

        $this->disabledDates = $this->getDisabledDates();
        $this->disabledSlotTypes = $this->getDisabledSlotTypes();
    }

    private function getDisabledDates()
    {
        $startOfWeek = $this->now();
        $endOfWeek = $startOfWeek->copy()->addDays(6);
        $period = CarbonPeriod::create($startOfWeek, $endOfWeek);

        $disabledDates = [];
        foreach ($period as $date) {
            $dayOfWeek = $date->format('l');
            if (!$this->isAnySlotAvailableOnDay($dayOfWeek)) {
                $disabledDates[] = $date->format('Y-m-d');
            }
        }
        return $disabledDates;
    }

    private function isAnySlotAvailableOnDay($dayOfWeek)
    {
        foreach ($this->availableSlotTypes as $slotDays) {
            if (in_array($dayOfWeek, $slotDays)) {
                return true;
            }
        }
        return false;
    }

    private function getDisabledSlotTypes()
    {
        $dayOfWeek = $this->getDayOfWeek();
        $disabledSlotTypes = [];
        foreach (self::SLOT_TYPES as $type) {
            if (!$this->isSlotTypeAvailableForDay($type, $dayOfWeek)) {
                $disabledSlotTypes[] = $type;
            }
        }
        return $disabledSlotTypes;
    }

    private function isSlotTypeAvailableForDay($slotType, $dayOfWeek)
    {
        return isset($this->availableSlotTypes[$slotType]) &&
            in_array($dayOfWeek, $this->availableSlotTypes[$slotType]);
    }

    public function loadAvailableTimeSlots()
    {
        $this->timeSlots = [];

        if (!$this->date || !$this->slotType) {
            return;
        }

        $dayOfWeek = $this->getDayOfWeek();

        $settings = ReservationSetting::where([
                ['day_of_week', $dayOfWeek],
                ['slot_type', $this->slotType],
                ['branch_id', $this->shopBranch?->id],
            ])->first();

        if (!$settings) {
            return;
        }

        $startTime = Carbon::parse($settings->time_slot_start);
        $endTime = Carbon::parse($settings->time_slot_end);
        $slotDifference = (int)$settings->time_slot_difference;

        while ($startTime->lte($endTime)) {
            $this->timeSlots[] = $startTime->format('H:i:s');
            $startTime->addMinutes($slotDifference);
        }
    }

    public function isSlotTypeAvailable($type = null)
    {
        $slotType = $type ?? $this->slotType;
        $selectedDay = $this->getDayOfWeek();
        return $this->isSlotTypeAvailableForDay($slotType, $selectedDay);
    }

        public function isTimeSlotPast($timeSlot)
    {
        $isToday = $this->normalizeBookingDate() === $this->now()->format('Y-m-d');
        $currentTime = $this->now()->format('H:i:s');
        return $isToday && $timeSlot <= $currentTime;
    }

    public function isTimeSlotDisabled($timeSlot)
    {
        // Check if slot is past current time
        if ($this->isTimeSlotPast($timeSlot)) {
            return true;
        }

        // Check if slot type is not available
        if (!$this->isSlotTypeAvailable()) {
            return true;
        }

        // Check disable slot minutes for today
        $bookingDate = $this->normalizeBookingDate();
        $isToday = $bookingDate === $this->now()->format('Y-m-d');
        if ($isToday) {
            // Get disable slot minutes from restaurant or use default
            $disableSlotMinutes = $this->restaurant ? (int)($this->restaurant->disable_slot_minutes ?? 30) : 30;
            $currentTimeWithBuffer = $this->now()->copy()->addMinutes($disableSlotMinutes);

            $slotDateTime = Carbon::parse("{$bookingDate} {$timeSlot}", timezone());

            if ($slotDateTime->lte($currentTimeWithBuffer)) {
                return true;
            }
        }

        return false;
    }

    public function updatedDate($value)
    {
        if (!$value || trim($value) === '') {
            return;
        }

        $previousDay = $this->normalizeBookingDate($this->date);
        $convertedDate = $this->normalizeBookingDate($value);

        if (!$convertedDate) {
            Log::warning('Failed to parse booking date: ' . $value);

            return;
        }

        $this->date = $convertedDate;

        // Only reset time slots when the calendar day actually changes (not display-format sync).
        if ($previousDay !== $convertedDate) {
            $this->refreshAvailabilityData();
        }
    }

    public function setReservationDate($selectedDate)
    {
        $this->date = $selectedDate;
        $this->refreshAvailabilityData();
    }

    public function setReservationGuest($noOfGuests)
    {
        // Get minimum party size from restaurant settings
        $minimumPartySize = $this->restaurant->minimum_party_size ?? 1;

        // Validate that the selected number of guests meets the minimum requirement
        if ($noOfGuests < $minimumPartySize) {
            $this->addError('numberOfGuests', __('messages.minimumPartySizeRequired', ['size' => $minimumPartySize]));
            return;
        }

        $this->numberOfGuests = $noOfGuests;
    }

    public function setReservationSlotType($type)
    {
        $this->slotType = $type;
        $this->refreshAvailabilityData();
    }

    protected function branchAreas()
    {
        if (! $this->shopBranch) {
            return collect();
        }

        return Area::withoutGlobalScopes()
            ->where('branch_id', $this->shopBranch->id)
            ->with(['tables' => function ($query) {
                $query->where('status', 'active')->orderBy('table_code');
            }])
            ->withCount(['tables' => function ($query) {
                $query->where('status', 'active');
            }])
            ->orderBy('area_name')
            ->get();
    }

    /**
     * Table IDs already assigned to a reservation at the selected date and time.
     *
     * @return array<int>
     */
    protected function reservedTableIdsForSelectedSlot(): array
    {
        $bookingDate = $this->normalizeBookingDate();

        if (! $this->shopBranch || ! $bookingDate || ! $this->availableTimeSlots) {
            return [];
        }

        return Reservation::query()
            ->where('branch_id', $this->shopBranch->id)
            ->whereNotNull('table_id')
            ->whereDate('reservation_date_time', $bookingDate)
            ->whereTime('reservation_date_time', $this->availableTimeSlots)
            ->whereNotIn('reservation_status', ['Cancelled', 'No_Show'])
            ->pluck('table_id')
            ->unique()
            ->values()
            ->all();
    }

    protected function validateSelectedArea(): bool
    {
        if ($this->selectedAreaId === '' || $this->selectedAreaId === null) {
            return true;
        }

        $valid = $this->branchAreas()->contains('id', (int) $this->selectedAreaId);

        if (! $valid) {
            $this->addError('selectedAreaId', __('modules.reservation.invalidPreferredArea'));
        }

        return $valid;
    }

    public function submitReservation()
    {
        $availability = RestaurantAvailabilityService::getAvailability($this->restaurant, $this->shopBranch, null, 'reservation');
        if (!($availability['is_open'] ?? true)) {
            $this->alert('error', RestaurantAvailabilityService::getMessage($availability, $this->restaurant), [
                'toast' => false,
                'position' => 'center',
            ]);
            return;
        }

        // Get minimum party size from restaurant settings
        $minimumPartySize = $this->restaurant->minimum_party_size ?? 1;

        $this->validate([
            'availableTimeSlots' => 'required',
            'numberOfGuests' => "required|integer|min:{$minimumPartySize}",
        ]);

        // Additional validation for minimum party size
        if ($this->numberOfGuests < $minimumPartySize) {
            $this->addError('numberOfGuests', __('messages.minimumPartySizeRequired', ['size' => $minimumPartySize]));
            return;
        }

        if (!$this->isSlotTypeAvailable()) {
            $this->alert('error', __('messages.slotTypeNotAvailable'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        if ($this->isTimeSlotPast($this->availableTimeSlots)) {
            $this->alert('error', __('messages.pastTimeSlot'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        if (! $this->validateSelectedArea()) {
            return;
        }

        $bookingDate = $this->normalizeBookingDate();

        // Check if the selected time slot is disabled due to disable slot minutes
        $isToday = $bookingDate === $this->now()->format('Y-m-d');
        if ($isToday) {
            $disableSlotMinutes = $this->restaurant ? (int)($this->restaurant->disable_slot_minutes ?? 30) : 30;
            $currentTimeWithBuffer = $this->now()->copy()->addMinutes($disableSlotMinutes);

            $slotDateTime = Carbon::parse("{$bookingDate} {$this->availableTimeSlots}", timezone());

            if ($slotDateTime->lte($currentTimeWithBuffer)) {
                $this->alert('error', __('messages.slotDisabled'), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
                return;
            }
        }

        $reservation = Reservation::create([
            'reservation_date_time' => $bookingDate . ' ' . $this->availableTimeSlots,
            'customer_id' => customer()->id,
            'branch_id' => $this->shopBranch->id,
            'party_size' => $this->numberOfGuests,
            'reservation_slot_type' => $this->slotType,
            'reservation_status' => 'Pending',
            'special_requests' => $this->specialRequest,
            'area_id' => $this->selectedAreaId !== '' ? (int) $this->selectedAreaId : null,
        ]);

        $reservation->load(['customer', 'branch.restaurant']);

        ReservationSubmitted::dispatch($reservation);
        ReservationReceived::dispatch($reservation);

        $this->alert('success', __('messages.reservationSubmitted'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);

        $this->redirect(route('my_bookings', $this->restaurant->hash), navigate: true);
    }

    public function render()
    {
        $availability = RestaurantAvailabilityService::getAvailability($this->restaurant, $this->shopBranch, null, 'reservation');

        return view('livewire.shop.book-a-table', [
            'isRestaurantOpenForReservations' => (bool) ($availability['is_open'] ?? true),
            'restaurantClosedMessage' => RestaurantAvailabilityService::getMessage($availability, $this->restaurant),
            'branchAreas' => $this->branchAreas(),
            'reservedTableIds' => $this->reservedTableIdsForSelectedSlot(),
        ]);
    }
}
