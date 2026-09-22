<?php

namespace Database\Factories;

use App\Models\Devotee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Devotee> */
class DevoteeFactory extends Factory
{
    protected $model = Devotee::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'devotee-password',
            'locale' => 'en',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
