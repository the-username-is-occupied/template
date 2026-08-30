<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Domain\Citations\DTOs\CitationData;
use App\Domain\Citations\DTOs\ResolvedAskResultDTO;
use App\Domain\NotebookLM\DTOs\AskResultDTO;
use App\Domain\NotebookLM\NotebookLMService;
use App\Enums\MdBundleStatus;
use App\Enums\MdBundleType;
use App\Exceptions\DailyLimitExceededException;
use App\Models\Chat;
use App\Models\Notebook;
use App\Models\TechAccount;
use App\Models\TechAccountUsage;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AskService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->notebookLMService = $this->mock(NotebookLMService::class);
    $this->accountService = $this->mock(AccountService::class);
    $this->askService = new AskService($this->notebookLMService, $this->accountService);
});

test('ask throws exception when notebook is consolidating', function (): void {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->forUser($user)->create();

    // Create a consolidating bundle using valid enum values
    $notebook->mdBundles()->create([
        'is_consolidating' => true,
        'status' => MdBundleStatus::Uploading->value,
        'type' => MdBundleType::ActiveDelta->value,
    ]);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Notebook is currently being optimized. Please wait a moment and try again.');

    $this->askService->ask($notebook, 'Test question');
});

test('ask creates new chat when chat is null', function (): void {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->forUser($user)->create();
    $account = TechAccount::factory()->create();

    $this->accountService->shouldReceive('getAccountForAsk')
        ->once()
        ->andReturn($account);

    $askResultDTO = Mockery::mock(AskResultDTO::class);
    $askResultDTO->shouldReceive('resolve')
        ->once()
        ->andReturn(new ResolvedAskResultDTO(
            answer: 'Test answer',
            citations: new DataCollection(CitationData::class, [])
        ));
    $askResultDTO->shouldReceive('toArray')
        ->once()
        ->andReturn(['answer' => 'Test answer', 'conversation_id' => 'conv-123']);

    $this->notebookLMService->shouldReceive('askQuestion')
        ->once()
        ->with($account->id, $notebook->nlm_notebook_id, 'Test question')
        ->andReturn($askResultDTO);

    $result = $this->askService->ask($notebook, 'Test question');

    expect($result)->toBeInstanceOf(ResolvedAskResultDTO::class);
    expect($notebook->chats)->toHaveCount(1);
    expect($notebook->chats->first()->user_id)->toBe($user->id);
});

test('ask uses existing chat when provided', function (): void {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->forUser($user)->create();
    $chat = Chat::create([
        'user_id' => $user->id,
        'notebook_id' => $notebook->id,
    ]);
    $account = TechAccount::factory()->create();

    $this->accountService->shouldReceive('getAccountForAsk')
        ->once()
        ->andReturn($account);

    $askResultDTO = Mockery::mock(AskResultDTO::class);
    $askResultDTO->shouldReceive('resolve')
        ->once()
        ->andReturn(new ResolvedAskResultDTO(
            answer: 'Test answer',
            citations: new DataCollection(CitationData::class, [])
        ));
    $askResultDTO->shouldReceive('toArray')
        ->once()
        ->andReturn(['answer' => 'Test answer', 'conversation_id' => 'conv-123']);

    $this->notebookLMService->shouldReceive('askQuestion')
        ->once()
        ->with($account->id, $notebook->nlm_notebook_id, 'Test question')
        ->andReturn($askResultDTO);

    $result = $this->askService->ask($notebook, 'Test question', $chat);

    expect($result)->toBeInstanceOf(ResolvedAskResultDTO::class);
    expect($notebook->chats)->toHaveCount(1);
    expect($chat->fresh()->messages)->toHaveCount(2); // user + assistant
});

test('ask saves user message', function (): void {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->forUser($user)->create();
    $account = TechAccount::factory()->create();

    $this->accountService->shouldReceive('getAccountForAsk')
        ->once()
        ->andReturn($account);

    $askResultDTO = Mockery::mock(AskResultDTO::class);
    $askResultDTO->shouldReceive('resolve')
        ->once()
        ->andReturn(new ResolvedAskResultDTO(
            answer: 'Test answer',
            citations: new DataCollection(CitationData::class, [])
        ));
    $askResultDTO->shouldReceive('toArray')
        ->once()
        ->andReturn(['answer' => 'Test answer', 'conversation_id' => 'conv-123']);

    $this->notebookLMService->shouldReceive('askQuestion')
        ->once()
        ->andReturn($askResultDTO);

    $this->askService->ask($notebook, 'What is EOL?');

    $chat = $notebook->chats()->first();
    $userMessage = $chat->messages()->where('role', 'user')->first();

    expect($userMessage)->not->toBeNull();
    expect($userMessage->content)->toBe('What is EOL?');
});

