<?php

namespace App\Models;

use App\Exceptions\MenuItemSizeUnavailableException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItem extends Model
{
    use HasFactory;

    // "Archived" = removed from the business, as opposed to is_available's
    // "not today". Brings a global scope that hides archived rows from every
    // query — see App\Models\Concerns\Archivable for why it is global.
    use \App\Models\Concerns\Archivable;

    protected $fillable = [
        'category_id',
        'subcategory_id',
        'inventory_item_id',
        'inventory_amount_used',
        'branch_id',
        'name',
        'description',
        'ingredients',
        'price',
        'cost',
        'image',
        'display_order',
        'is_available',
        'is_featured',
        'track_stock',
        'stock_quantity',
        'low_stock_alert',
        'prep_time_minutes',
        'total_sold',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'is_available' => 'boolean',
        'is_featured' => 'boolean',
        'track_stock' => 'boolean',
        'archived_at' => 'datetime',
    ];

    /**
     * RELATIONSHIPS
     */

    // Belongs to a category
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // Belongs to a subcategory (optional)
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    // Belongs to a branch (optional — null = available sa lahat)
    public function branch()
    {
        return $this->belongsTo(\App\Models\Branch::class);
    }

    // Has many available options (via pivot table)
    public function options(): BelongsToMany
    {
        return $this->belongsToMany(MenuOption::class, 'menu_item_options');
    }

    /**
     * This item's assigned options, narrowed to the ones actually orderable
     * for $branchId — see MenuOption::isMappedForBranch() (Phase 3 audit,
     * Finding #3). This is what the customer-facing "Customize" list must
     * render from, not the raw options() relation, so an add-on with no
     * ingredient link for this branch is hidden rather than shown-but-inert.
     *
     * Eager-load 'options.ingredients.inventory' before calling this to
     * avoid an N+1 per option.
     */
    public function optionsAvailableForBranch(?int $branchId): \Illuminate\Support\Collection
    {
        return $this->options->filter(
            fn (MenuOption $option) => $option->isMappedForBranch($branchId)
        )->values();
    }

    // Has many order items (sales tracking)
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * HELPER METHODS
     */

    // Check if item is in stock (kung naka-track yung stocks)
    public function isInStock(): bool
    {
        if (!$this->track_stock) {
            return true; // Hindi naka-track, always available
        }
        return $this->stock_quantity > 0;
    }

    // Check kung mababa na stock
    public function isLowStock(): bool
    {
        if (!$this->track_stock) {
            return false;
        }
        return $this->stock_quantity <= $this->low_stock_alert;
    }
    /**
     * Linked inventory item (auto-deduct source)
     */
    public function inventoryItem()
    {
        return $this->belongsTo(\App\Models\Inventory::class, 'inventory_item_id');
    }

    // Multi-ingredient recipe: every line is deducted whenever this item is ordered.
    // Named recipeIngredients (not ingredients) because the menu_items table already
    // has a free-text `ingredients` column for the customer-facing item description.
    // Eloquent attribute accessors win over relation accessors, so `->ingredients`
    // would return the text column and crash any ->isEmpty() / ->count() call.
    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(MenuItemIngredient::class);
    }

    /**
     * MENU ITEM SIZES (Phase 1).
     *
     * An item with NO size rows is unsized and behaves exactly as before —
     * menu_items.price, recipeIngredients(), every stock function below with
     * no size argument. An item WITH size rows has exactly two, Regular and
     * Large (see MenuItemSize). The size-aware paths below only ever engage
     * when a size is passed in explicitly; nothing reads sizes implicitly.
     */

    /** Live sizes (not archived), Regular first. */
    public function sizes(): HasMany
    {
        return $this->hasMany(MenuItemSize::class)->orderBy('display_order');
    }

    /** Every size row, archived included — what the admin editor shows. */
    public function allSizes(): HasMany
    {
        return $this->hasMany(MenuItemSize::class)
            ->withoutGlobalScope(Concerns\NotArchivedScope::class)
            ->orderBy('display_order');
    }

    /**
     * Sized = has size rows, archived ones included. An item whose two sizes
     * are both archived is still a sized item with no sellable size — it does
     * NOT quietly revert to being unsized.
     */
    public function hasSizes(): bool
    {
        return $this->relationLoaded('allSizes')
            ? $this->allSizes->isNotEmpty()
            : $this->allSizes()->exists();
    }

    /**
     * Keep menu_items.price equal to the "starting from" figure: the LOWEST
     * price among this item's LIVE sizes (active and not archived).
     *
     * Called by MenuItemSize's saved/deleted hooks, so every size write keeps
     * it in step. The column is display/support data for a sized item — the
     * size functions never read it back as a price or a recipe fallback.
     *
     * - Unsized item: returns without touching anything. Its price is its own.
     * - Sized item with no live size: the last starting price is RETAINED.
     *   menu_items.price is NOT NULL, so there is nothing to clear it to
     *   without a schema change, and the admin list has always shown a price
     *   for every row. The item is not sellable by size regardless — every
     *   size-aware function refuses a size that is inactive or archived — so
     *   the retained figure is never charged for a size.
     */
    public function syncStartingPriceFromSizes(): void
    {
        $sizes = MenuItemSize::withArchived()->where('menu_item_id', $this->getKey())->get();

        if ($sizes->isEmpty()) {
            return;
        }

        $lowest = $sizes->filter(fn (MenuItemSize $size) => $size->isLive())
            ->sortBy(fn (MenuItemSize $size) => (float) $size->price)
            ->first();

        if (! $lowest) {
            return;
        }

        if (number_format((float) $this->price, 2, '.', '') !== number_format((float) $lowest->price, 2, '.', '')) {
            $this->price = $lowest->price;
            $this->save();
        }

        // Keep an eager-loaded size list honest for whoever holds this model.
        $this->unsetRelation('allSizes');
        $this->unsetRelation('sizes');
    }

    /**
     * THE size resolver, step one: is $size a sellable size OF THIS ITEM?
     *
     * Accepts the model or its id. Refuses — by throwing, never by falling
     * back to the base recipe or menu_items.price — a size that does not
     * exist, belongs to a different menu item, is archived, or is inactive.
     * A passed model is judged on the attributes it carries; callers that
     * need a fresh verdict pass a fresh model or an id.
     *
     * @throws MenuItemSizeUnavailableException
     */
    public function resolveOrderableSize(MenuItemSize|int $size): MenuItemSize
    {
        if (is_int($size)) {
            $found = $this->relationLoaded('allSizes') ? $this->allSizes->firstWhere('id', $size) : null;
            $found ??= MenuItemSize::withArchived()->find($size);

            if (! $found) {
                throw MenuItemSizeUnavailableException::notFound($this);
            }

            $size = $found;
        }

        if (! $size->exists) {
            throw MenuItemSizeUnavailableException::notFound($this);
        }

        if (! $this->exists || (int) $size->menu_item_id !== (int) $this->getKey()) {
            throw MenuItemSizeUnavailableException::wrongItem($this);
        }

        if ($size->isArchived()) {
            throw MenuItemSizeUnavailableException::archived($this, $size);
        }

        if (! $size->is_active) {
            throw MenuItemSizeUnavailableException::inactive($this, $size);
        }

        return $size;
    }

    /**
     * THE size resolver, step two: the recipe rows of a sellable size.
     *
     * This is the single path "resolve size -> load its recipe -> decide it is
     * orderable" runs through; requirementsForLine(), hasIngredientStock(),
     * remainingServings(), hasRecipe() and orderBlockedReason() all call it,
     * so they cannot disagree about which rows a size uses or whether it can
     * be sold at all.
     *
     * The rows REPLACE recipeIngredients() — the base recipe is never read on
     * this path. An empty size recipe is "No Recipe Set" and throws, rather
     * than returning an empty collection a caller could read as a valid recipe
     * needing nothing.
     *
     * Reuses an eager-loaded `ingredients` (with `inventory`) on the size when
     * the caller provides one, else costs one query.
     *
     * @throws MenuItemSizeUnavailableException
     */
    public function sizeRecipe(MenuItemSize|int $size): EloquentCollection
    {
        $size = $this->resolveOrderableSize($size);

        $rows = $size->relationLoaded('ingredients')
            ? $size->ingredients
            : $size->ingredients()->with('inventory')->get();

        if ($rows->isEmpty()) {
            throw MenuItemSizeUnavailableException::noRecipe($this, $size);
        }

        return $rows;
    }

    /**
     * The real price of one of this item's sizes — the size row's own price,
     * never menu_items.price (which, for a sized item, is only the "starting
     * from" figure). Refuses exactly what resolveOrderableSize() refuses.
     * There is deliberately no "no size" branch: nothing here can hand back
     * the parent's price in place of a size's.
     *
     * @throws MenuItemSizeUnavailableException
     */
    public function priceForSize(MenuItemSize|int $size): string
    {
        return (string) $this->resolveOrderableSize($size)->price;
    }

    /**
     * How much of one inventory row is genuinely still promisable.
     *
     * $reserved is an optional [inventory_id => amount] map of stock that
     * already-placed, not-yet-completed orders have committed but which is
     * still sitting in inventory.quantity, because deduction only happens when
     * staff completes an order — see
     * InventoryDeductionService::committedQuantities(). Passing null keeps the
     * older, raw reading of the pantry, which is what the admin-side callers
     * (manual orders, costing) still want.
     */
    private function freeQuantity($inventoryRow, ?array $reserved): float
    {
        return (float) $inventoryRow->quantity
            - (float) ($reserved[$inventoryRow->id] ?? 0);
    }

    /**
     * The one per-row walk behind hasIngredientStock(), for the base recipe
     * (menu_item_ingredients.quantity_used) and a size recipe
     * (menu_item_size_ingredients.quantity) alike — same rule for both: every
     * row whose inventory row exists must cover $servings of it.
     */
    private function recipeCoversServings(iterable $recipe, string $quantityColumn, int $servings, ?array $reserved): bool
    {
        foreach ($recipe as $row) {
            $inv = $row->inventory; // eager-loaded by every caller
            if (!$inv) {
                // Recipe line points at a missing inventory row — cannot
                // judge it, so do not block ordering on it.
                continue;
            }

            $have = $this->freeQuantity($inv, $reserved);
            $need = (float) $row->{$quantityColumn} * $servings;

            if ($have <= 0 || $have < $need) {
                return false;
            }
        }

        return true;
    }

    /**
     * The one per-row walk behind remainingServings(), shared by the base and
     * size recipes exactly as recipeCoversServings() is. Null when no row is
     * measurable (every row zero-quantity or pointing at a missing inventory
     * row) — the same "unmeasurable" answer remainingServings() always gave.
     */
    private function servingsCoveredBy(iterable $recipe, string $quantityColumn, ?array $reserved): ?int
    {
        $max = null;
        foreach ($recipe as $row) {
            $inv = $row->inventory;
            if (!$inv || (float) $row->{$quantityColumn} <= 0) {
                continue;
            }
            $servingsFromRow = (int) floor($this->freeQuantity($inv, $reserved) / (float) $row->{$quantityColumn});
            $max = $max === null ? $servingsFromRow : min($max, $servingsFromRow);
        }

        return $max === null ? null : max(0, $max);
    }

    /**
     * AUTOMATIC OUT-OF-STOCK — ingredient inventory check.
     *
     * Deliberately SEPARATE from is_available: that flag is the admin's
     * manual "not selling this today" switch and is never touched here. This
     * answers a different question — can the kitchen physically assemble one
     * (or $servings) of this item right now, given what is in the inventory?
     *
     * An item is out of stock when ANY line of its recipe points at an
     * inventory row that is empty (quantity <= 0) or holds less than the
     * recipe needs (quantity < quantity_used * $servings). Items with no
     * recipe and no legacy single-ingredient link are never blocked here —
     * there is nothing to measure them against, so they fall through as
     * "in stock" exactly as the ordering flow already assumes.
     *
     * Reads eager-loaded relations when the caller provides them
     * (`recipeIngredients.inventory`, `inventoryItem`) so a menu grid can
     * call this once per card without an N+1.
     *
     * WITH A SIZE ($size, Phase 1): the size's own recipe is measured INSTEAD
     * of the base recipe (and the legacy link is never consulted). A size that
     * cannot be sold — not this item's, inactive, archived, or with no recipe
     * — answers false: unlike an unsized item with no recipe, a size with no
     * recipe is not "unconstrained", it is not orderable. See sizeRecipe().
     */
    public function hasIngredientStock(int $servings = 1, ?array $reserved = null, MenuItemSize|int|null $size = null): bool
    {
        $servings = max(1, $servings);

        if ($size !== null) {
            try {
                $sizeRows = $this->sizeRecipe($size);
            } catch (MenuItemSizeUnavailableException) {
                return false;
            }

            return $this->recipeCoversServings($sizeRows, 'quantity', $servings, $reserved);
        }

        $recipe = $this->relationLoaded('recipeIngredients')
            ? $this->recipeIngredients
            : $this->recipeIngredients()->with('inventory')->get();

        if ($recipe->isNotEmpty()) {
            return $this->recipeCoversServings($recipe, 'quantity_used', $servings, $reserved);
        }

        // Legacy single-ingredient link for items that pre-date the recipe table.
        if ($this->inventory_item_id) {
            $inv = $this->relationLoaded('inventoryItem')
                ? $this->inventoryItem
                : $this->inventoryItem()->first();

            if (!$inv) {
                return true;
            }

            $have = $this->freeQuantity($inv, $reserved);
            $need = (float) ($this->inventory_amount_used ?: 1) * $servings;

            return $have > 0 && $have >= $need;
        }

        return true;
    }

    /**
     * Customer-facing inverse of hasIngredientStock(): true when the item
     * should show an "Out of Stock" badge and have its order button disabled.
     */
    public function isIngredientOutOfStock(MenuItemSize|int|null $size = null): bool
    {
        return ! $this->hasIngredientStock(1, null, $size);
    }

    /**
     * RECIPE GUARD — an item with no recipe cannot be ordered.
     *
     * As of 2026-09-04 (second pass), an item is only orderable if the kitchen
     * has been told how to make it: at least one recipe line
     * (`menu_item_ingredients`) OR the legacy single-ingredient link
     * (`inventory_item_id`). An item with neither has no bill of materials, so
     * ordering it would deduct nothing from inventory and the kitchen would have
     * no instructions — the owner asked for these to read as "not for sale"
     * until a recipe is entered, rather than silently sellable.
     *
     * This is DELIBERATELY computed and never writes `is_available` or
     * `stock_quantity` — same principle as hasIngredientStock() above. The admin
     * toggle stays the admin's; this is the app stating a fact about the item.
     *
     * Reads an eager-loaded `recipeIngredients` relation when the caller
     * provides one (menu grids do), else costs a single EXISTS query.
     *
     * WITH A SIZE ($size, Phase 1): true only when $size is a sellable size of
     * THIS item (see resolveOrderableSize()) AND it has at least one line of
     * its own recipe. A size with no lines is "No Recipe Set" and the base
     * recipe / legacy link never stand in for it. A size that is not sellable
     * at all also answers false — callers use this to decide orderability,
     * and orderBlockedReason() says which reason applied.
     */
    public function hasRecipe(MenuItemSize|int|null $size = null): bool
    {
        if ($size !== null) {
            try {
                $this->sizeRecipe($size);

                return true;
            } catch (MenuItemSizeUnavailableException) {
                return false;
            }
        }

        $hasRecipeLines = $this->relationLoaded('recipeIngredients')
            ? $this->recipeIngredients->isNotEmpty()
            : $this->recipeIngredients()->exists();

        return $hasRecipeLines || (bool) $this->inventory_item_id;
    }

    /**
     * Customer-facing inverse of hasRecipe(): true when the item should show an
     * "Unavailable — No Recipe Set" badge and have its order button disabled.
     */
    public function isMissingRecipe(MenuItemSize|int|null $size = null): bool
    {
        return ! $this->hasRecipe($size);
    }

    /**
     * The single "can a customer order this right now?" question, folding both
     * guards together: it must have a recipe AND the inventory to cover
     * $servings of it. Used by the cart-add and checkout paths.
     */
    public function isOrderable(int $servings = 1, MenuItemSize|int|null $size = null): bool
    {
        return $this->hasRecipe($size) && $this->hasIngredientStock($servings, null, $size);
    }

    /**
     * How many servings of this item current inventory can still cover, or
     * null when there is nothing to measure against (no recipe, no legacy
     * link — same "unconstrained" case hasIngredientStock() already treats
     * as in-stock). Drives the customer-facing "Only N left!" indicator and
     * lets the cart quantity guard clamp a rejected update to the true max
     * instead of just refusing with no number attached.
     *
     * Mirrors hasIngredientStock()'s two paths (recipe lines, then the
     * legacy single-ingredient link) so the two can never disagree about
     * what "in stock" means.
     *
     * WITH A SIZE ($size, Phase 1): counted from the size's own recipe only.
     * A size that cannot be sold (see sizeRecipe()) can make 0 — never null,
     * because null means "nothing limits it" to the callers of this method,
     * and a refused size must never read as unlimited.
     */
    public function remainingServings(?array $reserved = null, MenuItemSize|int|null $size = null): ?int
    {
        if ($size !== null) {
            try {
                $sizeRows = $this->sizeRecipe($size);
            } catch (MenuItemSizeUnavailableException) {
                return 0;
            }

            return $this->servingsCoveredBy($sizeRows, 'quantity', $reserved);
        }

        $recipe = $this->relationLoaded('recipeIngredients')
            ? $this->recipeIngredients
            : $this->recipeIngredients()->with('inventory')->get();

        if ($recipe->isNotEmpty()) {
            return $this->servingsCoveredBy($recipe, 'quantity_used', $reserved);
        }

        if ($this->inventory_item_id) {
            $inv = $this->relationLoaded('inventoryItem')
                ? $this->inventoryItem
                : $this->inventoryItem()->first();

            $amountUsed = (float) ($this->inventory_amount_used ?: 1);
            if (!$inv || $amountUsed <= 0) {
                return null;
            }

            return max(0, (int) floor($this->freeQuantity($inv, $reserved) / $amountUsed));
        }

        return null;
    }

    /**
     * True when the item is still orderable but the servings its recipe can
     * still cover (remainingServings()) have dropped to, or below, the
     * config('inventory.low_stock_threshold') line (default 3) — and are
     * still above 0, since 0 is "Out of Stock", handled elsewhere. Drives
     * the "N stocks left" badge on the menu grid and item page;
     * remainingServings() supplies the N.
     */
    public function isLowOnIngredientStock(?array $reserved = null, MenuItemSize|int|null $size = null): bool
    {
        if (!$this->hasRecipe($size) || !$this->hasIngredientStock(1, $reserved, $size)) {
            return false; // no recipe, or already out of stock — not "low", handled elsewhere
        }

        $remaining = $this->remainingServings($reserved, $size);

        return $remaining !== null
            && $remaining > 0
            && $remaining <= (int) config('inventory.low_stock_threshold', 3);
    }

    /**
     * The customer-facing reason this item cannot be ordered, or null when it
     * can. Keeps the "no recipe" and "out of stock" wording in one place so the
     * cart-add guard, the checkout guard and the cart page all agree.
     *
     * WITH A SIZE ($size, Phase 1): the refusal names the size, and a size
     * that cannot be sold at all gets its own reason (not this item's,
     * inactive, archived, or "no recipe has been set") from the one resolver,
     * sizeRecipe().
     */
    public function orderBlockedReason(int $servings = 1, MenuItemSize|int|null $size = null): ?string
    {
        if ($size !== null) {
            try {
                $resolved = $this->resolveOrderableSize($size);
                $this->sizeRecipe($resolved);
            } catch (MenuItemSizeUnavailableException $e) {
                return $e->getMessage();
            }

            if (! $this->hasIngredientStock($servings, null, $resolved)) {
                return 'Sorry, ' . $this->name . ' (' . $resolved->name . ')'
                    . ' is currently out of stock due to ingredient availability.';
            }

            return null;
        }

        if ($this->isMissingRecipe()) {
            return 'Sorry, ' . $this->name
                . ' is unavailable right now — no recipe has been set for it yet.';
        }

        if (! $this->hasIngredientStock($servings)) {
            return 'Sorry, ' . $this->name
                . ' is currently out of stock due to ingredient availability.';
        }

        return null;
    }

    /**
     * MENU ITEM SIZES (Phase 2) — what each of this item's LIVE sizes can do
     * right now, for the pages that offer a size: the customer item page, the
     * customer menu card and the walk-in counter.
     *
     * Every verdict comes from the Phase 1 functions, never a copy of their
     * rules: sizeRecipe() decides "sellable, with a recipe", then
     * hasIngredientStock() / remainingServings() WITH the size decide stock.
     * An archived size is left out entirely (it is gone from the menu); an
     * inactive one is listed but not orderable.
     *
     * $checkStock = false skips the stock verdict — the counter's cards, which
     * (like its unsized cards) only say whether a size CAN be sold and leave
     * stock to the server gate.
     *
     * Eager-load `allSizes.ingredients.inventory` (just `allSizes.ingredients`
     * with $checkStock = false) so a grid of items costs no query per item.
     *
     * @return list<array{size: MenuItemSize, id: int, name: string, price: float,
     *     state: string, orderable: bool, label: ?string, remaining: ?int, low_stock: bool}>
     *   state is 'available', 'out_of_stock', 'no_recipe' or 'unavailable'.
     */
    public function sizeChoices(?array $reserved = null, bool $checkStock = true): array
    {
        $threshold = (int) config('inventory.low_stock_threshold', 3);
        $choices = [];

        foreach ($this->allSizes as $size) {
            if ($size->isArchived()) {
                continue;
            }

            $state = 'available';
            $label = null;
            $remaining = null;

            try {
                $this->sizeRecipe($size);
            } catch (MenuItemSizeUnavailableException $e) {
                $state = $e->reason() === MenuItemSizeUnavailableException::NO_RECIPE ? 'no_recipe' : 'unavailable';
                $label = $state === 'no_recipe' ? 'No Recipe Set' : 'Unavailable';
            }

            if ($state === 'available' && $checkStock) {
                if (! $this->hasIngredientStock(1, $reserved, $size)) {
                    $state = 'out_of_stock';
                    $label = 'Out of Stock';
                } else {
                    $remaining = $this->remainingServings($reserved, $size);
                }
            }

            $choices[] = [
                'size'      => $size,
                'id'        => (int) $size->id,
                'name'      => (string) $size->name,
                'price'     => (float) $size->price,
                'state'     => $state,
                'orderable' => $state === 'available',
                'label'     => $label,
                'remaining' => $remaining,
                'low_stock' => $remaining !== null && $remaining > 0 && $remaining <= $threshold,
            ];
        }

        return $choices;
    }

    /**
     * The customer menu card's state — the one place both menu grids ask, so
     * they cannot drift.
     *
     * UNSIZED: exactly the expressions the grids always computed inline
     * (isMissingRecipe(), hasIngredientStock(), isLowOnIngredientStock(),
     * remainingServings() — same calls, same order), same labels.
     *
     * SIZED (Phase 2): judged by its live sizes through sizeChoices(), never
     * by the base recipe — a sized item with no base recipe is not "No Recipe
     * Set", and one whose base recipe is empty on the shelf is not "Out of
     * Stock". Orderable while ANY size is; "N left" is the most any single
     * size can still make (null, so no badge, when any orderable size is
     * unmeasurable). With nothing orderable, the label says why: out of stock
     * if any size merely lacks stock, else no recipe, else unavailable.
     *
     * @return array{sized: bool, unavailable: bool, label: string, detail: string, low_stock: bool, remaining: ?int}
     */
    public function menuCardState(?array $reserved = null): array
    {
        if (! $this->hasSizes()) {
            $missingRecipe = $this->isMissingRecipe();
            $outOfStock = ! $missingRecipe && ! $this->hasIngredientStock(1, $reserved);
            $unavailable = $missingRecipe || $outOfStock;
            $lowStock = ! $unavailable && $this->isLowOnIngredientStock($reserved);

            return [
                'sized'       => false,
                'unavailable' => $unavailable,
                'label'       => $missingRecipe ? 'No Recipe Set' : 'Out of Stock',
                'detail'      => $missingRecipe
                    ? 'Unavailable — no recipe set'
                    : 'Currently unavailable — ingredients out of stock',
                'low_stock'   => $lowStock,
                'remaining'   => $lowStock ? $this->remainingServings($reserved) : null,
            ];
        }

        $choices = collect($this->sizeChoices($reserved));
        $orderable = $choices->where('orderable', true);

        if ($orderable->isNotEmpty()) {
            $remaining = $orderable->contains(fn (array $choice) => $choice['remaining'] === null)
                ? null
                : (int) $orderable->max('remaining');
            $lowStock = $remaining !== null
                && $remaining > 0
                && $remaining <= (int) config('inventory.low_stock_threshold', 3);

            return [
                'sized'       => true,
                'unavailable' => false,
                'label'       => '',
                'detail'      => '',
                'low_stock'   => $lowStock,
                'remaining'   => $lowStock ? $remaining : null,
            ];
        }

        if ($choices->contains('state', 'out_of_stock')) {
            [$label, $detail] = ['Out of Stock', 'Currently unavailable — ingredients out of stock'];
        } elseif ($choices->contains('state', 'no_recipe')) {
            [$label, $detail] = ['No Recipe Set', 'Unavailable — no recipe set'];
        } else {
            [$label, $detail] = ['Unavailable', 'Currently unavailable'];
        }

        return [
            'sized'       => true,
            'unavailable' => true,
            'label'       => $label,
            'detail'      => $detail,
            'low_stock'   => false,
            'remaining'   => null,
        ];
    }
}
