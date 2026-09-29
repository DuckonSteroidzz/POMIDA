<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * updateMenuItem() used to unconditionally unlink() the old picture whenever
 * a new one was uploaded — even when another menu_items row (any branch,
 * including an archived one) still pointed at that exact same path.
 * CatalogueLifecycle::removeMenuItemImage() already guards Permanent Delete
 * against this ("is this path used elsewhere": MenuItem::withArchived()
 * ->where('image', $image)->exists() — see MenuItemSoldPermanentDeleteTest's
 * "shared image" case). This covers the same guard on the Edit path, added
 * directly in updateMenuItem() rather than by reusing the private helper,
 * since here the row being changed is still live in the database (the
 * helper's callers have already had their row deleted, so it never needs to
 * exclude the id it was called for).
 */
class MenuItemUpdateSharedImageTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'SHAREDIMG';

    /** @var string[] */
    private array $writtenPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenPaths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function owner(): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Owner',
            'email'     => strtolower(self::PREFIX) . '-owner-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => 'admin',
            'branch_id' => null,
            'is_active' => true,
        ]);
    }

    /** A real file dropped straight into the upload folder, as an already-saved menu item's image would be. */
    private function existingImageFile(): string
    {
        $dir = public_path('uploads/menu-items');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $relative = 'uploads/menu-items/' . self::PREFIX . '_' . uniqid() . '.png';
        file_put_contents(public_path($relative), $this->pngBytes());
        $this->writtenPaths[] = public_path($relative);

        return $relative;
    }

    /**
     * A genuine 1x1 PNG (not UploadedFile::fake()->image(), which needs GD
     * just to fabricate the fixture) so the controller's real resize step
     * runs against real bytes instead of being bypassed.
     */
    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    private function newUpload(): UploadedFile
    {
        $tmp = sys_get_temp_dir() . '/' . self::PREFIX . '_new_' . uniqid() . '.png';
        file_put_contents($tmp, $this->pngBytes());
        $this->writtenPaths[] = $tmp;

        return new UploadedFile($tmp, 'new.png', 'image/png', null, true);
    }

    private function item(string $label, ?string $image, ?int $branchId = 1): MenuItem
    {
        return MenuItem::create([
            'category_id' => Category::value('id'),
            'name'        => self::PREFIX . " $label " . uniqid(),
            'price'       => 100,
            'branch_id'   => $branchId,
            'image'       => $image,
        ]);
    }

    public function test_updating_one_items_picture_does_not_delete_a_file_another_item_still_uses(): void
    {
        $shared = $this->existingImageFile();
        $itemA = $this->item('A', $shared);
        $itemB = $this->item('B', $shared);

        $response = $this->actingAs($this->owner(), 'admin')
            ->put(route('admin.menu-items.update', $itemA->id), [
                'name'        => $itemA->name,
                'price'       => 120,
                'category_id' => $itemA->category_id,
                'branch_id'   => 1,
                'image'       => $this->newUpload(),
            ]);

        $response->assertSessionDoesntHaveErrors();

        $itemA->refresh();
        $this->assertNotSame($shared, $itemA->image, 'item A should have moved on to its new picture');
        $this->writtenPaths[] = public_path($itemA->image);

        $this->assertFileExists(public_path($shared), 'another menu item still uses this exact file');
        $this->assertSame($shared, $itemB->fresh()->image, "item B's own image column must be untouched");
    }

    public function test_updating_a_picture_no_one_else_uses_still_deletes_the_old_file(): void
    {
        $solo = $this->existingImageFile();
        $item = $this->item('Solo', $solo);

        $response = $this->actingAs($this->owner(), 'admin')
            ->put(route('admin.menu-items.update', $item->id), [
                'name'        => $item->name,
                'price'       => 120,
                'category_id' => $item->category_id,
                'branch_id'   => 1,
                'image'       => $this->newUpload(),
            ]);

        $response->assertSessionDoesntHaveErrors();

        $this->assertFileDoesNotExist(public_path($solo), 'no one else uses it, so cleanup must still happen');

        $this->writtenPaths[] = public_path($item->fresh()->image);
    }

    public function test_an_archived_items_image_is_also_protected(): void
    {
        $shared = $this->existingImageFile();
        $active = $this->item('Active', $shared);
        $archived = $this->item('Archived', $shared);
        $archived->archive();

        $response = $this->actingAs($this->owner(), 'admin')
            ->put(route('admin.menu-items.update', $active->id), [
                'name'        => $active->name,
                'price'       => 120,
                'category_id' => $active->category_id,
                'branch_id'   => 1,
                'image'       => $this->newUpload(),
            ]);

        $response->assertSessionDoesntHaveErrors();

        $this->assertFileExists(public_path($shared), 'an archived item still displays this file if restored');

        $this->writtenPaths[] = public_path($active->fresh()->image);
    }
}
