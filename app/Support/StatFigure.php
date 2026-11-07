<?php

namespace App\Support;

/**
 * The two attributes every big number on an admin stat card needs.
 *
 * QA Nov 5: "Total Stock Value" (₱5,343,127.60) spilled out of its card when
 * the window was narrowed. A figure like that is ONE unbreakable word at a
 * fixed font size, so the card could only grow or be overflowed. The layout's
 * `.stat-fit` / `.stat-fig` rules now size the figure from the card's own
 * width and the number of characters in it, and let it wrap as a last resort;
 * this class supplies the character count and the full value for the tooltip.
 *
 * Money and counts only. It never changes the text that is shown.
 */
final class StatFigure
{
    /**
     * `title` carries the full, untruncated value (the tooltip QA asked for);
     * `--n` is the character count the font-fit rule divides the card width by.
     */
    public static function attrs(string $text): string
    {
        $text = trim($text);

        return 'title="' . e($text) . '" style="--n:' . max(1, mb_strlen($text)) . '"';
    }
}
