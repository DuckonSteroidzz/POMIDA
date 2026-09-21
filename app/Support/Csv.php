<?php

namespace App\Support;

/**
 * One rule, applied once: never hand a spreadsheet a cell it will execute.
 *
 * Menu item names, ingredient names and bottleneck names are all user-entered
 * free text. Excel, LibreOffice Calc and Google Sheets all treat a cell whose
 * first character is =, +, - or @ as a FORMULA rather than as text, so an
 * ingredient saved as `=HYPERLINK("http://evil","Click")` — or the far more
 * mundane `-Sauce` — stops being a label the moment the exported file is
 * opened. `fputcsv()` does not help here: quoting is about the CSV grammar
 * (commas, quotes, newlines), and a correctly quoted "=cmd" is still parsed as
 * a formula once the quotes are stripped by the reader.
 *
 * The neutralisation is the OWASP-documented one: prefix a single quote, which
 * every major spreadsheet reads as "the rest of this cell is literal text" and
 * does not display as part of the value. The original characters are kept, so
 * nothing is silently lost from the export — `-Sauce` still reads `-Sauce` in
 * the cell, it just no longer reads as "subtract Sauce".
 *
 * Deliberately NOT a generic export framework (Phase 2d explicitly rules one
 * out): one static method, applied to the free-text cells of the three CSV
 * exports that have them. Numbers, dates and our own fixed labels do not go
 * through it — they cannot contain user input, and putting `'` in front of a
 * formatted number would break the column's arithmetic in the spreadsheet for
 * no gain.
 */
final class Csv
{
    /**
     * Characters a spreadsheet may treat as the start of a formula.
     *
     * Tab and carriage return are included because both are stripped or
     * normalised by some readers BEFORE the formula test runs, which turns
     * "\t=1+1" back into "=1+1" after this guard would otherwise have judged
     * it harmless.
     */
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * A cell safe to write into a CSV that a spreadsheet will open.
     *
     * Non-strings pass through untouched: an int, a float or null cannot carry
     * a formula, and stringifying them here would only change how fputcsv()
     * renders them.
     */
    public static function cell($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], self::TRIGGERS, true)
            ? "'" . $value
            : $value;
    }
}
