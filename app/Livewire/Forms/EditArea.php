<?php

namespace App\Livewire\Forms;

use App\Helper\Files;
use App\Models\Area;
use App\Support\ImageUpload;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;
use Livewire\WithFileUploads;

class EditArea extends Component
{
    use LivewireAlert;
    use WithFileUploads;

    public $activeArea;

    public $areaName;

    public $areaImageTemp;

    public bool $removeAreaImage = false;

    public function mount()
    {
        $this->areaName = $this->activeArea->area_name;
    }

    public function updatedAreaImageTemp(): void
    {
        $this->removeAreaImage = false;
        $this->resetErrorBag('areaImageTemp');
    }

    public function removeSelectedImage(): void
    {
        $this->areaImageTemp = null;
    }

    public function removeCurrentImage(): void
    {
        $this->areaImageTemp = null;
        $this->removeAreaImage = true;
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

        $updateData = [
            'area_name' => $this->areaName,
        ];

        $oldImage = $this->activeArea->image;

        if ($this->areaImageTemp) {
            $updateData['image'] = Files::uploadLocalOrS3($this->areaImageTemp, 'area', width: 500, height: 500);
            $this->removeAreaImage = false;
        } elseif ($this->removeAreaImage) {
            $updateData['image'] = null;
        }

        Area::where('id', $this->activeArea->id)->update($updateData);

        if ($this->areaImageTemp && ! empty($oldImage) && $oldImage !== ($updateData['image'] ?? null)) {
            Area::deleteImageFile($oldImage);
        } elseif ($this->removeAreaImage && ! empty($oldImage)) {
            Area::deleteImageFile($oldImage);
        }

        $this->areaName = '';
        $this->areaImageTemp = null;
        $this->removeAreaImage = false;

        $this->dispatch('hideEditArea');

        $this->alert('success', __('messages.areaUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close'),
        ]);
    }

    public function render()
    {
        return view('livewire.forms.edit-area');
    }
}
