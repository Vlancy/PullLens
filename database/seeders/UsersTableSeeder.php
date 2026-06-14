<?php

namespace Database\Seeders;

use App\Models\Users\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    // Initial admin user
    private string $adminEmail = 'admin@example.com';

    private string $adminPassword = 'password';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (User::query()->exists()) {
            return;
        }

        User::query()->updateOrCreate(
            ['email' => $this->adminEmail],
            [
                'name' => 'Admin User',
                'password' => Hash::make($this->adminPassword),
                'email_verified_at' => now(),
            ],
        );
    }
}
