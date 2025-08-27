<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeType;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);

        $organization = Organization::factory()->create([
            'name' => 'Test Org',
            'email' => 'test@example.com',
            'phone_number' => '254795704301',
        ]);

        $shift = Shift::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'break_minutes' => 30,
            'overtime_rate' => 1.5,
            'status' => 'active',
            'notes' => 'Standard 8-hour day shift with 30-minute break.',
        ]);

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $department = Department::factory()->create([
            'name' => 'ICT',
            'description' => 'ICT',
            'manager_id' => $user->id,
            'organization_id' => $organization->id
        ]);

        // Assign the supervisor role
        $user->assignRole('supervisor');

        Employee::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'name' => 'Test Employee',
            'id_number' => 'EMP999',
            'email' => 'test@example.com',
            'phone' => '0712345678',
            'active' => true,
        ]);

        Employee::factory()->count(9)->create();

        //create token to be used for APis
        $user->createToken('Api Token')->plainTextToken;

        // Update ALL roles
        Role::query()->update([
            'organization_id' => $organization->id,
        ]);

    }
}
