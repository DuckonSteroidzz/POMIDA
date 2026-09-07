<?php

namespace App\Http\Controllers;

use App\Services\DiscountIdAccess;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves PWD/Senior ID documents, which are no longer web-reachable.
 *
 * See App\Services\DiscountIdAccess for the exposure this closes, who is
 * allowed to view a document, and why every refusal is a 404.
 *
 * THE URL CARRIES AN ORDER ID, NOT A FILENAME
 * --------------------------------------------
 * The route is /discount-id/{order}, and the stored filename never appears in
 * a URL anywhere. That is the point, and it fixes two things at once:
 *
 *  1. A leaked URL is worthless on its own. Before this change the URL WAS the
 *     credential — anyone holding it had the file forever. Now the URL is just
 *     an order number, and every request is re-authorised against the session
 *     making it.
 *
 *  2. Path traversal is structurally impossible rather than filtered. The
 *     client never supplies a path, so there is no `../` to sanitise; the
 *     path comes from the database row for an order the viewer has already
 *     been authorised against.
 */
class DiscountIdController extends Controller
{
    /**
     * The only directories an ID document may ever be read from.
     *
     * Belt and braces against a malformed or tampered database value: even if
     * a row somehow held "../../.env", it would not match and the request
     * would 404 rather than read an arbitrary file off the disk.
     *
     * `discount_ids/` is where OrderController::placeOrder() writes a
     * per-transaction upload, and is the only one in use today.
     *
     * `discount_cards/` is listed for the saved-discount-card branch at
     * OrderController line ~310, which copies DiscountCard::id_image into the
     * order. That branch is currently unreachable — the discount_cards table
     * holds zero rows and nothing in the codebase creates one — but if card
     * uploads are ever built, they belong on the `local` disk under this
     * prefix. Listing it now means that work does not silently 404 here and
     * cost someone an afternoon; adding a prefix is a deliberate decision to
     * be made in this file, not a lookup that quietly widens.
     */
    private const ALLOWED_PREFIXES = ['discount_ids/', 'discount_cards/'];

    public function show(int $order): Response
    {
        $path = DiscountIdAccess::resolvePathFor($order);

        // No such order, no document on it, or this visitor has no claim to
        // it. All three are the same 404 on purpose — see DiscountIdAccess.
        if ($path === null) {
            abort(404);
        }

        // Normalise a legacy "public/"-prefixed value, then require the result
        // to sit inside discount_ids/. Anything else is treated as missing.
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $path = preg_replace('#^public/#', '', $path);

        $inAllowedDirectory = false;
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $inAllowedDirectory = true;
                break;
            }
        }

        if (! $inAllowedDirectory || str_contains($path, '..')) {
            abort(404);
        }

        $disk = Storage::disk('local');

        // The row can outlive the file — someone clearing storage by hand, a
        // partial restore. A dangling reference must not become a 500.
        if (! $disk->exists($path)) {
            abort(404);
        }

        /*
         * Inline, not a download, because staff view it inside the order
         * board's discount modal.
         *
         * The Content-Type is taken from the stored file rather than echoed
         * from anything the client sent, and it is pinned to an image type.
         * Upload validation already restricts this to jpeg/jpg/png/webp
         * (see OrderController::placeOrder), so a stored file that reports
         * anything else is a sign something is wrong and is refused rather
         * than served — that stops a file that somehow got in as text/html
         * being rendered as a page on this origin.
         */
        $mime = (string) $disk->mimeType($path);

        if (! str_starts_with($mime, 'image/')) {
            abort(404);
        }

        return response($disk->get($path), 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="discount-id-' . $order . '"',

            // Identity documents must not sit in shared caches or proxies, and
            // must not be written to disk by the browser for the next person
            // at a shared café terminal to find.
            'Cache-Control'       => 'private, no-store, max-age=0',
            'Pragma'              => 'no-cache',

            // This is never a page; stop a browser from ever deciding it is.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
