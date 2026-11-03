<?php

namespace App\Http\Controllers;

use App\Services\CustomerOrderAccess;
use App\Services\GcashProof;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer's GCash payment screenshot: attach or replace it, remove it, and
 * serve it to the people allowed to see it.
 *
 * See App\Services\GcashProof for the rules. Like /discount-id/{order}, the URL
 * carries an order id and never a filename, so a leaked URL is worth nothing
 * and every request is authorised again.
 */
class GcashProofController extends Controller
{
    private const SCREENSHOT_RULES = 'required|file|mimes:jpg,jpeg,png,webp|max:' . GcashProof::MAX_KILOBYTES;

    private const NOT_AN_IMAGE = 'Please attach a JPG, PNG or WEBP image.';

    private const SAVE_FAILED = 'We could not save your screenshot. Please try again.';

    public function show(int $order): Response
    {
        $viewable = GcashProof::viewableOrder($order);
        $path = $viewable ? GcashProof::storedPath($viewable) : null;

        // No such order, no screenshot on it, or this visitor has no claim to
        // it: the same 404 on purpose. See DiscountIdAccess.
        if ($path === null) {
            abort(404);
        }

        $disk = Storage::disk(GcashProof::DISK);

        // The row can outlive the file (a partial restore, a hand-cleaned
        // folder). That must not become a 500.
        if (! $disk->exists($path)) {
            abort(404);
        }

        return response($disk->get($path), 200, [
            // Pinned, not read from anywhere: reEncode() only ever writes JPEG.
            'Content-Type'           => 'image/jpeg',
            'Content-Disposition'    => 'inline; filename="gcash-receipt-' . $order . '.jpg"',
            'Cache-Control'          => 'private, no-store, max-age=0',
            'Pragma'                 => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $order = $this->ownedGcashOrder($id);

        // Before validating, so a closed order is told why rather than having
        // its file checked first.
        if (! GcashProof::isOpen($order)) {
            return $this->closed($request);
        }

        $request->validate(['screenshot' => self::SCREENSHOT_RULES], [
            'screenshot.required' => 'Please choose a screenshot to attach.',
            'screenshot.file'     => self::NOT_AN_IMAGE,
            'screenshot.uploaded' => 'That image could not be uploaded. Please use a JPG, PNG or WEBP image up to 5 MB.',
            'screenshot.mimes'    => self::NOT_AN_IMAGE,
            'screenshot.max'      => 'That image is larger than 5 MB. Please attach a smaller one.',
        ]);

        $jpeg = GcashProof::reEncode($request->file('screenshot')->getRealPath());

        if ($jpeg === null) {
            throw ValidationException::withMessages(['screenshot' => GcashProof::UNREADABLE_MESSAGE]);
        }

        try {
            $saved = GcashProof::attach($order, $jpeg);
        } catch (\Throwable $e) {
            report($e);

            return $this->failed($request);
        }

        if (! $saved) {
            return $this->closed($request);
        }

        return $this->saved($request, 'Screenshot saved. Staff will see it with your payment.', $order->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $order = $this->ownedGcashOrder($id);

        try {
            $removed = GcashProof::remove($order);
        } catch (\Throwable $e) {
            report($e);

            return $this->failed($request);
        }

        if (! $removed) {
            return $this->closed($request);
        }

        return $this->saved($request, 'Screenshot removed.', $order->fresh());
    }

    /**
     * Only the person who owns the order, by the rule the payment page itself
     * uses. Staff have no way in here: the picture is the customer's evidence.
     */
    private function ownedGcashOrder(int $id): \App\Models\Order
    {
        $order = CustomerOrderAccess::resolveOwnedOrder($id);

        if (! $order || $order->payment_method !== 'gcash') {
            abort(404);
        }

        return $order;
    }

    private function saved(Request $request, string $message, \App\Models\Order $order): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'url'     => $order->gcashProofUrl(),
            ]);
        }

        return redirect()->route('customer.gcash-payment', $order->id);
    }

    private function closed(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => GcashProof::CLOSED_MESSAGE], 409);
        }

        // Orders, not the payment page: that page sends a closed payment to
        // Orders by itself, and the message would not survive the second hop.
        return redirect()->route('customer.orders')->withErrors(['screenshot' => GcashProof::CLOSED_MESSAGE]);
    }

    private function failed(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => self::SAVE_FAILED], 500);
        }

        return back()->withErrors(['screenshot' => self::SAVE_FAILED]);
    }
}
