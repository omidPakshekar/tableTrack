<?php

namespace App\Livewire\Forms;

use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;

class EditRefundReason extends Component
{
    use LivewireAlert;

    public $refundReason;
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

        foreach ($this->refundReason->translations as $translation) {
            $this->translationReasons[$translation->locale] = $translation->reason;
        }

        $this->translationReasons[$this->globalLocale] = $this->translationReasons[$this->globalLocale]
            ?: $this->refundReason->getRawOriginal('reason');

        $this->reason = $this->translationReasons[$this->currentLanguage] ?? '';
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

        $this->refundReason->update([
            'reason' => $this->translationReasons[$this->globalLocale],
        ]);

        $newTranslations = collect($this->translationReasons)
            ->filter(fn ($reasonText) => ! empty($reasonText))
            ->map(fn ($reasonText, $locale) => [
                'locale' => $locale,
                'reason' => $reasonText,
            ]);

        foreach ($newTranslations as $translation) {
            $this->refundReason->translations()->updateOrCreate(
                ['locale' => $translation['locale']],
                ['reason' => $translation['reason']]
            );
        }

        $newLocales = $newTranslations->pluck('locale')->toArray();
        $this->refundReason->translations()->whereNotIn('locale', $newLocales)->delete();

        $this->dispatch('hideEditRefundReason');
         $this->alert('success', __('messages.reasonUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function render()
    {
        return view('livewire.forms.edit-refund-reason');
    }
}
