<?php

namespace App\Support;

class ClinicNavigation
{
    /** @return array<string, array<string, string>> */
    public function groups(string $role): array
    {
        $groups = [
            'Workspace' => ['dashboard' => 'Overview'],
            $role === 'owner' ? 'My care' : 'Patient care' => [
                'pets' => $role === 'owner' ? 'My pets' : 'Patients',
                'appointments' => 'Appointments', 'records' => 'Medical records',
                'prescriptions' => 'Prescriptions', 'vaccinations' => 'Vaccinations',
            ],
        ];
        if ($role === 'admin') {
            $groups['Clinic management'] = [
                'owners' => 'Pet owners', 'veterinarians' => 'Veterinarians',
                'services' => 'Services', 'inventory' => 'Medicine inventory', 'transactions' => 'Stock history',
            ];
        }
        if (in_array($role, ['owner', 'admin'])) {
            $groups['Billing'] = ['invoices' => 'Invoices'];
        }
        if ($role === 'admin') {
            $groups['Billing']['payments'] = 'Payments';
            $groups['Administration'] = ['reports' => 'Reports', 'users' => 'Users', 'settings' => 'Clinic settings'];
        }

        return $groups;
    }

    /** @return array<string, string> */
    public function links(string $role): array
    {
        return array_merge(...array_values($this->groups($role)));
    }

    public function description(string $section, string $role): string
    {
        return match ($section) {
            'dashboard' => match ($role) {
                'owner' => 'Keep track of your pets, upcoming visits, and care reminders.',
                'veterinarian' => 'Review your appointments, patient history, and follow-up care.',
                default => 'Review today\'s appointments, clinic activity, and tasks that need attention.',
            },
            'pets' => $role === 'owner' ? 'Keep your pets\' details and photos up to date.' : 'Find a patient, review their details, and open their medical history.',
            'appointments' => $role === 'owner' ? 'Request a visit, choose a veterinarian, and track your appointments.' : 'Review appointment requests, visit times, and consultation status.',
            'records' => $role === 'veterinarian' ? 'Review patient history and record an examination for a confirmed visit.' : 'Search patient history, diagnoses, treatments, and past visits.',
            'prescriptions' => 'Review prescribed medicines, dosage instructions, and supplied quantities.',
            'vaccinations' => 'Check vaccination history, upcoming doses, and overdue follow-ups.',
            'invoices' => 'Review visit charges, payment history, and outstanding balances.',
            'owners' => 'Manage pet owner accounts and contact details.',
            'veterinarians' => 'Manage veterinarian accounts, specializations, and account status.',
            'services' => 'Manage the services and prices available for appointments.',
            'inventory' => 'Check medicine stock, expiry dates, and reorder levels.',
            'transactions' => 'Review stock movements, quantities, and recorded adjustments.',
            'payments' => 'Record received payments and review the payment ledger.',
            'users' => 'Manage staff and owner accounts, roles, and account status.',
            'reports' => 'Review all-time appointments, payments, inventory, and preventive care.',
            'settings' => 'Update the clinic name, phone number, and address.',
            default => '',
        };
    }
}
