<?php

namespace App\Livewire\Forms;

use App\Helper\Files;
use App\Models\Area;
use App\Support\ImageUpload;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;
use Livewire\WithFileUploads;

class AddArea extends Component
{
    use LivewireAlert;
    use WithFileUploads;

    public $areaName;

    public $areaImageTemp;

    public function mount()
    {
        $this->areaName = '';
    }

    public function updatedAreaImageTemp(): void
    {
        $this->resetErrorBag('areaImageTemp');
    }

    public function removeSelectedImage(): void
    {
        $this->areaImageTemp = null;
    }

    public function validateImage(): void
    {
        if (! $this->areaImageTemp) {
            return;
        }

        $this->validate([
            'areaImageTemp' => ImageUpload::IMAGE_MIMES_MAX_2048,
        ]);

        $imageInfo = @getimagesize($this->areaImageTemp->getRealPath());
        if ($imageInfo) {
            $width = $imageInfo[0];
            $height = $imageInfo[1];

            if ($width < 200 || $height < 200) {
                $this->addError('areaImageTemp', __('modules.table.areaImageDimensionsTooSmall'));
            }
        }
    }

    public function submitForm()
    {
        if ($this->areaImageTemp) {
            $this->validateImage();

            if ($this->getErrorBag()->has('areaImageTemp')) {
                return;
            }
        }

        $this->validate([
            'areaName' => 'required',
        ]);

        $data = [
            'area_name' => $this->areaName,
        ];

        if ($this->areaImageTemp) {
            $data['image'] = Files::uploadLocalOrS3($this->areaImageTemp, 'area', width: 500, height: 500);
        }

        Area::create($data);

        $this->areaName = '';
        $this->areaImageTemp = null;

        $this->dispatch('hideAddArea');
        $this->dispatch('areaAdded');
        $this->dispatch('closeAddAreaModal');

        $this->alert('success', __('messages.areaAdded'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close'),
        ]);
    }

    public function render()
    {
        return view('livewire.forms.add-area');
    }
}
