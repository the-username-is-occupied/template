<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-6 rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur">
    @csrf
    @if($method !== 'POST')
        @method($method)
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <label class="space-y-2">
            <span class="text-sm font-medium text-slate-200">Name</span>
            <input type="text" name="name" value="{{ old('name', $account->name) }}" class="w-full rounded-2xl border border-white/10 bg-slate-900/60 px-4 py-3 text-white">
            @error('name')<p class="text-sm text-rose-300">{{ $message }}</p>@enderror
        </label>

        <label class="space-y-2">
            <span class="text-sm font-medium text-slate-200">Email</span>
            <input type="email" name="email" value="{{ old('email', $account->email) }}" class="w-full rounded-2xl border border-white/10 bg-slate-900/60 px-4 py-3 text-white">
            @error('email')<p class="text-sm text-rose-300">{{ $message }}</p>@enderror
        </label>

        <label class="space-y-2">
            <span class="text-sm font-medium text-slate-200">Tier</span>
            <select name="pool_type" class="w-full rounded-2xl border border-white/10 bg-slate-900/60 px-4 py-3 text-white">
                @foreach($poolTypes as $poolType)
                    <option value="{{ $poolType->value }}" @selected(old('pool_type', $account->pool_type?->value) === $poolType->value)>{{ $poolType->label() }}</option>
                @endforeach
            </select>
            @error('pool_type')<p class="text-sm text-rose-300">{{ $message }}</p>@enderror
        </label>

        <label class="space-y-2">
            <span class="text-sm font-medium text-slate-200">Status</span>
            <select name="status" class="w-full rounded-2xl border border-white/10 bg-slate-900/60 px-4 py-3 text-white">
                @foreach($statuses as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $account->status?->value) === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
            @error('status')<p class="text-sm text-rose-300">{{ $message }}</p>@enderror
        </label>

        <label class="space-y-2">
            <span class="text-sm font-medium text-slate-200">Storage state JSON {{ $account->exists ? '(optional)' : '(required)' }}</span>
            <input type="file" name="storage_state" accept="application/json,.json" class="w-full rounded-2xl border border-dashed border-white/20 bg-slate-900/60 px-4 py-3 text-white file:mr-4 file:rounded-full file:border-0 file:bg-white file:px-4 file:py-2 file:text-sm file:font-semibold file:text-slate-950">
            @error('storage_state')<p class="text-sm text-rose-300">{{ $message }}</p>@enderror
            @if($account->cookie_path)
                <p class="text-xs text-slate-400 break-all">Current file: {{ $account->cookie_path }}</p>
            @endif
        </label>

        <label class="space-y-2">
            <span class="text-sm font-medium text-slate-200">Notebooks count</span>
            <input type="number" min="0" name="notebooks_count" value="{{ old('notebooks_count', $account->notebooks_count ?? 0) }}" class="w-full rounded-2xl border border-white/10 bg-slate-900/60 px-4 py-3 text-white">
            @error('notebooks_count')<p class="text-sm text-rose-300">{{ $message }}</p>@enderror
        </label>

        <label class="space-y-2">
            <span class="text-sm font-medium text-slate-200">Chats today</span>
            <input type="number" min="0" name="chats_today" value="{{ old('chats_today', $account->chats_today ?? 0) }}" class="w-full rounded-2xl border border-white/10 bg-slate-900/60 px-4 py-3 text-white">
            @error('chats_today')<p class="text-sm text-rose-300">{{ $message }}</p>@enderror
        </label>

        <label class="space-y-2">
            <span class="text-sm font-medium text-slate-200">Chats reset at</span>
            <input type="datetime-local" name="chats_reset_at" value="{{ old('chats_reset_at', optional($account->chats_reset_at)->format('Y-m-d\TH:i')) }}" class="w-full rounded-2xl border border-white/10 bg-slate-900/60 px-4 py-3 text-white">
            @error('chats_reset_at')<p class="text-sm text-rose-300">{{ $message }}</p>@enderror
        </label>

        <label class="space-y-2">
            <span class="text-sm font-medium text-slate-200">Last used at</span>
            <input type="datetime-local" name="last_used_at" value="{{ old('last_used_at', optional($account->last_used_at)->format('Y-m-d\TH:i')) }}" class="w-full rounded-2xl border border-white/10 bg-slate-900/60 px-4 py-3 text-white">
            @error('last_used_at')<p class="text-sm text-rose-300">{{ $message }}</p>@enderror
        </label>
    </div>

    <div class="flex items-center justify-end gap-3">
        <a href="{{ route('admin.tech-accounts.index') }}" class="rounded-2xl border border-white/10 px-4 py-3 text-sm font-semibold text-white">Cancel</a>
        <button type="submit" class="rounded-2xl bg-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950">{{ $submitLabel }}</button>
    </div>
</form>