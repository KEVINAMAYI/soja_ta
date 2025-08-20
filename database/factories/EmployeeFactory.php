<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Shift;
use App\Models\User;
use App\Models\Organization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'department_id' => Department::factory(),
            'shift_id' => Shift::factory(),
            'user_id' => User::factory(),
            'name' => $this->faker->name(),
            'id_number' => $this->faker->unique()->numerify('EMP###'),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'active' => $this->faker->randomElement([0, 1]),
        ];
    }
}

