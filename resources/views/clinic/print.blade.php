<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $section === 'invoices' ? 'Invoices' : 'Clinic report' }} — {{ $clinic->name ?? 'VetCare' }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('clinic-print.css') }}?v={{ filemtime(public_path('clinic-print.css')) }}">
    <script src="{{ asset('clinic-print.js') }}?v={{ filemtime(public_path('clinic-print.js')) }}" defer></script>
</head>
<body data-auto-print>
    <nav class="print-toolbar" aria-label="Print controls">
        <a href="{{ $returnUrl }}">← Back to {{ $section === 'invoices' ? 'invoices' : 'reports' }}</a>
        <button type="button" data-print>Print / Save PDF</button>
        <p data-print-feedback role="status" aria-live="polite">The print dialog will open when this page is ready.</p>
        <noscript>Use your browser's Print command or press Ctrl + P to print this page.</noscript>
    </nav>
    <main>
        <header class="print-heading">
            <div>
                <h1>{{ $clinic->name ?? 'VetCare Animal Clinic' }}</h1>
                @if($clinic->address ?? null)<p>{{ $clinic->address }}</p>@endif
                @if($clinic->phone ?? null)<p>Phone: {{ $clinic->phone }}</p>@endif
            </div>
            <div class="print-stamp"><strong>{{ $section === 'invoices' ? 'Invoices & balances' : 'Clinic report' }}</strong><p>Generated {{ now()->format('M d, Y h:i A') }}<br>{{ config('app.timezone') }}</p><p>Prepared for {{ auth()->user()->name }}</p></div>
        </header>

        @if($section === 'invoices')
            <section class="print-summary" aria-label="Invoice report totals">
                <h2>{{ $invoices->count() }} {{ $invoices->count() === 1 ? 'invoice' : 'invoices' }}</h2>
                <p>Includes all {{ $search !== '' ? 'matching' : 'accessible' }} invoices across every page.</p>
                @if($search !== '')<p>Search: <strong>{{ $search }}</strong></p>@endif
                <dl class="print-totals"><div><dt>Total invoiced</dt><dd>₱{{ number_format($totals['issued'] / 100, 2) }}</dd></div><div><dt>Payments received</dt><dd>₱{{ number_format($totals['received'] / 100, 2) }}</dd></div><div><dt>Outstanding balance</dt><dd>₱{{ number_format($totals['balance'] / 100, 2) }}</dd></div></dl>
            </section>
            @forelse($invoices as $invoice)
                @php($balance = $invoice->balanceCents())
                <article class="printed-invoice" data-printed-invoice="{{ $invoice->id }}">
                    <header class="invoice-heading"><h2>INV-{{ str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT) }} · {{ $invoice->appointment->pet->name }}</h2><strong>{{ $balance === 0 ? 'Paid' : ($invoice->due_date->lt(today()) ? 'Overdue' : 'Unpaid / partial') }}</strong></header>
                    <dl class="invoice-details"><div><dt>Pet owner</dt><dd>{{ $invoice->appointment->pet->owner->user->name }}</dd></div><div><dt>Veterinarian</dt><dd>{{ $invoice->appointment->veterinarian->user->name }}</dd></div><div><dt>Visit date</dt><dd>{{ $invoice->appointment->appointment_date->format('M d, Y') }}</dd></div><div><dt>Due date</dt><dd>{{ $invoice->due_date->format('M d, Y') }}</dd></div></dl>
                    <table><thead><tr><th>Description</th><th class="money">Amount (PHP)</th></tr></thead><tbody>
                        <tr><td>{{ $invoice->appointment->service_name ?? $invoice->appointment->service->name }}</td><td class="money">{{ number_format($invoice->service_amount, 2) }}</td></tr>
                        <tr><td>Medicines supplied by clinic</td><td class="money">{{ number_format($invoice->medicine_amount, 2) }}</td></tr>
                        <tr class="total-row"><th>Total</th><td class="money">{{ number_format($invoice->total, 2) }}</td></tr>
                        <tr><th>Balance due</th><td class="money"><strong>{{ number_format($balance / 100, 2) }}</strong></td></tr>
                    </tbody></table>
                    @if($invoice->payments->isNotEmpty())
                        <h3>Payment history</h3>
                        <table class="payment-history"><thead><tr><th>Date received</th><th>Method</th><th>Reference</th><th class="money">Amount (PHP)</th></tr></thead><tbody>
                            @foreach($invoice->payments->sortBy('paid_at') as $payment)<tr><td>{{ $payment->paid_at->format('M d, Y h:i A') }}</td><td>{{ ucfirst(str_replace('_', ' ', $payment->method)) }}</td><td>{{ $payment->reference ?: '—' }}</td><td class="money">{{ number_format($payment->amount, 2) }}</td></tr>@endforeach
                        </tbody></table>
                    @else<p class="invoice-note">No payments recorded.</p>@endif
                </article>
            @empty<p class="empty">{{ $search !== '' ? 'No invoices match this search.' : 'No invoices have been issued yet.' }}</p>@endforelse
        @else
            <section class="print-summary" aria-label="Clinic report totals">
                <h2>All-time clinic activity</h2>
                <dl class="print-totals"><div><dt>Completed visits</dt><dd>{{ $appointments->where('status', 'completed')->count() }}</dd></div><div><dt>Invoices issued</dt><dd>{{ $invoices->count() }}</dd></div><div><dt>Payments received</dt><dd>₱{{ number_format($totals['received'] / 100, 2) }}</dd></div><div><dt>Outstanding balance</dt><dd>₱{{ number_format($totals['balance'] / 100, 2) }}</dd></div></dl>
            </section>
            <section class="report-section"><h2>Visit status report</h2><table><thead><tr><th>Status</th><th class="money">Visits</th></tr></thead><tbody>@foreach(['pending', 'confirmed', 'completed', 'cancelled'] as $status)<tr><td>{{ ucfirst($status) }}</td><td class="money">{{ $appointments->where('status', $status)->count() }}</td></tr>@endforeach</tbody></table></section>
            <section class="report-section"><h2>Inventory &amp; preventive care</h2><table><thead><tr><th>Measure</th><th class="money">Count</th></tr></thead><tbody>
                <tr><td>Low stock medicines</td><td class="money">{{ $medicines->filter(fn ($medicine) => $medicine->quantity <= $medicine->reorder_level)->count() }}</td></tr>
                <tr><td>Expired medicines</td><td class="money">{{ $medicines->filter(fn ($medicine) => $medicine->expires_at->lt(today()))->count() }}</td></tr>
                <tr><td>Vaccinations due within 30 days or overdue</td><td class="money">{{ $latestVaccinations->filter(fn ($row) => $row['vaccination']->next_due_date && $row['vaccination']->next_due_date->lte(today()->addDays(30)))->count() }}</td></tr>
                <tr><td>Registered pet owners</td><td class="money">{{ $owners->count() }}</td></tr>
            </tbody></table></section>
        @endif
        <footer class="print-footer">{{ $clinic->name ?? 'VetCare' }} · All amounts in Philippine pesos (PHP).</footer>
    </main>
</body>
</html>
