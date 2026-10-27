<?php

namespace Database\Factories;

use App\Models\RentalAgreement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For automated tests only. The real agreement text is supplied by ARC.
 *
 * @extends Factory<RentalAgreement>
 */
class RentalAgreementFactory extends Factory
{
    /**
     * Define the model's default state: an unpublished draft.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version' => 'test-'.fake()->unique()->numberBetween(1, 99999),
            'title' => 'Test Agreement',
            'sections' => [['title' => 'Test section', 'body' => 'Automated test text.']],
        ];
    }

    public function published(mixed $at = null): static
    {
        return $this->afterCreating(function (RentalAgreement $agreement) use ($at) {
            $agreement->forceFill(['published_at' => $at ?? now()])->save();
        });
    }
}
