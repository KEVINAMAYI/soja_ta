<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $this->call([
            RolesAndPermissionsSeeder::class, // Ensure roles are created first
        ]);

        // --- 1. Define All Organizations Data ---
        $organizationsData = [
            [
                'name' => 'Kingsway Enterprises Ltd',
                'email' => 'info@kingswayenterprises.net',
                'phone_number' => '+256704247124',
                'address' => 'Plot 14, Fourth Street, Industrial area, Kampala, Uganda',
                'role' => 'admin',
                'user_name' => 'Kingsway Admin',
            ],
            [
                'name' => 'Marksol Ltd',
                'email' => 'admin@marksolinc.com',
                'phone_number' => '+211926475689',
                'address' => 'NRA Yard, Rock City, Nimule, South Sudan',
                'role' => 'admin',
                'user_name' => 'Marksol Admin',
            ],
            [
                'name' => 'Cafe Mocca Ltd',
                'email' => 'info@cafemocca.net',
                'phone_number' => '+256750500600',
                'address' => 'NIC Building, Portal Avenue, Kampala, Uganda',
                'role' => 'admin',
                'user_name' => 'Cafe Mocca Admin',
            ],
        ];

        // --- 2. Seed Test Organization (SUPER-ADMIN) ---
        $testOrganization = Organization::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test Org',
                'phone_number' => '254795704301',
            ]
        );

        $testUser = User::firstOrCreate(
            ['email' => $testOrganization->email],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
            ]
        );

        // Assign super-admin role
        $testUser->assignRole('super-admin');

        // --- 3. Seed Related Records for Test Org ---
        $shift = Shift::firstOrCreate(
            ['organization_id' => $testOrganization->id, 'name' => 'Morning Shift'],
            [
                'start_time' => '08:00:00',
                'end_time' => '16:00:00',
                'break_minutes' => 30,
                'overtime_rate' => 1.5,
                'status' => 'active',
                'notes' => 'Standard 8-hour day shift with 30-minute break.',
            ]
        );

        $department = Department::firstOrCreate(
            ['organization_id' => $testOrganization->id, 'name' => 'ICT'],
            [
                'description' => 'ICT',
                'manager_id' => $testUser->id,
            ]
        );

        Employee::firstOrCreate(
            ['organization_id' => $testOrganization->id, 'user_id' => $testUser->id],
            [
                'department_id' => $department->id,
                'shift_id' => $shift->id,
                'name' => 'Test Employee',
                'id_number' => 'EMP999',
                'email' => $testUser->email,
                'phone' => '254712345678',
                'active' => true,
            ]
        );

        // --- 4. Loop Through New Organizations (ADMINS) ---
        foreach ($organizationsData as $data) {

            // a. Create/Get Organization
            $org = Organization::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'phone_number' => $data['phone_number'],
                    'address' => $data['address'],
                ]
            );

            // b. Create/Get User for this Organization
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['user_name'],
                    'password' => bcrypt('password'), // Use a generic password
                ]
            );

            // c. Assign Admin role
            // We use syncRoles to ensure only the desired role is applied
            $user->syncRoles(['admin']);

            // d. (Optional) Create a default Shift/Department/Employee for the new orgs
            // This is crucial if your app requires these related records to function

            $defaultShift = Shift::firstOrCreate(
                ['organization_id' => $org->id, 'name' => 'Default Shift'],
                [
                    'start_time' => '09:00:00', 'end_time' => '17:00:00', 'break_minutes' => 30, 'overtime_rate' => 1.0, 'status' => 'active',
                ]
            );

            $defaultDept = Department::firstOrCreate(
                ['organization_id' => $org->id, 'name' => 'Management'],
                [
                    'description' => 'Top Level Management', 'manager_id' => $user->id,
                ]
            );

            Employee::firstOrCreate(
                ['organization_id' => $org->id, 'user_id' => $user->id],
                [
                    'department_id' => $defaultDept->id,
                    'shift_id' => $defaultShift->id,
                    'name' => $data['user_name'],
                    'id_number' => 'ADM-' . $org->id,
                    'email' => $user->email,
                    'phone' => $data['phone_number'],
                    'active' => true,
                ]
            );
        }

        // create token to be used for APis (optional, for convenience)
        $testUser->createToken('Api Token')->plainTextToken;
    }
}
