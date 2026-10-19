<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use SensitiveParameter;

class ClinicUserProvider extends EloquentUserProvider
{
    /**
     * @param  array<string, mixed>  $credentials
     * @return (Authenticatable&Model)|null
     */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials)
    {
        if (isset($credentials['email']) && is_string($credentials['email'])) {
            $email = Str::lower(trim($credentials['email']));
            // Retain other constraints (including active) and support legacy email casing.
            $credentials['email'] = fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]);
        }

        return parent::retrieveByCredentials($credentials);
    }
}
