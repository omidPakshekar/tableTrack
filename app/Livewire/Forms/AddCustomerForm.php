<?php

namespace App\Livewire\Forms;

use App\Models\Country;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;

class AddCustomerForm extends Component
{
    use LivewireAlert;

    public $customerName;
    public $customerEmail;
    public $customerPhone;
    public $customerPhoneCode;

    /** Working copy for the active address tab (map + textarea). */
    public $customerAddress;
    public $customerLat;
    public $customerLng;
    public $addressLabel;

    /** @var array<int, array{id: int|null, label: string, address: string, lat: mixed, lng: mixed}> */
    public $addresses = [];

    public int $activeAddressTab = 0;

    public ?int $addressPendingDeleteIndex = null;

    public $phoneCodeDetected = false;
    public $phoneCodeSearch = '';
    public $phoneCodeIsOpen = false;
    public $allPhoneCodes;
    public $filteredPhoneCodes;

    protected $listeners = [
        'confirmRemoveAddCustomerAddressTab' => 'confirmRemoveAddCustomerAddressTab',
        'dismissRemoveAddCustomerAddressTab' => 'dismissRemoveAddCustomerAddressTab',
    ];

    public function mount(): void
    {
        $this->initializePhoneCodes();
        $this->ensureDefaultAddressTabs();
        $this->seedDefaultMapCenterFromBranch();

        $this->js('setTimeout(() => window.refreshCustomerAddMapOnOpen?.(), 100)');
    }

    private function seedDefaultMapCenterFromBranch(): void
    {
        $branch = function_exists('branch') ? branch() : null;

        if (! $branch || $branch->lat === null || $branch->lng === null) {
            return;
        }

        $this->customerLat = (float) $branch->lat;
        $this->customerLng = (float) $branch->lng;

        if (isset($this->addresses[$this->activeAddressTab])) {
            $this->addresses[$this->activeAddressTab]['lat'] = $this->customerLat;
            $this->addresses[$this->activeAddressTab]['lng'] = $this->customerLng;
        }
    }

    private function initializePhoneCodes(): void
    {
        $this->allPhoneCodes = collect(Country::pluck('phonecode')->unique()->filter()->values());
        $this->filteredPhoneCodes = $this->allPhoneCodes;

        $detectedPhoneCode = (new User())->getPhoneCodeFromIp();
        $this->phoneCodeDetected = !empty($detectedPhoneCode);

        $this->customerPhoneCode = $detectedPhoneCode
            ?? restaurant()->phone_code
            ?? $this->allPhoneCodes->first();
    }

    private function ensureDefaultAddressTabs(): void
    {
        if (empty($this->addresses)) {
            $this->addresses = [[
                'id' => null,
                'label' => '',
                'address' => '',
                'lat' => null,
                'lng' => null,
            ]];
        }
        $this->activeAddressTab = min($this->activeAddressTab, max(0, count($this->addresses) - 1));
        $this->loadWorkingAddressFromArray();
    }

    public function updatedCustomerAddress(): void
    {
        $this->syncCurrentAddressRow();
    }

    public function updatedCustomerLat(): void
    {
        $this->syncCurrentAddressRow();
    }

    public function updatedCustomerLng(): void
    {
        $this->syncCurrentAddressRow();
    }

    public function updatedAddressLabel(): void
    {
        $this->syncCurrentAddressRow();
    }

    public function selectAddressTab(int $index): void
    {
        if (!isset($this->addresses[$index])) {
            return;
        }
        $this->syncCurrentAddressRow();
        $this->activeAddressTab = $index;
        $this->loadWorkingAddressFromArray();
        $this->dispatchMapRebuild();
    }

    public function addAddressTab(): void
    {
        $this->syncCurrentAddressRow();
        $this->addresses[] = [
            'id' => null,
            'label' => '',
            'address' => '',
            'lat' => null,
            'lng' => null,
        ];
        $this->activeAddressTab = count($this->addresses) - 1;
        $this->loadWorkingAddressFromArray();
        $this->dispatchMapRebuild();
    }

