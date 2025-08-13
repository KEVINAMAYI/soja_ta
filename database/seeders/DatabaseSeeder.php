<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeType;
use App\Models\Organization;
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
        $organization = Organization::factory()->create([
            'name' => 'Test Org',
            'email' => 'test@example.com',
            'phone_number' => '254795704301',
        ]);

        $department = Department::factory()->create([
            'name' => 'ICT',
            'organization_id' => $organization->id
        ]);

        $employeeType = EmployeeType::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Full Time',
            'description' => 'Full time employee',
        ]);

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        Employee::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'employee_type_id' => $employeeType->id,
            'user_id' => $user->id,
            'name' => 'Test Employee',
            'id_number' => 'EMP999',
            'email' => 'test@example.com',
            'phone' => '0712345678',
            'active' => true,
        ]);

        Employee::factory()->count(9)->create();

        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);

    }
}
