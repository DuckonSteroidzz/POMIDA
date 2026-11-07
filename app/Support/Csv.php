<?php

namespace App\Support;

/**
 * Two rules, applied once, for every free-text cell written to a CSV: never
 * hand a spreadsheet a cell it will EXECUTE, and never hand it a cell it will
 * RE-INTERPRET as a number.
 *
 * 1. Formulas. Menu item names, ingredient names and bottleneck names are all
 * user-entered free text. Excel, LibreOffice Calc and Google Sheets all treat a
 * cell whose first character is =, +, - or @ as a FORMULA rather than as text,
 * so an ingredient saved as `=HYPERLINK("http://evil","Click")` — or the far
 * more mundane `-Sauce` — stops being a label the moment the exported file is
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
 * 2. Numbers that were never numbers (QA Nov 5: a cell read 1.11111E+46).
 * Quoting does not stop a spreadsheet from parsing a cell made only of digits
 * as a NUMBER. A 47-digit item name became 1.11111E+46 and its digits were
 * gone; a 16-digit ID loses everything past the 15th digit; a phone number or
 * table label written `007` loses its leading zeros. Such a cell is written as
 * `="digits"`, which Excel, LibreOffice and Google Sheets all show as the text
 * between the quotes. It is only ever built from a string that already matched
 * the digits-only pattern below, so it cannot carry anything executable.
 *
 * Deliberately NOT a generic export framework (Phase 2d explicitly rules one
 * out): one static method, applied to the free-text cells of the CSV exports
 * that have them. Real numbers and dates (money, quantities) do not go through
 * it — they stay numeric, which is the point of the second rule — and our own
 * fixed labels cannot contain user input.
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
     * Digits-only text a spreadsheet would turn into a number: 12 or more
     * digits (General format shows 12+ as scientific and keeps 15), or any
     * digit string with a leading zero, or e-notation such as "1e46".
     */
    private const NUMBER_LOOKING = '/^(?:\d{12,}|0\d+|\d+(?:\.\d+)?[eE][+-]?\d+)$/D';

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

        if (preg_match(self::NUMBER_LOOKING, $value) === 1) {
            return '="' . $value . '"';
        }

        return in_array($value[0], self::TRIGGERS, true)
            ? "'" . $value
            : $value;
    }
}
