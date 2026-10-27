<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The only way to change a role: no API route can grant staff or admin access. Works on an existing
 * account, so no placeholder staff users are ever created.
 */
#[Signature('arc:set-role {email : Email of an existing account} {role : renter, staff or admin}')]
#[Description('Set the role of an existing ARC Ride account')]
class SetUserRole extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $role = UserRole::tryFrom(Str::lower((string) $this->argument('role')));

        if ($role === null) {
            $this->error('Unknown role. Use one of: '.implode(', ', array_column(UserRole::cases(), 'value')).'.');

            return self::FAILURE;
        }

        $user = User::where('email', Str::lower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->error('No account uses that email address.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => $role])->save();

        $this->info("{$user->email} is now {$role->value}.");

        return self::SUCCESS;
    }
}
