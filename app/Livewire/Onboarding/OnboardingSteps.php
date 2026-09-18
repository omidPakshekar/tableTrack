<?php

namespace App\Livewire\Onboarding;

use App\Models\Branch;
use App\Models\OnboardingStep;
use App\Services\BranchMenuCloneService;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Component;

class OnboardingSteps extends Component
{
    use LivewireAlert;

    public $showAddArea = false;

    public $showAddTable = false;

    public $showAddMenu = false;

    public $showAddMenuItem = false;

    public $showCopyMenuPanel = false;

    public $sourceBranchId = '';

    public $cloneMenu = true;

    public $cloneCategories = true;

    public $cloneMenuItems = true;

    public $cloneItemModifiers = true;

    public $cloneModifierGroups = true;

    public bool $showCloneDependencyNote = false;

    #[On('areaAdded')]
    public function areaAdded(): void
    {
        $onboarding = $this->currentOnboardingStep();
        $onboarding->add_area_completed = 1;
        $onboarding->save();

        $this->showAddArea = false;
    }

    #[On('tableAdded')]
    public function tableAdded(): void
    {
        $onboarding = $this->currentOnboardingStep();
        $onboarding->add_table_completed = 1;
        $onboarding->save();

        $this->showAddTable = false;
    }

    #[On('menuAdded')]
    public function menuAdded(): void
    {
        $onboarding = $this->currentOnboardingStep();
        $onboarding->add_menu_completed = 1;
        $onboarding->save();

        $this->showAddMenu = false;
    }

    #[On('menuItemAdded')]
    public function menuItemAdded(): void
    {
        $onboarding = $this->currentOnboardingStep();
        $onboarding->add_menu_items_completed = 1;
        $onboarding->save();

        $this->showAddMenuItem = false;
    }

    public function showAddAreaForm(): void
    {
        $this->showAddMenu = false;
        $this->showAddTable = false;
        $this->showAddArea = true;
        $this->showAddMenuItem = false;
        $this->showCopyMenuPanel = false;
    }

    public function showAddTableForm(): void
    {
        $this->showAddMenu = false;
        $this->showAddTable = true;
        $this->showAddArea = false;
        $this->showAddMenuItem = false;
        $this->showCopyMenuPanel = false;
    }

    public function showAddMenuForm(): void
    {
        $this->showAddMenu = true;
        $this->showAddTable = false;
        $this->showAddArea = false;
        $this->showAddMenuItem = false;
        $this->showCopyMenuPanel = false;
    }

    public function showAddMenuItemForm(): void
    {
        $this->showAddMenu = false;
        $this->showAddTable = false;
        $this->showAddArea = false;
        $this->showAddMenuItem = true;
        $this->showCopyMenuPanel = false;
    }

    public function showCopyMenuFromBranchForm(): void
    {
        $this->showCopyMenuPanel = true;
        $this->showAddMenu = false;
        $this->showAddTable = false;
        $this->showAddArea = false;
        $this->showAddMenuItem = false;

        if ($this->sourceBranchId === '' && $this->otherBranches()->isNotEmpty()) {
            $this->sourceBranchId = (string) $this->otherBranches()->first()->id;
        }
    }

    public function handleCloneMenuItemsChange(): void
    {
        if ($this->cloneMenuItems) {
            $autoSelected = false;

            if (! $this->cloneMenu) {
                $this->cloneMenu = true;
                $autoSelected = true;
            }

            if (! $this->cloneCategories) {
                $this->cloneCategories = true;
                $autoSelected = true;
            }

            $this->showCloneDependencyNote = $autoSelected;
        } else {
            $this->cloneItemModifiers = false;
            $this->showCloneDependencyNote = false;
        }
    }

    public function handleCloneItemModifiersChange(): void
    {
        if ($this->cloneItemModifiers) {
            $autoSelected = false;

            foreach ([
                'cloneMenuItems' => true,
                'cloneMenu' => true,
                'cloneCategories' => true,
                'cloneModifierGroups' => true,
            ] as $property => $value) {
                if (! $this->{$property}) {
                    $this->{$property} = $value;
                    $autoSelected = true;
                }
            }

            $this->showCloneDependencyNote = $autoSelected;
        } else {
            $this->showCloneDependencyNote = false;
        }
    }

    public function dismissCloneDependencyNote(): void
    {
        $this->showCloneDependencyNote = false;
    }

    public function copyMenuFromBranch(BranchMenuCloneService $cloneService): void
    {
        $this->validate(
            ['sourceBranchId' => 'required|integer|exists:branches,id'],
            ['sourceBranchId.required' => __('modules.onboarding.selectSourceBranch')]
        );

        $targetBranch = branch();
        $sourceBranchId = (int) $this->sourceBranchId;

        if ($sourceBranchId === (int) $targetBranch->id) {
            $this->alert('error', __('modules.onboarding.cannotCopySameBranch'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            return;
        }

        if (! $this->cloneMenu && ! $this->cloneCategories && ! $this->cloneMenuItems && ! $this->cloneItemModifiers && ! $this->cloneModifierGroups) {
            $this->alert('error', __('modules.onboarding.selectCloneOption'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            return;
        }

        $counts = $cloneService->clone($sourceBranchId, $targetBranch, [
            'clone_menu' => $this->cloneMenu,
            'clone_categories' => $this->cloneCategories,
            'clone_menu_items' => $this->cloneMenuItems,
            'clone_item_modifiers' => $this->cloneItemModifiers,
            'clone_modifier_groups' => $this->cloneModifierGroups,
        ]);

        $onboarding = $this->currentOnboardingStep();

        if ($counts['menus'] > 0) {
            $onboarding->add_menu_completed = 1;
        }

        if ($counts['menu_items'] > 0) {
            $onboarding->add_menu_items_completed = 1;
        }

        $onboarding->save();

        $this->showCopyMenuPanel = false;

        $this->alert('success', __('modules.onboarding.menuCopiedSuccess', [
            'menus' => $counts['menus'],
            'categories' => $counts['categories'],
            'items' => $counts['menu_items'],
            'groups' => $counts['modifier_groups'],
        ]), [
            'toast' => true,
            'position' => 'top-end',
        ]);
    }

    protected function currentOnboardingStep(): OnboardingStep
    {
        return OnboardingStep::firstOrCreate(
            ['branch_id' => branch()->id],
            [
                'add_area_completed' => 0,
                'add_table_completed' => 0,
                'add_menu_completed' => 0,
                'add_menu_items_completed' => 0,
            ]
        );
    }

    protected function otherBranches()
    {
        return Branch::withoutGlobalScopes()
            ->where('restaurant_id', restaurant()->id)
            ->where('id', '!=', branch()->id)
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        $onboardingSteps = $this->currentOnboardingStep();
        $otherBranches = $this->otherBranches();

        return view('livewire.onboarding.onboarding-steps', [
            'onboardingSteps' => $onboardingSteps,
            'otherBranches' => $otherBranches,
        ]);
    }
}
