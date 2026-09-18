<?php

namespace App\Livewire\Customer;

use App\Models\Order;
use Livewire\Component;
use App\Models\Customer;
use Livewire\Attributes\On;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use App\Models\Country;
use App\Helper\Common;
use App\Models\User;

class AddCustomer extends Component
{
    use LivewireAlert;

    public $order;
    public $customerName;
    public $customerPhone;
    public $customerEmail;
    public $availableResults = [];
    public $customerAddress;
    public $showAddCustomerModal = false;
    public $fromPos;
    public $selectedCustomerId = null;
    public $customerPhoneCode;
    public $phoneCodeDetected = false;
    public $phoneCodeSearch = '';
    public $phoneCodeIsOpen = false;
    public $allPhoneCodes;
    public $filteredPhoneCodes;
    public $searchQuery = '';
    public $editingFields = [
        'name' => false,
        'phone' => false,
        'email' => false,
        'address' => false
    ];
    public $restaurantPhoneCode;


    public function mount()
    {
        // Initialize phone codes
        $this->allPhoneCodes = collect(Country::pluck('phonecode')->unique()->filter()->values());
        $this->filteredPhoneCodes = $this->allPhoneCodes;

        $detectedPhoneCode = (new User())->getPhoneCodeFromIp();
        $this->phoneCodeDetected = !empty($detectedPhoneCode);

        // Set default phone code (prefer detected, fallback to restaurant)
        $this->restaurantPhoneCode = $detectedPhoneCode
            ?? restaurant()->country->phonecode
            ?? $this->allPhoneCodes->first();
        $this->customerPhoneCode = $this->restaurantPhoneCode;

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
        $this->customerPhoneCode = $phonecode;
        $this->phoneCodeIsOpen = false;
        $this->phoneCodeSearch = '';
        $this->updatedPhoneCodeSearch();
    }

    #[On('showAddCustomerModal')]
    public function showAddCustomer($id = null, $customerId = null, $fromPos = false)
    {
        $this->resetAddCustomerFormState();

        if (!is_null($id)) {
            $this->order = Order::find($id);
        }

        if (!is_null($customerId)) {
            $customer = Customer::find($customerId);
            $this->selectedCustomerId = $customerId;
            if ($customer) {
                $this->customerName = $customer->name;
                $this->customerPhone = $customer->phone;
                $this->customerPhoneCode = $customer->phone_code;
                $this->customerEmail = $customer->email;
                $this->customerAddress = $customer->delivery_address;
            }
        } else {
            $detectedPhoneCode = (new User())->getPhoneCodeFromIp();
            $this->phoneCodeDetected = !empty($detectedPhoneCode);
            $this->customerPhoneCode = $detectedPhoneCode
                ?? restaurant()->country->phonecode
                ?? $this->allPhoneCodes->first();
        }
        $this->fromPos = $fromPos ?? false;
    }

    public function updatedSearchQuery()
    {
        if (strlen($this->searchQuery) >= 2) {
            $this->availableResults = $this->fetchSearchResults();
        } else {
            $this->availableResults = [];
        }
    }

    public function updatedCustomerPhone()
    {
        if (strlen($this->customerPhone) >= 2) {
            $this->availableResults = $this->fetchSearchResults();
        } else {
            $this->availableResults = [];
        }
    }

    public function fetchSearchResults()
    {
        $searchTerm = $this->searchQuery ?: $this->customerPhone;

        if (empty($searchTerm)) {
            return collect();
        }

        $results = Customer::where('restaurant_id', restaurant()->id)
            ->where(function ($query) use ($searchTerm) {
                $safeTerm = Common::safeString($searchTerm);
                $query->where('name', 'like', '%' . $safeTerm . '%')
                    ->orWhere('phone', 'like', '%' . $safeTerm . '%')
                    ->orWhere('email', 'like', '%' . $safeTerm . '%');
            })
            ->orderBy('name')
            ->limit(10)
            ->get();

        return $results;
    }

    public function selectCustomer($customerId)
    {
        $customer = Customer::find($customerId);

        if ($customer) {
            $this->selectedCustomerId = $customer->id;
            $this->customerName = $customer->name;
            $this->customerPhone = $customer->phone;
            $this->customerPhoneCode = $customer->phone_code;
            $this->customerEmail = $customer->email;
            $this->customerAddress = $customer->delivery_address;
            $this->searchQuery = ''; // Clear the search query
            $this->availableResults = []; // Clear the results

            // Reset all fields to readonly when selecting a customer
            $this->editingFields = [
                'name' => false,
                'phone' => false,
                'email' => false,
                'address' => false
            ];
        }
    }

