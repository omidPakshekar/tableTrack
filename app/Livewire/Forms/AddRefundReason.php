<?php

namespace App\Livewire\Forms;

use Livewire\Component;
use App\Models\RefundReason;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class AddRefundReason extends Component
{
    use LivewireAlert;

    public $reason;
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
            'translationReasons.' . $this->globalLocale => 'required|string',
        ], [
            'translationReasons.' . $this->globalLocale . '.required' => __('validation.required'),
        ]);

        if (! branch()) {
            $this->addError('reason', 'No branch found. Please select a branch.');
            return;
        }

        $refundReason = RefundReason::create([
            'reason' => $this->translationReasons[$this->globalLocale],
            'branch_id' => branch()->id,
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
            $refundReason->translations()->createMany($translations);
        }

        $this->dispatch('hideAddRefundReason');
         $this->alert('success', __('messages.reasonAdded'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);

        $this->reason = '';
        $this->translationReasons = array_fill_keys(array_keys($this->languages), '');
    }

    public function render()
    {
        return view('livewire.forms.add-refund-reason');
    }
}
