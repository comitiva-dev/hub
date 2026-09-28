<?php

namespace App\Extension\Community;

use App\Extension\IdentityProvider;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class PasswordIdentity implements IdentityProvider
{
    public function attempt(string $email, string $password): ?User
    {
        $user = User::whereRaw('lower(email) = lower(?)', [$email])->first();

        return $user !== null && Hash::check($password, $user->password) ? $user : null;
    }

    public function register(string $name, string $email, string $password): User
    {
        return User::create(['name' => $name, 'email' => $email, 'password' => $password]);
    }
}
