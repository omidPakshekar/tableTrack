<?php

namespace App\Livewire\Menu;

use App\Models\Branch;
use App\Services\BranchMenuCloneService;
use App\Services\Pos\PosBranchCacheInvalidation;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Component;

class CopyMenuToBranches extends Component
{
    use LivewireAlert;

    public bool $showModal = false;

    /** @var 'to'|'from' */
    public string $copyDirection = 'from';

    public string $entityScope = 'all';

    public array $targetBranchIds = [];

    public string $sourceBranchId = '';

    public bool $cloneMenu = true;

    public bool $cloneCategories = true;

    public bool $cloneMenuItems = true;

    public bool $cloneItemModifiers = true;

    public bool $cloneModifierGroups = true;

    public bool $showCloneDependencyNote = false;

    #[On('openCopyMenuToBranches')]
    public function openModal(string $scope = 'all', string $direction = 'from'): void
    {
        if (! $this->canCopyToBranches()) {
            $this->alert('error', __('modules.menu.copyBetweenBranchesUnavailable'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            return;
        }

        $this->entityScope = $scope;
        $this->copyDirection = in_array($direction, ['to', 'from'], true) ? $direction : 'from';
        $this->applyScopeDefaults();
        $this->targetBranchIds = [];
        $this->sourceBranchId = $this->copyDirection === 'from' && $this->otherBranches()->isNotEmpty()
            ? (string) $this->otherBranches()->first()->id
            : '';
        $this->showCloneDependencyNote = false;
        $this->showModal = true;
    }

    public function updatedCopyDirection(): void
    {
        $this->targetBranchIds = [];
        $this->sourceBranchId = $this->copyDirection === 'from' && $this->otherBranches()->isNotEmpty()
            ? (string) $this->otherBranches()->first()->id
            : '';
        $this->showCloneDependencyNote = false;
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

    public function copyBetweenBranches(BranchMenuCloneService $cloneService): void
    {
        if (! $this->canCopyToBranches()) {
            return;
        }

        if ($this->copyDirection === 'from') {
            $this->validate(
                ['sourceBranchId' => 'required|integer|exists:branches,id'],
                ['sourceBranchId.required' => __('modules.menu.selectSourceBranch')]
            );

            $sourceBranchId = (int) $this->sourceBranchId;
            $targetIds = [(int) branch()->id];

            if ($sourceBranchId === (int) branch()->id) {
                $this->alert('error', __('modules.onboarding.cannotCopySameBranch'), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);

                return;
            }
        } else {
            $this->validate(
                ['targetBranchIds' => 'required|array|min:1'],
                ['targetBranchIds.required' => __('modules.menu.selectTargetBranches')]
            );

            $sourceBranchId = (int) branch()->id;
            $targetIds = array_values(array_filter(
                array_map('intval', $this->targetBranchIds),
                fn (int $id) => $id !== $sourceBranchId
            ));

            if ($targetIds === []) {
                $this->alert('error', __('modules.menu.selectTargetBranches'), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);

                return;
            }
        }

        if (! $this->cloneMenu && ! $this->cloneCategories && ! $this->cloneMenuItems && ! $this->cloneItemModifiers && ! $this->cloneModifierGroups) {
            $this->alert('error', __('modules.onboarding.selectCloneOption'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            return;
        }

        $options = [
            'clone_menu' => $this->cloneMenu,
            'clone_categories' => $this->cloneCategories,
            'clone_menu_items' => $this->cloneMenuItems,
            'clone_item_modifiers' => $this->cloneItemModifiers,
            'clone_modifier_groups' => $this->cloneModifierGroups,
            'skip_existing' => true,
        ];

        $results = $cloneService->cloneToBranches($sourceBranchId, $targetIds, $options);

        foreach (array_keys($results) as $branchId) {
            PosBranchCacheInvalidation::invalidateForBranch((int) $branchId);
        }

        $totals = [
            'menus' => 0,
            'categories' => 0,
            'menu_items' => 0,
            'modifier_groups' => 0,
            'item_modifiers' => 0,
        ];

        foreach ($results as $counts) {
            foreach ($totals as $key => $value) {
                $totals[$key] += $counts[$key] ?? 0;
            }
        }

        $this->showModal = false;

        $messageKey = $this->copyDirection === 'from'
            ? 'modules.menu.copyFromBranchSuccess'
            : 'modules.menu.copyToBranchesSuccess';

        $this->alert('success', __($messageKey, [
            'branches' => count($results),
            'menus' => $totals['menus'],
            'categories' => $totals['categories'],
            'items' => $totals['menu_items'],
            'groups' => $totals['modifier_groups'],
            'modifiers' => $totals['item_modifiers'],
        ]), [
            'toast' => true,
            'position' => 'top-end',
        ]);

        $this->dispatch('menuCopiedToBranches');
    }

    public function canCopyToBranches(): bool
    {
        if (! function_exists('restaurant') || ! restaurant()) {
            return false;
        }

        if (! in_array('Change Branch', restaurant_modules(), true)) {
            return false;
        }

        return $this->otherBranches()->isNotEmpty();
    }

    protected function applyScopeDefaults(): void
    {
        $this->cloneMenu = false;
        $this->cloneCategories = false;
        $this->cloneMenuItems = false;
        $this->cloneItemModifiers = false;
        $this->cloneModifierGroups = false;

        match ($this->entityScope) {
            'menus' => $this->cloneMenu = true,
            'categories' => $this->cloneCategories = true,
            'menu_items' => $this->setMenuItemScopeDefaults(),
            'modifier_groups' => $this->cloneModifierGroups = true,
            'item_modifiers' => $this->setItemModifierScopeDefaults(),
            default => $this->setFullScopeDefaults(),
        };
    }

    protected function setFullScopeDefaults(): void
    {
        $this->cloneMenu = true;
        $this->cloneCategories = true;
        $this->cloneMenuItems = true;
        $this->cloneItemModifiers = true;
        $this->cloneModifierGroups = true;
    }

    protected function setMenuItemScopeDefaults(): void
    {
        $this->cloneMenu = true;
        $this->cloneCategories = true;
        $this->cloneMenuItems = true;
        $this->cloneItemModifiers = true;
        $this->cloneModifierGroups = true;
    }

    protected function setItemModifierScopeDefaults(): void
    {
        $this->setMenuItemScopeDefaults();
        $this->cloneItemModifiers = true;
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
        return view('livewire.menu.copy-menu-to-branches', [
            'otherBranches' => $this->canCopyToBranches() ? $this->otherBranches() : collect(),
            'currentBranchName' => branch()->name ?? '',
        ]);
    }
}
