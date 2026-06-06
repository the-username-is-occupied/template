<?php

declare(strict_types=1);

namespace App\Domain\TechAccount;

use App\Domain\NotebookLM\NotebookLMService;
use App\Enums\TechAccountStatus;
use App\Http\Requests\Admin\StoreTechAccountRequest;
use App\Http\Requests\Admin\UpdateTechAccountRequest;
use App\Models\TechAccounts;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use RuntimeException;

class TechAccountService
{
    public function __construct(
        private readonly NotebookLMService $notebookLMService
    ) {}

    public function create(StoreTechAccountRequest $request): TechAccounts
    {
        $validated = $request->validated();

        $account = new TechAccounts;
        $account->fill($validated);
        $account->status = TechAccountStatus::Initializing;
        $account->save();

        $this->storeStorageState($request, $account);
        $account->forceFill([
            'status' => $validated['status'],
            'cookie_path' => $this->cookiePath($account),
        ])->save();

        // $this->notebookLMService->initializeAccount((string) $account->id);

        return $account;
    }

    public function update(UpdateTechAccountRequest $request, TechAccounts $account): void
    {
        $validated = $request->validated();

        $account->fill($validated);

        if ($request->hasFile('storage_state')) {
            $this->storeStorageState($request, $account);
        }

        $account->cookie_path = $this->cookiePath($account);
        $account->save();

    }

    public function delete(TechAccounts $account): void
    {
        // $this->notebookLMService->removeAccount((string) $account->id);

        $directory = dirname($this->cookiePath($account));

        if (File::exists($directory)) {
            File::deleteDirectory($directory);
        }

        $account->delete();
    }

    private function storeStorageState(Request $request, TechAccounts $account): void
    {
        if (! $request->hasFile('storage_state')) {
            return;
        }

        $uploadedFile = $request->file('storage_state');
        $contents = file_get_contents($uploadedFile->getRealPath());

        if ($contents === false) {
            throw new RuntimeException('Unable to read uploaded storage_state file.');
        }

        json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        $path = $this->cookiePath($account);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }

    private function cookiePath(TechAccounts $account): string
    {
        return rtrim((string) config('tech-accounts.cookie_base_path'), '/').'/'.$account->id.'/storage_state.json';
    }

    public function index(Request $request): LengthAwarePaginator
    {
        return TechAccounts::query()
            ->when($request->string('status')->toString() !== '', fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->string('pool_type')->toString() !== '', fn ($query) => $query->where('pool_type', $request->string('pool_type')->toString()))
            ->when($request->string('search')->toString() !== '', fn ($query) => $query->where(function ($innerQuery) use ($request): void {
                $search = $request->string('search')->toString();
                $innerQuery->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            }))
            ->with('tierLimit')
            ->orderBy('status')
            ->orderBy('chats_today')
            ->orderBy('notebooks_count')
            ->paginate(20)
            ->withQueryString();
    }
}
