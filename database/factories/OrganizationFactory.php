<?php

namespace Database\Factories;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'type' => OrganizationType::Buyer,
            'name' => fake()->unique()->company(),
            'city' => fake()->randomElement(['Mohali', 'Ludhiana', 'Panchkula', 'Chandigarh', 'Baddi']),
            'state' => 'Punjab',
            'status' => 'active',
        ];
    }

    public function supplier(): static
    {
        return $this->state(fn () => ['type' => OrganizationType::Supplier]);
    }
}
