<?php

namespace App\Livewire\Forms;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Country;
use App\Models\User;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;

class EditCustomer extends Component
{
    use LivewireAlert;

    public $customer;

    public $customerName;
    public $customerEmail;
    public $customerPhone;
    public $customerPhoneCode;

    /** Working copy for the active address tab (map + textarea bind here). */
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
        'confirmRemoveAddressTab' => 'confirmRemoveAddressTab',
        'dismissRemoveAddressTab' => 'dismissRemoveAddressTab',
        'editCustomerSave' => 'editFrontFeature',
    ];

    public function mount(): void
    {
        $this->customer = Customer::query()
            ->with(['addresses', 'latestDeliveryAddress'])
            ->findOrFail($this->customer->id);

        $this->customerPhone = $this->customer->phone;
        $this->customerName = $this->customer->name;
        $this->customerEmail = $this->customer->email;
        $this->customerPhoneCode = $this->customer->phone_code;

        $this->loadCustomerAddresses();

        $this->activeAddressTab = 0;
        $this->loadWorkingAddressFromArray();

        $this->allPhoneCodes = collect(Country::pluck('phonecode')->unique()->filter()->values());
        $this->filteredPhoneCodes = $this->allPhoneCodes;

        $detectedPhoneCode = (new User())->getPhoneCodeFromIp();
        $this->phoneCodeDetected = empty($this->customerPhoneCode) && !empty($detectedPhoneCode);
        if (empty($this->customerPhoneCode)) {
            $this->customerPhoneCode = $detectedPhoneCode
                ?? restaurant()->phone_code
                ?? $this->allPhoneCodes->first();
        }

        $this->js('setTimeout(() => window.refreshCustomerEditMapOnOpen?.(), 250)');
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
            'onConfirmed' => 'confirmRemoveAddressTab',
            'onDismissed' => 'dismissRemoveAddressTab',
        ]);
    }

    public function confirmRemoveAddressTab(): void
    {
        $index = $this->addressPendingDeleteIndex;
        $this->addressPendingDeleteIndex = null;

        if ($index === null || !isset($this->addresses[$index]) || count($this->addresses) < 2) {
            return;
        }

        $this->removeAddressTab($index);
    }

    public function dismissRemoveAddressTab(): void
    {
        $this->addressPendingDeleteIndex = null;
    }

    public function removeAddressTab(int $index): void
    {
        if (!isset($this->addresses[$index]) || count($this->addresses) < 2) {
            return;
        }

        $this->syncCurrentAddressRow();

        $row = $this->addresses[$index];
        if (!empty($row['id'])) {
            CustomerAddress::where('customer_id', $this->customer->id)
                ->where('id', $row['id'])
                ->delete();
        }

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

    public function editFrontFeature(): void
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
                            ->where('id', '!=', $this->customer->id)
                            ->exists();
                        if ($exists) {
                            $fail(__('validation.unique', ['attribute' => __('app.email')]));
                        }
                    }
                },
            ],
            'customerPhoneCode' => 'required',
            'customerPhone' => 'required',
            'addresses' => 'array',
            'addresses.*.label' => 'nullable|string|max:100',
            'addresses.*.address' => 'nullable|string|max:10000',
            'addresses.*.lat' => 'nullable|numeric|between:-90,90',
            'addresses.*.lng' => 'nullable|numeric|between:-180,180',
        ]);

        $this->customer->name = $this->customerName;
        $this->customer->email = $this->customerEmail ?? null;
        $this->customer->phone = $this->customerPhone;
        $this->customer->phone_code = $this->customerPhoneCode;

        $primaryLine = collect($this->addresses)
            ->pluck('address')
            ->first(fn ($a) => filled($a));
        $this->customer->delivery_address = $primaryLine;

        $this->customer->save();

        foreach ($this->addresses as $row) {
            $id = $row['id'] ?? null;
            $label = isset($row['label']) && $row['label'] !== '' ? $row['label'] : null;
            $addressLine = isset($row['address']) && $row['address'] !== '' ? $row['address'] : null;
            $lat = isset($row['lat']) && $row['lat'] !== null && $row['lat'] !== '' ? (float) $row['lat'] : null;
            $lng = isset($row['lng']) && $row['lng'] !== null && $row['lng'] !== '' ? (float) $row['lng'] : null;
            $empty = $addressLine === null && $lat === null && $lng === null && !filled($label);

            if ($id) {
                $ca = CustomerAddress::where('customer_id', $this->customer->id)->where('id', $id)->first();
                if ($ca) {
                    if ($empty) {
                        $ca->delete();
                    } else {
                        $ca->update([
                            'label' => $label,
                            'address' => $addressLine,
                            'lat' => $lat,
                            'lng' => $lng,
                        ]);
                    }
                }
            } elseif (!$empty) {
                CustomerAddress::create([
                    'customer_id' => $this->customer->id,
                    'label' => $label,
                    'address' => $addressLine,
                    'lat' => $lat,
                    'lng' => $lng,
                ]);
            }
        }

        $this->dispatch('refreshCustomers');
        $this->dispatch('hideEditCustomer');
    }

    public function render()
    {
        return view('livewire.forms.edit-customer', [
            'phonecodes' => $this->filteredPhoneCodes ?? collect(),
            'mapApiKey' => global_setting()->google_map_api_key ?? null,
            'mapProvider' => global_setting()->map_provider ?? 'google',
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

    protected function loadCustomerAddresses(): void
    {
        $rows = $this->customer->addresses->sortBy('id')->values();

        $this->addresses = $rows->map(function ($address) {
            return $this->normalizeAddressRow(
                $address->id,
                $address->label ?? '',
                $address->address,
                $address->lat,
                $address->lng
            );
        })->values()->all();

        $hasUsableAddress = collect($this->addresses)->contains(function ($row) {
            return filled($row['address'] ?? '')
                || ($row['lat'] !== null && $row['lng'] !== null);
        });

        if (!$hasUsableAddress) {
            $latest = $this->customer->latestDeliveryAddress;
            $fallbackAddress = trim((string) ($latest?->address ?? $this->customer->delivery_address ?? ''));

            if (filled($fallbackAddress) || ($latest?->lat !== null && $latest?->lng !== null)) {
                $this->addresses = [
                    $this->normalizeAddressRow(
                        $latest?->id,
                        $latest?->label ?? '',
                        $fallbackAddress !== '' ? $fallbackAddress : null,
                        $latest?->lat,
                        $latest?->lng
                    ),
                ];
            }
        }

        if (empty($this->addresses)) {
            $this->addresses = [[
                'id' => null,
                'label' => '',
                'address' => '',
                'lat' => null,
                'lng' => null,
            ]];
        }
    }

    protected function normalizeAddressRow(?int $id, string $label, ?string $address, mixed $lat, mixed $lng): array
    {
        $addressLine = trim((string) ($address ?? ''));

        if ($addressLine === '' && $lat !== null && $lat !== '' && $lng !== null && $lng !== '') {
            $addressLine = $lat . ', ' . $lng;
        }

        return [
            'id' => $id,
            'label' => $label,
            'address' => $addressLine,
            'lat' => $lat !== null && $lat !== '' ? $lat : null,
            'lng' => $lng !== null && $lng !== '' ? $lng : null,
        ];
    }

    protected function dispatchMapRebuild(): void
    {
        $this->js('setTimeout(() => window.refreshCustomerEditMapOnOpen?.(), 150)');
    }
}
