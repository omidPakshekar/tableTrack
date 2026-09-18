<?php

namespace App\Livewire\Settings;

use App\Helper\Files;
use App\Models\Restaurant;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;
use Livewire\WithFileUploads;

class MenuItemImageSettings extends Component
{
    use LivewireAlert;
    use WithFileUploads;

    public Restaurant $settings;

    public $disableDefaultImage = false;
    public $defaultImage;

    public function mount(Restaurant $settings): void
    {
        $this->settings = $settings;
        $this->disableDefaultImage = (bool) ($settings->disable_menu_item_default_image ?? false);
    }

    public function updatedDefaultImage(): void
    {
        $this->validateOnly('defaultImage', [
            'defaultImage' => 'nullable|image|max:2048',
        ]);
    }

    public function removeDefaultImage(): void
    {
        if (!empty($this->settings->menu_item_default_image_path)) {
            Files::deleteFile(basename($this->settings->menu_item_default_image_path), 'menu/default');
        }

        $this->settings->menu_item_default_image_path = null;
        $this->settings->save();

        session()->forget(['restaurant']);
        $this->dispatch('settingsUpdated');

        $this->alert('success', __('messages.settingsUpdated'), [
            'toast' => true,
            'position' => 'top-end',
        ]);
    }

    public function submitForm(): void
    {
        $this->validate([
            'disableDefaultImage' => 'boolean',
            'defaultImage' => 'nullable|image|max:2048',
        ]);

        $this->settings->disable_menu_item_default_image = (bool) $this->disableDefaultImage;

        if ($this->defaultImage) {
            $oldFilename = !empty($this->settings->menu_item_default_image_path)
                ? basename($this->settings->menu_item_default_image_path)
                : null;
            $uploadedFileName = Files::uploadLocalOrS3($this->defaultImage, 'menu/default', oldFilename: $oldFilename);
            $this->settings->menu_item_default_image_path = 'menu/default/' . $uploadedFileName;
        }

        $this->settings->save();

        session()->forget(['restaurant']);
        $this->dispatch('settingsUpdated');

        $this->defaultImage = null;

        $this->alert('success', __('messages.settingsUpdated'), [
            'toast' => true,
            'position' => 'top-end',
        ]);
    }

    public function getCurrentDefaultImageUrlProperty(): ?string
    {
        if (!empty($this->settings->menu_item_default_image_path)) {
            return asset_url_local_s3($this->settings->menu_item_default_image_path);
        }

        if ($this->disableDefaultImage) {
            return null;
        }

        return asset('img/food.svg');
    }

    public function render()
    {
        return view('livewire.settings.menu-item-image-settings');
    }
}

