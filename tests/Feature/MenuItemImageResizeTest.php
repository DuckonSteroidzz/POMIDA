<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Uploaded menu-item photos are resized server-side so a phone on cellular
 * doesn't download an oversized original for a small grid card. Covers both
 * upload call sites (create and edit) and confirms the file that lands on
 * disk is actually shrunk, not just accepted.
 */
class MenuItemImageResizeTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'IMGRESIZE';

    private array $writtenPaths = [];

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

    /** A 1600x1200 JPEG, well above the 800px cap this feature enforces. */
    private function oversizedUpload(): UploadedFile
    {
        $manager = new ImageManager(new Driver());
        $tmp = sys_get_temp_dir() . '/' . self::PREFIX . '_' . uniqid() . '.jpg';
        $manager->create(1600, 1200)->fill('3366ff')->save($tmp, quality: 90);

        $this->writtenPaths[] = $tmp;

        return new UploadedFile($tmp, 'oversized.jpg', 'image/jpeg', null, true);
    }

    private function assertStoredImageIsResized(string $relativePath): void
    {
        $absolute = public_path($relativePath);
        $this->writtenPaths[] = $absolute;

        $this->assertFileExists($absolute);

        [$width, $height] = getimagesize($absolute);

        $this->assertLessThanOrEqual(800, $width, 'stored image width exceeds the 800px cap');
        $this->assertLessThanOrEqual(800, $height, 'stored image height exceeds the 800px cap');
        // Aspect ratio (4:3) must survive the resize.
        $this->assertEqualsWithDelta(4 / 3, $width / $height, 0.01);
    }

    public function test_new_menu_item_upload_is_resized(): void
    {
        $categoryId = Category::query()->value('id');

        $response = $this->actingAs($this->owner(), 'admin')
            ->withSession(['selected_branch_id' => 1])
            ->post(route('admin.new-menu-item.post'), [
                'name'        => self::PREFIX . ' Dish ' . uniqid(),
                'price'       => 99,
                'category_id' => $categoryId,
                'branch_id'   => 1,
                'image'       => $this->oversizedUpload(),
            ]);

        $response->assertSessionDoesntHaveErrors();

        $menuItem = MenuItem::query()
            ->where('name', 'like', self::PREFIX . '%')
            ->latest('id')
            ->first();

        $this->assertNotNull($menuItem);
        $this->assertNotNull($menuItem->image);

        $this->assertStoredImageIsResized($menuItem->image);
    }

    public function test_menu_item_update_upload_is_resized(): void
    {
        $categoryId = Category::query()->value('id');

        $menuItem = MenuItem::create([
            'category_id' => $categoryId,
            'name'        => self::PREFIX . ' Existing ' . uniqid(),
            'price'       => 50,
            'branch_id'   => 1,
        ]);

        $response = $this->actingAs($this->owner(), 'admin')->put(route('admin.menu-items.update', $menuItem->id), [
            'name'        => $menuItem->name,
            'price'       => 55,
            'category_id' => $categoryId,
            'branch_id'   => 1,
            'image'       => $this->oversizedUpload(),
        ]);

        $response->assertSessionDoesntHaveErrors();

        $this->assertStoredImageIsResized($menuItem->fresh()->image);
    }
}
