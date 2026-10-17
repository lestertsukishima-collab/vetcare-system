<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClinicAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_admin_command_creates_a_verified_admin_with_a_hashed_password(): void
    {
        $this->artisan('clinic:admin')
            ->expectsQuestion('Full name', 'Clinic Admin')
            ->expectsQuestion('Email address', 'admin@example.test')
            ->expectsQuestion('Password (at least 12 characters)', 'a-strong-initial-password')
            ->expectsOutput('Administrator created. You can now sign in.')
            ->assertSuccessful();
        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('a-strong-initial-password', $admin->password));
        $this->assertDatabaseCount('owners', 0);
        $this->assertDatabaseCount('veterinarians', 0);
    }
}
