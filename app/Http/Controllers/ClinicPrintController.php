<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\User;
use App\Services\ClinicAccess;
use App\Services\WorkspaceData;
use App\Services\WorkspaceSearch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ClinicPrintController extends Controller
{
    public function __invoke(Request $request, string $section): Response
    {
        abort_unless(in_array($section, ['invoices', 'reports']), 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        app(ClinicAccess::class)->authorize($user, $section);

        $search = $request->query('search', '');
        $page = $request->query('page', '1');
        abort_unless(is_string($search) && mb_strlen($search) <= 150, 400, 'Invalid print search.');
        abort_unless(is_string($page) && preg_match('/^[1-9][0-9]{0,5}$/', $page), 400, 'Invalid return page.');
        $search = $section === 'invoices' ? trim($search) : '';

        $data = app(WorkspaceData::class)->for($user, $section);
        /** @var Collection<int, Invoice> $invoices */
        $invoices = $data['invoices'];
        $invoices->loadMissing(['appointment.pet.owner.user', 'appointment.veterinarian.user', 'appointment.service']);
        if ($search !== '') {
            $invoices = $invoices->filter(fn (Invoice $invoice) => app(WorkspaceSearch::class)->matches('invoices', $invoice, mb_strtolower($search)))->values();
        }
        $data['invoices'] = $invoices;
        $data['totals'] = [
            'issued' => $invoices->sum(fn (Invoice $invoice) => Invoice::cents($invoice->total)),
            'received' => $invoices->sum(fn (Invoice $invoice) => $invoice->payments->sum(fn ($payment) => Invoice::cents($payment->amount))),
            'balance' => $invoices->sum(fn (Invoice $invoice) => $invoice->balanceCents()),
        ];
        $data['search'] = $search;
        $data['returnUrl'] = route('clinic.index', array_filter([
            'section' => $section, 'search' => $search, 'page' => $page === '1' ? null : $page,
        ], fn ($value) => $value !== '' && $value !== null));

        return response()->view('clinic.print', $data)->header('Cache-Control', 'private, no-store');
    }
}
