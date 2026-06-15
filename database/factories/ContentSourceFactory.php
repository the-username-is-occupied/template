<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DiscoveryMethod;
use App\Enums\ExtractionStatus;
use App\Enums\ReviewStatus;
use App\Enums\SourceType;
use App\Models\ContentSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentSource>
 */
class ContentSourceFactory extends Factory
{
    protected $model = ContentSource::class;

    public function definition(): array
    {
        $type = fake()->randomElement(SourceType::cases());

        return [
            'user_id' => User::factory(),
            'type' => $type,
            'url' => in_array($type, [SourceType::Pdf, SourceType::Audio, SourceType::Video, SourceType::Text], true) ? null : fake()->url(),
            'file_ref' => in_array($type, [SourceType::Pdf, SourceType::Audio, SourceType::Video, SourceType::Text], true) ? 'files/'.$this->faker->uuid().'.bin' : null,
            'title' => fake()->sentence(4),
            'auto_update' => fake()->boolean(20),
            'extraction_status' => fake()->randomElement(ExtractionStatus::cases()),
            'nlm_temp_source_id' => fake()->uuid(),
            'parent_source_id' => null,
            'parent_item_id' => null,
            'discovery_method' => DiscoveryMethod::Manual,
            'review_status' => ReviewStatus::Approved,
            'last_fetched_id' => fake()->optional()->numerify('########'),
            'metadata' => ['domain' => parse_url(fake()->url(), PHP_URL_HOST)],
            'error_message' => null,
            'error_code' => null,
        ];
    }

    public function telegram(): static
    {
        return $this->state(fn () => [
            'type' => SourceType::TelegramChannel,
            'url' => 'https://t.me/'.fake()->userName(),
            'metadata' => [
                'channel_id' => fake()->numerify('########'),
                'title' => fake()->company(),
                'members' => fake()->numberBetween(1000, 250000),
                'avatar_url' => fake()->imageUrl(),
            ],
        ]);
    }

    public function youtube(): static
    {
        return $this->state(fn () => [
            'type' => SourceType::YoutubeChannel,
            'url' => 'https://www.youtube.com/channel/'.fake()->regexify('[A-Za-z0-9_-]{24}'),
            'metadata' => [
                'channel_id' => fake()->regexify('[A-Za-z0-9_-]{24}'),
                'title' => fake()->company(),
                'description' => fake()->sentence(10),
                'handle' => '@'.fake()->userName(),
                'avatar_url' => fake()->imageUrl(),
                'subscribers_count' => fake()->numberBetween(1000, 5000000),
                'view_count' => fake()->numberBetween(10000, 100000000),
                'video_count' => fake()->numberBetween(10, 5000),
            ],
        ]);
    }

    public function autoExtracted(): static
    {
        return $this->state(fn () => [
            'discovery_method' => DiscoveryMethod::AutoExtracted,
            'review_status' => ReviewStatus::PendingReview,
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn () => [
            'review_status' => ReviewStatus::PendingReview]);
    }
}
