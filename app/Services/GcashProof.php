<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * GcashProof — the customer's screenshot of their GCash payment, attached to
 * the order so staff can read the amount and reference number before they
 * approve it.
 *
 * It is optional and it changes nothing about the payment itself: "I Have Paid",
 * awaiting verification, Approve/Reject and refunds all work without one, and
 * none of them look at it.
 *
 * WHERE THE FILES LIVE
 * --------------------
 * On the `local` disk under gcash_proofs/ (storage/app/gcash_proofs), which is
 * not web-reachable, and only ever served by GcashProofController. A payment
 * screenshot shows a person's name, number and balance, so it gets the same
 * treatment as a PWD/Senior ID: see DiscountIdAccess for the exposure that
 * came from putting those on the public disk.
 *
 * WHY EVERY UPLOAD IS RE-ENCODED
 * ------------------------------
 * Nothing the customer sent is stored. The image is decoded and a NEW JPEG is
 * written from the pixels, under a random name. So a non-image cannot get in
 * under any extension, a real image cannot carry a payload past this point (a
 * COM segment passes getimagesize(); see UploadedImageName), and the phone's
 * EXIF data, GPS position included, never reaches the disk.
 *
 * WHO MAY VIEW ONE
 * ----------------
 * See viewableOrder(). Every refusal is a 404, for the reasons AdminOrderAccess
 * and DiscountIdAccess give.
 */
class GcashProof
{
    public const DISK = 'local';

    public const DIRECTORY = 'gcash_proofs';

    /** 5 MB, in the kilobytes the `max:` validation rule counts in. */
    public const MAX_KILOBYTES = 5120;

    /**
     * Refused before decoding, because a small PNG can decode to gigabytes and
     * running out of memory is a fatal error nothing can catch. 20 MP is about
     * 80 MB decoded; a 12 MP phone photo and every phone screenshot fit.
     */
    public const MAX_PIXELS = 20000000;

    /** The longest side kept. A reference number stays readable at this size. */
    public const MAX_EDGE = 2400;

    public const JPEG_QUALITY = 90;

    public const UNREADABLE_MESSAGE = 'That file could not be read as a picture. Please attach a JPG, PNG or WEBP screenshot.';

    public const CLOSED_MESSAGE = 'This payment has already been reviewed or the order was cancelled, '
        . 'so the screenshot can no longer be changed.';

    /**
     * May the screenshot on this order still be attached, replaced or removed?
     *
     * Only while the payment is waiting on the customer or on staff. Once staff
     * have approved or rejected it, or the order is over, the picture is the
     * record of what they looked at and has to stop changing.
     */
    public static function isOpen(Order $order): bool
    {
        return $order->payment_method === 'gcash'
            && ! in_array($order->status, ['cancelled', 'completed'], true)
            && in_array($order->payment_status, ['pending', 'awaiting_verification'], true);
    }

    /**
     * The order, if the current visitor may see its screenshot, otherwise null.
     *
     * Staff, supervisor and owner go through AdminOrderAccess: their own
     * branch's orders for the first two, any order for the owner. A deactivated
     * account is not staff (see viewerIsVerifyingStaff). Everyone else has only
     * the ownership claim, the same rule as the payment page and the receipt.
     *
     * Not "staff, otherwise customer": one browser can hold both logins (see
     * SessionSide), and staff at another branch still own their own order.
     */
    public static function viewableOrder(int $orderId): ?Order
    {
        if (DiscountIdAccess::viewerIsVerifyingStaff()) {
            $order = AdminOrderAccess::findInScope($orderId);

            if ($order) {
                return $order;
            }
        }

        return CustomerOrderAccess::resolveOwnedOrder($orderId);
    }

    /**
     * The stored path, only if it has the exact shape this class writes. The
     * value comes from the database, but a tampered row must not be able to
     * read or delete anything else on the disk.
     */
    public static function storedPath(Order $order): ?string
    {
        $path = (string) $order->gcash_proof_path;

        return preg_match('#^' . self::DIRECTORY . '/[a-f0-9]{40}\.jpg$#', $path) === 1 ? $path : null;
    }

