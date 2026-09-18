<?php

namespace App\Livewire\Forms;

use Livewire\Component;
use App\Models\KotCancelReason;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class AddKotCancelReason extends Component
{
    use LivewireAlert;

    public $reason;
    public $cancel_order = false;
    public $cancel_kot = false;
    public $languages = [];
    public $translationReasons = [];
    public $currentLanguage;
    public $globalLocale;

    public function mount()
    {
        $this->languages = languages()->pluck('language_name', 'language_code')->toArray();
        $this->translationReasons = array_fill_keys(array_keys($this->languages), '');
        $this->globalLocale = global_setting()->locale;
        $this->currentLanguage = $this->globalLocale;
    }

    public function updateTranslation()
    {
        $this->translationReasons[$this->currentLanguage] = $this->reason;
    }

    public function updatedCurrentLanguage()
    {
        $this->reason = $this->translationReasons[$this->currentLanguage] ?? '';
    }

    public function submitForm()
    {
        $this->updateTranslation();

        $this->validate([
            'translationReasons.' . $this->globalLocale => 'required',
            'cancel_order' => 'boolean',
            'cancel_kot' => 'boolean',
        ], [
            'translationReasons.' . $this->globalLocale . '.required' => __('validation.required'),
        ]);

        if (! $this->cancel_order && ! $this->cancel_kot) {
            $this->addError('cancel_order', 'Please select at least one cancellation type.');
            return;
        }

        $kotReason = KotCancelReason::create([
            'reason' => $this->translationReasons[$this->globalLocale],
            'cancel_order' => $this->cancel_order,
            'cancel_kot' => $this->cancel_kot,
        ]);

        $translations = collect($this->translationReasons)
            ->filter(fn ($reasonText) => ! empty($reasonText))
            ->map(fn ($reasonText, $locale) => [
                'locale' => $locale,
                'reason' => $reasonText,
            ])
            ->values()
            ->all();

        if ($translations !== []) {
            $kotReason->translations()->createMany($translations);
        }

        $this->dispatch('hideAddKotReason');
         $this->alert('success', __('messages.reasonAdded'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);

        $this->reason = '';
        $this->translationReasons = array_fill_keys(array_keys($this->languages), '');
        $this->cancel_order = false;
        $this->cancel_kot = false;
    }

    public function render()
    {
        return view('livewire.forms.add-kot-cancel-reason');
    }
}
