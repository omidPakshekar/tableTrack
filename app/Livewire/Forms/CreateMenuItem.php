<?php


namespace App\Livewire\Forms;

use App\Models\Tax;
use App\Models\Menu;
use App\Helper\Files;
use Livewire\Component;
use App\Models\KotPlace;
use App\Models\MenuItem;
use App\Models\OrderType;
use App\Models\ItemCategory;
use Livewire\WithFileUploads;
use App\Models\MenuItemPrices;
use App\Models\DeliveryPlatform;
use App\Models\MenuItemVariation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Illuminate\Support\Facades\DB;
use App\Services\Pos\PosBranchCacheInvalidation;
use App\Support\DietaryLabels;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class CreateMenuItem extends Component
{
    use WithFileUploads, LivewireAlert;

    protected $listeners = ['refreshCategories'];

    // Core Properties (name validated with translations: at least one locale required)
    #[Validate('nullable|string|max:255')]
    public string $itemName = '';

    #[Validate('required')]
    public string $menu = '';

    #[Validate('required')]
    public string $itemCategory = '';

    #[Validate('nullable|string')]
    public string $itemDescription = '';

    #[Validate('required|in:veg,non-veg,other,spicy,mild,sweet')]
    public string $itemType = 'veg';

    #[Validate('required|numeric|min:0')]
    public string $itemPrice = '';

    #[Validate('nullable|integer|min:0')]
    public ?int $preparationTime = null;

    #[Validate('required|boolean')]
    public bool $isAvailable = true;

    #[Validate('required|boolean')]
    public bool $sellByWeight = false;

    #[Validate('nullable|string')]
    public ?string $kitchenType = null;

    #[Validate(\App\Support\ImageUpload::NULLABLE_MIMES_MAX_2048)]
    public $itemImageTemp;

    public ?string $itemImage = null;

    // Translation Properties
    public array $translationNames = [];
    public array $translationDescriptions = [];
    public string $currentLanguage = '';
    public array $languages = [];
    public string $globalLocale = '';

    // Variation Properties - Using indexed arrays
    public array $inputs = [];
    public int $i = 0;
    public bool $hasVariations = false;
    public bool $showItemPrice = true;
    public array $variationName = [];
    public array $variationPrice = [];

    // Pricing Properties
    public array $orderTypePrices = [];
    public array $deliveryPrices = [];
    public array $platformAvailability = [];
    public string $baseDeliveryPrice = '';
    public array $variationOrderTypePrices = []; // Structure: [index => [orderTypeId => price]]
    public array $variationPlatformAvailability = []; // Structure: [index => [appId => bool]]
    public array $variationBaseDeliveryPrice = []; // Structure: [index => price]
    public array $variationDeliveryPrices = []; // Structure: [index => [appId => calculated_price]]
    // Linked Price Tracking
    public array $linkedOrderTypePrices = [];
    public bool $linkedDeliveryPrice = true;
    public array $variationLinkedOrderTypePrices = [];
    public array $variationLinkedDeliveryPrice = [];

    // Tax Properties
    public array $selectedTaxes = [];
    public bool $taxInclusive = false;
    public ?array $taxInclusivePriceDetails = null;
    public bool $isTaxModeItem = false;
    public array $variationBreakdowns = [];

    /** @var array<int, string> */
    public array $selectedEuAllergens = [];

    /** @var array<int, string> */
    public array $selectedDietaryLabels = [];
    public bool $showMenuCategoryModal = false;

    // Collections (computed properties to avoid N+1 queries)
    public $categoryList;
    public $menus;
    public $kitchenTypes;
    public $taxes;
    public $orderTypes;
    public $deliveryApps;

    public function mount(): void
    {
        $this->initializeCollections();
        $this->initializeLanguages();
        $this->initializePricing();
        $this->initializeTaxSettings();
    }

    /**
     * Initialize collections to avoid N+1 queries
     */
    private function initializeCollections(): void
    {
        $this->categoryList = ItemCategory::all();
        $this->menus = Menu::all();
        $this->kitchenTypes = KotPlace::where('is_active', true)->get();
        $this->taxes = Tax::all();
        $this->orderTypes = OrderType::where('is_active', 1)
            ->availableForRestaurant()
            ->get();
        $this->deliveryApps = DeliveryPlatform::where('is_active', 1)->get();
    }

    /**
     * Initialize language settings
     */
    private function initializeLanguages(): void
    {
        $this->languages = languages()->pluck('language_name', 'language_code')->toArray();
        $this->globalLocale = global_setting()->locale;
        $this->currentLanguage = $this->globalLocale;
        $this->translationNames = array_fill_keys(array_keys($this->languages), '');
        $this->translationDescriptions = array_fill_keys(array_keys($this->languages), '');
    }

    /**
     * Initialize pricing arrays
     */
    private function initializePricing(): void
    {
        foreach ($this->orderTypes as $orderType) {
            $this->orderTypePrices[$orderType->id] = '';
            $this->linkedOrderTypePrices[$orderType->id] = true;
        }

        foreach ($this->deliveryApps as $app) {
            $this->deliveryPrices[$app->id] = '';
            $this->platformAvailability[$app->id] = true;
        }

        $this->linkedDeliveryPrice = true;
    }

    /**
     * Initialize tax settings
     */
    private function initializeTaxSettings(): void
    {
        $this->taxInclusive = (bool)(restaurant()->tax_inclusive ?? false);
        $this->isTaxModeItem = (restaurant()->tax_mode === 'item');
    }

    // VARIATION MANAGEMENT
    public function addMoreField(int $i): void
    {
        $i = $i + 1;
        $this->i = $i;
        array_push($this->inputs, $i);

        if (count($this->inputs) > 0) {
            $this->showItemPrice = false;
        }

        // Initialize pricing for new variation
        $this->initializeVariationPricing($i);
    }

    public function removeField(int $i): void
    {
        // Prevent removal if it's the last remaining variation
        if (count($this->inputs) <= 1) {
            $this->alert('warning', __('messages.invalidRequest'));
            return;
        }

        // Find the actual index in the inputs array
        $inputIndex = array_search($i, $this->inputs);

        if ($inputIndex !== false) {
            // Remove from inputs array
            unset($this->inputs[$inputIndex]);

            // Remove variation data - DO NOT reindex to maintain proper key associations
            unset($this->variationName[$i]);
            unset($this->variationPrice[$i]);
            unset($this->variationOrderTypePrices[$i]);
            unset($this->variationPlatformAvailability[$i]);
            unset($this->variationBaseDeliveryPrice[$i]);
            unset($this->variationDeliveryPrices[$i]);
            unset($this->variationBreakdowns[$i]);

            // Keep original array keys - DO NOT use array_values()
            // This ensures proper data tracking between frontend and backend
        }

        if (empty($this->inputs)) {
            $this->showItemPrice = true;
            $this->i = 0;
        }

        $this->updateVariationBreakdowns();
    }

    private function initializeVariationPricing(int $index): void
    {
        if (!isset($this->variationOrderTypePrices[$index])) {
            $this->variationOrderTypePrices[$index] = [];
        }

        $this->variationLinkedOrderTypePrices[$index] = [];
        foreach ($this->orderTypes as $orderType) {
            $this->variationOrderTypePrices[$index][$orderType->id] = '';
            $this->variationLinkedOrderTypePrices[$index][$orderType->id] = true;
        }

        if (!isset($this->variationPlatformAvailability[$index])) {
            $this->variationPlatformAvailability[$index] = [];
        }

        if (!isset($this->variationDeliveryPrices[$index])) {
            $this->variationDeliveryPrices[$index] = [];
        }

        foreach ($this->deliveryApps as $app) {
            $this->variationPlatformAvailability[$index][$app->id] = true;
            $this->variationDeliveryPrices[$index][$app->id] = '0.00';
        }

        if (!isset($this->variationBaseDeliveryPrice[$index])) {
            $this->variationBaseDeliveryPrice[$index] = '';
        }

        $this->variationLinkedDeliveryPrice[$index] = true;
    }

    /**
     * Calculate delivery prices for a specific variation
     */
    private function calculateVariationDeliveryPrices(int $index): void
    {
        if (!isset($this->variationPrice[$index])) {
            return;
        }

        // Use base delivery price from variation if set, otherwise use variation's main price
        $baseDeliveryPrice = !empty($this->variationBaseDeliveryPrice[$index])
                           ? (float)$this->variationBaseDeliveryPrice[$index]
                           : (float)($this->variationPrice[$index] ?? 0);

        foreach ($this->deliveryApps as $app) {
            // Calculate final price with commission
            $commission = (float)($app->commission_value ?? 0);
            $finalPrice = $baseDeliveryPrice + ($baseDeliveryPrice * $commission / 100);

            $this->variationDeliveryPrices[$index][$app->id] = number_format($finalPrice, 2);
        }
    }

    public function updatedVariationPrice($value, $key): void
    {
        $this->variationOrderTypePrices[$key] ??= [];
        $this->variationBaseDeliveryPrice[$key] ??= '';
        $this->variationLinkedOrderTypePrices[$key] ??= [];

        if (!isset($this->variationLinkedDeliveryPrice[$key])) {
            $this->variationLinkedDeliveryPrice[$key] = true;
        }

        foreach ($this->orderTypes as $orderType) {
            if ($this->variationLinkedOrderTypePrices[$key][$orderType->id] ?? true) {
                $this->variationOrderTypePrices[$key][$orderType->id] = $value;
            }
        }

        if ($this->variationLinkedDeliveryPrice[$key] ?? true) {
            $this->variationBaseDeliveryPrice[$key] = $value;
        }

        $this->calculateVariationDeliveryPrices($key);
        $this->updateVariationBreakdowns();
    }

    public function updatedVariationBaseDeliveryPrice($value, $key): void
    {
        $varPrice = $this->variationPrice[$key] ?? '';
        if ((string)$value !== (string)$varPrice) {
            $this->variationLinkedDeliveryPrice[$key] = false;
        }

        $this->calculateVariationDeliveryPrices((int)$key);
    }

    public function updatedVariationPlatformAvailability($value, $key): void
    {
        // Extract index and app ID from the key (format: index.appId)
        $keys = explode('.', $key);
        if (count($keys) === 2) {
            $index = (int)$keys[0];
            $this->calculateVariationDeliveryPrices($index);
        }
    }


    // UTILITY METHODS
    public function refreshCategories(): void
    {
        $this->categoryList = ItemCategory::all();
    }

    private function cleanupEmptyVariations(): void
    {
        // Remove any variations that have empty names or prices
        $keysToRemove = [];

        foreach ($this->variationName as $key => $value) {
            if (empty($value) || empty($this->variationPrice[$key])) {
                $keysToRemove[] = $key;
            }
        }

        // Remove identified empty variations
        foreach ($keysToRemove as $key) {
            $inputIndex = array_search($key, $this->inputs);
            if ($inputIndex !== false) {
                unset($this->inputs[$inputIndex]);
            }

            unset($this->variationName[$key]);
            unset($this->variationPrice[$key]);
            unset($this->variationOrderTypePrices[$key]);
            unset($this->variationPlatformAvailability[$key]);
            unset($this->variationBaseDeliveryPrice[$key]);
            unset($this->variationDeliveryPrices[$key]);
            unset($this->variationBreakdowns[$key]);
        }

        // DO NOT reindex - maintain original keys for proper data tracking
    }


    public function checkVariations(): void
    {
        if ($this->hasVariations) {
            $this->enableVariations();
        } else {
            $this->disableVariations();
        }
    }

    private function enableVariations(): void
    {
        $this->showItemPrice = false;
        $this->taxInclusivePriceDetails = null;
        $this->variationBreakdowns = $this->getVariationBreakdowns();

        if (empty($this->inputs) && $this->hasVariations) {
            $this->addMoreField($this->i);
        }
    }

    private function disableVariations(): void
    {
        $this->showItemPrice = true;
        $this->clearAllVariations();
    }

    private function clearAllVariations(): void
    {
        $this->variationName = [];
        $this->variationPrice = [];
        $this->variationOrderTypePrices = [];
        $this->variationPlatformAvailability = [];
        $this->variationBaseDeliveryPrice = [];
        $this->variationDeliveryPrices = [];
        $this->variationLinkedOrderTypePrices = [];
        $this->variationLinkedDeliveryPrice = [];
        $this->inputs = [];
        $this->i = 0;
    }

    // FORM SUBMISSION AND VALIDATION
    public function submitForm(): void
    {
        $this->validateForm();

        try {
            DB::beginTransaction();

            $menuItem = $this->createMenuItem();
            $this->handleTranslations($menuItem);
            $this->handleImageUpload($menuItem);
            $this->handleVariationsOrPricing($menuItem);
            $this->handleTaxes($menuItem);

            DB::commit();

            $this->handleSuccessfulSubmission();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->alert('error', __('messages.menuItemCreationFailed'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
        }
    }

    private function validateForm(): void
    {
        $this->updateTranslation();

        $this->cleanupEmptyVariations();

        if ($this->hasVariations && empty($this->variationName)) {
            $this->addError('variationName.0', __('validation.atLeastOneVariationRequired'));
            return;
        }

        $filledNameLocales = collect($this->translationNames)
            ->map(fn($v) => is_string($v) ? trim($v) : $v)
            ->filter(fn($v) => !empty($v));

        if ($filledNameLocales->isEmpty()) {
            $this->addError('translationNames', __('validation.itemNameRequiredAnyLanguage'));
            return;
        }

        // If has variations, set itemPrice to first variation price for main validation
        if ($this->hasVariations && !empty($this->variationPrice)) {
            $this->itemPrice = reset($this->variationPrice) ?: '0';
        }

        $rules = [
            'baseDeliveryPrice' => 'nullable|numeric|min:0',
            'itemCategory' => 'required',
            'menu' => 'required',
            'isAvailable' => 'required|boolean',
            'orderTypePrices.*' => 'nullable|numeric|min:0',
            'platformAvailability.*' => 'nullable|boolean',
        ];

        // Add validation rules for variations if they exist
        if ($this->hasVariations && !empty($this->variationName)) {
            $rules['variationName.*'] = 'required|string|max:255';
            $rules['variationPrice.*'] = 'required|numeric|min:0';
            $rules['variationOrderTypePrices.*.*'] = 'nullable|numeric|min:0';
            $rules['variationBaseDeliveryPrice.*'] = 'nullable|numeric|min:0';
        }

        // Only require itemPrice if not using variations
        if (!$this->hasVariations) {
            $rules['itemPrice'] = 'required|numeric|min:0';
        }

        $euSelectable = restaurant() ? restaurant()->selectableEuAllergenKeys() : [];
        if ($euSelectable !== []) {
            $rules['selectedEuAllergens'] = 'nullable|array';
            $rules['selectedEuAllergens.*'] = 'string|in:' . implode(',', $euSelectable);
        }

        $rules['selectedDietaryLabels'] = 'nullable|array';
        $rules['selectedDietaryLabels.*'] = 'string|in:' . implode(',', DietaryLabels::keys());
    }

    private function getValidationMessages(): array
    {
        return [
            'baseDeliveryPrice.numeric' => __('validation.baseDeliveryPriceMustBeNumeric'),
            'baseDeliveryPrice.min' => __('validation.baseDeliveryPriceMustBePositive'),
            'itemPrice.required_if' => __('validation.itemPriceRequired'),
            'itemPrice.numeric' => __('validation.itemPriceMustBeNumeric'),
            'itemPrice.min' => __('validation.itemPriceMustBePositive'),
        ];
    }

    private function getPrimaryItemNameLocale(): string
    {
        $filled = collect($this->translationNames)
            ->map(fn($v) => is_string($v) ? trim($v) : $v)
            ->filter(fn($v) => !empty($v));

        return $filled->has($this->currentLanguage)
            ? $this->currentLanguage
            : (string) $filled->keys()->first();
    }

    private function createMenuItem(): MenuItem
    {
        $primaryLocale = $this->getPrimaryItemNameLocale();

        $data = [
            'item_name' => is_string($this->translationNames[$primaryLocale] ?? null)
                ? trim($this->translationNames[$primaryLocale])
                : ($this->translationNames[$primaryLocale] ?? ''),
            'price' => $this->hasVariations ? 0 : (float) $this->itemPrice,
            'item_category_id' => $this->itemCategory,
            'description' => $this->translationDescriptions[$primaryLocale] ?? null,
            'is_available' => $this->isAvailable,
            'sell_by_weight' => $this->sellByWeight,
            'type' => $this->itemType,
            'menu_id' => $this->menu,
            'preparation_time' => $this->preparationTime,
            'kot_place_id' => $this->kitchenType,
            'tax_inclusive' => $this->isTaxModeItem ? $this->taxInclusive : false,
            'dietary_labels' => DietaryLabels::normalize($this->selectedDietaryLabels),
        ];

        $eu = $this->euAllergenKeysForPersist();
        if ($eu !== null) {
            $data['eu_allergen_keys'] = $eu;
        }

        return MenuItem::create($data);
    }

    /**
     * @return list<string>|null null when EU allergen UI is off for this restaurant
     */
    private function euAllergenKeysForPersist(): ?array
    {
        $r = restaurant();
        if (!$r) {
            return null;
        }

        $allowed = $r->selectableEuAllergenKeys();
        if ($allowed === []) {
            return null;
        }

        return array_values(array_unique(array_intersect($this->selectedEuAllergens, $allowed)));
    }

    private function handleTranslations(MenuItem $menuItem): void
    {
        $translations = collect($this->translationNames)
            ->filter(fn($name, $locale) => !empty($name) || !empty($this->translationDescriptions[$locale]))
            ->map(fn($name, $locale) => [
                'locale' => $locale,
                'item_name' => $name,
                'description' => $this->translationDescriptions[$locale] ?? ''
            ])->values()->all();

        if (!empty($translations)) {
            $menuItem->translations()->createMany($translations);
        }
    }

    private function handleImageUpload(MenuItem $menuItem): void
    {
        if ($this->itemImageTemp) {
            $menuItem->update([
                'image' => Files::uploadLocalOrS3($this->itemImageTemp, 'item', width: 350),
            ]);

            if ($menuItem->branch_id) {
                PosBranchCacheInvalidation::invalidateForBranch((int) $menuItem->branch_id);
            }
        }
    }

    private function handleVariationsOrPricing(MenuItem $menuItem): void
    {
        if ($this->hasVariations) {
            $this->createVariations($menuItem);
        } else {
            $this->savePricingData($menuItem->id);
        }
    }

    private function createVariations(MenuItem $menuItem): void
    {
        $validVariations = 0;

        foreach ($this->variationName as $key => $value) {
            if (!empty($value) && isset($this->variationPrice[$key]) && !empty($this->variationPrice[$key])) {
                $this->validate([
                    'variationPrice.' . $key => 'required|numeric'
                ], [
                    'variationPrice.' . $key . '.required' => __('validation.variationPriceRequired'),
                ]);

                $variationModel = MenuItemVariation::create([
                    'menu_item_id' => $menuItem->id,
                    'variation' => $value,
                    'price' => (float)$this->variationPrice[$key],
                    'batch_recipe_id' => $this->variationBatchRecipeId[$key] ?? null,
                    'batch_serving_size' => isset($this->variationBatchServingSize[$key]) && $this->variationBatchServingSize[$key] ? (float)$this->variationBatchServingSize[$key] : null,
                ]);

                // Save pricing data for this variation
                $this->savePricingData($menuItem->id, $variationModel->id, $key);
                $validVariations++;
            }
        }

        if ($validVariations === 0) {
            throw new \Exception(__('validation.atLeastOneVariationRequired'));
        }
    }

    private function handleTaxes(MenuItem $menuItem): void
    {
        if ($this->isTaxModeItem) {
            $menuItem->taxes()->sync($this->selectedTaxes ?: []);
            if (function_exists('restaurant') && restaurant()) {
                PosBranchCacheInvalidation::invalidateForRestaurant(restaurant());
            } else {
                PosBranchCacheInvalidation::invalidateForBranch(function_exists('branch') && branch() ? (int) branch()->id : null);
            }
        }
    }

    private function handleSuccessfulSubmission(): void
    {
        cache()->forget('restaurant_' . restaurant()->id . '_menu_item_stats');
        if (function_exists('branch') && branch()) {
            PosBranchCacheInvalidation::invalidateForBranch((int) branch()->id);
        }
        $this->resetForm();

        $this->dispatch('hideAddMenuItem');
        $this->dispatch('menuItemAdded');
        $this->dispatch('refreshCategories');

        $this->redirect(route('menu-items.index'), true);

        $this->alert('success', __('messages.menuItemAdded'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function resetForm()
    {
        $this->itemName = '';
        $this->menu = '';
        $this->translationNames = array_fill_keys(array_keys($this->languages), '');
        $this->translationDescriptions = array_fill_keys(array_keys($this->languages), '');
        $this->itemCategory = '';
        $this->itemPrice = '';
        $this->itemDescription = '';
        $this->itemType = 'veg';
        $this->itemImage = null;
        $this->itemImageTemp = null;
        $this->preparationTime = null;
        $this->variationName = [];
        $this->variationPrice = [];
        $this->variationOrderTypePrices = [];
        $this->variationPlatformAvailability = [];
        $this->variationBaseDeliveryPrice = [];
        $this->variationDeliveryPrices = [];
        $this->variationBreakdowns = [];
        $this->taxInclusivePriceDetails = null;
        $this->inputs = [];
        $this->i = 0;
        $this->showItemPrice = true;
        $this->hasVariations = false;
        $this->selectedTaxes = [];
        $this->selectedEuAllergens = [];
        $this->selectedDietaryLabels = [];
        $this->baseDeliveryPrice = '';
        $this->deliveryPrices = [];
        $this->orderTypePrices = [];
        $this->platformAvailability = [];
        $this->linkedOrderTypePrices = [];
        $this->linkedDeliveryPrice = true;
        $this->variationLinkedOrderTypePrices = [];
        $this->variationLinkedDeliveryPrice = [];

        foreach ($this->orderTypes as $orderType) {
            $this->orderTypePrices[$orderType->id] = '';
            $this->linkedOrderTypePrices[$orderType->id] = true;
        }
        foreach ($this->deliveryApps as $app) {
            $this->deliveryPrices[$app->id] = '';
            $this->platformAvailability[$app->id] = true;
        }
    }

    public function updateTranslation()
    {
        $this->translationNames[$this->currentLanguage] = $this->itemName;
        $this->translationDescriptions[$this->currentLanguage] = $this->itemDescription;
    }

    public function updatedCurrentLanguage()
    {
        $this->itemName = $this->translationNames[$this->currentLanguage];
        $this->itemDescription = $this->translationDescriptions[$this->currentLanguage];
    }

    public function showMenuCategoryModal()
    {
        $this->dispatch('showMenuCategoryModal');
    }

    public function updatedTaxInclusive()
    {
        $this->recalculateTaxBreakdowns();
    }

    public function updatedItemPrice($value): void
    {
        if (!$this->hasVariations) {
            foreach ($this->orderTypes as $orderType) {
                if ($this->linkedOrderTypePrices[$orderType->id] ?? true) {
                    $this->orderTypePrices[$orderType->id] = $value;
                }
            }

            if ($this->linkedDeliveryPrice) {
                $this->baseDeliveryPrice = $value;
            }

            $this->calculateDeliveryPrices();
        }

        $this->recalculateTaxBreakdowns();
    }

    public function updatedOrderTypePrices($value, $key): void
    {
        if ((string)$value !== (string)$this->itemPrice) {
            $this->linkedOrderTypePrices[$key] = false;
        }
    }

    public function toggleOrderTypePriceLink($orderTypeId): void
    {
        $linked = !($this->linkedOrderTypePrices[$orderTypeId] ?? true);
        $this->linkedOrderTypePrices[$orderTypeId] = $linked;

        if ($linked) {
            $this->orderTypePrices[$orderTypeId] = $this->itemPrice;
        }
    }

    public function toggleDeliveryPriceLink(): void
    {
        $this->linkedDeliveryPrice = !$this->linkedDeliveryPrice;

        if ($this->linkedDeliveryPrice) {
            $this->baseDeliveryPrice = $this->itemPrice;
            $this->calculateDeliveryPrices();
        }
    }

    public function updatedVariationOrderTypePrices($value, $key): void
    {
        $parts = explode('.', $key);
        if (count($parts) === 2) {
            [$varKey, $orderTypeId] = $parts;
            $varPrice = $this->variationPrice[$varKey] ?? '';
            if ((string)$value !== (string)$varPrice) {
                $this->variationLinkedOrderTypePrices[$varKey][$orderTypeId] = false;
            }
        }
    }

    public function toggleVariationOrderTypePriceLink($variationKey, $orderTypeId): void
    {
        $linked = !($this->variationLinkedOrderTypePrices[$variationKey][$orderTypeId] ?? true);
        $this->variationLinkedOrderTypePrices[$variationKey][$orderTypeId] = $linked;

        if ($linked) {
            $this->variationOrderTypePrices[$variationKey][$orderTypeId] = $this->variationPrice[$variationKey] ?? '';
        }
    }

    public function toggleVariationDeliveryPriceLink($variationKey): void
    {
        $linked = !($this->variationLinkedDeliveryPrice[$variationKey] ?? true);
        $this->variationLinkedDeliveryPrice[$variationKey] = $linked;

        if ($linked) {
            $this->variationBaseDeliveryPrice[$variationKey] = $this->variationPrice[$variationKey] ?? '';
            $this->calculateVariationDeliveryPrices((int)$variationKey);
        }
    }

    public function updatedSelectedTaxes(): void
    {
        $this->recalculateTaxBreakdowns();
    }

    private function recalculateTaxBreakdowns(): void
    {
        if ($this->hasVariations) {
            $this->variationBreakdowns = $this->getVariationBreakdowns();
            $this->taxInclusivePriceDetails = null;
        } else {
            $this->taxInclusivePriceDetails = $this->getTaxInclusivePriceDetailsProperty();
            $this->variationBreakdowns = [];
        }
    }

    public function updatedItemImageTemp()
    {
        $this->itemImage = null;
        $this->validateImage();
    }

    public function removeSelectedImage()
    {
        $this->itemImageTemp = null;
        $this->itemImage = null;
    }

    public function validateImage()
    {
        if (!$this->itemImageTemp) return;

        $this->validate([
            'itemImageTemp' => \App\Support\ImageUpload::IMAGE_MIMES_MAX_2048,
        ]);

        // Check image dimensions
        $imageInfo = @getimagesize($this->itemImageTemp->getRealPath());
        if ($imageInfo) {
            $width = $imageInfo[0];
            $height = $imageInfo[1];

            // Recommend minimum dimensions
            if ($width < 200 || $height < 200) {
                $this->addError('itemImageTemp', 'Image dimensions are too small. Recommended minimum: 200x200 pixels.');
            }
        }
    }

    public function formatFileSize(int $bytes): string
    {
        $units = ['bytes', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return number_format($bytes, 2) . ' ' . $units[$unitIndex];
    }

    // TAX CALCULATIONS
    #[Computed]
    public function getTaxInclusivePriceDetailsProperty(): ?array
    {
        if (empty($this->itemPrice) || !$this->isTaxModeItem) {
            return null;
        }

        return (new MenuItem)->getTaxBreakdown(
            (float)$this->itemPrice,
            $this->selectedTaxes,
            $this->taxInclusive
        );
    }

    private function getVariationBreakdowns(): array
    {
        if (!$this->isTaxModeItem) {
            return [];
        }

        $breakdowns = [];
        foreach ($this->variationPrice as $key => $price) {
            if (!empty($price)) {
                $breakdowns[$key] = [
                    'name' => $this->variationName[$key] ?? '',
                    'breakdown' => (new MenuItem)->getTaxBreakdown(
                        (float)$price,
                        $this->selectedTaxes,
                        $this->taxInclusive
                    )
                ];
            }
        }
        return $breakdowns;
    }

    private function updateVariationBreakdowns(): void
    {
        $this->variationBreakdowns = $this->getVariationBreakdowns();
    }

    // PRICING MANAGEMENT
    public function updatedBaseDeliveryPrice(): void
    {
        if ((string)$this->baseDeliveryPrice !== (string)$this->itemPrice) {
            $this->linkedDeliveryPrice = false;
        }

        $this->calculateDeliveryPrices();
    }

    private function calculateDeliveryPrices(): void
    {
        $basePrice = !empty($this->baseDeliveryPrice)
            ? (float)$this->baseDeliveryPrice
            : (!empty($this->itemPrice) ? (float)$this->itemPrice : 0);

        foreach ($this->deliveryApps as $app) {
            $commission = $app->commission_value ?? 0;
            $finalPrice = $basePrice + ($basePrice * $commission / 100);
            $this->deliveryPrices[$app->id] = number_format($finalPrice, 2);
        }
    }

    /**
     * Save pricing data for menu item or variation
     */
    private function savePricingData(int $menuItemId, ?int $variationId = null, ?int $localIndex = null): void
    {
        if ($variationId !== null && $localIndex !== null) {
            $this->saveVariationPricingData($menuItemId, $variationId, $localIndex);
        } else {
            $this->saveItemPricingData($menuItemId);
        }
    }

    private function saveVariationPricingData(int $menuItemId, int $variationId, int $localIndex): void
    {
        if (!isset($this->variationPrice[$localIndex])) return;

        $basePrice = (float)$this->variationPrice[$localIndex];
        $orderTypePrices = $this->variationOrderTypePrices[$localIndex] ?? [];
        $baseDeliveryPrice = $this->variationBaseDeliveryPrice[$localIndex] ?? '';

        $this->createPricingRecords($menuItemId, $basePrice, $orderTypePrices, $baseDeliveryPrice, $variationId, $localIndex);
    }

    private function saveItemPricingData(int $menuItemId): void
    {
        $basePrice = (float)$this->itemPrice;
        $this->createPricingRecords($menuItemId, $basePrice, $this->orderTypePrices, $this->baseDeliveryPrice);
    }

    private function createPricingRecords(
        int $menuItemId,
        float $basePrice,
        array $orderTypePrices,
        string $baseDeliveryPrice,
        ?int $variationId = null,
        ?int $localIndex = null
    ): void {
        // Save order type pricing (excluding delivery)
        foreach ($this->orderTypes as $orderType) {

            $orderTypePrice = !empty($orderTypePrices[$orderType->id]) ? (float)$orderTypePrices[$orderType->id] : $basePrice;

            if (strtolower($orderType->slug ?? $orderType->name) === 'delivery') {
                $deliveryBase = !empty($baseDeliveryPrice) ? (float)$baseDeliveryPrice : $basePrice;
                $orderTypePrice = $deliveryBase;
            }

            MenuItemPrices::create([
                'menu_item_id' => $menuItemId,
                'order_type_id' => $orderType->id,
                'delivery_app_id' => null,
                'menu_item_variation_id' => $variationId,
                'calculated_price' => $orderTypePrice,
                'final_price' => $orderTypePrice,
                'status' => true,
            ]);
        }

        // Save delivery platform pricing
        $this->saveDeliveryPlatformPricing($menuItemId, $basePrice, $variationId, $baseDeliveryPrice, $localIndex);
    }

    private function saveDeliveryPlatformPricing(int $menuItemId, float $basePrice, ?int $variationId = null, string $baseDeliveryPrice = '', ?int $localIndex = null): void
    {
        $deliveryOrderType = $this->orderTypes->where('slug', 'delivery')->first();

        if (!$deliveryOrderType) return;

        foreach ($this->deliveryApps as $app) {
            // Determine availability - for variations, check variation-specific availability
            // Default to TRUE if not explicitly set to false
            $isAvailable = true;

            if ($localIndex !== null) {
                // For variations - check if the platform is available (defaults to true)
                $isAvailable = isset($this->variationPlatformAvailability[$localIndex][$app->id])
                    ? (bool)$this->variationPlatformAvailability[$localIndex][$app->id]
                    : true;
            } else {
                // For regular items - check if the platform is available (defaults to true)
                $isAvailable = isset($this->platformAvailability[$app->id])
                    ? (bool)$this->platformAvailability[$app->id]
                    : true;
            }

            // Get the base delivery price for calculation
            $deliveryBase = $basePrice; // Default to variation/item price

            if ($localIndex !== null) {
                // For variations, check if base delivery price is set
                if (!empty($this->variationBaseDeliveryPrice[$localIndex])) {
                    $deliveryBase = (float)$this->variationBaseDeliveryPrice[$localIndex];
                }
            } else {
                // For regular items
                if (!empty($baseDeliveryPrice)) {
                    $deliveryBase = (float)$baseDeliveryPrice;
                } elseif (!empty($this->baseDeliveryPrice)) {
                    $deliveryBase = (float)$this->baseDeliveryPrice;
                }
            }

            // Calculate final price with commission
            $commission = (float)($app->commission_value ?? 0);
            $calculatedPrice = $deliveryBase + ($deliveryBase * $commission / 100);

            MenuItemPrices::create([
                'menu_item_id' => $menuItemId,
                'order_type_id' => $deliveryOrderType->id,
                'delivery_app_id' => $app->id,
                'menu_item_variation_id' => $variationId,
                'calculated_price' => $deliveryBase,
                'final_price' => $calculatedPrice,
                'status' => $isAvailable, // Save the toggle state
            ]);
        }
    }

    public function orderTypeColor($id)
    {
        // Use a hash to generate a color from the id
        $colors = [
            'bg-red-500',
            'bg-gray-600',
            'bg-blue-500',
            'bg-pink-500',
            'bg-purple-500',
            'bg-yellow-500',
            'bg-rose-700',
            'bg-green-500',
            'bg-indigo-500',
            'bg-teal-500',
            'bg-lime-500',
            'bg-orange-500',
            'bg-cyan-500',
            'bg-sky-500',
            'bg-amber-500',
            'bg-rose-400',
        ];
        // Use crc32 to get a consistent index
        $index = abs(crc32($id)) % count($colors);
        return $colors[$index];
    }

    public function render()
    {
        return view('livewire.forms.create-menu-item');
    }
}

