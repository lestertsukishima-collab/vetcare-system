<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\ProfilePhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public static function roles(): array
    {
        return [['owner'], ['veterinarian'], ['admin']];
    }

    #[DataProvider('roles')]
    public function test_every_role_can_upload_replace_and_remove_their_profile_photo(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $other = User::factory()->create();
        $component = Livewire::actingAs($user)->test(ProfilePhoto::class)
            ->set('photo', $this->photo())->call('savePhoto')->assertHasNoErrors()
            ->assertDispatched('profile-photo-updated');

        $first = $user->refresh()->profile_photo_path;
        $firstUrl = $user->profilePhotoUrl();
        Storage::disk('local')->assertExists($first);
        $this->assertStringStartsWith('profile-photos/'.$user->id.'/', $first);
        $this->get($firstUrl)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('dashboard'))->assertOk()->assertSee($firstUrl, false);
        $this->get(route('profile.edit'))->assertOk()->assertSee('Choose a new photo')->assertSee($firstUrl, false);

        $component->set('photo', $this->photo())->call('savePhoto')->assertHasNoErrors();
        $second = $user->refresh()->profile_photo_path;
        $this->assertNotSame($first, $second);
        $this->assertNotSame($firstUrl, $user->profilePhotoUrl());
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
        $this->get($firstUrl)->assertNotFound();
        $this->assertNull($other->refresh()->profile_photo_path);

        $component->call('removePhoto')->assertHasNoErrors();
        $this->assertNull($user->refresh()->profile_photo_path);
        $this->assertNull($user->profilePhotoUrl());
        Storage::disk('local')->assertMissing($second);
        $this->get(route('profile.edit'))->assertOk()->assertSee('Choose a photo')->assertDontSee('Choose a new photo');
    }

    public function test_a_photo_of_exactly_two_megabytes_is_accepted(): void
    {
        Livewire::actingAs(User::factory()->create())->test(ProfilePhoto::class)
            ->set('photo', $this->photo()->size(2048))
            ->call('savePhoto')->assertHasNoErrors();
    }

    public function test_oversized_photo_is_rejected_and_existing_photo_is_preserved(): void
    {
        $user = User::factory()->create();
        $component = Livewire::actingAs($user)->test(ProfilePhoto::class)
            ->set('photo', $this->photo())->call('savePhoto');
        $path = $user->refresh()->profile_photo_path;

        $component->set('photo', $this->photo()->size(2049))
            ->call('savePhoto')->assertHasErrors(['photo' => 'max']);

        $this->assertSame($path, $user->refresh()->profile_photo_path);
        Storage::disk('local')->assertExists($path);
        $this->assertCount(1, Storage::disk('local')->files('profile-photos/'.$user->id));
    }

    public function test_non_square_images_are_rejected_even_when_only_one_pixel_off(): void
    {
        $user = User::factory()->create();
        $component = Livewire::actingAs($user)->test(ProfilePhoto::class);

        foreach ([[100, 50], [50, 100], [512, 511]] as [$width, $height]) {
            $component->set('photo', $this->photo($width, $height))
                ->call('savePhoto')->assertHasErrors('photo');
        }

        $this->assertNull($user->refresh()->profile_photo_path);
        $this->assertEmpty(Storage::disk('local')->files('profile-photos/'.$user->id));
    }

    public function test_invalid_images_and_svg_files_are_rejected(): void
    {
        $user = User::factory()->create();
        $component = Livewire::actingAs($user)->test(ProfilePhoto::class);

        foreach ([
            UploadedFile::fake()->createWithContent('photo.png', 'not an image'),
            UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"></svg>'),
        ] as $file) {
            $component->set('photo', $file)->call('savePhoto')->assertHasErrors('photo');
        }

        $component->set('photo', null)->call('savePhoto')->assertHasErrors(['photo' => 'required']);
        $this->assertNull($user->refresh()->profile_photo_path);
    }

    public function test_photo_delivery_requires_authentication_and_does_not_expose_another_account_photo(): void
    {
        $owner = User::factory()->create();
        Livewire::actingAs($owner)->test(ProfilePhoto::class)
            ->set('photo', $this->photo())->call('savePhoto')->assertHasNoErrors();
        $url = $owner->refresh()->profilePhotoUrl();

        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_internal_file_path_is_hidden_from_serialized_user_data(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(ProfilePhoto::class)
            ->set('photo', $this->photo())->call('savePhoto')->assertHasNoErrors();

        $this->assertArrayNotHasKey('profile_photo_path', $user->refresh()->toArray());
        $this->assertFalse($user->isFillable('profile_photo_path'));
    }

    public function test_a_failed_upload_can_be_corrected_without_leaving_old_validation_errors(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(ProfilePhoto::class)
            ->set('photo', $this->photo(100, 50))->call('savePhoto')->assertHasErrors('photo')
            ->set('photo', $this->photo())->call('savePhoto')->assertHasNoErrors();

        Storage::disk('local')->assertExists($user->refresh()->profile_photo_path);
    }

    public function test_tampered_upload_values_return_validation_errors_instead_of_saving_a_path(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(ProfilePhoto::class)
            ->set('photo', 'profile-photos/another-account/photo.png')
            ->call('savePhoto')->assertHasErrors('photo')
            ->call('removePhoto')->assertHasNoErrors();

        $this->assertNull($user->refresh()->profile_photo_path);
    }

    // Generate a real PNG without requiring the optional GD extension.
    private function photo(int $width = 64, int $height = 64): File
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $row = "\0".str_repeat("\x40\x80\x60", $width);
        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat($row, $height)))
            .$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('portrait.png', $png);
    }
}
