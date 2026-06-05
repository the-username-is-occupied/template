@extends('layouts.admin', ['title' => 'Tech accounts'])

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-sm text-slate-400">Manage Google technical accounts, their pool assignment, and cookie state.</p>
        </div>
        <a href="{{ route('admin.tech-accounts.create') }}" class="rounded-full bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">New account</a>
    </div>

    <form method="GET" action="{{ route('admin.tech-accounts.index') }}" class="mb-6 grid gap-4 rounded-3xl border border-white/10 bg-white/5 p-4 backdrop-blur sm:grid-cols-3 lg:grid-cols-4">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search name or email" class="rounded-2xl border border-white/10 bg-slate-900/60 px-4 py-3 text-white placeholder:text-slate-500">
        <select name="status" class="rounded-2xl border border-white/10 bg-slate-900/60 px-4 py-3 text-white">
            <option value="">All statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
            @endforeach
        </select>
        <select name="pool_type" class="rounded-2xl border border-white/10 bg-slate-900/60 px-4 py-3 text-white">
            <option value="">All tiers</option>
            @foreach($poolTypes as $poolType)
                <option value="{{ $poolType->value }}" @selected(request('pool_type') === $poolType->value)>{{ $poolType->label() }}</option>
            @endforeach
        </select>
        <div class="flex gap-3">
            <button type="submit" class="rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-950">Filter</button>
            <a href="{{ route('admin.tech-accounts.index') }}" class="rounded-2xl border border-white/10 px-4 py-3 text-sm font-semibold text-white">Reset</a>
        </div>
    </form>

    <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/70 shadow-2xl shadow-black/20">
        <table class="min-w-full divide-y divide-white/10 text-left text-sm">
            <thead class="bg-white/5 text-slate-300">
            <tr>
                <th class="px-4 py-3 font-medium">Account</th>
                <th class="px-4 py-3 font-medium">Tier</th>
                <th class="px-4 py-3 font-medium">Status</th>
                <th class="px-4 py-3 font-medium">Usage</th>
                <th class="px-4 py-3 font-medium">Cookie</th>
                <th class="px-4 py-3 font-medium">Updated</th>
                <th class="px-4 py-3 font-medium"></th>
            </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
            @forelse($accounts as $account)
                <tr class="align-top text-slate-100">
                    <td class="px-4 py-4">
                        <div class="font-semibold">{{ $account->name }}</div>
                        <div class="text-slate-400">{{ $account->email }}</div>
                        <div class="mt-2 text-xs text-slate-500">{{ $account->id }}</div>
                    </td>
                    <td class="px-4 py-4">{{ $account->pool_type->label() }}</td>
                    <td class="px-4 py-4">
                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ match ($account->status->value) {
                            'active' => 'bg-emerald-400/15 text-emerald-200',
                            'initializing' => 'bg-amber-400/15 text-amber-200',
                            'inactive' => 'bg-slate-400/15 text-slate-200',
                            'banned' => 'bg-rose-400/15 text-rose-200',
                        } }}">{{ $account->status->label() }}</span>
                    </td>
                    <td class="px-4 py-4 text-slate-300">
                        <div>Notebooks: {{ $account->notebooks_count }}</div>
                        <div>Chats today: {{ $account->chats_today }}</div>
                        <div>Reset: {{ $account->chats_reset_at?->format('Y-m-d H:i') ?? '—' }}</div>
                    </td>
                    <td class="px-4 py-4 text-slate-300">
                        <div class="max-w-xs break-all">{{ $account->cookie_path ?? '—' }}</div>
                        <div class="mt-2 text-xs text-slate-500">Proxy: {{ $account->proxy_host ?? '—' }}</div>
                    </td>
                    <td class="px-4 py-4 text-slate-300">{{ $account->last_used_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="px-4 py-4 text-right">
                        <div class="flex justify-end gap-2">
                            <a href="{{ route('admin.tech-accounts.edit', $account) }}" class="rounded-xl border border-white/10 px-3 py-2 text-xs font-semibold text-white hover:bg-white/10">Edit</a>
                            <form method="POST" action="{{ route('admin.tech-accounts.destroy', $account) }}" onsubmit="return confirm('Delete this account?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-xl border border-rose-400/20 px-3 py-2 text-xs font-semibold text-rose-200 hover:bg-rose-400/10">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-10 text-center text-slate-400">No accounts yet.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $accounts->links() }}
    </div>
@endsection