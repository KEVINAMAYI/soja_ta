<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\User;
use App\Models\Organization;
use App\Models\Employee;
use App\Models\EmployeeType;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'department_id' => Department::factory(),
            'employee_type_id' => EmployeeType::factory(),
            'user_id' => User::factory(),
            'name' => $this->faker->name(),
            'id_number' => $this->faker->unique()->numerify('EMP###'),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'active' => $this->faker->randomElement([0, 1]),
        ];
    }
}

