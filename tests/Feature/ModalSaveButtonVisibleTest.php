<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Both modal "Save Changes" buttons on Admin > Add Category rendered
 * completely blank and white — no label, no icon, no colour (2026-09-02).
 *
 * ROOT CAUSE, confirmed by reading the actual DOM nesting rather than
 * guessing
 * --------------------------------------------------------------------
 * --p1 through --p6 (the page's colour tokens, including --p4/--p5 that
 * .pchy-btn's gradient reads) used to be declared only on `.pchy`, the page
 * wrapper. Both #editCategoryModal and #editSubcategoryModal are DOM
 * SIBLINGS of that wrapper, not descendants of it — .pchy opens, closes, and
 * only THEN do the modal divs begin. CSS custom properties cascade only to
 * descendants of the element that declares them, so inside either modal
 * var(--p4) and var(--p5) were simply undefined.
 *
 * .pchy-btn's `background: linear-gradient(135deg, var(--p4), var(--p5))`
 * with an unresolved var() is invalid at computed-value time, which resets
 * the WHOLE background property to its initial value — transparent,
 * revealing the white modal box behind it. `color:#fff` is a plain static
 * value, not a var(), so it stayed white regardless. White text on a
 * transparent background over a white modal box is exactly the "blank white
 * button" reported. The label and icon were never missing — confirmed
 * present in the rendered HTML both before and after this fix — only
 * unrenderable from where the modals sit in the DOM.
 *
 * This affected BOTH modals identically and predates the recent Edit
 * Subcategory work — Edit Category's modal has the exact same structural
 * relationship to .pchy and was never touched by that pass.
 *
 * FUNCTIONALLY, the button always worked: no `disabled` attribute, no
 * `pointer-events` restriction anywhere near it — a native
 * `<button type="submit">` inside a real `<form method="POST">` submits
 * regardless of how it is painted. This was purely cosmetic.
 *
 * THE FIX moves the six tokens to `:root`, which every element on the page
 * can see regardless of nesting — not a hardcoded inline style on the two
 * buttons, so any modal added to this page later inherits them
 * automatically instead of needing to remember to nest inside .pchy.
 */
class ModalSaveButtonVisibleTest extends TestCase
{
    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function page(): string
    {
        return $this->actingAs($this->admin(), 'admin')
            ->get('/admin/add-category')
            ->getContent();
    }

    // ══════════ the label renders, in both modals ══════════

    public function test_both_modal_save_buttons_render_their_label_text(): void
    {
        $html = $this->page();

        $this->assertSame(
            2,
            substr_count($html, 'Save Changes'),
            'both the Edit Category and Edit Subcategory modals must render a "Save Changes" label'
        );
    }

    public function test_the_edit_category_modal_save_button_carries_the_label_icon_and_primary_class(): void
    {
        $html = $this->page();

        $modal = $this->extractModal($html, 'editCategoryModal');

        $this->assertMatchesRegularExpression(
            '/<button type="submit" class="pchy-btn"><i class="bi bi-check-circle"><\/i> Save Changes<\/button>/',
            $modal,
            'the Edit Category submit button must carry the label, the icon, and the plain primary class'
        );
    }

    public function test_the_edit_subcategory_modal_save_button_carries_the_label_icon_and_primary_class(): void
    {
        $html = $this->page();

        $modal = $this->extractModal($html, 'editSubcategoryModal');

        $this->assertMatchesRegularExpression(
            '/<button type="submit" class="pchy-btn"><i class="bi bi-check-circle"><\/i> Save Changes<\/button>/',
            $modal,
            'the Edit Subcategory submit button must carry the label, the icon, and the plain primary class'
        );
    }

    // ══════════ not the ghost/cancel styling ══════════

    public function test_neither_save_button_carries_the_ghost_cancel_class(): void
    {
        $html = $this->page();

        foreach (['editCategoryModal', 'editSubcategoryModal'] as $modalId) {
            $modal = $this->extractModal($html, $modalId);

            $this->assertDoesNotMatchRegularExpression(
                '/<button type="submit" class="[^"]*pchy-btn-ghost[^"]*"/',
                $modal,
                "[$modalId] the Save button must not carry the ghost/cancel styling"
            );
        }
    }

    // ══════════ the root cause itself: the colour tokens must resolve everywhere ══════════

    /**
     * Pins the actual fix, not just its visible effect: the colour tokens
     * .pchy-btn depends on must be declared somewhere that reaches every
     * element on the page, not only descendants of .pchy — which is exactly
     * what let this break in the first place while every non-modal button
     * kept working.
     */
    public function test_the_colour_tokens_are_declared_on_root_not_only_on_the_page_wrapper(): void
    {
        $source = file_get_contents(resource_path('views/admin/add-category.blade.php'));

        $this->assertMatchesRegularExpression(
            '/:root\{[^}]*--p4:[^}]*--p5:[^}]*\}/',
            $source,
            '--p4/--p5 must be declared on :root so they resolve inside a modal that sits '
            . 'outside .pchy in the DOM — this is the actual fix, not a per-button patch'
        );
    }

    // ══════════ regression: the forms still post correctly ══════════

    public function test_the_edit_category_form_still_posts_put_to_the_correct_route(): void
    {
        $html = $this->page();
        $modal = $this->extractModal($html, 'editCategoryModal');

        $this->assertStringContainsString('method="POST"', $modal);
        $this->assertStringContainsString("@method('PUT')", file_get_contents(
            resource_path('views/admin/add-category.blade.php')
        ));
        $this->assertMatchesRegularExpression('/name="_method"[^>]*value="PUT"/', $modal);
    }

    public function test_the_edit_subcategory_form_still_posts_put_to_the_correct_route(): void
    {
        $html = $this->page();
        $modal = $this->extractModal($html, 'editSubcategoryModal');

        $this->assertStringContainsString('method="POST"', $modal);
        $this->assertMatchesRegularExpression('/name="_method"[^>]*value="PUT"/', $modal);
    }

    /**
     * Isolate one modal's markup from the rest of the page, the same way the
     * option/history sectioning helpers elsewhere in this suite do — both
     * modals share class names (pchy-btn, pchy-modal-foot), so an
     * unscoped assertion would pass even if only one of the two were fixed.
     */
    private function extractModal(string $html, string $modalId): string
    {
        $start = strpos($html, 'id="' . $modalId . '"');
        $this->assertNotFalse($start, "modal #$modalId is missing from the page entirely");

        $end = strpos($html, '</form>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }
}
