<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use Tests\TestCase;

class SessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_password_change_invalidates_an_older_browser_session(): void
    {
        $user = User::factory()->create();
        $oldHash = Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword());
        $user->update(['password' => 'changed-password-123']);

        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])
            ->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_stale_session_cannot_write_to_the_api(): void
    {
        $user = User::factory()->create();
        $oldHash = Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword());
        $user->update(['password' => 'changed-password-123']);

        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])
            ->postJson('/api/v1/pets', ['name' => 'Stale session pet', 'species' => 'Cat', 'sex' => 'Female'])
            ->assertUnauthorized();
        $this->assertDatabaseMissing('pets', ['name' => 'Stale session pet']);
    }

    public function test_the_session_that_changes_its_password_can_continue_using_the_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))->assertOk();

        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'changed-password-123')
            ->set('password_confirmation', 'changed-password-123')
            ->call('updatePassword')->assertHasNoErrors();

        $this->assertTrue(Hash::check('changed-password-123', $user->fresh()->password));
        $this->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_password_reset_invalidates_an_established_browser_session(): void
    {
        $this->skipUnlessFortifyHas(Features::resetPasswords());
        $user = User::factory()->create();
        $oldHash = Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword());
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'reset-password-123', 'password_confirmation' => 'reset-password-123',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login', absolute: false));

        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])
            ->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_signing_in_regenerates_the_session_identifier(): void
    {
        $user = User::factory()->create();
        $this->withSession(['login-marker' => 'present']);
        $previous = session()->getId();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

        $this->assertNotSame($previous, session()->getId());
        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_invalidates_session_data_and_rotates_the_csrf_token(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['private-marker' => 'temporary', '_token' => 'old-csrf-token'])
            ->post(route('logout'))->assertRedirect(route('home'))
            ->assertSessionMissing('private-marker');

        $this->assertGuest();
        $this->assertNotSame('old-csrf-token', session()->token());
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
