<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * UploadedImageName — the one place that decides what an uploaded image is
 * CALLED on disk.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every image upload in the admin area used to build its stored filename from
 * the browser's filename:
 *
 *     $filename = time() . '_' . preg_replace('/[^A-Za-z0-9\.]/', '_',
 *                                             $file->getClientOriginalName());
 *
 * That sanitiser keeps letters, digits and DOTS, so it keeps the attacker's
 * EXTENSION. Validating the file's CONTENT does not save you, because Laravel's
 * `mimes:` rule compares the extension it guesses from the MIME type, not the
 * one the client sent — so a genuine JPEG uploaded as `payload.html` passes
 * `image|mimes:jpeg,png,jpg,webp` and was then written to
 * `public/uploads/...` as `1790577993_payload.html`.
 *
 * public/ is served straight off the filesystem BY EXTENSION, so that file came
 * back to the browser as text/html and any markup carried in the JPEG's bytes
 * (a COM comment segment is enough) executed ON THE APPLICATION'S OWN ORIGIN.
 * Laravel's own shouldBlockPhpUpload() stops the php/phtml/phar family, which
 * is why this was not an RCE — but it says nothing about .html, .htm, .xhtml
 * or .svg, all of which run script when served as a document.
 *
 * Measured before the fix (UploadFilenameHardeningTest): .html, .htm, .xhtml,
 * .svg and .jpg.html were all stored verbatim on both the menu-item and the
 * advertisement paths. Menu-item and ad uploads are role:admin,supervisor, so a
 * SUPERVISOR could plant a same-origin page and send its URL to the Owner;
 * session cookies are HttpOnly, but same-origin script can read a page, lift
 * its CSRF token and drive owner-only endpoints as the Owner.
 *
 * The menu-item resize step was not a mitigation either — Intervention picks
 * its encoder from the extension, has none for `.html`, throws, and
 * resizeMenuImage()'s catch deliberately keeps the ORIGINAL bytes. The ad path
 * has no resize step at all.
 *
 * THE RULE
 * --------
 * Nothing the client sent reaches the filesystem. The name is
 *
 *     <unix time>_<prefix><32 random hex chars>.<extension from CONTENT>
 *
 * and the extension is whitelisted to the four formats the validation rules
 * already accept. The leading timestamp is kept only because it matches the
 * names already sitting in public/uploads and reads sensibly to a human; it is
 * NOT what makes the name unique.
 *
 * WHY THE RANDOM TOKEN, NOT JUST time()
 * -------------------------------------
 * The old name was second-granular, so two uploads of `logo.jpg` within the
 * same second produced the SAME path and the second silently overwrote the
 * first — reproduced as a failing assertion before this fix. That is an
 * integrity bug with a security edge, because one branch's supervisor could
 * replace another branch's image. 16 random bytes makes a collision not worth
 * reasoning about.
 */
final class UploadedImageName
{
    /**
     * The only extensions an upload may ever be stored with.
     *
     * Deliberately the same set the `mimes:jpeg,png,jpg,webp` rules accept, so
     * this cannot drift into accepting something the validator rejects. `jpeg`
     * is normalised to `jpg` below rather than listed twice.
     */
    private const ALLOWED = ['jpg', 'png', 'webp'];

    /**
     * Build the on-disk filename for a validated image upload.
     *
     * @param  string  $prefix  A short label kept from the previous naming
     *                          scheme so the directories stay readable
     *                          ('cat_', 'ad_', or '' for menu items).
     */
    public static function for(UploadedFile $file, string $prefix = ''): string
    {
        return time() . '_' . $prefix . bin2hex(random_bytes(16)) . '.' . self::extensionFor($file);
    }

    /**
     * The extension to store, derived from the file's CONTENT.
     *
     * UploadedFile::extension() is Symfony's guessExtension(), which reads the
     * file's magic bytes — it is not getClientOriginalExtension() and cannot be
     * set by the uploader.
     *
     * The whitelist is belt and braces. Validation has already established
     * that this is a JPEG, PNG or WEBP by the time we are called, so the
     * fallback should be unreachable; it exists so that a future caller who
     * forgets the `mimes:` rule still cannot get an arbitrary extension onto
     * disk. `jpg` is the safe default because it is inert when served.
     */
    private static function extensionFor(UploadedFile $file): string
    {
        $guessed = strtolower((string) $file->extension());

        if ($guessed === 'jpeg') {
            $guessed = 'jpg';
        }

        return in_array($guessed, self::ALLOWED, true) ? $guessed : 'jpg';
    }
}
