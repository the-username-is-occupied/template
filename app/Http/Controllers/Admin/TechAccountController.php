<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\TechAccount\TechAccountService;
use App\Enums\TechAccountPoolType;
use App\Enums\TechAccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTechAccountRequest;
use App\Http\Requests\Admin\UpdateTechAccountRequest;
use App\Models\TechAccounts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TechAccountController extends Controller
{
    public function __construct(
        private readonly TechAccountService $service
    ) {}

    public function index(Request $request): View
    {
        $accounts = $this->service->index($request);

        return view('admin.tech-accounts.index', [
            'accounts' => $accounts,
            'statuses' => TechAccountStatus::cases(),
            'poolTypes' => TechAccountPoolType::cases(),
        ]);
    }

    public function create(): View
    {
        return view('admin.tech-accounts.create', [
            'account' => new TechAccounts,
            'statuses' => TechAccountStatus::cases(),
            'poolTypes' => TechAccountPoolType::cases(),
            'action' => route('admin.tech-accounts.store'),
            'method' => 'POST',
            'title' => 'Create tech account',
            'submitLabel' => 'Create account',
        ]);
    }

    public function store(StoreTechAccountRequest $request): RedirectResponse
    {
        $this->service->create($request);

        return redirect()
            ->route('admin.tech-accounts.index')
            ->with('status', 'Account created successfully.');
    }

    public function edit(TechAccounts $techAccount): View
    {
        return view('admin.tech-accounts.edit', [
            'account' => $techAccount,
            'statuses' => TechAccountStatus::cases(),
            'poolTypes' => TechAccountPoolType::cases(),
            'action' => route('admin.tech-accounts.update', $techAccount),
            'method' => 'PUT',
            'title' => 'Edit tech account',
            'submitLabel' => 'Save changes',
        ]);
    }

    public function update(UpdateTechAccountRequest $request, TechAccounts $techAccount): RedirectResponse
    {
        $this->service->update($request, $techAccount);

        return redirect()
            ->route('admin.tech-accounts.index')
            ->with('status', 'Account updated successfully.');
    }

    public function destroy(TechAccounts $techAccount): RedirectResponse
    {
        $this->service->delete($techAccount);

        return redirect()
            ->route('admin.tech-accounts.index')
            ->with('status', 'Account deleted successfully.');
    }
}