    public function createNewCustomer()
    {
        // Store the search query before clearing it
        $searchTerm = $this->searchQuery;

        // Clear the search and focus on creating a new customer
        $this->searchQuery = '';
        $this->availableResults = [];
        $this->selectedCustomerId = null;

        $detectedPhoneCode = (new User())->getPhoneCodeFromIp();
        $this->phoneCodeDetected = !empty($detectedPhoneCode);
        $this->customerPhoneCode = $detectedPhoneCode
            ?? restaurant()->phone_code
            ?? $this->allPhoneCodes->first();

        // Make all fields editable when creating new customer
        $this->editingFields = [
            'name' => true,
            'phone' => true,
            'email' => true,
            'address' => true
        ];

        // Pre-fill the name field with the search term if it looks like a name
        if (!empty($searchTerm) && !preg_match('/\d/', $searchTerm)) {
            $this->customerName = $searchTerm;
        }
    }

    public function clearSelection()
    {
        // Clear the selected customer but keep the form data
        $this->selectedCustomerId = null;
        $this->searchQuery = '';
        $this->availableResults = [];

        $detectedPhoneCode = (new User())->getPhoneCodeFromIp();
        $this->phoneCodeDetected = !empty($detectedPhoneCode);
        $this->customerPhoneCode = $detectedPhoneCode
            ?? restaurant()->phone_code
            ?? $this->allPhoneCodes->first();

        // Make all fields editable when creating new customer
        $this->editingFields = [
            'name' => true,
            'phone' => true,
            'email' => true,
            'address' => true
        ];
    }

    public function toggleFieldEdit($field)
    {
        if (isset($this->editingFields[$field])) {
            $this->editingFields[$field] = !$this->editingFields[$field];
        }
    }

    public function submitForm()
    {
        // Validate with custom rule for duplicate email check only
        $this->validate([
            'customerName' => 'required|string|max:255',
            'customerPhoneCode' => 'required',
            'customerPhone' => [
                'required',
                'regex:/^[0-9\s]{5,20}$/',
            ],
            'customerEmail' => [
                'nullable',
                'email',
                function ($attribute, $value, $fail) {
                    if (!empty($value)) {
                        $exists = Customer::where('restaurant_id', restaurant()->id)
                            ->where('email', $value)
                            ->when($this->selectedCustomerId, fn($q) => $q->where('id', '!=', $this->selectedCustomerId))
                            ->exists();
                        if ($exists) {
                            $fail(__('validation.unique', ['attribute' => __('app.email')]));
                        }
                    }
                }
            ],
            'customerAddress' => 'nullable|string|max:500',
        ]);

        // Check for existing customer by phone only
        $existingCustomer = null;

        //commented this code because when customer is creating new customer so if user have same phone it was updating that existing customer details.
        // if (!empty($this->customerPhone)) {
        //     $existingCustomer = Customer::where('restaurant_id', restaurant()->id)
        //         ->where('phone', $this->customerPhone)
        //         ->first();
        // }

        // If not found by phone, try to find by email
        if (!$existingCustomer && !empty($this->customerEmail)) {
            $existingCustomer = Customer::where('restaurant_id', restaurant()->id)
                ->where('email', $this->customerEmail)
                ->first();
        }

        $customerData = [
            'name' => $this->customerName,
            'phone' => $this->customerPhone,
            'phone_code' => $this->customerPhoneCode,
        ];

        foreach (
            [
                'phone' => $this->customerPhone,
                'email' => $this->customerEmail,
                'delivery_address' => $this->customerAddress
            ] as $field => $value
        ) {
            if (!empty($value)) {
                $customerData[$field] = $value;
            }
        }

        if (!empty($this->customerAddress)) {
            $customerData['delivery_address'] = $this->customerAddress;
        }

        // Update existing customer by phone or create new one
        if ($existingCustomer) {
            $customer = tap($existingCustomer)->update($customerData);
        } else {
            $customerData['restaurant_id'] = restaurant()->id;
            $customer = Customer::create($customerData);
        }

        if (!is_null($this->order)) {
            $this->order->customer_id = $customer->id;
            $this->order->delivery_address = $this->customerAddress;
            $this->order->save();

            if (!$this->fromPos) {
                $this->dispatch('showOrderDetail', id: $this->order->id);
            }
            $this->dispatch('refreshOrders');
            $this->dispatch('refreshPos');
        }
            $this->dispatch('customerSelected', customer: $customer);

        $this->resetForm();
    }

    public function resetSearch()
    {
        $this->availableResults = [];
        $this->searchQuery = '';
    }

    public function resetForm()
    {
        $this->resetAddCustomerFormState();
        $this->js("window.dispatchEvent(new CustomEvent('add-customer-modal-close'))");
    }

    /**
     * Clear customer form fields (no modal close). Used on every open and after successful save.
     */
    protected function resetAddCustomerFormState(): void
    {
        $this->customerName = '';
        $this->customerPhone = '';
        $this->customerPhoneCode = restaurant()->phone_code ?? $this->allPhoneCodes->first();
        $this->customerEmail = '';
        $this->customerAddress = '';
        $this->searchQuery = '';
        $this->availableResults = [];
        $this->selectedCustomerId = null;
        $this->editingFields = [
            'name' => false,
            'phone' => false,
            'email' => false,
            'address' => false
        ];
    }

    public function render()
    {
        return view('livewire.customer.add-customer', [
            'phonecodes' => $this->filteredPhoneCodes,
        ]   );
    }
}
