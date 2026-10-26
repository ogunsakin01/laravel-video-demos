<?php

namespace Ogunsakin01\VideoDemo\Tests\Seeders;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the test app's sample database a users table when its migrations
 * have none, and one user, so the tests see a seeder run.
 */
class SampleUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

        DB::table('users')->insert(['name' => 'Seeded', 'email' => 'seeded@example.com', 'password' => bcrypt('password')]);
    }
}
