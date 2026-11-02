<?php

use App\Modules\Menu\Filament\Resources\Categories\Pages\EditCategory;
use App\Modules\Menu\Filament\Resources\Items\Pages\EditItem;
use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Item;
use App\Modules\Settings\Filament\Pages\ReceiptSettingsPage;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Security review L2: SVG was accepted as a menu or logo image. An SVG can carry a script that runs
 * on the app's own address when the picture is opened. Only PNG, JPEG and WebP are accepted now.
 */
beforeEach(function () {
    seedRoles();
    Storage::fake('public');
    $this->branch = provisionTenant();
    $this->owner = User::factory()->forBranch($this->branch)->create();
    $this->owner->assignRole(Role::Owner->value);
    $this->category = Category::create(['name' => 'Rice']);
    $this->item = Item::create(['category_id' => $this->category->id, 'name' => 'Jollof Rice', 'price' => 4500]);
    Tenancy::forget();
    $this->actingAs($this->owner);
});

function svgFile(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
}

it('refuses an SVG as a menu item picture but takes a PNG', function () {
    Livewire::test(EditItem::class, ['record' => $this->item->getRouteKey()])
        ->fillForm(['image_path' => svgFile()])->call('save')->assertHasFormErrors(['image_path']);
    expect($this->item->fresh()->image_path)->toBeNull();

    Livewire::test(EditItem::class, ['record' => $this->item->getRouteKey()])
        ->fillForm(['image_path' => UploadedFile::fake()->image('jollof.png', 10, 10)])->call('save')->assertHasNoFormErrors();
    expect($this->item->fresh()->image_path)->toEndWith('.png');
});

it('refuses an SVG as a category picture', function () {
    Livewire::test(EditCategory::class, ['record' => $this->category->getRouteKey()])
        ->fillForm(['image_path' => svgFile()])->call('save')->assertHasFormErrors(['image_path']);
});

it('refuses an SVG as the receipt logo but takes a JPEG', function () {
    Livewire::test(ReceiptSettingsPage::class)
        ->fillForm(['logo_path' => svgFile()])->call('save')->assertHasFormErrors(['logo_path']);

    Tenancy::set($this->branch->tenant, $this->branch); // as the middleware does on a real request
    Livewire::test(ReceiptSettingsPage::class)
        ->fillForm(['logo_path' => UploadedFile::fake()->image('logo.jpg', 10, 10)])->call('save')->assertHasNoFormErrors();
    expect(app(SettingsService::class)->get('receipt.logo_path'))->toEndWith('.jpg');
});
