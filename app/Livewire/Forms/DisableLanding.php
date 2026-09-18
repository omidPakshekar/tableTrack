<?php

namespace App\Livewire\Forms;

use Livewire\Component;
use App\Models\CustomMenu;
use App\Helper\Files;
use Livewire\WithFileUploads;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class DisableLanding extends Component
{

    use LivewireAlert, WithFileUploads;

    public $settings;
    public $disableLandingSite;
    public $landingType;

    public $landingSiteType;
    public $landingSiteUrl;
    public $facebook;
    public $instagram;
    public $twitter;
    public $yelp;
    public $googleBusinessLink;
    public $metaKeyword;
    public $metaDescription;
    public $metaTitle;
    public $metaImage;
    public $trixId;
    public $menuName;
    public $menuSlug;
    public $menuContent;
    public $addDyanamicMenuModal = false;
    public $showEditDynamicMenuModal = false;
    public $confirmDeleteMenuModal = false;
    public $editMenuId;
    public $editMenuName;
    public $editMenuSlug;
    public $editMenuContent;
    public $showAddDynamicMenu;
    public $menuId;
    public $menu;
    public $menuStates = [];
    public $menuIdToDelete = null;
    #[Url]
    public $activeSetting = 'settings';
    public $menuPosition = 'header';

    protected $listeners = ['refreshCustomers' => '$refresh'];

    public function mount()
    {
        $this->disableLandingSite = $this->settings ? (bool)$this->settings->disable_landing_site : false;
        $this->landingType = $this->settings ? $this->settings->landing_type : false;
        $this->landingSiteType = $this->settings ? $this->settings->landing_site_type : '';
        $this->landingSiteUrl = $this->settings ? $this->settings->landing_site_url : '';
        $this->facebook = $this->settings ? $this->settings->facebook_link : '';
        $this->instagram = $this->settings ? $this->settings->instagram_link : '';
        $this->twitter = $this->settings ? $this->settings->twitter_link : '';
        $this->yelp = $this->settings ? $this->settings->yelp_link : '';
        $this->googleBusinessLink = $this->settings ? $this->settings->google_business_link : '';
        $this->metaTitle = $this->settings ? $this->settings->meta_title : '';
        $this->metaKeyword = $this->settings ? $this->settings->meta_keyword : '';
        $this->metaDescription = $this->settings ? $this->settings->meta_description : '';
        $this->trixId = 'trix-' . uniqid();
    }

    public function submitForm()
    {
        $this->validate([
            'landingSiteUrl' => [
                'required_if:landingSiteType,custom',
                'nullable',
                'url',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $host = parse_url($value, PHP_URL_HOST);
                        $appUrl = parse_url(config('app.url'), PHP_URL_HOST);

                        if ($host === $appUrl) {
                            $fail(__('messages.cannotUseSelfDomain'));
                        }
                    }
                }
            ],
            'metaImage' => \App\Support\ImageUpload::mimesRule(),
        ]);

        if ($this->metaImage) {
            // Store a reasonably small/shareable image; UI shows a small preview.
            $this->settings->meta_image = Files::uploadLocalOrS3(
                $this->metaImage,
                'meta-image',
                600,
                600,
                oldFilename: $this->settings->meta_image
            );
        }

        $this->settings->disable_landing_site = $this->disableLandingSite;
        $this->settings->landing_type = $this->landingType;
        $this->settings->landing_site_type = $this->landingSiteType;
        $this->settings->landing_site_url = $this->landingSiteUrl;
        $this->settings->facebook_link = $this->facebook;
        $this->settings->instagram_link = $this->instagram;
        $this->settings->twitter_link = $this->twitter;
        $this->settings->yelp_link = $this->yelp;
        $this->settings->google_business_link = $this->googleBusinessLink;
        $this->settings->meta_title = $this->metaTitle;
        $this->settings->meta_keyword = $this->metaKeyword;
        $this->settings->meta_description = $this->metaDescription;


        $this->settings->save();

        cache()->forget('global_setting');

        $this->alert('success', __('messages.settingsUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function removeMetaImage(): void
    {
        if (! $this->settings) {
            return;
        }

        if (! empty($this->settings->meta_image)) {
            Files::deleteFile($this->settings->meta_image, 'meta-image');
        }

        $this->settings->meta_image = null;
        $this->settings->save();

        $this->metaImage = null;

        cache()->forget('global_setting');
    }

    public function showEditDynamicMenu($id)
    {
        $this->menuId = $id;
        $this->showEditDynamicMenuModal = true;
    }

    #[On('hideEditDyanamicMenu')]
    public function hideEditDyanamicMenu()
    {
        $this->showEditDynamicMenuModal = false;
    }

    public function showAddDynamicMenu()
    {
        $this->addDyanamicMenuModal = true;
    }

    #[On('hideAddDyanamicMenu')]
    public function hideAddDyanamicMenu()
    {
        $this->addDyanamicMenuModal = false;
    }

    public function confirmDeleteMenu($menuId)
    {
        $this->menuIdToDelete = $menuId;
    }

    public function deleteMenu()
    {
        if ($this->menuIdToDelete) {
            CustomMenu::find($this->menuIdToDelete)->delete();
            $this->menuIdToDelete = null;
            $this->alert('success', __('messages.settingsUpdated'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);
        }

        $this->dispatch('$refresh');
    }

    public function toggleMenuStatus($menuId)
    {
        $menu = CustomMenu::findOrFail($menuId);
        $menu->is_active = !$menu->is_active;
        $menu->save();

        $this->dispatch('$refresh');

        $this->alert('success', __('messages.settingsUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function sortCustomMenu($sortedIds)
    {
        foreach ($sortedIds as $sortedItem) {
            CustomMenu::where('id', $sortedItem['value'])
                ->update(['sort_order' => $sortedItem['order']]);
        }

        $this->alert('success', __('messages.settingsUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function render()
    {
        return view('livewire.forms.disable-landing', [
            'customMenu' => CustomMenu::where('position', $this->menuPosition)
            ->orderBy('sort_order')
            ->paginate(10),
        ]);
    }
}
