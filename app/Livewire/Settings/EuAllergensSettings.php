<?php

namespace App\Livewire\Settings;

use App\Models\Restaurant;
use App\Support\EuAnnexIiAllergens;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;

class EuAllergensSettings extends Component
{
    use LivewireAlert;

    public Restaurant $settings;

    public bool $euAllergensEnabled = false;

    /** @var array<int, string> */
    public array $selectedKeys = [];

    public function mount(Restaurant $settings): void
    {
        $this->settings = $settings;
        $row = $this->settings->euAllergenSetting()->firstOrCreate(
            ['restaurant_id' => $settings->id],
            ['enabled' => false, 'allergen_keys' => null]
        );

        $this->euAllergensEnabled = (bool) $row->enabled;
        $this->selectedKeys = EuAnnexIiAllergens::normalizedSelection($row->allergen_keys);
    }

    public function resetToAnnexIiDefaults(): void
    {
        $this->selectedKeys = EuAnnexIiAllergens::keys();

        $this->alert('success', __('modules.settings.euAllergensResetToDefaults'), [
            'toast' => true,
            'position' => 'top-end',
        ]);
    }

    public function submitForm(): void
    {
        $allowed = EuAnnexIiAllergens::keys();

        $this->validate([
            'euAllergensEnabled' => 'boolean',
            'selectedKeys' => 'array',
            'selectedKeys.*' => 'string|in:' . implode(',', $allowed),
        ]);

        $normalized = array_values(array_unique(array_intersect($this->selectedKeys, $allowed)));

        if ($this->euAllergensEnabled && $normalized === []) {
            $this->addError('selectedKeys', __('modules.settings.euAllergensSelectAtLeastOne'));

            return;
        }

        if (!$this->euAllergensEnabled && $normalized === []) {
            $normalized = EuAnnexIiAllergens::keys();
        }

        $this->settings->euAllergenSetting()->updateOrCreate(
            ['restaurant_id' => $this->settings->id],
            [
                'enabled' => $this->euAllergensEnabled,
                'allergen_keys' => $normalized,
            ]
        );

        $this->selectedKeys = $normalized;

        session()->forget(['restaurant']);
        $this->dispatch('settingsUpdated');

        $this->alert('success', __('messages.settingsUpdated'), [
            'toast' => true,
            'position' => 'top-end',
        ]);
    }

    public function render()
    {
        $annexLabels = [];
        $annexIconUrls = [];
        foreach (EuAnnexIiAllergens::keys() as $key) {
            $annexLabels[$key] = __(EuAnnexIiAllergens::langKey($key));
            $annexIconUrls[$key] = EuAnnexIiAllergens::defaultIconUrl($key);
        }

        return view('livewire.settings.eu-allergens-settings', [
            'annexKeys' => EuAnnexIiAllergens::keys(),
            'annexLabels' => $annexLabels,
            'annexIconUrls' => $annexIconUrls,
        ]);
    }
}
