<?php

namespace App\Concerns;

trait OwnerContactValidationRules
{
    /** @return array<string, list<string>> */
    protected function ownerContactRules(): array
    {
        return ['phone' => ['nullable', 'string', 'max:50'], 'address' => ['nullable', 'string', 'max:1000']];
    }

    protected function contactValue(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
