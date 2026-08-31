<?php

namespace Database\Seeders;

use App\Enums\Users\UserRole;
use App\Models\Users\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Provisions the first administrator account.
 *
 * Public registration is disabled, so this seeder is the only bootstrap path into
 * the application. Credentials come from the environment — never from a literal in
 * source control. In production a password MUST be supplied explicitly; outside
 * production a random one is generated and printed once.
 */
class UsersTableSeeder extends Seeder
{
    /**
     * Run the seeder.
     */
    public function run(): void
    {
        // Never touch an installation that already has accounts.
        if (User::query()->exists()) {
            return;
        }

        $email = (string) config('pulllens.admin.email');
        $name = (string) config('pulllens.admin.name');
        $password = config('pulllens.admin.password');

        if (blank($password)) {
            if (app()->isProduction()) {
                throw new RuntimeException(
                    'ADMIN_PASSWORD must be set before seeding the initial administrator in production.',
                );
            }

            $password = Str::password(20);

            $this->command?->warn("Generated administrator password for {$email}: {$password}");
            $this->command?->warn('Store it now — it is not written anywhere else.');
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // The bootstrap account is verified out of the box; there is no inbox to click through.
        $user->forceFill(['email_verified_at' => now()])->save();

        $user->assignRoleEnum(UserRole::Admin);
    }
}
