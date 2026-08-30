<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SourceDraftStatus;
use App\Enums\SourceType;
use App\Models\ContentSource;
use App\Models\Notebook;
use App\Models\SourceDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceDraft>
 */
class SourceDraftFactory extends Factory
{
    protected $model = SourceDraft::class;

    public function definition(): array
    {
        $type = fake()->optional()->randomElement(SourceType::cases());

        return [
            'user_id' => User::factory(),
            'knowledge_base_id' => Notebook::factory(),
            'content_source_id' => null,
            'type' => $type,
            'raw_input' => fake()->url(),
            'channel_meta' => fake()->optional()->randomElement([
                ['title' => fake()->company(), 'description' => fake()->sentence(), 'members' => fake()->numberBetween(100, 100000), 'avatar_url' => fake()->imageUrl()],
                ['title' => fake()->company(), 'description' => fake()->sentence()],
            ]),
            'scrape_config' => fake()->optional()->randomElement([
                ['limit' => fake()->numberBetween(1, 100)],
                ['from_date' => now()->subDays(30)->toDateString(), 'to_date' => now()->toDateString()],
            ]),
            'auto_update' => fake()->boolean(20),
            'status' => fake()->randomElement(SourceDraftStatus::cases()),
        ];
    }

    public function fetchingMeta(): static
    {
        return $this->state(['status' => SourceDraftStatus::FetchingMeta]);
    }

    public function awaitingConfirm(): static
    {
        return $this->state(['status' => SourceDraftStatus::AwaitingConfirm]);
    }

    public function processing(): static
    {
        return $this->state(['status' => SourceDraftStatus::Processing]);
    }

    public function awaitingIndex(): static
    {
        return $this->state(['status' => SourceDraftStatus::AwaitingIndex]);
    }

    public function abandoned(): static
    {
        return $this->state(['status' => SourceDraftStatus::Abandoned]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->id]);
    }

    public function forNotebook(Notebook $notebook): static
    {
        return $this->state(fn (): array => ['knowledge_base_id' => $notebook->id]);
    }

    public function withContentSource(ContentSource $contentSource): static
    {
        return $this->state(fn (): array => ['content_source_id' => $contentSource->id]);
    }

    public function withType(SourceType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function withUrl(string $url): static
    {
        return $this->state(fn (): array => ['raw_input' => $url]);
    }
}
