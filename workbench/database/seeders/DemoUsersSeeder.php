<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The demo app's two users, one of each type in video-demo.recorder.user_types
 * (WorkbenchServiceProvider), so videos can be recorded signed in as them.
 * The demo has no sign-in page: the recorder signs them in itself.
 */
class DemoUsersSeeder extends Seeder
{
    /**
     * Add the users, or bring them up to date.
     */
    public function run(): void
    {
        foreach (['recruiter@acme.test' => 'Dana Okafor', 'manager@acme.test' => 'Sam Patel'] as $email => $name) {
            DB::table('users')->updateOrInsert(['email' => $email], [
                'name' => $name,
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(32)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
