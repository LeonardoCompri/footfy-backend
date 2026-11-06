<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class GameEventTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => 'tipo_'.fake()->unique()->numerify('######'),
            'name' => fake()->words(2, true),
            'icon' => 'mdi-star',
            'color' => 'grey',
            'counts_for' => null,
            'allows_assist' => false,
            'card' => null,
            'sort_order' => fake()->numberBetween(10, 100),
            'active' => true,
        ];
    }

    public function scoring(): static
    {
        return $this->state(fn () => ['counts_for' => 'team', 'allows_assist' => true]);
    }

    public function card(string $card = 'red'): static
    {
        return $this->state(fn () => ['card' => $card]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
