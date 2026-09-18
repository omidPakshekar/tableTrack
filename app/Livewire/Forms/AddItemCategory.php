<?php

namespace App\Livewire\Forms;

use Livewire\Component;
use App\Models\ItemCategory;
use App\Helper\Files;
use Illuminate\Validation\Rule;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\WithFileUploads;

class AddItemCategory extends Component
{
    use LivewireAlert, WithFileUploads;

    public $categoryName = '';
    public $translations = [];
    public $languages = [];
    public $currentLanguage;
    public $globalLocale;
    public $categoryImageTemp;

    public function mount()
    {
        $this->languages = languages()->pluck('language_name', 'language_code')->toArray();
        $this->translations = array_fill_keys(array_keys($this->languages), '');
        $this->globalLocale = global_setting()->locale;
        $this->currentLanguage = $this->globalLocale;
    }

    public function updateTranslation()
    {
        $this->translations[$this->currentLanguage] = $this->categoryName;
    }

    public function updatedCategoryImageTemp(): void
    {
        if (!$this->categoryImageTemp) {
            return;
        }
        $this->validate([
            'categoryImageTemp' => \App\Support\ImageUpload::mimesRule(),
        ]);
    }

    public function removeSelectedImage(): void
    {
        $this->categoryImageTemp = null;
    }

    public function updatedCurrentLanguage()
    {
        $this->categoryName = $this->translations[$this->currentLanguage] ?? '';
    }

    public function submitForm()
    {
        $this->validate([
            'translations.' . $this->globalLocale => [
                'required',
                Rule::unique('item_categories', "category_name->{$this->globalLocale}")
                    ->where('branch_id', branch()->id),
            ],
            'categoryImageTemp' => \App\Support\ImageUpload::mimesRule(),
        ], [
            'translations.' . $this->globalLocale . '.required' => __('validation.categoryNameRequired', ['language' => $this->languages[$this->globalLocale]]),
            'translations.' . $this->globalLocale . '.unique' => __('validation.categoryNameUnique', ['language' => $this->languages[$this->globalLocale]]),
        ]);

        $filteredTranslations = array_filter($this->translations, 'trim');

        $imageName = null;
        if ($this->categoryImageTemp) {
            $imageName = Files::uploadLocalOrS3($this->categoryImageTemp, 'item-category', width: 350, height: 350);
        }

        ItemCategory::create([
            'category_name' => $filteredTranslations,
            'image' => $imageName,
        ]);

        // Reset the value
        $this->categoryName = '';
        $this->translations = array_fill_keys(array_keys($this->translations), '');
        $this->categoryImageTemp = null;

        $this->dispatch('refreshCategories');
        $this->dispatch('hideCategoryModal');
        // close Alpine modal (item-categories.blade.php listens for this)
        $this->dispatch('close-add-category-modal');

        $this->alert('success', __('messages.categoryAdded'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function render()
    {
        return view('livewire.forms.add-item-category');
    }

}