test('ask calls NotebookLM service with correct parameters', function (): void {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->forUser($user)->create([
        'nlm_notebook_id' => 'test-notebook-123',
    ]);
    $account = TechAccount::factory()->create(['id' => 'account-456']);

    $this->accountService->shouldReceive('getAccountForAsk')
        ->once()
        ->andReturn($account);

    $askResultDTO = Mockery::mock(AskResultDTO::class);
    $askResultDTO->shouldReceive('resolve')
        ->once()
        ->andReturn(new ResolvedAskResultDTO(
            answer: 'Test answer',
            citations: new DataCollection(CitationData::class, [])
        ));
    $askResultDTO->shouldReceive('toArray')
        ->once()
        ->andReturn(['answer' => 'Test answer', 'conversation_id' => 'conv-123']);

    $this->notebookLMService->shouldReceive('askQuestion')
        ->once()
        ->with('account-456', 'test-notebook-123', 'Test question')
        ->andReturn($askResultDTO);

    $this->askService->ask($notebook, 'Test question');
});

test('ask increments usage count', function (): void {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->forUser($user)->create();
    $account = TechAccount::factory()->create();

    $this->accountService->shouldReceive('getAccountForAsk')
        ->times(2)
        ->andReturn($account);

    $askResultDTO = Mockery::mock(AskResultDTO::class);
    $askResultDTO->shouldReceive('resolve')
        ->times(2)
        ->andReturn(new ResolvedAskResultDTO(
            answer: 'Test answer',
            citations: new DataCollection(CitationData::class, [])
        ));
    $askResultDTO->shouldReceive('toArray')
        ->times(2)
        ->andReturn(['answer' => 'Test answer', 'conversation_id' => 'conv-123']);

    $this->notebookLMService->shouldReceive('askQuestion')
        ->times(2)
        ->andReturn($askResultDTO);

    expect(TechAccountUsage::where('tech_account_id', $account->id)->count())->toBe(0);

    // First call - creates usage with count = 1
    $this->askService->ask($notebook, 'Test question');

    $usage = TechAccountUsage::where('tech_account_id', $account->id)
        ->where('date', today())
        ->first();

    expect($usage)->not->toBeNull();
    expect($usage->count)->toBe(1);

    // Second call - should increment count to 2 (tests ON CONFLICT DO UPDATE)
    $this->askService->ask($notebook, 'Another question');

    $usage->refresh();

    expect($usage->count)->toBe(2);
});

test('ask saves assistant message on success', function (): void {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->forUser($user)->create();
    $account = TechAccount::factory()->create();

    $this->accountService->shouldReceive('getAccountForAsk')
        ->once()
        ->andReturn($account);

    $askResultDTO = Mockery::mock(AskResultDTO::class);
    $askResultDTO->shouldReceive('resolve')
        ->once()
        ->andReturn(new ResolvedAskResultDTO(
            answer: 'Test answer',
            citations: new DataCollection(CitationData::class, [])
        ));
    $askResultDTO->shouldReceive('toArray')
        ->once()
        ->andReturn(['answer' => 'Test answer', 'conversation_id' => 'conv-123']);

    $this->notebookLMService->shouldReceive('askQuestion')
        ->once()
        ->andReturn($askResultDTO);

    $this->askService->ask($notebook, 'Test question');

    $chat = $notebook->chats()->first();
    $assistantMessage = $chat->messages()->where('role', 'assistant')->first();

    expect($assistantMessage)->not->toBeNull();
    expect($assistantMessage->tech_account_id)->toBe($account->id);
    expect($assistantMessage->is_success)->toBeTrue();
    expect($assistantMessage->result)->toBe(['answer' => 'Test answer', 'conversation_id' => 'conv-123']);
});

test('ask saves failed message on error', function (): void {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->forUser($user)->create();
    $account = TechAccount::factory()->create();

    $this->accountService->shouldReceive('getAccountForAsk')
        ->once()
        ->andReturn($account);

    $this->notebookLMService->shouldReceive('askQuestion')
        ->once()
        ->andThrow(new Exception('API error'));

    try {
        $this->askService->ask($notebook, 'Test question');
    } catch (Exception $e) {
        // Expected exception
    }

    $chat = $notebook->chats()->first();
    $assistantMessage = $chat->messages()->where('role', 'assistant')->first();

    expect($assistantMessage)->not->toBeNull();
    expect($assistantMessage->tech_account_id)->toBe($account->id);
    expect($assistantMessage->is_success)->toBeFalse();
    expect($assistantMessage->result)->toBeNull();
    expect($assistantMessage->content)->toBeNull();
});

