<?php

namespace Tests\Feature;

use App\Livewire\Clinic\ActionForm;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Models\Veterinarian;
use App\Services\ProfilePhotos;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VeterinarianBookingPhotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public static function bookingRoles(): array
    {
        return [['owner'], ['admin']];
    }

    #[DataProvider('bookingRoles')]
    public function test_bookers_can_choose_a_doctor_by_photo_name_and_specialization(string $role): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $actor = $role === 'owner' ? $owner : User::factory()->admin()->create();
        $doctor = $this->doctor('Dr. Avery Santos', true);
        $doctor->update(['specialization' => 'Small animal care']);
        $withoutPhoto = $this->doctor('Dr. Morgan Reyes');
        $inactive = $this->doctor('Dr. Inactive');
        $inactive->user->forceFill(['active' => false])->save();
        $formerDoctor = $this->doctor('Former Doctor');
        $formerDoctor->user->forceFill(['role' => 'owner'])->save();

        $pet = $owner->owner->pets()->create(['name' => 'Bella', 'species' => 'Dog', 'sex' => 'Female']);
        $service = Service::factory()->create(['active' => true]);
        $component = Livewire::actingAs($actor)->test(ActionForm::class, ['section' => 'appointments'])
            ->assertSee('Dr. Avery Santos')->assertSee('Small animal care')
            ->assertSee($doctor->bookingPhotoUrl(), false)
            ->assertSee('Dr. Morgan Reyes')->assertSee('General practice')
            ->assertDontSee('Dr. Inactive')->assertDontSee('Former Doctor');

        $xpath = $this->xpath($component->html());
        $this->assertSame(2, $xpath->query('//input[@name="veterinarian_id" and @type="radio"]')->length);
        $this->assertSame(1, $xpath->query('//label[contains(@class,"veterinarian-option")][.//input[@value="'.$doctor->id.'"]]//img')->length);
        $this->assertSame(0, $xpath->query('//label[contains(@class,"veterinarian-option")][.//input[@value="'.$withoutPhoto->id.'"]]//img')->length);
        $this->assertStringContainsString($withoutPhoto->user->initials(), $xpath->evaluate('string(//label[.//input[@name="veterinarian_id" and @value="'.$withoutPhoto->id.'"]])'));
        $this->get($doctor->bookingPhotoUrl())->assertOk()->assertHeader('Content-Type', 'image/png');

        // A failed submission retains the selected card, then books that exact doctor.
        $component->set('data.veterinarian_id', (string) $doctor->id)->call('save')->assertHasErrors('pet_id');
        $this->assertSame(''.$doctor->id, $this->xpath($component->html())->evaluate('string(//input[@name="veterinarian_id" and @checked]/@value)'));
        $component->set('data.pet_id', (string) $pet->id)->set('data.service_id', (string) $service->id)
            ->set('data.appointment_date', now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('data.reason', 'Routine consultation')->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('appointments', ['pet_id' => $pet->id, 'veterinarian_id' => $doctor->id, 'status' => 'pending']);
    }

    public function test_rescheduling_shows_and_keeps_the_selected_doctor_then_can_change_doctors(): void
    {
        $owner = User::factory()->create();
        $first = $this->doctor('First Doctor', true);
        $second = $this->doctor('Second Doctor', true);
        $pet = $owner->owner->pets()->create(['name' => 'Bella', 'species' => 'Dog', 'sex' => 'Female']);
        $service = Service::factory()->create();
        $visit = Appointment::create([
            'pet_id' => $pet->id, 'veterinarian_id' => $first->id, 'service_id' => $service->id,
            'service_name' => $service->name, 'service_fee' => $service->price,
            'appointment_date' => now()->addDays(2), 'status' => 'confirmed', 'reason' => 'Checkup',
        ]);

        $component = Livewire::actingAs($owner)->test(ActionForm::class, ['section' => 'appointments', 'recordId' => $visit->id, 'variant' => 'reschedule'])
            ->assertSee($first->bookingPhotoUrl(), false)->assertSee($second->bookingPhotoUrl(), false);
        $this->assertSame(''.$first->id, $this->xpath($component->html())->evaluate('string(//input[@name="veterinarian_id" and @checked]/@value)'));
        $component->set('data.veterinarian_id', (string) $second->id)->call('save')->assertHasNoErrors();

        $this->assertSame($second->id, $visit->refresh()->veterinarian_id);
        $this->assertSame('pending', $visit->status);
    }

    public function test_doctor_photo_access_requires_a_verified_clinic_account(): void
    {
        $doctor = $this->doctor('Booking Doctor', true);
        $url = $doctor->bookingPhotoUrl();
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())->get($url)->assertRedirect(route('verification.notice'));
        $this->actingAs(User::factory()->create())->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_inactive_former_and_mismatched_doctor_photos_cannot_be_requested(): void
    {
        $viewer = User::factory()->create();
        $doctor = $this->doctor('Booking Doctor', true);
        $other = $this->doctor('Other Doctor', true);
        $url = $doctor->bookingPhotoUrl();
        $this->actingAs($viewer)->get(route('veterinarians.photo', [
            'veterinarian' => $other->id, 'version' => basename($doctor->user->profile_photo_path),
        ]))->assertNotFound();

        $doctor->user->forceFill(['active' => false])->save();
        $this->get($url)->assertNotFound();
        $doctor->user->forceFill(['active' => true, 'role' => 'owner'])->save();
        $this->get($url)->assertNotFound();
    }

    public function test_a_replacement_photo_gets_a_new_booking_url_and_owner_photos_remain_private(): void
    {
        $viewer = User::factory()->create();
        $doctor = $this->doctor('Booking Doctor', true);
        $url = $doctor->bookingPhotoUrl();
        app(ProfilePhotos::class)->replace($doctor->user, $this->photo());
        $doctor->refresh();
        $newUrl = $doctor->bookingPhotoUrl();
        $this->assertNotSame($url, $newUrl);
        $this->actingAs($viewer)->get($url)->assertNotFound();
        $this->get($newUrl)->assertOk();

        $owner = User::factory()->create();
        app(ProfilePhotos::class)->replace($owner, $this->photo());
        $this->get($owner->refresh()->profilePhotoUrl())->assertNotFound();
        app(ProfilePhotos::class)->remove($doctor->user);
        $doctor->refresh();
        $this->assertNull($doctor->bookingPhotoUrl());
        $this->get($newUrl)->assertNotFound();
    }

    private function doctor(string $name, bool $photo = false): Veterinarian
    {
        $user = User::factory()->veterinarian()->create(['name' => $name]);
        if ($photo) {
            app(ProfilePhotos::class)->replace($user, $this->photo());
        }

        return $user->veterinarian->load('user');
    }

    private function photo(): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', 64, 64, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\x40\x80\x60", 64), 64)))
            .$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('doctor.png', $png);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }
}
