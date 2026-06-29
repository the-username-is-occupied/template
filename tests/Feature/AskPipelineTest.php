<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TechAccountPoolType;
use App\Enums\TechAccountStatus;
use App\Models\Notebook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('ask endpoint validates required question field', function () {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    $response = $this->postJson("/api/notebooks/{$notebook->id}/ask", []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['question']);
});

test('ask returns 503 when daily limit exceeded', function () {
    // This test requires implementing the DailyLimitExceededException handling
    // For now, we'll skip this as it needs proper setup with tech_account_usages
    $this->markTestSkipped('Needs DailyLimitExceededException setup');
});

test('ask reuses existing chat when chat_id provided', function () {
    $user = User::factory()->create();
    $techAccount = TechAccount::factory()->create([
        'status' => TechAccountStatus::Active,
        'pool_type' => TechAccountPoolType::Free,
    ]);
    $notebook = Notebook::factory()->create([
        'user_id' => $user->id,
        'tech_account_id' => $techAccount->id,
    ]);
    $existingChat = Chat::create([
        'user_id' => $user->id,
        'notebook_id' => $notebook->id,
    ]);

    $this->mock(AskService::class, function ($mock) {
        $mock->shouldReceive('ask')
            ->once()
            ->andReturnUsing(function ($nb, $question, $chat) {
                return ['answer' => 'Test answer', 'citations' => []];
            });
    });

    $this->actingAs($user);

    // Act
    $response = $this->postJson("/api/notebooks/{$notebook->id}/ask", [
        'question' => 'Follow-up question',
        'chat_id' => $existingChat->id,
    ]);

    // Assert
    $response->assertStatus(200);

    // Check no new chat was created
    $this->assertDatabaseCount('chats', 1);

    // Check messages were added to existing chat
    $this->assertDatabaseCount('chat_messages', 2);
    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $existingChat->id,
        'role' => 'user',
        'content' => 'Follow-up question',
    ]);
});

test('ask validates required question field', function () {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    $response = $this->postJson("/api/notebooks/{$notebook->id}/ask", []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['question']);
});

test('ask validates question max length', function () {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    $response = $this->postJson("/api/notebooks/{$notebook->id}/ask", [
        'question' => str_repeat('a', 5001),
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['question']);
});