test('ask rethrows exception after saving failed message', function (): void {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->forUser($user)->create();
    $account = TechAccount::factory()->create();

    $this->accountService->shouldReceive('getAccountForAsk')
        ->once()
        ->andReturn($account);

    $this->notebookLMService->shouldReceive('askQuestion')
        ->once()
        ->andThrow(new RuntimeException('Service unavailable'));

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Service unavailable');

    $this->askService->ask($notebook, 'Test question');
});

test('ask returns resolved ask result DTO', function (): void {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->forUser($user)->create();
    $account = TechAccount::factory()->create();

    $this->accountService->shouldReceive('getAccountForAsk')
        ->once()
        ->andReturn($account);

    $citations = new DataCollection(CitationData::class, [
        new CitationData(
            source_url: 'http://example.com',
            title: 'Example Title',
            published_at: null,
            source_type: 'web',
            content_source_id: 'source-123',
            cited_text_clean: 'Example text',
            citation_number: 1,
        ),
    ]);

    $resolvedDTO = new ResolvedAskResultDTO(
        answer: 'The answer is 42',
        citations: $citations
    );

    $askResultDTO = Mockery::mock(AskResultDTO::class);
    $askResultDTO->shouldReceive('resolve')
        ->once()
        ->andReturn($resolvedDTO);
    $askResultDTO->shouldReceive('toArray')
        ->once()
        ->andReturn(['answer' => 'The answer is 42', 'conversation_id' => 'conv-123']);

    $this->notebookLMService->shouldReceive('askQuestion')
        ->once()
        ->andReturn($askResultDTO);

    $result = $this->askService->ask($notebook, 'What is the answer?');

    expect($result)->toBeInstanceOf(ResolvedAskResultDTO::class);
    expect($result->answer)->toBe('The answer is 42');
    expect($result->getUrls())->toContain('http://example.com');
});

test('ask handles DailyLimitExceededException', function (): void {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->forUser($user)->create();
    $account = TechAccount::factory()->create();

    $this->accountService->shouldReceive('getAccountForAsk')
        ->once()
        ->andThrow(new DailyLimitExceededException('Daily limit exceeded'));

    $this->expectException(DailyLimitExceededException::class);

    $this->askService->ask($notebook, 'Test question');
});

test('ask creates chat with correct notebook relationship', function (): void {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->forUser($user)->create();
    $account = TechAccount::factory()->create();

    $this->accountService->shouldReceive('getAccountForAsk')
        ->once()
        ->andReturn($account);

    $askResultDTO = Mockery::mock(AskResultDTO::class);
    $askResultDTO->shouldReceive('resolve')
        ->once()
        ->andReturn(new ResolvedAskResultDTO(
            answer: 'Test answer',
            citations: new DataCollection(CitationData::class, [])
        ));
    $askResultDTO->shouldReceive('toArray')
        ->once()
        ->andReturn(['answer' => 'Test answer', 'conversation_id' => 'conv-123']);

    $this->notebookLMService->shouldReceive('askQuestion')
        ->once()
        ->andReturn($askResultDTO);

    $this->askService->ask($notebook, 'Test question');

    $chat = $notebook->chats()->first();
    expect($chat)->not->toBeNull();
    expect($chat->notebook_id)->toBe($notebook->id);
    expect($chat->user_id)->toBe($user->id);
});

test('ask creates both user and assistant messages', function (): void {
    $user = User::factory()->create();
    $notebook = Notebook::factory()->forUser($user)->create();
    $account = TechAccount::factory()->create();

    $this->accountService->shouldReceive('getAccountForAsk')
        ->once()
        ->andReturn($account);

    $askResultDTO = Mockery::mock(AskResultDTO::class);
    $askResultDTO->shouldReceive('resolve')
        ->once()
        ->andReturn(new ResolvedAskResultDTO(
            answer: 'Test answer',
            citations: new DataCollection(CitationData::class, [])
        ));
    $askResultDTO->shouldReceive('toArray')
        ->once()
        ->andReturn(['answer' => 'Test answer', 'conversation_id' => 'conv-123']);

    $this->notebookLMService->shouldReceive('askQuestion')
        ->once()
        ->andReturn($askResultDTO);

    $this->askService->ask($notebook, 'Test question');

    $chat = $notebook->chats()->first();
    expect($chat->messages)->toHaveCount(2);

    $userMessage = $chat->messages()->where('role', 'user')->first();
    $assistantMessage = $chat->messages()->where('role', 'assistant')->first();

    expect($userMessage)->not->toBeNull();
    expect($userMessage->content)->toBe('Test question');
    expect($assistantMessage)->not->toBeNull();
    expect($assistantMessage->is_success)->toBeTrue();
});
