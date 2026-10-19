<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\UniqueEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateClinicAdmin extends Command
{
    protected $signature = 'clinic:admin';

    protected $description = 'Create the initial veterinary clinic administrator';

    public function handle(): int
    {
        $data = ['name' => $this->ask('Full name'), 'email' => $this->ask('Email address'), 'password' => $this->secret('Password (at least 12 characters)')];
        $validator = Validator::make($data, ['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', new UniqueEmail], 'password' => 'required|string|min:12|max:255']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User($data);
        $user->role = 'admin';
        $user->email_verified_at = now();
        $user->save();
        $this->info('Administrator created. You can now sign in.');

        return self::SUCCESS;
    }
}
