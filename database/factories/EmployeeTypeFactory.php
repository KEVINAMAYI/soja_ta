<?php

namespace Database\Factories;

use App\Models\EmployeeType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeTypeFactory extends Factory
{
    protected $model = EmployeeType::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->jobTitle(),
            'description' => $this->faker->optional()->sentence(),
        ];
    }
}

