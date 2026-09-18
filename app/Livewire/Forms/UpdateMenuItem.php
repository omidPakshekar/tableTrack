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
use App\Scopes\AvailableMenuItemScope;
use App\Services\Pos\PosBranchCacheInvalidation;
use App\Support\DietaryLabels;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class UpdateMenuItem extends Component
{
    use WithFileUploads, LivewireAlert;

    protected $listeners = ['refreshCategories'];

    // MenuItem being edited
    public MenuItem $menuItem;

    // Core Properties
    #[Validate('required')]
    public string $itemName = '';

    #[Validate('required')]
    public string $menu = '';

    #[Validate('required')]
    public string $itemCategory = '';

    #[Validate('nullable|string')]
    public string $itemDescription = '';

    #[Validate('required|in:veg,non-veg,egg,drink,halal,other')]
    public string $itemType = 'veg';

    #[Validate('required|numeric|min:0')]
    public string $itemPrice = '';

    #[Validate('nullable|integer|min:0')]
    public ?int $preparationTime = null;

    #[Validate('required')]
    public $isAvailable = '1';

    #[Validate('nullable|string')]
    public ?string $kitchenType = null;

    #[Validate('required')]
    public $showOnCustomerSite = '1';

    #[Validate('required')]
    public $inStock = '1';

    #[Validate('required')]
    public $sellByWeight = '0';

    #[Validate(\App\Support\ImageUpload::NULLABLE_MIMES_MAX_2048)]
    public $itemImageTemp;

    public ?string $itemImage = null;

    // Translation Properties
    public array $translationNames = [];
    public array $translationDescriptions = [];
    public array $originalTranslations = [];
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
    public array $variationIds = [];

    // Batch Recipe Properties (for Inventory module)
    public ?int $batchRecipeId = null;
    public ?float $batchServingSize = null;
    public array $variationBatchRecipeId = [];
    public array $variationBatchServingSize = [];

    /** Direct-sale inventory SKU (1:1 recipe) */
    public $directSaleInventoryItemId = null;
    public $inventoryItemsForDirectSale = [];

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

    // Modal Properties
    public bool $showMenuCategoryModal = false;

    // Collections (computed properties to avoid N+1 queries)
    public $categoryList;
    public $menus;
    public $kitchenTypes;
    public $taxes;
    public $orderTypes;
    public $deliveryApps;
    public $menuItemId;

    public function mount(): void
    {
        $this->menuItem = MenuItem::withoutGlobalScope(AvailableMenuItemScope::class)
            ->with(['translations', 'variations', 'prices', 'taxes'])
            ->findOrFail($this->menuItemId);

        $this->initializeCollections();
        $this->initializeLanguages();
        $this->loadMenuItemData();
        $this->initializePricing();
        $this->initializeTaxSettings();
    }

    /**
     * Initialize all database collections
     */
    private function initializeCollections(): void
    {
        $this->categoryList = ItemCategory::all();
        $this->menus = Menu::all();
        $this->kitchenTypes = KotPlace::where('is_active', true)->get();
        $this->taxes = Tax::where('restaurant_id', restaurant()->id)->get();
        $this->orderTypes = OrderType::where('is_active', 1)
            ->availableForRestaurant()
            ->get();
        $this->deliveryApps = DeliveryPlatform::where('is_active', 1)->get();
    }

    /**
     * Initialize language settings and arrays
     */
    private function initializeLanguages(): void
    {
        $this->languages = languages()->pluck('language_name', 'language_code')->toArray();
        $this->translationNames = array_fill_keys(array_keys($this->languages), '');
        $this->translationDescriptions = array_fill_keys(array_keys($this->languages), '');
        $this->globalLocale = global_setting()->locale;
        $this->currentLanguage = $this->globalLocale;
    }

    /**
     * Load existing menu item data
     */
    private function loadMenuItemData(): void
    {
        // Load basic data
        $this->menu = (string)$this->menuItem->menu_id;
        $this->itemCategory = (string)$this->menuItem->item_category_id;
        $this->itemPrice = (string)$this->menuItem->price;
        $this->preparationTime = $this->menuItem->preparation_time;
        $this->itemType = $this->menuItem->type;
        $this->isAvailable = $this->menuItem->is_available ? '1' : '0';
        $this->inStock = $this->menuItem->in_stock ? '1' : '0';
        $this->sellByWeight = ($this->menuItem->sell_by_weight ?? false) ? '1' : '0';
        $this->kitchenType = $this->menuItem->kot_place_id ? (string)$this->menuItem->kot_place_id : null;
        $this->showOnCustomerSite = $this->menuItem->show_on_customer_site ? '1' : '0';
        $this->itemImage = $this->menuItem->image;

        $allowedEu = restaurant()->selectableEuAllergenKeys();
        $storedEu = $this->menuItem->eu_allergen_keys;
        $this->selectedEuAllergens = ($allowedEu !== [] && is_array($storedEu))
            ? array_values(array_intersect($storedEu, $allowedEu))
            : [];

        $storedDietary = $this->menuItem->dietary_labels;
        $this->selectedDietaryLabels = is_array($storedDietary)
            ? DietaryLabels::normalize($storedDietary)
            : [];

        // Load batch recipe data
        if (in_array('Inventory', restaurant_modules())) {
            $this->batchRecipeId = $this->menuItem->batch_recipe_id;
            $this->batchServingSize = $this->menuItem->batch_serving_size;

            if (class_exists(\Modules\Inventory\Services\DirectSaleRecipeService::class)) {
                $this->directSaleInventoryItemId = \Modules\Inventory\Services\DirectSaleRecipeService::findDirectSaleInventoryItemId(
                    (int) $this->menuItem->id
                );
                $this->inventoryItemsForDirectSale = \Modules\Inventory\Entities\InventoryItem::query()
                    ->with('unit')
                    ->where('branch_id', $this->menuItem->branch_id)
                    ->orderBy('name')
                    ->get(['id', 'name', 'unit_id']);
            }
        }

        // Load translations
        foreach ($this->menuItem->translations as $translation) {
            $this->translationNames[$translation->locale] = $translation->item_name;
            $this->translationDescriptions[$translation->locale] = $translation->description;
            $this->originalTranslations[$translation->locale] = [
                'item_name' => $translation->item_name,
                'description' => $translation->description
            ];
        }

        $this->translationNames[$this->globalLocale] = $this->menuItem->item_name;
        $this->translationDescriptions[$this->globalLocale] = $this->menuItem->description;

        // Load variations
        $this->hasVariations = $this->menuItem->variations->count() > 0;
        $this->showItemPrice = !$this->hasVariations;

        if ($this->hasVariations) {
            foreach ($this->menuItem->variations as $key => $variation) {
                $this->variationName[$key] = $variation->variation;
                $this->variationPrice[$key] = (string)$variation->price;
                $this->variationIds[$key] = $variation->id;
                if (in_array('Inventory', restaurant_modules())) {
                    $this->variationBatchRecipeId[$key] = $variation->batch_recipe_id;
                    $this->variationBatchServingSize[$key] = $variation->batch_serving_size;
                }
                $this->inputs[] = $key;
                $this->i = $key + 1;

                // Load variation pricing
                $this->loadVariationPricing($key, $variation->id);
            }
        }

        $this->updatedCurrentLanguage();
        $this->updateTranslation();
    }

    /**
     * Initialize pricing arrays and load existing prices
     */
    private function initializePricing(): void
    {
        foreach ($this->orderTypes as $orderType) {
            $this->orderTypePrices[$orderType->id] = '';
            $this->linkedOrderTypePrices[$orderType->id] = true;
        }

        foreach ($this->deliveryApps as $app) {
            $this->platformAvailability[$app->id] = true;
        }

        $this->linkedDeliveryPrice = true;

        if (!$this->hasVariations) {
            $this->loadItemPricing();
        }
    }

    /**
     * Load pricing for the main item
     */
    private function loadItemPricing(): void
    {
        $existingPrices = MenuItemPrices::where('menu_item_id', $this->menuItem->id)
            ->whereNull('menu_item_variation_id')
            ->get();

        foreach ($existingPrices as $price) {
            if ($price->delivery_app_id) {
                // Delivery platform price
                $this->deliveryPrices[$price->delivery_app_id] = number_format((float)$price->final_price, 2);
                $this->platformAvailability[$price->delivery_app_id] = (bool)$price->status;
            } else {
                // Order type price
                $this->orderTypePrices[$price->order_type_id] = (string)$price->final_price;

                // Check if this is delivery order type to set base delivery price
                $orderType = $this->orderTypes->firstWhere('id', $price->order_type_id);
                if ($orderType && strtolower($orderType->slug ?? $orderType->name) === 'delivery') {
                    $this->baseDeliveryPrice = (string)$price->calculated_price;
                }
            }
        }

        if ($existingPrices->count() === 0) {
            $this->baseDeliveryPrice = $this->itemPrice;
            foreach ($this->deliveryApps as $app) {
                $this->deliveryPrices[$app->id] = $this->itemPrice;
                $this->platformAvailability[$app->id] = true;
            }

            foreach ($this->orderTypes as $orderType) {
                $this->orderTypePrices[$orderType->id] = (string)$this->itemPrice;
            }
        }

        $this->calculateDeliveryPrices();

        // Determine linked state by comparing with base price
        $baseStr = (string)(float)$this->itemPrice;
        foreach ($this->orderTypes as $orderType) {
            $priceStr = (string)(float)($this->orderTypePrices[$orderType->id] ?? '');
            $this->linkedOrderTypePrices[$orderType->id] = ($priceStr === $baseStr);
        }

        $deliveryStr = (string)(float)($this->baseDeliveryPrice ?: '0');
        $this->linkedDeliveryPrice = ($deliveryStr === $baseStr);
    }

    /**
     * Load pricing for a variation
     */
    private function loadVariationPricing(int $index, int $variationId): void
    {
        $existingPrices = MenuItemPrices::where('menu_item_id', $this->menuItem->id)
            ->where('menu_item_variation_id', $variationId)
            ->get();

        // Initialize arrays
        $this->variationOrderTypePrices[$index] = [];
        $this->variationPlatformAvailability[$index] = [];
        $this->variationDeliveryPrices[$index] = [];

        // Initialize order type prices with empty values
        foreach ($this->orderTypes as $orderType) {
            $this->variationOrderTypePrices[$index][$orderType->id] = '';
        }

        // Initialize delivery platform availability
        // Only set defaults for platforms that don't have existing prices
        // This avoids unnecessary overwrites
        $existingPlatformIds = $existingPrices->where('delivery_app_id', '!=', null)
            ->pluck('delivery_app_id')
            ->toArray();

        foreach ($this->deliveryApps as $app) {
            // Only set default if no existing price record
            if (!in_array($app->id, $existingPlatformIds)) {
                $this->variationPlatformAvailability[$index][$app->id] = true;
            }
        }

        // Load existing prices (this will overwrite defaults where needed)
        foreach ($existingPrices as $price) {
            if ($price->delivery_app_id) {
                // Delivery platform price
                $this->variationDeliveryPrices[$index][$price->delivery_app_id] = number_format((float)$price->final_price, 2);
                $this->variationPlatformAvailability[$index][$price->delivery_app_id] = (bool)$price->status;
            } else {
                // Order type price
                $this->variationOrderTypePrices[$index][$price->order_type_id] = (string)$price->final_price;

                // Check if this is delivery order type to set base delivery price
                $orderType = $this->orderTypes->firstWhere('id', $price->order_type_id);
                if ($orderType && strtolower($orderType->slug ?? $orderType->name) === 'delivery') {
                    $this->variationBaseDeliveryPrice[$index] = (string)$price->calculated_price;
                }
            }
        }

        // Calculate delivery prices for display
        $this->calculateVariationDeliveryPrices($index);

        // Determine linked state by comparing with variation price
        $varBaseStr = (string)(float)($this->variationPrice[$index] ?? '0');
        $this->variationLinkedOrderTypePrices[$index] = [];
        foreach ($this->orderTypes as $orderType) {
            $priceStr = (string)(float)($this->variationOrderTypePrices[$index][$orderType->id] ?? '');
            $this->variationLinkedOrderTypePrices[$index][$orderType->id] = ($priceStr === $varBaseStr);
        }

        $deliveryStr = (string)(float)($this->variationBaseDeliveryPrice[$index] ?? '0');
        $this->variationLinkedDeliveryPrice[$index] = ($deliveryStr === $varBaseStr);
    }

    /**
     * Initialize tax settings
     */
    private function initializeTaxSettings(): void
    {
        $this->isTaxModeItem = (restaurant()->tax_mode === 'item');
        $this->selectedTaxes = $this->menuItem->taxes->pluck('id')->toArray();
        $this->taxInclusive = (bool)($this->menuItem->tax_inclusive ?? restaurant()->tax_inclusive ?? false);

        // Calculate tax breakdown for initial display
        if ($this->hasVariations) {
            $this->variationBreakdowns = $this->getVariationBreakdowns();
            $this->taxInclusivePriceDetails = null;
        } else {
            $this->taxInclusivePriceDetails = $this->getTaxInclusivePriceDetailsProperty();
            $this->variationBreakdowns = [];
        }
    }

    // VARIATION MANAGEMENT
    public function addMoreField(int $i): void
    {
        $i = $i + 1;
        $this->i = $i;
        $this->inputs[] = $i;

        if (count($this->inputs) > 0) {
            $this->showItemPrice = false;
        }

        // Explicitly set that this new variation has NO existing ID
        // This is critical to differentiate new variations from existing ones
        $this->variationIds[$i] = null;

        // Initialize variation name and price as empty
        $this->variationName[$i] = '';
        $this->variationPrice[$i] = '';

        // Initialize batch recipe properties if Inventory module is enabled
        if (in_array('Inventory', restaurant_modules())) {
            $this->variationBatchRecipeId[$i] = null;
            $this->variationBatchServingSize[$i] = null;
        }

        // Initialize pricing for new variation
        $this->initializeVariationPricing($i);
    }

    public function removeField(int $i): void
    {
        unset($this->inputs[$i]);
        unset($this->variationName[$i]);
        unset($this->variationPrice[$i]);
        unset($this->variationIds[$i]);
        unset($this->variationOrderTypePrices[$i]);
        unset($this->variationPlatformAvailability[$i]);
        unset($this->variationBaseDeliveryPrice[$i]);
        unset($this->variationDeliveryPrices[$i]);
        unset($this->variationBreakdowns[$i]);
        unset($this->variationBatchRecipeId[$i]);
        unset($this->variationBatchServingSize[$i]);
    }

    private function initializeVariationPricing(int $index): void
    {
        $this->variationOrderTypePrices[$index] = [];
        $this->variationLinkedOrderTypePrices[$index] = [];
        foreach ($this->orderTypes as $orderType) {
            $this->variationOrderTypePrices[$index][$orderType->id] = '';
            $this->variationLinkedOrderTypePrices[$index][$orderType->id] = true;
        }

        $this->variationPlatformAvailability[$index] = [];
        foreach ($this->deliveryApps as $app) {
            $this->variationPlatformAvailability[$index][$app->id] = true;
        }

        $this->variationBaseDeliveryPrice[$index] = '';
        $this->variationLinkedDeliveryPrice[$index] = true;

        $this->variationDeliveryPrices[$index] = [];
        $this->calculateVariationDeliveryPrices($index);
    }

    /**
     * Calculate delivery prices for a variation
     */
    private function calculateVariationDeliveryPrices(int $index): void
    {
        $basePrice = !empty($this->variationBaseDeliveryPrice[$index])
            ? (float)$this->variationBaseDeliveryPrice[$index]
            : (!empty($this->variationPrice[$index]) ? (float)$this->variationPrice[$index] : 0);

        $this->variationDeliveryPrices[$index] = [];

        foreach ($this->deliveryApps as $app) {
            $commission = $app->commission_value ?? 0;
            $finalPrice = $basePrice + ($basePrice * $commission / 100);
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
        $this->recalculateTaxBreakdowns();
    }

    public function updatedVariationBaseDeliveryPrice($value, $key): void
    {
        $varPrice = $this->variationPrice[$key] ?? '';
        if ((string)$value !== (string)$varPrice) {
            $this->variationLinkedDeliveryPrice[$key] = false;
        }

        $this->calculateVariationDeliveryPrices($key);
    }

    public function updatedVariationPlatformAvailability($value, $key): void
    {
        // Key format: "index.appId"
        $parts = explode('.', $key);
        if (count($parts) >= 2) {
            [$index, $appId] = $parts;
            $this->calculateVariationDeliveryPrices((int)$index);
        }
    }

    // UTILITY METHODS
    public function refreshCategories(): void
    {
        $this->categoryList = ItemCategory::all();
    }

    public function updatedHasVariations($value)
    {
        if ($value) {
            $this->showItemPrice = false;
            if (count($this->inputs) == 0) {
                $this->addMoreField($this->i);
            }
            $this->variationBreakdowns = $this->getVariationBreakdowns();
            $this->itemPrice = '0';
            $this->taxInclusivePriceDetails = null;
        } else {
            $this->showItemPrice = true;
            $this->taxInclusivePriceDetails = $this->getTaxInclusivePriceDetailsProperty();
            $this->variationBreakdowns = [];
        }
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
        if (count($this->inputs) == 0) {
            $this->addMoreField($this->i);
        }
        $this->variationBreakdowns = $this->getVariationBreakdowns();
        $this->taxInclusivePriceDetails = null;
    }

    private function disableVariations(): void
    {
        $this->showItemPrice = true;
        $this->taxInclusivePriceDetails = $this->getTaxInclusivePriceDetailsProperty();
        $this->variationBreakdowns = [];

        // If variations are now disabled, delete all old variations
        if ($this->menuItem->variations->count() > 0) {
            MenuItemVariation::where('menu_item_id', $this->menuItem->id)->delete();
        }
    }

    // FORM SUBMISSION AND VALIDATION
    public function submitForm(): void
    {
        try {
            DB::beginTransaction();

            // Check if variations are enabled but no valid variations exist
            if ($this->hasVariations) {
                $hasAtLeastOne = false;
                foreach ($this->inputs as $key => $value) {
                    if (!empty($this->variationName[$key]) && !empty($this->variationPrice[$key])) {
                        $hasAtLeastOne = true;
                        break;
                    }
                }
                if (!$hasAtLeastOne) {
                    $this->addError('variationName.0', __('validation.atLeastOneVariationRequired'));
                    return;
                }
            }

            $this->validateForm();
            $this->updateMenuItem();
            $this->syncDirectSaleRecipe();
            $this->handleTranslations($this->menuItem);
            $this->handleImageUpload($this->menuItem);
            $this->handleVariationsOrPricing($this->menuItem);
            $this->handleTaxes($this->menuItem);

            DB::commit();

            $this->handleSuccessfulSubmission();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->alert('error', __('messages.somethingWentWrong') . ': ' . $e->getMessage());
        }
    }

    private function syncDirectSaleRecipe(): void
    {
        if (! in_array('Inventory', restaurant_modules(), true)) {
            return;
        }

        if (! class_exists(\Modules\Inventory\Services\DirectSaleRecipeService::class)) {
            return;
        }

        // Only upsert when user picked a SKU, or cleared an existing direct-sale link.
        // Avoid wiping multi-ingredient recipes when the picker was never used.
        $selectedId = $this->directSaleInventoryItemId ? (int) $this->directSaleInventoryItemId : null;
        $existingId = \Modules\Inventory\Services\DirectSaleRecipeService::findDirectSaleInventoryItemId(
            (int) $this->menuItem->id
        );

        if ($selectedId === null && $existingId === null) {
            return;
        }

        \Modules\Inventory\Services\DirectSaleRecipeService::upsertForMenuItem(
            (int) $this->menuItem->id,
            $selectedId
        );
    }

    private function validateForm(): void
    {
        $rules = [
            'translationNames.' . $this->globalLocale => 'required',
            'baseDeliveryPrice' => 'nullable|numeric|min:0',
            'itemCategory' => 'required',
            'menu' => 'required',
            'isAvailable' => 'required',
            'showOnCustomerSite' => 'required',
            'platformAvailability.*' => 'nullable|boolean',
        ];

        // Add validation for variations if hasVariations is true
        if ($this->hasVariations) {
            foreach ($this->inputs as $key => $value) {
                if (isset($this->variationName[$key]) || isset($this->variationPrice[$key])) {
                    $rules['variationName.' . $key] = 'required';
                    $rules['variationPrice.' . $key] = 'required|numeric|min:0';
                }
            }
        } else {
            // Validate order type prices for non-variation items
            foreach ($this->orderTypes as $orderType) {
                $isDelivery = strtolower($orderType->slug ?? $orderType->name) === 'delivery';
                // If it's the delivery order type and marked as default, make price nullable (not required)
                if ($isDelivery && !empty($orderType->is_default)) {
                    $rules['orderTypePrices.' . $orderType->id] = 'nullable|numeric|min:0';
                } else {
                    $rules['orderTypePrices.' . $orderType->id] = 'required|numeric|min:0';
                }
            }
            $rules['itemPrice'] = 'required|numeric|min:0';
        }

        // Validate image if present
        if ($this->itemImageTemp) {
            $this->validateImage();
        }

        $euSelectable = restaurant() ? restaurant()->selectableEuAllergenKeys() : [];
        if ($euSelectable !== []) {
            $rules['selectedEuAllergens'] = 'nullable|array';
            $rules['selectedEuAllergens.*'] = 'string|in:' . implode(',', $euSelectable);
        }

        $rules['selectedDietaryLabels'] = 'nullable|array';
        $rules['selectedDietaryLabels.*'] = 'string|in:' . implode(',', DietaryLabels::keys());

        $this->validate($rules, $this->getValidationMessages());
    }

    private function getValidationMessages(): array
    {
        $messages = [
            // Item name validation
            'translationNames.' . $this->globalLocale . '.required' => __('validation.itemNameRequired', [
                'language' => $this->languages[$this->globalLocale]
            ]),

            // Base delivery price validation
            'baseDeliveryPrice.numeric' => __('validation.baseDeliveryPriceMustBeNumeric'),
            'baseDeliveryPrice.min' => __('validation.baseDeliveryPriceMustBePositive'),

            // Item price validation (for non-variation items)
            'itemPrice.required' => __('validation.itemPriceRequired'),
            'itemPrice.numeric' => __('validation.itemPriceMustBeNumeric'),
            'itemPrice.min' => __('validation.itemPriceMustBePositive'),

            // Category and menu validation
            'itemCategory.required' => __('validation.categoryRequired'),
            'menu.required' => __('validation.menuRequired'),

            // Boolean validations
            'isAvailable.required' => __('validation.availabilityRequired'),
            'isAvailable.boolean' => __('validation.availabilityMustBeBoolean'),
            'showOnCustomerSite.required' => __('validation.showOnCustomerSiteRequired'),
            'showOnCustomerSite.boolean' => __('validation.showOnCustomerSiteMustBeBoolean'),
        ];

        // Add validation messages for order type prices (non-variation)
        if (!$this->hasVariations) {
            foreach ($this->orderTypes as $orderType) {
                $messages['orderTypePrices.' . $orderType->id . '.required'] = __('validation.orderTypePriceRequired', [
                    'orderType' => $orderType->order_type_name
                ]);
                $messages['orderTypePrices.' . $orderType->id . '.numeric'] = __('validation.priceMustBeNumeric');
                $messages['orderTypePrices.' . $orderType->id . '.min'] = __('validation.priceMinZero');
            }
        }

        // Add variation-specific validation messages
        if ($this->hasVariations) {
            foreach ($this->inputs as $key => $value) {
                // Use $value as the actual index
                $messages['variationName.' . $value . '.required'] = __('validation.variationNameRequired');
                $messages['variationName.' . $value . '.string'] = __('validation.variationNameMustBeString');
                $messages['variationName.' . $value . '.max'] = __('validation.variationNameMaxLength');
                $messages['variationPrice.' . $value . '.required'] = __('validation.variationPriceRequired');
                $messages['variationPrice.' . $value . '.numeric'] = __('validation.variationPriceMustBeNumeric');
                $messages['variationPrice.' . $value . '.min'] = __('validation.variationPriceMustBePositive');
                $messages['variationOrderTypePrices.' . $value . '.*.numeric'] = __('validation.priceMustBeNumeric');
                $messages['variationOrderTypePrices.' . $value . '.*.min'] = __('validation.priceMinZero');
                $messages['variationBaseDeliveryPrice.' . $value . '.numeric'] = __('validation.baseDeliveryPriceMustBeNumeric');
                $messages['variationBaseDeliveryPrice.' . $value . '.min'] = __('validation.baseDeliveryPriceMustBePositive');
            }
        }

        return $messages;
    }

    private function updateMenuItem(): void
    {
        $updateData = [
            'item_name' => $this->translationNames[$this->globalLocale],
            'price' => (!$this->hasVariations) ? $this->itemPrice : 0,
            'item_category_id' => $this->itemCategory,
            'description' => $this->translationDescriptions[$this->globalLocale],
            'type' => $this->itemType,
            'preparation_time' => $this->preparationTime,
            'menu_id' => $this->menu,
            'is_available' => $this->normalizeBoolean($this->isAvailable),
            'kot_place_id' => $this->kitchenType,
            'show_on_customer_site' => $this->normalizeBoolean($this->showOnCustomerSite),
            'sell_by_weight' => $this->normalizeBoolean($this->sellByWeight),
            'tax_inclusive' => $this->isTaxModeItem ? $this->taxInclusive : (restaurant()->tax_inclusive ?? false),
            'eu_allergen_keys' => $this->euAllergenKeysForUpdate(),
            'dietary_labels' => DietaryLabels::normalize($this->selectedDietaryLabels),
        ];

        // Add inStock and batch recipe data only if Inventory module is enabled
        if (in_array('Inventory', restaurant_modules())) {
            $updateData['in_stock'] = $this->normalizeBoolean($this->inStock);
            $updateData['batch_recipe_id'] = $this->batchRecipeId;
            $updateData['batch_serving_size'] = $this->batchServingSize;
        }

        MenuItem::withoutGlobalScope(AvailableMenuItemScope::class)
            ->where('id', $this->menuItem->id)
            ->update($updateData);

        // Refresh the model to get updated data
        $this->menuItem->refresh();
    }

    /**
     * Persisted EU allergen keys for this item, or null when the feature is disabled for the restaurant.
     *
     * @return list<string>|null
     */
    private function euAllergenKeysForUpdate(): ?array
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

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return (bool)$value;
    }

    private function handleTranslations(MenuItem $menuItem): void
    {
        // Efficiently update translations - only update what has changed
        foreach ($this->translationNames as $locale => $name) {
            $description = $this->translationDescriptions[$locale];

            // Skip empty translations
            if (empty($name) && empty($description)) {
                continue;
            }

            $isNew = !isset($this->originalTranslations[$locale]);
            $hasChanged = $isNew ||
                $this->originalTranslations[$locale]['item_name'] !== $name ||
                $this->originalTranslations[$locale]['description'] !== $description;

            if ($hasChanged) {
                if ($isNew) {
                    // Create new translation
                    $menuItem->translations()->create([
                        'locale' => $locale,
                        'item_name' => $name,
                        'description' => $description
                    ]);
                } else {
                    // Update existing translation
                    $menuItem->translations()
                        ->where('locale', $locale)
                        ->update([
                            'item_name' => $name,
                            'description' => $description
                        ]);
                }
            }
        }
    }

    private function handleImageUpload(MenuItem $menuItem): void
    {
        if ($this->itemImageTemp) {
            
            $newImage = Files::uploadLocalOrS3(
                $this->itemImageTemp,
                'item',
                width: 350,
                height: 350,
                oldFilename: $menuItem->image
            );

            $menuItem->update([
                'image' => $newImage,
            ]);

            if (! empty($oldImage) && $oldImage !== $newImage) {
                MenuItem::deleteImageFileIfUnreferenced($oldImage);
            }

            if ($menuItem->branch_id) {
                PosBranchCacheInvalidation::invalidateForBranch((int) $menuItem->branch_id);
            }
        }
    }

    private function handleVariationsOrPricing(MenuItem $menuItem): void
    {
        if ($this->hasVariations) {
            $this->updateVariations($menuItem);
        } else {
            // If variations are now disabled, delete all old variations
            MenuItemVariation::where('menu_item_id', $menuItem->id)->delete();
            $this->updateItemPricing($menuItem);
        }
    }

    private function updateVariations(MenuItem $menuItem): void
    {
        $existingVariationIds = $menuItem->variations()->pluck('id')->toArray();
        $submittedVariationIds = [];

        foreach ($this->inputs as $key => $value) {
            // Check if variation data exists and is not empty
            if (
                isset($this->variationName[$key]) && isset($this->variationPrice[$key]) &&
                !empty(trim($this->variationName[$key])) && !empty(trim($this->variationPrice[$key]))
            ) {
                $variationData = [
                    'variation' => trim($this->variationName[$key]),
                    'price' => $this->variationPrice[$key],
                    'menu_item_id' => $menuItem->id
                ];

                // Add batch recipe data if Inventory module is enabled
                if (in_array('Inventory', restaurant_modules())) {
                    $variationData['batch_recipe_id'] = $this->variationBatchRecipeId[$key] ?? null;
                    $variationData['batch_serving_size'] = isset($this->variationBatchServingSize[$key]) && $this->variationBatchServingSize[$key]
                        ? (float)$this->variationBatchServingSize[$key]
                        : null;
                }

                // Check if this is an existing variation (has ID) or a new one
                if (isset($this->variationIds[$key]) && !empty($this->variationIds[$key])) {
                    // Update existing variation
                    MenuItemVariation::where('id', $this->variationIds[$key])->update($variationData);
                    $submittedVariationIds[] = $this->variationIds[$key];

                    // Refresh pricing for this variation
                    MenuItemPrices::where('menu_item_id', $menuItem->id)
                        ->where('menu_item_variation_id', $this->variationIds[$key])
                        ->delete();

                    $this->savePricingData($menuItem->id, $this->variationIds[$key], $key);
                } else {
                    // Create new variation
                    $newVariation = MenuItemVariation::create($variationData);
                    $submittedVariationIds[] = $newVariation->id;

                    // Save pricing data for new variation
                    $this->savePricingData($menuItem->id, $newVariation->id, $key);
                }
            }
        }

        // Delete variations that were removed (not in submitted list)
        $variationsToDelete = array_diff($existingVariationIds, $submittedVariationIds);
        if (!empty($variationsToDelete)) {
            MenuItemVariation::whereIn('id', $variationsToDelete)->delete();
        }
    }

    private function updateItemPricing(MenuItem $menuItem): void
    {
        // Delete existing item prices (not variation prices)
        MenuItemPrices::where('menu_item_id', $menuItem->id)
            ->whereNull('menu_item_variation_id')
            ->delete();

        // Save new pricing data
        $this->saveItemPricingData($menuItem->id);
    }

    private function handleTaxes(MenuItem $menuItem): void
    {
        // Attach taxes if tax_mode is 'item'
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
        $this->clearTranslationCache();
        // updateMenuItem() uses query builder update (no model events); always sync POS caches + tax revision
        if ($this->menuItem->branch_id) {
            PosBranchCacheInvalidation::invalidateForBranch((int) $this->menuItem->branch_id);
        }
        $this->dispatch('hideUpdateMenuItem');
        $this->dispatch('menuItemUpdated');
        $this->dispatch('refreshCategories');

        $this->redirect(route('menu-items.index'), true);
        $this->alert('success', __('messages.menuItemUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    private function clearTranslationCache(): void
    {
        foreach (array_keys($this->languages) as $locale) {
            cache()->forget("menu_item_{$this->menuItem->id}_item_name_{$locale}");
            cache()->forget("menu_item_{$this->menuItem->id}_description_{$locale}");
        }
    }

    public function resetForm(): void
    {
        $this->itemName = '';
        $this->menu = '';
        $this->translationNames = array_fill_keys(array_keys($this->languages), '');
        $this->translationDescriptions = array_fill_keys(array_keys($this->languages), '');
        $this->originalTranslations = [];
        $this->itemCategory = '';
        $this->itemPrice = '';
        $this->itemDescription = '';
        $this->itemType = 'veg';
        $this->itemImage = null;
        $this->itemImageTemp = null;
        $this->preparationTime = null;
        $this->variationName = [];
        $this->variationPrice = [];
        $this->variationIds = [];
        $this->variationBreakdowns = [];
        $this->taxInclusivePriceDetails = null;
        $this->orderTypePrices = [];
        $this->deliveryPrices = [];
        $this->platformAvailability = [];
        $this->baseDeliveryPrice = '';
        $this->variationOrderTypePrices = [];
        $this->variationPlatformAvailability = [];
        $this->variationBaseDeliveryPrice = [];
        $this->variationDeliveryPrices = [];
        $this->linkedOrderTypePrices = [];
        $this->linkedDeliveryPrice = true;
        $this->variationLinkedOrderTypePrices = [];
        $this->variationLinkedDeliveryPrice = [];
        $this->batchRecipeId = null;
        $this->batchServingSize = null;
        $this->variationBatchRecipeId = [];
        $this->variationBatchServingSize = [];
    }

    public function updateTranslation(): void
    {
        $this->translationNames[$this->currentLanguage] = $this->itemName;
        $this->translationDescriptions[$this->currentLanguage] = $this->itemDescription;
    }

    public function updatedCurrentLanguage(): void
    {
        $this->itemName = $this->translationNames[$this->currentLanguage];
        $this->itemDescription = $this->translationDescriptions[$this->currentLanguage];
    }

    public function showMenuCategoryModal(): void
    {
        $this->dispatch('showMenuCategoryModal');
    }

    public function updatedTaxInclusive(): void
    {
        $this->recalculateTaxBreakdowns();
    }

    public function updatedItemPrice(): void
    {
        $value = $this->itemPrice;

        foreach ($this->orderTypes as $orderType) {
            if ($this->linkedOrderTypePrices[$orderType->id] ?? true) {
                $this->orderTypePrices[$orderType->id] = $value;
            }
        }

        if ($this->linkedDeliveryPrice) {
            $this->baseDeliveryPrice = $value;
        }

        $this->calculateDeliveryPrices();
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

    public function updatedItemImageTemp(): void
    {
        $this->itemImage = null;
        $this->validateImage();
    }

    public function removeSelectedImage(): void
    {
        $this->itemImageTemp = null;
        $this->itemImage = null;
    }

    public function validateImage(): void
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
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
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
                'override_price' => null,
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
                'override_price' => null,
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
        return view('livewire.forms.update-menu-item');
    }
}
