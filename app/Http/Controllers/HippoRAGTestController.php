<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IndexRequest;
use App\Http\Requests\QueryRequest;
use App\Models\Source;
use App\Models\UserSpace;
use App\Services\HippoRAGClient;
use App\Services\HippoRAGIndexingService;
use App\Services\HippoRAGQueryService;
use App\Services\LlmModelCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class HippoRAGTestController extends Controller
{
    public function __construct(
        private readonly HippoRAGClient $client,
        private readonly HippoRAGIndexingService $indexingService,
        private readonly HippoRAGQueryService $queryService,
        private readonly LlmModelCatalogService $modelCatalog,
    ) {}

    public function index(Request $request): View
    {
        $selectedSpace = $this->selectedSpace($request);
        $selectedSpace?->load(['sources' => fn ($query) => $query->latest(), 'tokenUsageLogs' => fn ($query) => $query->latest()->limit(20)]);

        $modelCatalog = $this->modelCatalog->models();

        return view('hipporag-index', [
            'spaces' => UserSpace::query()->latest()->get(),
            'selectedSpace' => $selectedSpace,
            'lastOperation' => session('last_operation'),
            'operationLogs' => $this->operationLogs($request),
            'health' => $this->health(),
            'llmModels' => $modelCatalog['models'],
            'catalogWarnings' => $modelCatalog['warnings'],
            'formState' => $this->formState($request),
        ]);
    }

    public function storeSpace(): RedirectResponse
    {
        $space = UserSpace::query()->create([
            'name' => 'Test Space '.now()->format('H:i:s'),
        ]);

        return redirect()
            ->route('hipporag.index', ['space' => $space->uuid])
            ->with('status', sprintf('Created space %s.', $space->name));
    }

    public function selectSpace(UserSpace $userSpace): RedirectResponse
    {
        return redirect()->route('hipporag.index', ['space' => $userSpace->uuid]);
    }

    public function destroySpace(UserSpace $userSpace): RedirectResponse
    {
        try {
            $this->client->delete($userSpace->workDir());
        } catch (Throwable $throwable) {
            report($throwable);
        }

        $name = $userSpace->name;
        Storage::disk('local')->deleteDirectory($userSpace->storageDirectory());
        $userSpace->delete();

        return redirect()
            ->route('hipporag.index')
            ->with('status', sprintf('Deleted space %s.', $name));
    }

    public function indexFiles(IndexRequest $request): RedirectResponse
    {
        $space = $request->userSpace();
        $this->persistFormState($request, [
            'llm_model_name' => $request->llmModelName(),
            'llm_provider' => $request->llmProvider(),
            'index_mode' => $request->indexMode(),
            'chunk_size' => $request->chunkSize(),
            'overlap_ratio' => $request->overlapRatio(),
            'pasted_text' => $request->pastedText(),
        ]);

        try {
            $result = $this->indexingService->index(
                userSpace: $space,
                files: $request->file('files', []),
                pastedText: $request->pastedText(),
                llmModelName: $request->llmModelName(),
                llmProvider: $request->llmProvider(),
                indexMode: $request->indexMode(),
                chunkSize: $request->chunkSize(),
                overlapRatio: $request->overlapRatio(),
            );
        } catch (Throwable $throwable) {
            report($throwable);
            logger()->warning('HippoRAG indexing failed.', [
                'user_space_id' => $space->id,
                'mode' => $request->indexMode(),
                'llm_model_name' => $request->llmModelName(),
                'error' => $throwable->getMessage(),
            ]);

            $message = trim($throwable->getMessage());
            $detail = $message !== '' ? $message : 'unknown runtime error';

            return redirect()
                ->route('hipporag.index', ['space' => $space->uuid])
                ->withErrors([
                    'indexing' => sprintf('HippoRAG indexing failed: %s', $detail),
                ]);
        }

        foreach ($result['files'] as $fileResult) {
            $this->logOperation($request, sprintf(
                '%s %s (~%s tokens, $%0.6f)',
                $result['mode'] === 'chunk' ? 'Chunk preview for' : 'Indexed file',
                $fileResult['filename'],
                number_format((int) $fileResult['tokens']),
                (float) $fileResult['cost'],
            ));
        }

        return redirect()
            ->route('hipporag.index', ['space' => $space->uuid])
            ->with('last_operation', [
                'type' => 'index',
                ...$result,
            ]);
    }

    public function query(QueryRequest $request): RedirectResponse
    {
        $space = $request->userSpace();
        $result = $this->queryService->query(
            $space,
            $request->queries(),
            (string) $request->validated('mode'),
            (int) $request->validated('num_to_retrieve'),
            $request->llmModelName(),
            $request->llmProvider(),
            $request->scoreThreshold(),
            $request->agentInstructions(),
        );

        $this->persistFormState($request, [
            'llm_model_name' => $request->llmModelName(),
            'llm_provider' => $request->llmProvider(),
            'questions' => (string) $request->validated('questions'),
            'mode' => (string) $request->validated('mode'),
            'num_to_retrieve' => (int) $request->validated('num_to_retrieve'),
            'score_threshold' => $request->scoreThreshold(),
            'agent_instructions' => $request->agentInstructions(),
        ]);

        return redirect()
            ->route('hipporag.index', ['space' => $space->uuid])
            ->with('last_operation', [
                'type' => 'query',
                ...$result,
            ]);
    }

    public function downloadSource(Source $source): Response
    {
        abort_unless(Storage::disk('local')->exists($source->path), 404);

        $contents = Storage::disk('local')->get($source->path);

        return response($contents, 200, [
            'Content-Type' => $source->mime_type ?: 'text/plain',
            'Content-Disposition' => 'inline; filename="'.$source->original_name.'"',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function operationLogs(Request $request): array
    {
        return $request->session()->get('hipporag_operation_logs', []);
    }

    private function logOperation(Request $request, string $message): void
    {
        $logs = $this->operationLogs($request);
        array_unshift($logs, sprintf('[%s] %s', now()->format('H:i:s'), $message));

        $request->session()->put('hipporag_operation_logs', array_slice($logs, 0, 20));
    }

    private function selectedSpace(Request $request): ?UserSpace
    {
        $uuid = $request->query('space');

        if (! is_string($uuid) || $uuid === '') {
            return UserSpace::query()->latest()->first();
        }

        return UserSpace::query()->where('uuid', $uuid)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function health(): array
    {
        try {
            return $this->client->health();
        } catch (Throwable) {
            return [
                'status' => 'unavailable',
                'hipporag_available' => false,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formState(Request $request): array
    {
        $defaults = [
            'llm_model_name' => (string) config('hipporag.default_model'),
            'llm_provider' => (string) config('hipporag.default_provider'),
            'index_mode' => 'index',
            'chunk_size' => (int) config('hipporag.chunk_size', 512),
            'overlap_ratio' => (float) config('hipporag.chunk_overlap_ratio', 0.12),
            'pasted_text' => '',
            'questions' => '',
            'mode' => 'rag',
            'num_to_retrieve' => 5,
            'score_threshold' => (float) config('hipporag.score_threshold', 0),
            'agent_instructions' => '',
        ];

        $state = $request->session()->get('hipporag_form_state', []);
        if (! is_array($state)) {
            return $defaults;
        }

        return array_merge($defaults, $state);
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function persistFormState(Request $request, array $updates): void
    {
        $current = $request->session()->get('hipporag_form_state', []);
        if (! is_array($current)) {
            $current = [];
        }

        $request->session()->put('hipporag_form_state', array_merge($current, $updates));
    }
}
