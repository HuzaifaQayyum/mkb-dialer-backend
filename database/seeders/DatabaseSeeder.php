<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Companies
        $vexa = Company::create([
            'name' => 'Vexa HQ',
            'slug' => 'vexa-hq',
        ]);

        $apex = Company::create([
            'name' => 'Apex Dialer',
            'slug' => 'apex-dialer',
        ]);

        // 2. Create Users
        $admin = User::create([
            'name' => 'You (Admin)',
            'email' => 'you@vexa.app',
            'role' => 'Admin',
            'status' => 'inactive',
            'password' => bcrypt('password_123'),
        ]);

        $maya = User::create([
            'name' => 'Maya Chen',
            'email' => 'maya@vexa.app',
            'role' => 'Agent',
            'status' => 'inactive',
            'password' => bcrypt('password_123'),
        ]);

        $diego = User::create([
            'name' => 'Diego Alvarez',
            'email' => 'diego@vexa.app',
            'role' => 'Agent',
            'status' => 'inactive',
            'password' => bcrypt('password_123'),
        ]);

        $priya = User::create([
            'name' => 'Priya Shah',
            'email' => 'priya@vexa.app',
            'role' => 'Manager',
            'status' => 'inactive',
            'password' => bcrypt('password_123'),
        ]);

        // 3. Map Users to Companies via Pivot Table
        // Admin is in both Vexa HQ and Apex Dialer
        $vexa->users()->attach($admin, [
            'role' => 'Admin',
            'status' => 'online',
            'queue_eligible' => false,
            'last_seen' => now(),
        ]);
        $apex->users()->attach($admin, [
            'role' => 'Admin',
            'status' => 'online',
            'queue_eligible' => false,
            'last_seen' => now(),
        ]);

        // Maya is in Vexa HQ only (Agent)
        $vexa->users()->attach($maya, [
            'role' => 'Agent',
            'status' => 'online',
            'queue_eligible' => true,
            'last_seen' => now(),
        ]);

        // Diego is in Vexa HQ only (Agent)
        $vexa->users()->attach($diego, [
            'role' => 'Agent',
            'status' => 'inactive',
            'queue_eligible' => true,
            'last_seen' => now(),
        ]);

        // Priya is Manager in Vexa HQ, but Agent in Apex Dialer!
        $vexa->users()->attach($priya, [
            'role' => 'Manager',
            'status' => 'online',
            'queue_eligible' => false,
            'last_seen' => now(),
        ]);
        $apex->users()->attach($priya, [
            'role' => 'Agent',
            'status' => 'online',
            'queue_eligible' => true,
            'last_seen' => now(),
        ]);

        // 4. Seed Isolated Contacts
        // Vexa HQ Contacts
        Contact::create([
            'name' => 'Alice Johnson',
            'phone' => '+15550100',
            'email' => 'alice@gmail.com',
            'tags' => ['Hot Lead', 'Real Estate'],
            'company_id' => $vexa->id,
        ]);

        Contact::create([
            'name' => 'Bob Miller',
            'phone' => '+15550101',
            'email' => 'bob@gmail.com',
            'tags' => ['Follow Up'],
            'company_id' => $vexa->id,
        ]);

        // Apex Dialer Contacts
        Contact::create([
            'name' => 'Charlie Rose',
            'phone' => '+15550200',
            'email' => 'charlie@gmail.com',
            'tags' => ['Apex Lead'],
            'company_id' => $apex->id,
        ]);

        Contact::create([
            'name' => 'Diana Prince',
            'phone' => '+15550201',
            'email' => 'diana@gmail.com',
            'tags' => ['VIP'],
            'company_id' => $apex->id,
        ]);
    }
}
