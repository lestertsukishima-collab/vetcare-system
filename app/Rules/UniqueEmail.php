<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class UniqueEmail implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && User::whereRaw('LOWER(email) = ?', [Str::lower(trim($value))])
            ->when($this->ignoreId !== null, fn ($query) => $query->where('id', '!=', $this->ignoreId))->exists()) {
            $fail('validation.unique')->translate();
        }
    }
}
