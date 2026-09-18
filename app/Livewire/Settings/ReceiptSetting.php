<?php

namespace App\Livewire\Settings;

use App\Helper\Files;
use App\Models\Branch;
use Livewire\Component;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ReceiptSetting extends Component
{
    use LivewireAlert;
    use WithFileUploads;
    public $settings;
    public bool $customerName;
    public bool $customerAddress;
    public bool $customerPhone;
    public bool $tableNumber;
    public $paymentQrCode;
    public bool $waiter;
    public bool $totalGuest;
    public bool $restaurantLogo;
    public bool $showRestaurantName;
    public bool $showBranchName;
    public bool $showBranchAddress;
    public $receiptSetting;
    public bool $restaurantTax;
    public bool $showTax;
    public bool $showCrNumber;
    public bool $showVatNumber;
    public bool $showPaymentQrCode;
    public bool $showPaymentDetails;
    public bool $showPaymentStatus;
    public bool $showOrderType;
    public array $receiptLanguages = [];
    public array $availableReceiptLanguages = [];
    public ?string $footerText = null;

    public function mount()
    {
        $this->receiptSetting = branch()->receiptSetting()->first();
        $this->availableReceiptLanguages = languages()->pluck('language_name', 'language_code')->toArray();
        $this->customerName = (bool)$this->receiptSetting->show_customer_name;
        $this->customerAddress = (bool)$this->receiptSetting->show_customer_address;
        $this->customerPhone = (bool)$this->receiptSetting->show_customer_phone;
        $this->tableNumber = (bool)$this->receiptSetting->show_table_number;
        $this->showPaymentQrCode = (bool)$this->receiptSetting->show_payment_qr_code;
        $this->waiter = (bool)$this->receiptSetting->show_waiter;
        $this->totalGuest = (bool)$this->receiptSetting->show_total_guest;
        $this->restaurantLogo = (bool)$this->receiptSetting->show_restaurant_logo;
        $this->showRestaurantName = (bool)$this->receiptSetting->show_restaurant_name;
        $this->showBranchName = (bool)$this->receiptSetting->show_branch_name;
        $this->showBranchAddress = (bool)$this->receiptSetting->show_branch_address;
        $this->restaurantTax = (bool)$this->receiptSetting->show_tax;
        $this->showCrNumber = (bool)$this->receiptSetting->show_cr_number;
        $this->showVatNumber = (bool)$this->receiptSetting->show_vat_number;
        $this->showPaymentDetails = (bool)$this->receiptSetting->show_payment_details;
        $this->showPaymentStatus = (bool)$this->receiptSetting->show_payment_status;
        $this->paymentQrCode = $this->receiptSetting->payment_qr_code_url;
        $this->showOrderType = (bool)$this->receiptSetting->show_order_type;
        $this->receiptLanguages = array_values($this->receiptSetting->receipt_languages ?? [restaurant()->customer_site_language ?? 'en']);
        $this->footerText = $this->receiptSetting->footer_text;
    }

    public function updatedPaymentQrCode()
    {
        $this->validatePaymentQrCode();
    }

    public function validatePaymentQrCode()
    {
        // Clear any existing errors for this field
        $this->resetErrorBag('paymentQrCode');

        if ($this->paymentQrCode instanceof TemporaryUploadedFile) {
            // Validate image dimensions
            $imageInfo = @getimagesize($this->paymentQrCode->getRealPath());
            if ($imageInfo) {
                $width = $imageInfo[0];
                $height = $imageInfo[1];

                // Only show error if dimensions are smaller than recommended (200 × 200)
                // Images larger than recommended size are acceptable and will not show an error
                if ($width < 200 || $height < 200) {
                    $this->addError('paymentQrCode', __('modules.settings.imageDimensionsTooSmall', [
                        'width' => 200,
                        'height' => 200,
                        'currentWidth' => $width,
                        'currentHeight' => $height
                    ]));
                }
            }
        }
    }

    public function submitForm()
    {

        $data = [
            'show_customer_name' => $this->customerName,
            'show_customer_address' => $this->customerAddress,
            'show_customer_phone' => $this->customerPhone,
            'show_table_number' => $this->tableNumber,
            'show_payment_qr_code' => $this->showPaymentQrCode,
            'show_waiter' => $this->waiter,
            'show_total_guest' => $this->totalGuest,
            'show_restaurant_logo' => $this->restaurantLogo,
            'show_restaurant_name' => $this->showRestaurantName,
            'show_branch_name' => $this->showBranchName,
            'show_branch_address' => $this->showBranchAddress,
            'show_tax' => $this->restaurantTax,
            'show_cr_number' => $this->showCrNumber,
            'show_vat_number' => $this->showVatNumber,
            'show_payment_details' => $this->showPaymentDetails,
            'show_payment_status' => $this->showPaymentStatus,
            'show_order_type' => $this->showOrderType,
            'footer_text' => \App\Models\ReceiptSetting::footerTextToPlain($this->footerText) !== ''
                ? trim((string) $this->footerText)
                : null,
        ];

        if (count($this->availableReceiptLanguages) > 1) {
            $validLanguageCodes = array_keys($this->availableReceiptLanguages);
            $selectedReceiptLanguages = array_values(array_intersect($validLanguageCodes, $this->receiptLanguages));

            if (empty($selectedReceiptLanguages)) {
                $this->addError('receiptLanguages', __('validation.required', ['attribute' => __('modules.settings.receiptLanguages')]));
                return;
            }

            $data['receipt_languages'] = $selectedReceiptLanguages;
        }

        if ($this->showPaymentQrCode && !$this->paymentQrCode) {
            $this->addError('paymentQrCode', __('messages.paymentQrCodeRequired'));
            return;
        }

        // Validate QR code dimensions if a new file is provided
        if ($this->paymentQrCode instanceof TemporaryUploadedFile) {
            $this->validatePaymentQrCode();

            // Check if there are validation errors
            if ($this->getErrorBag()->has('paymentQrCode')) {
                return;
            }
        }

        // Handle QR Code upload only if a new file is provided
        if ($this->paymentQrCode instanceof TemporaryUploadedFile) {
            $data['payment_qr_code'] = Files::uploadLocalOrS3(
                $this->paymentQrCode,
                'payment_qr_code',
                200,
                200,
                oldFilename: $this->receiptSetting->payment_qr_code
            );
        }

        $this->receiptSetting->update($data);

        // Refresh the session branch with a fresh DB instance so its cached relationships
        // (receiptSetting, etc.) are cleared everywhere, not just in this component.
        session(['branch' => Branch::find(branch()->id)]);

        $this->receiptSetting = $this->receiptSetting->fresh();
        $this->paymentQrCode = $this->receiptSetting->payment_qr_code_url;


        $this->dispatch('settingsUpdated');

        $this->alert('success', __('messages.settingsUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close'),
        ]);
    }

    public function render()
    {
        return view('livewire.settings.receipt-setting');
    }
}