    public function requestRemoveAddressTab(int $index): void
    {
        if (!isset($this->addresses[$index]) || count($this->addresses) < 2) {
            return;
        }

        $this->addressPendingDeleteIndex = $index;

        $this->confirm(__('modules.delivery.confirmDeleteAddress'), [
            'text' => __('modules.delivery.confirmDeleteAddressDescription'),
            'confirmButtonText' => __('app.yes'),
            'cancelButtonText' => __('app.cancel'),
            'onConfirmed' => 'confirmRemoveAddCustomerAddressTab',
            'onDismissed' => 'dismissRemoveAddCustomerAddressTab',
        ]);
    }

    public function confirmRemoveAddCustomerAddressTab(): void
    {
        $index = $this->addressPendingDeleteIndex;
        $this->addressPendingDeleteIndex = null;

        if ($index === null || !isset($this->addresses[$index]) || count($this->addresses) < 2) {
            return;
        }

        $this->removeAddressTab($index);
    }

    public function dismissRemoveAddCustomerAddressTab(): void
    {
        $this->addressPendingDeleteIndex = null;
    }

    public function removeAddressTab(int $index): void
    {
        if (!isset($this->addresses[$index]) || count($this->addresses) < 2) {
            return;
        }

        $this->syncCurrentAddressRow();

        array_splice($this->addresses, $index, 1);

        if ($this->activeAddressTab >= count($this->addresses)) {
            $this->activeAddressTab = count($this->addresses) - 1;
        } elseif ($index < $this->activeAddressTab) {
            $this->activeAddressTab--;
        }

        $this->loadWorkingAddressFromArray();
        $this->dispatchMapRebuild();
    }

    public function updatedPhoneCodeIsOpen($value): void
    {
        if (!$value) {
            $this->reset(['phoneCodeSearch']);
            $this->updatedPhoneCodeSearch();
        }
    }

    public function updatedPhoneCodeSearch(): void
    {
        $this->filteredPhoneCodes = $this->allPhoneCodes->filter(function ($phonecode) {
            return str_contains($phonecode, $this->phoneCodeSearch);
        })->values();
    }

    public function selectPhoneCode($phonecode): void
    {
        $this->customerPhoneCode = $phonecode;
        $this->phoneCodeIsOpen = false;
        $this->phoneCodeSearch = '';
        $this->updatedPhoneCodeSearch();
    }

