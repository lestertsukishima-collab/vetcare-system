<?php

namespace Tests\Feature;

use App\Livewire\Clinic\Workspace;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClinicPrintTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceFor(User $user, string $name = 'Print patient'): Invoice
    {
        $owner = Owner::firstOrCreate(['user_id' => $user->id]);
        $pet = Pet::factory()->create(['owner_id' => $owner->id, 'name' => $name]);
        $appointment = Appointment::factory()->completed()->create([
            'pet_id' => $pet->id, 'service_fee' => 500, 'service_name' => 'Original consultation',
        ]);

        return Invoice::factory()->create(['appointment_id' => $appointment->id]);
    }

    public function test_owner_prints_every_accessible_invoice_across_pages_and_all_its_payments(): void
    {
        $owner = User::factory()->owner()->create();
        $invoices = collect(range(1, 13))->map(fn ($number) => $this->invoiceFor($owner, 'Patient '.$number));
        $private = $this->invoiceFor(User::factory()->owner()->create(), 'Private other patient');
        Payment::factory()->create(['invoice_id' => $invoices->first()->id, 'amount' => 100, 'reference' => 'PRINT-RECEIPT']);

        $response = $this->actingAs($owner)->get(route('clinic.print', ['section' => 'invoices', 'page' => '2']))
            ->assertOk()->assertSee('13 invoices')->assertSee('PRINT-RECEIPT')
            ->assertSee('Original consultation')->assertDontSee('Private other patient')
            ->assertSee(route('clinic.index', ['section' => 'invoices', 'page' => '2']), false)
            ->assertViewHas('totals', ['issued' => 650000, 'received' => 10000, 'balance' => 640000]);
        foreach ($invoices as $invoice) {
            $response->assertSee('data-printed-invoice="'.$invoice->id.'"', false);
        }
        $response->assertDontSee('data-printed-invoice="'.$private->id.'"', false);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertSee('clinic-print.css')->assertSee('clinic-print.js')
            ->assertDontSee('wire:click=')->assertDontSee('wire:model')
            ->assertDontSee('class="pagination', false)->assertDontSee('clinic-bootstrap');
    }

    public function test_invoice_search_prints_all_matches_and_uses_displayed_numbers_and_payment_references(): void
    {
        $owner = User::factory()->owner()->create();
        $matches = collect(range(1, 7))->map(fn ($number) => $this->invoiceFor($owner, 'Bella '.$number));
        $other = $this->invoiceFor($owner, 'Nana');
        Payment::factory()->create(['invoice_id' => $other->id, 'reference' => 'BANK-PRINT-123', 'amount' => 125]);
        $this->actingAs($owner)->get(route('clinic.print', ['section' => 'invoices', 'search' => ' BeLLa ']))
            ->assertOk()->assertSee('7 invoices')->assertDontSee('data-printed-invoice="'.$other->id.'"', false)
            ->assertViewHas('invoices', fn ($invoices) => $invoices->modelKeys() === $matches->sortByDesc('id')->pluck('id')->all());
        foreach (['INV-'.str_pad((string) $other->id, 5, '0', STR_PAD_LEFT), 'BANK-PRINT-123'] as $search) {
            $this->get(route('clinic.print', ['section' => 'invoices', 'search' => $search]))
                ->assertOk()->assertViewHas('invoices', fn ($invoices) => $invoices->modelKeys() === [$other->id]);
        }
    }

    public function test_admin_can_print_all_invoices_and_an_accurate_clinic_report(): void
    {
        $admin = User::factory()->admin()->create();
        $first = $this->invoiceFor(User::factory()->owner()->create());
        $this->invoiceFor(User::factory()->owner()->create());
        Payment::factory()->create(['invoice_id' => $first->id, 'amount' => 200, 'recorded_by' => $admin->id]);
        Appointment::factory()->confirmed()->create();
        $this->actingAs($admin)->get(route('clinic.print', ['section' => 'invoices']))->assertOk()->assertSee('2 invoices');
        $this->get(route('clinic.print', ['section' => 'reports']))->assertOk()
            ->assertSee('All-time clinic activity')->assertSee('Visit status report')
            ->assertSee('Inventory &amp; preventive care', false)
            ->assertViewHas('totals', ['issued' => 100000, 'received' => 20000, 'balance' => 80000])
            ->assertViewHas('appointments', fn ($rows) => $rows->where('status', 'completed')->count() === 2 && $rows->where('status', 'confirmed')->count() === 1);
    }

    public function test_print_routes_enforce_authentication_verification_roles_and_supported_sections(): void
    {
        $this->get(route('clinic.print', ['section' => 'invoices']))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('clinic.print', ['section' => 'invoices']))->assertRedirect(route('verification.notice'));
        $this->flushSession();
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('clinic.print', ['section' => 'reports']))->assertForbidden();
        $this->flushSession();
        $this->actingAs(User::factory()->veterinarian()->create())
            ->get(route('clinic.print', ['section' => 'invoices']))->assertForbidden();
        $this->get(route('clinic.print', ['section' => 'reports']))->assertForbidden();
        $this->flushSession();
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('clinic.print', ['section' => 'users']))->assertNotFound();
        $this->get(route('clinic.print', ['section' => 'unknown']))->assertNotFound();
    }

    public function test_malformed_print_queries_are_rejected_and_search_is_escaped(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        foreach ([['search' => ['bad']], ['search' => str_repeat('a', 151)], ['page' => ['2']], ['page' => '-1'], ['page' => '0'], ['page' => '1000000']] as $query) {
            $this->get(route('clinic.print', ['section' => 'invoices', ...$query]))->assertBadRequest();
        }
        $this->get(route('clinic.print', ['section' => 'invoices', 'search' => '<script>alert(1)</script>']))
            ->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('No invoices match this search.');
    }

    public function test_print_button_redirect_preserves_search_and_return_page(): void
    {
        $owner = User::factory()->owner()->create();
        foreach (range(1, 7) as $number) {
            $this->invoiceFor($owner, 'Bella '.$number);
        }
        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'invoices'])
            ->set('search', 'Bella')->call('setPage', 2)->call('printReport')
            ->assertRedirect(route('clinic.print', ['section' => 'invoices', 'search' => 'Bella', 'page' => '2']));
    }

    public function test_report_print_button_redirects_and_other_sections_cannot_print(): void
    {
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(Workspace::class, ['section' => 'reports'])
            ->call('printReport')->assertRedirect(route('clinic.print', ['section' => 'reports']));
        Livewire::actingAs($admin)->test(Workspace::class, ['section' => 'pets'])
            ->call('printReport')->assertNotFound();
    }

    public function test_print_button_rechecks_permissions_after_role_change(): void
    {
        $admin = User::factory()->admin()->create();
        $component = Livewire::actingAs($admin)->test(Workspace::class, ['section' => 'reports']);
        $admin->forceFill(['role' => 'owner'])->save();
        $component->call('printReport')->assertForbidden();
    }
}
