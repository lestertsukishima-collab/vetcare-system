<?php

namespace Tests\Feature;

use App\Livewire\Clinic\PetPhoto;
use App\Models\Appointment;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PetPhotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public static function editors(): array
    {
        return [['owner'], ['admin']];
    }

    #[DataProvider('editors')]
    public function test_owners_and_admins_can_upload_replace_and_remove_a_pet_photo(string $role): void
    {
        $owner = User::factory()->create();
        $actor = $role === 'admin' ? User::factory()->admin()->create() : $owner;
        $pet = Pet::factory()->for($owner->owner)->create();
        $other = Pet::factory()->create();

        $component = Livewire::actingAs($actor)->test(PetPhoto::class, ['petId' => $pet->id])
            ->set('photo', $this->photo())->call('savePhoto')->assertHasNoErrors()
            ->assertDispatched('pet-photo-updated');
        $first = $pet->refresh()->photo_path;
        $firstUrl = $pet->photoUrl();
        Storage::disk('local')->assertExists($first);
        $this->get($firstUrl)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/clinic/pets')->assertOk()->assertSee($firstUrl, false)->assertSee('Manage pet photo');

        $component->set('photo', $this->photo())->call('savePhoto')->assertHasNoErrors();
        $second = $pet->refresh()->photo_path;
        $this->assertNotSame($first, $second);
        $this->assertNotSame($firstUrl, $pet->photoUrl());
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
        $this->get($firstUrl)->assertNotFound();
        $this->assertNull($other->refresh()->photo_path);

        $component->call('removePhoto')->assertHasNoErrors();
        $this->assertNull($pet->refresh()->photo_path);
        $this->assertNull($pet->photoUrl());
        Storage::disk('local')->assertMissing($second);
    }

    public function test_the_appointed_doctor_sees_the_pet_photo_in_appointments_patients_and_history(): void
    {
        $owner = User::factory()->create();
        $vet = User::factory()->veterinarian()->create();
        $pet = Pet::factory()->for($owner->owner)->create(['name' => 'Bella']);
        Livewire::actingAs($owner)->test(PetPhoto::class, ['petId' => $pet->id])
            ->set('photo', $this->photo())->call('savePhoto')->assertHasNoErrors();
        Appointment::factory()->for($pet)->for($vet->veterinarian)->create([
            'status' => 'pending', 'appointment_date' => now()->addDays(2),
        ]);
        $url = $pet->refresh()->photoUrl();

        $this->actingAs($vet)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        foreach (['/dashboard', '/clinic/appointments', '/clinic/pets', '/clinic/records', '/clinic/records?patient='.$pet->id] as $page) {
            $this->get($page)->assertOk()->assertSee($url, false)->assertSee('Bella')->assertDontSee('Manage pet photo');
        }
        Livewire::actingAs($vet)->test(PetPhoto::class, ['petId' => $pet->id])->assertForbidden();
    }

    public function test_unrelated_accounts_cannot_view_or_modify_the_photo(): void
    {
        $owner = User::factory()->create();
        $pet = Pet::factory()->for($owner->owner)->create();
        Livewire::actingAs($owner)->test(PetPhoto::class, ['petId' => $pet->id])
            ->set('photo', $this->photo())->call('savePhoto');
        $url = $pet->refresh()->photoUrl();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get($url)->assertNotFound();
        Livewire::actingAs($stranger)->test(PetPhoto::class, ['petId' => $pet->id])->assertNotFound();
        $this->actingAs(User::factory()->veterinarian()->create())->get($url)->assertNotFound();
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())->get($url)->assertRedirect(route('verification.notice'));
    }

    public function test_photo_access_follows_appointment_reassignment(): void
    {
        $owner = User::factory()->create();
        $first = User::factory()->veterinarian()->create();
        $second = User::factory()->veterinarian()->create();
        $pet = Pet::factory()->for($owner->owner)->create();
        Livewire::actingAs($owner)->test(PetPhoto::class, ['petId' => $pet->id])
            ->set('photo', $this->photo())->call('savePhoto');
        $visit = Appointment::factory()->for($pet)->for($first->veterinarian)->create();
        $url = $pet->refresh()->photoUrl();

        $this->actingAs($first)->get($url)->assertOk();
        $visit->update(['veterinarian_id' => $second->veterinarian->id]);
        $this->get($url)->assertNotFound();
        $this->actingAs($second)->get($url)->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk();
    }

    public function test_invalid_photos_preserve_the_existing_image_and_a_retry_clears_validation(): void
    {
        $owner = User::factory()->create();
        $pet = Pet::factory()->for($owner->owner)->create();
        $component = Livewire::actingAs($owner)->test(PetPhoto::class, ['petId' => $pet->id])
            ->set('photo', $this->photo()->size(2048))->call('savePhoto')->assertHasNoErrors();
        $path = $pet->refresh()->photo_path;

        $component->set('photo', $this->photo()->size(2049))->call('savePhoto')->assertHasErrors(['photo' => 'max']);
        $component->set('photo', $this->photo(64, 63))->call('savePhoto')->assertHasErrors('photo');
        $component->set('photo', UploadedFile::fake()->createWithContent('invalid.png', 'invalid image'))
            ->call('savePhoto')->assertHasErrors('photo');
        $component->set('photo', 'pet-photos/other/photo.png')->call('savePhoto')->assertHasErrors('photo');
        $this->assertSame($path, $pet->refresh()->photo_path);
        Storage::disk('local')->assertExists($path);
        $this->assertCount(1, Storage::disk('local')->files('pet-photos/'.$pet->id));
        $component->set('photo', $this->photo())->call('savePhoto')->assertHasNoErrors();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_an_open_editor_rechecks_the_current_role_before_saving(): void
    {
        $owner = User::factory()->create();
        $pet = Pet::factory()->for($owner->owner)->create();
        $component = Livewire::actingAs($owner)->test(PetPhoto::class, ['petId' => $pet->id])
            ->set('photo', $this->photo());
        $owner->forceFill(['role' => 'veterinarian'])->save();
        $component->call('savePhoto')->assertForbidden();
        $this->assertNull($pet->refresh()->photo_path);
    }

    public function test_an_open_editor_cannot_be_retargeted_to_another_pet(): void
    {
        $owner = User::factory()->create();
        $pet = Pet::factory()->for($owner->owner)->create();
        $other = Pet::factory()->create();
        $component = Livewire::actingAs($owner)->test(PetPhoto::class, ['petId' => $pet->id]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('petId', $other->id);
    }

    public function test_photo_paths_are_hidden_and_cannot_be_mass_assigned(): void
    {
        $pet = Pet::factory()->create();
        $pet->fill(['photo_path' => 'untrusted-path']);
        $this->assertNull($pet->photo_path);
        $this->assertArrayNotHasKey('photo_path', $pet->toArray());
    }

    private function photo(int $width = 64, int $height = 64): File
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\x40\x80\x60", $width), $height)))
            .$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('pet.png', $png);
    }
}