    public function submitForm(): void
    {
        $this->syncCurrentAddressRow();

        $this->validate([
            'customerName' => 'required|string|max:255',
            'customerEmail' => [
                'nullable',
                'email',
                function ($attribute, $value, $fail) {
                    if (!empty($value)) {
                        $exists = Customer::where('restaurant_id', restaurant()->id)
                            ->where('email', $value)
                            ->exists();
                        if ($exists) {
                            $fail(__('validation.unique', ['attribute' => __('app.email')]));
                        }
                    }
                },
            ],
            'customerPhoneCode' => 'required',
            'customerPhone' => [
                'required',
                function ($attribute, $value, $fail) {
                    $exists = Customer::where('restaurant_id', restaurant()->id)
                        ->where('phone', $value)
                        ->exists();
                    if ($exists) {
                        $fail(__('validation.unique', ['attribute' => __('app.phone')]));
                    }
                },
            ],
            'addresses' => 'array',
            'addresses.*.label' => 'nullable|string|max:100',
            'addresses.*.address' => 'nullable|string|max:10000',
            'addresses.*.lat' => 'nullable|numeric|between:-90,90',
            'addresses.*.lng' => 'nullable|numeric|between:-180,180',
        ]);

        $primaryLine = collect($this->addresses)
            ->pluck('address')
            ->first(fn ($a) => filled($a));

        DB::transaction(function () use ($primaryLine) {
            $customer = Customer::create([
                'name' => $this->customerName,
                'email' => $this->customerEmail ?? null,
                'phone' => $this->customerPhone,
                'phone_code' => $this->customerPhoneCode,
                'delivery_address' => $primaryLine,
            ]);

            foreach ($this->addresses as $row) {
                $label = isset($row['label']) && $row['label'] !== '' ? $row['label'] : null;
                $addressLine = isset($row['address']) && $row['address'] !== '' ? $row['address'] : null;
                $lat = isset($row['lat']) && $row['lat'] !== null && $row['lat'] !== '' ? (float) $row['lat'] : null;
                $lng = isset($row['lng']) && $row['lng'] !== null && $row['lng'] !== '' ? (float) $row['lng'] : null;
                $empty = $addressLine === null && $lat === null && $lng === null && !filled($label);

                if (!$empty) {
                    CustomerAddress::create([
                        'customer_id' => $customer->id,
                        'label' => $label,
                        'address' => $addressLine,
                        'lat' => $lat,
                        'lng' => $lng,
                    ]);
                }
            }
        });

        $this->alert('success', __('messages.customerAdded'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close'),
        ]);

        // Tell any customer listing components to refresh immediately.
        $this->dispatch('refreshCustomers');

        $this->resetForm();
        $this->js("window.dispatchEvent(new CustomEvent('close-add-customer-modal'))");
    }

    public function resetModalState(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->customerName = '';
        $this->customerEmail = '';
        $this->customerPhone = '';
        $this->customerAddress = '';
        $this->customerLat = null;
        $this->customerLng = null;
        $this->addressLabel = '';
        $this->addresses = [[
            'id' => null,
            'label' => '',
            'address' => '',
            'lat' => null,
            'lng' => null,
        ]];
        $this->activeAddressTab = 0;
        $this->addressPendingDeleteIndex = null;
        $this->phoneCodeSearch = '';
        $this->phoneCodeIsOpen = false;

        $this->initializePhoneCodes();
        $this->seedDefaultMapCenterFromBranch();

        $this->js("window.destroyCustomerAddMap?.(); const inp = document.getElementById('customer-add-location-search-input'); if (inp) inp.removeAttribute('data-add-search-wired');");
    }

    public function render()
    {
        $branch = branch();

        return view('livewire.forms.add-customer-form', [
            'phonecodes' => $this->filteredPhoneCodes ?? collect(),
            'mapApiKey' => global_setting()->google_map_api_key ?? null,
            'mapProvider' => global_setting()->map_provider ?? 'google',
            'branchLat' => $branch?->lat,
            'branchLng' => $branch?->lng,
        ]);
    }

    protected function syncCurrentAddressRow(): void
    {
        if (!isset($this->addresses[$this->activeAddressTab])) {
            return;
        }
        $this->addresses[$this->activeAddressTab]['address'] = $this->customerAddress ?? '';
        $this->addresses[$this->activeAddressTab]['label'] = $this->addressLabel ?? '';
        $this->addresses[$this->activeAddressTab]['lat'] = $this->customerLat !== null && $this->customerLat !== '' ? $this->customerLat : null;
        $this->addresses[$this->activeAddressTab]['lng'] = $this->customerLng !== null && $this->customerLng !== '' ? $this->customerLng : null;
    }

    protected function loadWorkingAddressFromArray(): void
    {
        $row = $this->addresses[$this->activeAddressTab] ?? null;
        if (!$row) {
            return;
        }
        $this->customerAddress = $row['address'] ?? '';
        $this->addressLabel = $row['label'] ?? '';
        $this->customerLat = $row['lat'] ?? null;
        $this->customerLng = $row['lng'] ?? null;
    }

    protected function dispatchMapRebuild(): void
    {
        $this->js("window.dispatchEvent(new CustomEvent('customer-add-map-rebuild'))");
    }
}
