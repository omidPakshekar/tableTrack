<?php

namespace App\Livewire\Forms;

use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;

class EditKotCancelReason extends Component
{
    use LivewireAlert;

    public $kotCancelReason;
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

        foreach ($this->kotCancelReason->translations as $translation) {
            $this->translationReasons[$translation->locale] = $translation->reason;
        }

        $this->translationReasons[$this->globalLocale] = $this->translationReasons[$this->globalLocale]
            ?: $this->kotCancelReason->getRawOriginal('reason');

        $this->reason = $this->translationReasons[$this->currentLanguage] ?? '';
        $this->cancel_order = $this->kotCancelReason->cancel_order;
        $this->cancel_kot = $this->kotCancelReason->cancel_kot;
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

        $this->kotCancelReason->update([
            'reason' => $this->translationReasons[$this->globalLocale],
            'cancel_order' => $this->cancel_order,
            'cancel_kot' => $this->cancel_kot,
        ]);

        $newTranslations = collect($this->translationReasons)
            ->filter(fn ($reasonText) => ! empty($reasonText))
            ->map(fn ($reasonText, $locale) => [
                'locale' => $locale,
                'reason' => $reasonText,
            ]);

        foreach ($newTranslations as $translation) {
            $this->kotCancelReason->translations()->updateOrCreate(
                ['locale' => $translation['locale']],
                ['reason' => $translation['reason']]
            );
        }

        $newLocales = $newTranslations->pluck('locale')->toArray();
        $this->kotCancelReason->translations()->whereNotIn('locale', $newLocales)->delete();

        $this->dispatch('hideEditKotReason');
         $this->alert('success', __('messages.reasonUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function render()
    {
        return view('livewire.forms.edit-kot-cancel-reason');
    }
}
