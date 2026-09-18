<?php

namespace App\Livewire\Settings;

use App\Models\Branch;
use App\Models\BranchOperationalShift;
use App\Scopes\BranchScope;
use Carbon\Carbon;
use Livewire\Component;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class BranchOperationalShifts extends Component
{
    use LivewireAlert;

    private const DEFAULT_TIMEZONE = 'UTC';

    public Branch $branch;
    public $selectedBranchId;
    public $shifts = [];
    public $showShiftModal = false;
    public $editingShiftId = null;
    public $shiftName = '';
    public $startTime = '09:00';
    public $endTime = '17:00';
    public $dayOfWeek = 'All';
    public $selectedDays = []; // For checkbox UI - can contain multiple days
    public $isActive = true;
    public $sortOrder = 0;
    public $branches = [];

    public function mount()
    {
        // Get current branch
        $this->branch = Branch::where('id', branch()->id)->with('operationalShifts')->first();
        $this->selectedBranchId = $this->branch->id;

        // Get all branches for selector (if multiple)
        $this->branches = Branch::where('restaurant_id', restaurant()->id)->get();

        $this->loadShifts();
    }

    public function updatedSelectedBranchId($value)
    {
        if ($value) {
            $this->branch = Branch::where('id', $value)->with('operationalShifts')->first();
            if ($this->branch) {
                $this->selectedBranchId = $this->branch->id;
                $this->loadShifts();
                $this->resetShiftForm();
            } else {
                $this->shifts = [];
            }
        }
    }
    
    public function getBranchNameProperty()
    {
        return $this->branch?->name ?? '';
    }

    public function loadShifts()
    {
        if (!$this->branch || !$this->branch->id) {
            $this->shifts = [];
            return;
        }

        $restaurantTimezone = $this->getRestaurantTimezone();
        $timeFormat = $this->branch->restaurant?->time_format ?? 'h:i A';

        // This allows viewing shifts for branches other than the session branch
        $shifts = BranchOperationalShift::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $this->branch->id)
            ->orderBy('sort_order')
            ->orderBy('start_time')
            ->get();

        // Convert to array once and prepare display fields in restaurant timezone.
        $this->shifts = $shifts->map(function ($shift) use ($restaurantTimezone, $timeFormat) {
            $shiftArray = $shift->toArray();

            $shiftArray['day_of_week'] = $this->decodeDays($shiftArray['day_of_week'] ?? []);

            $startLocal = $this->convertUtcTimeToRestaurantTime($shift->start_time, $restaurantTimezone);
            $endLocal = $this->convertUtcTimeToRestaurantTime($shift->end_time, $restaurantTimezone);

            $shiftArray['start_time_local'] = $startLocal;
            $shiftArray['end_time_local'] = $endLocal;
            $shiftArray['start_time_display'] = Carbon::createFromFormat('H:i', $startLocal)->format($timeFormat);
            $shiftArray['end_time_display'] = Carbon::createFromFormat('H:i', $endLocal)->format($timeFormat);
            $shiftArray['is_overnight_local'] = $endLocal < $startLocal;

            return $shiftArray;
        })->toArray();
    }

    public function openAddModal()
    {
        $this->resetShiftForm();
        $this->showShiftModal = true;
    }

    public function openEditModal($shiftId)
    {
        // Reset form first to clear any previous unsaved changes
        $this->resetShiftForm();
        
        // Use withoutGlobalScope to bypass BranchScope when editing shifts from different branches
        // Always fetch fresh data from database to ensure we have the latest values
        $shift = BranchOperationalShift::withoutGlobalScope(BranchScope::class)->findOrFail($shiftId);
        $restaurantTimezone = $this->getRestaurantTimezone();

        $this->editingShiftId = $shift->id;
        $this->shiftName = $shift->shift_name ?? '';
        // Store UTC, edit in restaurant timezone.
        $this->startTime = $this->convertUtcTimeToRestaurantTime($shift->start_time, $restaurantTimezone);
        $this->endTime = $this->convertUtcTimeToRestaurantTime($shift->end_time, $restaurantTimezone);
        $days = $this->decodeDays($shift->day_of_week ?? []);

        // Convert 'All' to all 7 days for display
        if (in_array('All', $days)) {
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        }
        // Filter out 'All' and ensure selectedDays is properly initialized as an array.
        $this->selectedDays = $this->normalizeSelectedDays($days);
        // For display purposes, use first day or default to Monday
        $this->dayOfWeek = !empty($this->selectedDays) ? $this->selectedDays[0] : 'Monday';
        $this->isActive = $shift->is_active;
        $this->sortOrder = $shift->sort_order;
        $this->showShiftModal = true;
    }

    public function closeModal()
    {
        $this->showShiftModal = false;
        $this->resetShiftForm();
    }

    public function resetShiftForm()
    {
        $this->editingShiftId = null;
        $this->shiftName = '';
        $this->startTime = '09:00';
        $this->endTime = '17:00';
        $this->dayOfWeek = 'Monday';
        $this->selectedDays = [];
        $this->isActive = true;
        // Auto-generate sort order based on existing shifts count
        $this->sortOrder = count($this->shifts);
    }

    public function updatedSelectedDays($value)
    {
        // Filter out 'All' if it exists (legacy support)
        // Ensure selectedDays is a proper array
        $this->selectedDays = $this->normalizeSelectedDays($this->selectedDays);

        // Update dayOfWeek for display purposes
        $this->dayOfWeek = !empty($this->selectedDays) ? $this->selectedDays[0] : 'Monday';
    }

    public function saveShift()
    {
        $restaurantTimezone = $this->getRestaurantTimezone();

        // Filter out 'All' from selectedDays for validation
        $validDays = $this->normalizeSelectedDays($this->selectedDays);

        $this->validate([
            'startTime' => 'required|date_format:H:i',
            'endTime' => 'required|date_format:H:i',
            'shiftName' => 'required|string|max:255',
            'selectedDays' => 'required|array',
            'selectedDays.*' => 'string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'isActive' => 'boolean',
            'sortOrder' => 'integer|min:0',
        ], [
            'startTime.required' => __('validation.required', ['attribute' => __('modules.settings.startTime')]),
            'endTime.required' => __('validation.required', ['attribute' => __('modules.settings.endTime')]),
            'shiftName.required' => __('validation.required', ['attribute' => __('modules.settings.shiftName')]),
            'selectedDays.required' => __('validation.required', ['attribute' => __('modules.settings.dayOfWeek')]),
        ]);
        
        // Additional validation: ensure at least one valid day is selected (excluding 'All')
        if (empty($validDays)) {
            $this->addError('selectedDays', __('validation.required', ['attribute' => __('modules.settings.dayOfWeek')]));
            return;
        }

        // Ensure editingShiftId is properly set - if null, we're creating a new shift
        if ($this->editingShiftId) {
            // Update existing shift - use withoutGlobalScope to bypass BranchScope
            $shift = BranchOperationalShift::withoutGlobalScope(BranchScope::class)->findOrFail($this->editingShiftId);

            // Ensure selectedDays is a proper array and not empty
            $selectedDaysArray = $this->normalizeSelectedDays($this->selectedDays);

            // If empty or invalid, default to all days
            if (empty($selectedDaysArray)) {
                $daysToSave = ['All'];
            } else {
                $daysToSave = array_values(array_unique($selectedDaysArray)); // Re-index and remove duplicates
            }

            $shift->update([
                'shift_name' => $this->shiftName ?: null,
                'start_time' => $this->convertRestaurantTimeToUtcTime($this->startTime, $restaurantTimezone),
                'end_time' => $this->convertRestaurantTimeToUtcTime($this->endTime, $restaurantTimezone),
                'day_of_week' => $daysToSave, // Model will auto-encode via cast
                'is_active' => $this->isActive,
                'sort_order' => $this->sortOrder,
                'restaurant_id' => $this->branch->restaurant_id,
            ]);
            
            // Refresh the shift to ensure we have the latest data
            $shift->refresh();

            $this->alert('success', __('messages.shiftUpdated'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
            ]);
        } else {
            // Create new shift - auto-generate sort_order if not set
            $sortOrder = $this->sortOrder ?? count($this->shifts);

            // Ensure selectedDays is a proper array and not empty
            $selectedDaysArray = $this->normalizeSelectedDays($this->selectedDays);

            // If empty or invalid, default to all days
            $daysToSave = !empty($selectedDaysArray)
                ? array_values(array_unique($selectedDaysArray)) // Re-index array
                : ['All'];

            BranchOperationalShift::create([
                'branch_id' => $this->branch->id,
                'restaurant_id' => $this->branch->restaurant_id,
                'shift_name' => $this->shiftName ?: null,
                'start_time' => $this->convertRestaurantTimeToUtcTime($this->startTime, $restaurantTimezone),
                'end_time' => $this->convertRestaurantTimeToUtcTime($this->endTime, $restaurantTimezone),
                'day_of_week' => $daysToSave, // Model will auto-encode via cast
                'is_active' => $this->isActive,
                'sort_order' => $sortOrder,
            ]);

            $this->alert('success', __('messages.shiftCreated'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
            ]);
        }

        $this->loadShifts();
        $this->closeModal();
    }

    public function deleteShift($shiftId)
    {
        // Use withoutGlobalScope to bypass BranchScope when deleting shifts from different branches
        $shift = BranchOperationalShift::withoutGlobalScope(BranchScope::class)->findOrFail($shiftId);
        $shift->delete();

        $this->alert('success', __('messages.shiftDeleted'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
        ]);

        $this->loadShifts();
    }

    private function getRestaurantTimezone(): string
    {
        return $this->branch->restaurant?->timezone ?? self::DEFAULT_TIMEZONE;
    }

    private function convertRestaurantTimeToUtcTime(string $time, string $restaurantTimezone): string
    {
        return Carbon::now($restaurantTimezone)
            ->setTimeFromTimeString($time)
            ->setTimezone(self::DEFAULT_TIMEZONE)
            ->format('H:i:s');
    }

    private function convertUtcTimeToRestaurantTime(?string $time, string $restaurantTimezone): string
    {
        if (!$time) {
            return '00:00';
        }

        return Carbon::now(self::DEFAULT_TIMEZONE)
            ->setTimeFromTimeString($time)
            ->setTimezone($restaurantTimezone)
            ->format('H:i');
    }

    private function normalizeSelectedDays($days): array
    {
        $days = $this->decodeDays($days);

        return array_values(array_filter(array_unique($days), function ($day) {
            return !empty($day) && is_string($day) && $day !== 'All';
        }));
    }

    private function decodeDays($days): array
    {
        if (is_string($days)) {
            $days = json_decode($days, true) ?? [];
        }

        if (!is_array($days)) {
            $days = [];
        }

        return $days;
    }

    public function render()
    {
        // Sync with session branch if it changed (e.g., from sidebar)
        // Only sync if selectedBranchId matches session branch (meaning it was changed from sidebar)
        $currentBranchId = branch()->id;
        
        // If branch is not set, initialize from session
        if (!$this->branch) {
            $this->branch = Branch::where('id', $currentBranchId)->with('operationalShifts')->first();
            if ($this->branch) {
                $this->selectedBranchId = $this->branch->id;
                $this->loadShifts();
            } else {
                $this->shifts = [];
            }
        } 
        // If selectedBranchId is set and doesn't match current branch, update branch (manual selection from dropdown)
        elseif ($this->selectedBranchId && $this->selectedBranchId != $this->branch->id) {
            $this->branch = Branch::where('id', $this->selectedBranchId)->with('operationalShifts')->first();
            if ($this->branch) {
                $this->loadShifts();
            } else {
                $this->shifts = [];
            }
        }
        // If session branch changed and selectedBranchId matches session (changed from sidebar), sync
        elseif ($this->branch && $this->branch->id !== $currentBranchId && $this->selectedBranchId == $currentBranchId) {
            $this->branch = Branch::where('id', $currentBranchId)->with('operationalShifts')->first();
            if ($this->branch) {
                $this->selectedBranchId = $this->branch->id;
                $this->loadShifts();
            } else {
                $this->shifts = [];
            }
        }
        // Ensure shifts are loaded if branch exists but shifts are empty
        elseif ($this->branch && empty($this->shifts)) {
            $this->loadShifts();
        }

        // Get business day info for display (similar to Orders component)
        $businessDayInfo = null;
        if ($this->branch) {
            $boundaries = getBusinessDayBoundaries($this->branch, now());
            $restaurantTimezone = $this->branch->restaurant->timezone ?? 'UTC';
            $timeFormat = $this->branch->restaurant->time_format ?? 'h:i A';
            
            // Use business_day_end for display (shows full business day end, not "now")
            $displayEnd = isset($boundaries['business_day_end']) 
                ? $boundaries['business_day_end'] 
                : $boundaries['end'];
            
            $businessDayStart = $boundaries['start'];
            $calendarDate = $boundaries['calendar_date'];
            
            // If business day ends on next calendar day, show info
            if ($displayEnd->toDateString() !== $calendarDate) {
                $businessDayInfo = [
                    'start' => $businessDayStart->format($timeFormat),
                    'end' => $displayEnd->format($timeFormat),
                    'end_date' => $displayEnd->toDateString(),
                    'calendar_date' => $calendarDate,
                    'extends_to_next_day' => true,
                ];
            } else {
                // Business day is within same calendar day
                $businessDayInfo = [
                    'start' => $businessDayStart->format($timeFormat),
                    'end' => $displayEnd->format($timeFormat),
                    'end_date' => $displayEnd->toDateString(),
                    'calendar_date' => $calendarDate,
                    'extends_to_next_day' => false,
                ];
            }
        }

        return view('livewire.settings.branch-operational-shifts', [
            'businessDayInfo' => $businessDayInfo,
            'branch' => $this->branch, // Ensure branch is passed to view
            'branchName' => $this->branchName, // Pass computed branch name for reactivity
            'shifts' => $this->shifts, // Explicitly pass shifts to ensure reactivity
        ]);
    }
}
