<?php

namespace App\Extension;

use App\Models\User;

/**
 * Who a user is. The community edition uses email and password; the
 * enterprise package can add SSO without the rest of the hub changing.
 */
interface IdentityProvider
{
    /** The user for these credentials, or null. */
    public function attempt(string $email, string $password): ?User;

    public function register(string $name, string $email, string $password): User;
}
