<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Source;
use App\Models\UserSpace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->slug().'.txt';

        return [
            'user_space_id' => UserSpace::factory(),
            'uuid' => (string) Str::uuid(),
            'filename' => $filename,
            'size' => fake()->numberBetween(100, 5000),
            'sha256' => hash('sha256', fake()->uuid()),
            'original_name' => $filename,
            'mime_type' => 'text/plain',
            'path' => 'userspaces/'.Str::uuid().'/'.$filename,
        ];
    }
}
