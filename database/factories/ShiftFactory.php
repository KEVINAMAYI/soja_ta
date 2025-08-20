<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        $startTime = $this->faker->dateTimeBetween('08:00', '12:00');
        $endTime = (clone $startTime)->modify('+8 hours'); // 8-hour shift

        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->jobTitle(),
            'start_time' => Carbon::instance($startTime)->format('H:i:s'),
            'end_time' => Carbon::instance($endTime)->format('H:i:s'),
            'break_minutes' => $this->faker->numberBetween(15, 60),
            'overtime_rate' => $this->faker->randomFloat(2, 1.0, 3.0),
            'status' => $this->faker->randomElement(['active', 'inactive']),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
