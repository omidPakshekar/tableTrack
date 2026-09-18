<?php

namespace App\Livewire\Settings;

use Livewire\Component;
use App\Models\GlobalCurrency;
use Livewire\Attributes\On;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use App\Models\GlobalInvoice;
use App\Models\GlobalSubscription;
use App\Models\Package;

class SuperadminCurrencySettings extends Component
{
    use LivewireAlert;

    protected $listeners = ['refreshCurrencies' => 'mount'];

    public $currencies;
    public $currency;
    public $showEditCurrencyModal = false;
    public $showAddCurrencyModal = false;
    public $confirmDeleteCurrencyModal = false;

    public function mount()
    {
        $this->currencies = GlobalCurrency::get();
    }

    public function showAddCurrency()
    {
        $this->showAddCurrencyModal = true;
    }

    public function showEditCurrency($id)
    {
        $this->currency = GlobalCurrency::findOrFail($id);
        $this->showEditCurrencyModal = true;
    }

    public function showDeleteCurrency($id)
    {
        if ((int) global_setting()->default_currency_id === (int) $id) {
            $this->addError('currency_error', '<strong>' . __('messages.currencyCannotBeDeleted') . '</strong><ul><li class="py-2">' . __('messages.cannotDeleteDefaultCurrency') . '</li></ul>');
            $this->confirmDeleteCurrencyModal = false;
            return;
        }

        $this->currency = GlobalCurrency::findOrFail($id);
        $this->confirmDeleteCurrencyModal = true;
    }

    public function deleteCurrency($id)
    {
        if ((int) global_setting()->default_currency_id === (int) $id) {
            $this->addError('currency_error', '<strong>' . __('messages.currencyCannotBeDeleted') . '</strong><ul><li class="py-2">' . __('messages.cannotDeleteDefaultCurrency') . '</li></ul>');
            $this->confirmDeleteCurrencyModal = false;
            return;
        }

        $defaultCurrencyId = global_setting()->default_currency_id;

        Package::where('currency_id', $id)->update(['currency_id' => $defaultCurrencyId]);
        GlobalSubscription::where('currency_id', $id)->update(['currency_id' => $defaultCurrencyId]);
        GlobalInvoice::where('currency_id', $id)->update(['currency_id' => $defaultCurrencyId]);

        GlobalCurrency::destroy($id);

        $this->currency = null;

        $this->confirmDeleteCurrencyModal = false;

        $this->dispatch('refreshCurrencies');

        $this->alert('success', __('messages.currencyDeleted'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);

        $this->currencies->fresh();
    }

    #[On('hideEditCurrency')]
    public function hideEditCurrency()
    {
        $this->showEditCurrencyModal = false;
        $this->dispatch('refreshCurrencies');
    }

    #[On('hideAddCurrency')]
    public function hideAddCurrency()
    {
        $this->showAddCurrencyModal = false;
        $this->dispatch('refreshCurrencies');
    }

    public function render()
    {
        $defaultCurrencyId = global_setting()->default_currency_id;
        
        return view('livewire.settings.superadmin-currency-settings', [
            'defaultCurrencyId' => $defaultCurrencyId
        ]);
    }
}