    /**
     * Decode an uploaded file and return a new JPEG of it, or null when it is
     * not an image this feature accepts.
     *
     * The file has already passed the `mimes` rule, which reads magic bytes.
     * This is the check that cannot be fooled by them: a file that merely starts
     * like an image fails to decode here.
     */
    public static function reEncode(string $sourcePath): ?string
    {
        $info = @getimagesize($sourcePath);

        if ($info === false
            || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $info[0] < 1
            || $info[1] < 1
            || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }

        try {
            // Without @ on purpose: libgd reports a corrupt JPEG or PNG as a
            // warning, which Laravel turns into the ErrorException caught below.
            // A corrupt WEBP is a fatal error instead: see bootstrap/app.php.
            $source = imagecreatefromstring((string) file_get_contents($sourcePath));

            if ($source === false) {
                return null;
            }

            ob_start();
            imagejpeg(self::uprightOnWhite($source, $sourcePath, $info), null, self::JPEG_QUALITY);
            $bytes = ob_get_clean();
        } catch (\Throwable $e) {
            // Anything but a bad file (GD missing on the server, say) would
            // otherwise look like every customer uploading bad files.
            if (! $e instanceof \ErrorException) {
                report($e);
            }

            return null;
        }

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /**
     * The picture as staff should see it: the way up the customer took it, no
     * larger than MAX_EDGE, with any transparency flattened onto white.
     */
    private static function uprightOnWhite(\GdImage $source, string $sourcePath, array $info): \GdImage
    {
        // Re-encoding drops EXIF, so a phone photo has to be turned now or it
        // arrives sideways. function_exists: the exif extension is optional.
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($sourcePath);
            $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;

            if ($angle !== 0) {
                $source = imagerotate($source, $angle, 0);
            }
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_EDGE / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $canvas;
    }

    /**
     * Save a re-encoded image as this order's screenshot, deleting the one it
     * replaces. False when the order has closed since the caller looked.
     *
     * The window is re-checked under a row lock, so an upload that loses a race
     * with Approve or Reject cannot change the picture afterwards, and two
     * uploads at once cannot orphan a file. The new file is written first, so a
     * failure at any point leaves the order's current screenshot intact.
     */
    public static function attach(Order $order, string $jpegBytes): bool
    {
        $disk = Storage::disk(self::DISK);
        $newPath = self::DIRECTORY . '/' . bin2hex(random_bytes(20)) . '.jpg';

        if (! $disk->put($newPath, $jpegBytes)) {
            throw new \RuntimeException('Could not write the GCash screenshot to the ' . self::DISK . ' disk.');
        }

        $old = null;

        try {
            $saved = self::swap($order, $newPath, $old);
        } catch (\Throwable $e) {
            $disk->delete($newPath);

            throw $e;
        }

        if (! $saved) {
            $disk->delete($newPath);

            return false;
        }

        self::deleteFile($old);

        return true;
    }

    /**
     * Take the screenshot off this order and delete the file. False when the
     * order has closed.
     */
    public static function remove(Order $order): bool
    {
        $old = null;

        if (! self::swap($order, null, $old)) {
            return false;
        }

        self::deleteFile($old);

        return true;
    }

    /**
     * Point the order at a new file, or none, under a row lock. $old receives
     * the path it replaces, so that file is only deleted after the commit.
     */
    private static function swap(Order $order, ?string $newPath, ?string &$old): bool
    {
        return DB::transaction(function () use ($order, $newPath, &$old) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked || ! self::isOpen($locked)) {
                return false;
            }

            $old = self::storedPath($locked);

            $locked->gcash_proof_path = $newPath;
            $locked->gcash_proof_uploaded_at = $newPath === null ? null : now();
            $locked->save();

            return true;
        });
    }

    private static function deleteFile(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
