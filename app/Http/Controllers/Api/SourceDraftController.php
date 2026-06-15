<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\CreateSourceDraftData;
use App\Domain\SourcePipeline\DTOs\SourceDraftResource;
use App\Http\Controllers\Controller;
use App\Models\Notebook;
use App\Models\SourceDraft;
use App\Services\SourceDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SourceDraftController extends Controller
{
    public function __construct(
        private readonly SourceDraftService $draftService,
    ) {}

    /**
     * Create one or more source drafts from raw input.
     */
    public function store(Request $request): JsonResponse
    {
        $data = CreateSourceDraftData::from($request->all());

        $user = Auth::user();
        $notebook = Notebook::where('id', $data->knowledge_base_id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $drafts = $this->draftService->create($user, $notebook, $data->raw_input);

        return response()->json(
            $drafts->map(fn (SourceDraft $draft) => SourceDraftResource::fromModel($draft))->toArray(),
            201
        );
    }

    /**
     * Get current state of a source draft.
     */
    public function show(SourceDraft $draft): JsonResponse
    {
        $this->authorizeDraft($draft);

        return response()->json(SourceDraftResource::fromModel($draft));
    }

    /**
     * Abandon (delete) a source draft.
     */
    public function destroy(SourceDraft $draft): JsonResponse
    {
        $this->authorizeDraft($draft);

        $this->draftService->abandon($draft);

        return response()->json(['message' => 'Draft abandoned.']);
    }

    private function authorizeDraft(SourceDraft $draft): void
    {
        if ((string) $draft->user_id !== (string) Auth::id()) {
            abort(403, 'You do not have access to this draft.');
        }
    }
}
