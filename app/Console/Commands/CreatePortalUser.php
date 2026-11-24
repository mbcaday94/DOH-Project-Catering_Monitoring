<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreatePortalUser extends Command
{
    protected $signature = 'portal:create-user';

    protected $description = 'Create a catering portal account';

    public function handle(): int
    {
        $name = $this->ask('Name');
        $email = $this->ask('Email address');
        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (strlen($password) > 72) {
            $this->error('The password must be 72 bytes or fewer.');

            return self::FAILURE;
        }

        User::create($validator->safe()->only(['name', 'email', 'password']));

        $this->info('Portal account created.');

        return self::SUCCESS;
    }
}
