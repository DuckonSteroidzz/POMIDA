<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 3b F11 — menu-items.blade.php's Add-mode draft-row builder wrote
 * select.value and qtyInput.value straight into value="…" attribute strings
 * via string concatenation into innerHTML. Both sources are, in practice,
 * numeric-only (a <select> of inventory ids, a <input type="number">), which
 * is why this was self-XSS only — but the sibling cells in the very same row
 * (item name, quantity+unit) were already built safely via .textContent, so
 * the hidden inputs were the one inconsistency.
 *
 * Fix: build those two inputs with createElement + .value, exactly like
 * every other dynamic value in this row. This is a client-side-only change
 * (MenuItemAddWithIngredientsTest's own "rejected add reopens the modal"
 * coverage exercises a SEPARATE, server-rendered old()-input code path via
 * admin/partials/recipe-ingredients.blade.php and is unaffected), so
 * coverage here is a source-level regression guard rather than a rendered
 * assertion — there is no browser-JS runner wired up for this page.
 */
class MenuItemRecipeRowAttributeEscapeTest extends TestCase
{
    use DatabaseTransactions;

    private function source(): string
    {
        return file_get_contents(resource_path('views/admin/menu-items.blade.php'));
    }

    public function test_the_draft_row_no_longer_concatenates_values_into_an_attribute_string(): void
    {
        $source = $this->source();

        // The exact vulnerable markup strings the fix removed — matched in
        // full (not a bare "value=\"' + select.value" fragment) because
        // select.value also legitimately appears, safely, inside a
        // querySelector(...) CSS-selector string elsewhere in this file
        // (the duplicate-ingredient check), which a looser substring match
        // would wrongly flag.
        $this->assertStringNotContainsString(
            '<input type="hidden" name="ingredients[\' + idx + \'][inventory_id]" value="\' + select.value + \'">',
            $source,
            'select.value is still concatenated straight into the draft row\'s innerHTML'
        );
        $this->assertStringNotContainsString(
            '<input type="hidden" name="ingredients[\' + idx + \'][quantity_used]" value="\' + qtyInput.value + \'">',
            $source,
            'qtyInput.value is still concatenated straight into the draft row\'s innerHTML'
        );
    }

    public function test_the_draft_row_now_builds_both_hidden_inputs_via_create_element(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            "var inventoryIdInput = document.createElement('input');",
            $source
        );
        $this->assertStringContainsString('inventoryIdInput.value = select.value;', $source);

        $this->assertStringContainsString(
            "var quantityUsedInput = document.createElement('input');",
            $source
        );
        $this->assertStringContainsString('quantityUsedInput.value = qtyInput.value;', $source);

        // Both must actually be attached to the row, or the form would post
        // an incomplete ingredient line.
        $this->assertStringContainsString('row.children[2].appendChild(inventoryIdInput);', $source);
        $this->assertStringContainsString('row.children[2].appendChild(quantityUsedInput);', $source);
    }

    /**
     * The name attributes — which carry idx, a plain incrementing integer
     * counter, never user input — must still be set correctly, or the form
     * would silently stop submitting ingredient rows at all.
     */
    public function test_the_hidden_inputs_still_carry_the_correct_field_names(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            "inventoryIdInput.name = 'ingredients[' + idx + '][inventory_id]';",
            $source
        );
        $this->assertStringContainsString(
            "quantityUsedInput.name = 'ingredients[' + idx + '][quantity_used]';",
            $source
        );
    }
}
