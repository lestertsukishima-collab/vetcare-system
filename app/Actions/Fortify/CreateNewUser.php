<?php

namespace App\Actions\Fortify;

use App\Concerns\OwnerContactValidationRules;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use OwnerContactValidationRules, PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $data = Validator::make($input, [
            ...$this->profileRules(),
            ...$this->ownerContactRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($data): User {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->owner()->firstOrFail()->update(['phone' => $this->contactValue($data['phone'] ?? null), 'address' => $this->contactValue($data['address'] ?? null)]);

            return $user;
        });
    }
}
