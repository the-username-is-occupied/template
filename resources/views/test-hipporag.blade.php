<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HippoRAG POC</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-900">
    @php
        $selectedSpace = $selectedSpace ?? null;
        $lastOperation = session('last_operation');
        $referencedSources = collect($lastOperation['referenced_sources'] ?? []);
        $sourceRows = $selectedSpace?->sources ?? collect();
    @endphp

    <main class="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-8">
        <header class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600">Laravel + HippoRAG</p>
                <h1 class="text-3xl font-bold">HippoRAG POC Console</h1>
                <p class="text-slate-600">Upload text files, index isolated spaces, then run RAG or retrieve-only queries.</p>
            </div>
            <a href="{{ route('hipporag.index') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium shadow-sm hover:bg-slate-50">Refresh</a>
        </header>

        @if ($errors->any())
            <section class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-semibold">Please fix the following:</p>
                <ul class="mt-2 list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if (session('status'))
            <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                {{ session('status') }}
            </section>
        @endif

        <div class="grid gap-6 lg:grid-cols-[340px_1fr]">
            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold">UserSpaces</h2>
                    <form method="POST" action="{{ route('hipporag.spaces.store') }}">
                        @csrf
                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Create New Space</button>
                    </form>
                </div>

                <div class="mt-4 flex flex-col gap-3">
                    @forelse ($spaces as $space)
                        <article class="rounded-xl border {{ $selectedSpace?->is($space) ? 'border-indigo-300 bg-indigo-50' : 'border-slate-200 bg-white' }} p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-medium">{{ $space->name }}</p>
                                    <p class="font-mono text-xs text-slate-500">{{ Str::limit($space->uuid, 13, '') }}</p>
                                </div>
                                <div class="flex gap-2">
                                    <form method="POST" action="{{ route('hipporag.spaces.select', $space) }}">
                                        @csrf
                                        <button type="submit" class="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium hover:bg-slate-50">Select</button>
                                    </form>
                                    <form method="POST" action="{{ route('hipporag.spaces.destroy', $space) }}" onsubmit="return confirm('Delete this space and its HippoRAG workdir?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded-md border border-red-200 bg-red-50 px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-100">Delete</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @empty
                        <p class="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">No spaces yet. Create one to begin.</p>
                    @endforelse
                </div>
            </section>

            <div class="flex flex-col gap-6">
                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                    <h2 class="text-lg font-semibold">Selected Space</h2>
                    @if ($selectedSpace)
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-xs font-semibold uppercase text-slate-500">Name</p>
                                <p class="font-medium">{{ $selectedSpace->name }}</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-xs font-semibold uppercase text-slate-500">UUID</p>
                                <p class="break-all font-mono text-sm">{{ $selectedSpace->uuid }}</p>
                            </div>
                        </div>
                    @else
                        <p class="mt-3 text-sm text-slate-600">Create or select a space to upload and query.</p>
                    @endif
                </section>

                <div class="grid gap-6 xl:grid-cols-2">
                    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                        <h2 class="text-lg font-semibold">Upload & Index</h2>
                        <form method="POST" action="{{ route('hipporag.index-files') }}" enctype="multipart/form-data" class="mt-4 flex flex-col gap-4">
                            @csrf
                            <input type="hidden" name="user_space_id" value="{{ $selectedSpace?->id }}">
                            <input type="file" name="files[]" multiple accept=".txt,.md,.text" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-indigo-700">
                            <button type="submit" @disabled(! $selectedSpace) class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">Upload & Index</button>
                        </form>
                    </section>

                    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                        <h2 class="text-lg font-semibold">Ask</h2>
                        <form method="POST" action="{{ route('hipporag.query') }}" class="mt-4 flex flex-col gap-4">
                            @csrf
                            <input type="hidden" name="user_space_id" value="{{ $selectedSpace?->id }}">
                            <textarea name="questions" rows="5" placeholder="One question per line" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('questions') }}</textarea>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="text-sm font-medium">
                                    Mode
                                    <select name="mode" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                        <option value="rag" @selected(old('mode', 'rag') === 'rag')>RAG (full)</option>
                                        <option value="retrieve" @selected(old('mode') === 'retrieve')>Retrieve (only)</option>
                                    </select>
                                </label>
                                <label class="text-sm font-medium">
                                    Num to retrieve
                                    <input type="number" name="num_to_retrieve" min="1" max="20" value="{{ old('num_to_retrieve', 5) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                </label>
                            </div>
                            <button type="submit" @disabled(! $selectedSpace) class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300">Ask</button>
                        </form>
                    </section>
                </div>

                @if (($lastOperation['type'] ?? null) === 'index')
                    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                        <h2 class="text-lg font-semibold">Last Indexing Run</h2>
                        <div class="mt-3 rounded-xl bg-slate-50 p-4 text-sm">
                            <p>Indexed <strong>{{ $lastOperation['num_files'] }}</strong> file(s) in <strong>{{ $lastOperation['response_time_ms'] }}</strong> ms.</p>
                            <p>Tokens used: ~{{ number_format($lastOperation['prompt_tokens']) }} prompt + ~{{ number_format($lastOperation['completion_tokens']) }} completion</p>
                            <p>Estimated cost: ${{ number_format($lastOperation['estimated_cost_usd'], 8) }}</p>
                        </div>
                    </section>
                @endif

                @if (($lastOperation['type'] ?? null) === 'query')
                    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                            <h2 class="text-lg font-semibold">Answers</h2>
                            <p class="text-sm text-slate-500">{{ strtoupper($lastOperation['mode']) }} · {{ $lastOperation['response_time_ms'] }} ms</p>
                        </div>

                        <div class="mt-4 flex flex-col gap-4">
                            @foreach ($lastOperation['results'] as $result)
                                <article class="rounded-xl border border-slate-200 p-4">
                                    <p class="text-sm font-semibold text-slate-600">{{ $result['question'] }}</p>
                                    @if (($lastOperation['mode'] ?? 'rag') === 'rag')
                                        <div class="mt-3 rounded-lg bg-slate-50 p-3 text-sm leading-6">{!! $result['answer_html'] !!}</div>
                                    @endif
                                    @if (! empty($result['sources']))
                                        <div class="mt-3">
                                            <p class="text-xs font-semibold uppercase text-slate-500">Sources</p>
                                            <div class="mt-2 flex flex-col gap-2">
                                                @foreach ($result['sources'] as $source)
                                                    <div class="rounded-lg bg-slate-50 p-3 text-sm">
                                                        <p class="font-mono text-xs text-slate-500">score: {{ $source['score'] ?? 'n/a' }}</p>
                                                        <p class="mt-1 leading-6">{!! $source['text_html'] !!}</p>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                        </div>

                        <div class="mt-5 grid gap-4 lg:grid-cols-2">
                            <div class="rounded-xl bg-slate-50 p-4">
                                <h3 class="font-semibold">Referenced Documents</h3>
                                @if ($referencedSources->isNotEmpty())
                                    <ul class="mt-2 space-y-1 text-sm">
                                        @foreach ($referencedSources as $source)
                                            <li><a href="{{ $source['url'] }}" class="text-indigo-700 underline">{{ $source['filename'] }}</a></li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="mt-2 text-sm text-slate-500">No known source markers were returned.</p>
                                @endif
                            </div>
                            <div class="rounded-xl bg-slate-50 p-4">
                                <h3 class="font-semibold">Statistics</h3>
                                <p class="mt-2 text-sm">Tokens used: ~{{ number_format($lastOperation['prompt_tokens']) }} prompt + ~{{ number_format($lastOperation['completion_tokens']) }} completion</p>
                                <p class="text-sm">Estimated cost: ${{ number_format($lastOperation['estimated_cost_usd'], 8) }} (≈ ${{ number_format($lastOperation['estimated_cost_usd'], 4) }})</p>
                                <p class="text-sm">Response time: {{ $lastOperation['response_time_ms'] }} ms</p>
                            </div>
                        </div>
                    </section>
                @endif

                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                    <h2 class="text-lg font-semibold">Operation Log</h2>
                    <ul class="mt-4 space-y-2 font-mono text-xs text-slate-700">
                        @forelse ($operationLogs as $log)
                            <li class="rounded-lg bg-slate-50 px-3 py-2">
                                {{ $log }}
                            </li>
                        @empty
                            <li class="rounded-lg bg-slate-50 px-3 py-2 text-slate-500">No operations yet.</li>
                        @endforelse
                    </ul>
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                    <h2 class="text-lg font-semibold">Sources in Selected Space</h2>
                    <ul class="mt-4 space-y-2 text-sm">
                        @forelse ($sourceRows as $source)
                            <li class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2">
                                <span>{{ $source->original_name }}</span>
                                <a href="{{ route('hipporag.sources.show', $source) }}" class="text-indigo-700 underline">Open</a>
                            </li>
                        @empty
                            <li class="rounded-lg bg-slate-50 px-3 py-2 text-slate-500">No uploaded sources yet.</li>
                        @endforelse
                    </ul>
                </section>
            </div>
        </div>
    </main>
</body>
</html>
