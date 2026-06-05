<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Admin' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<div class="absolute inset-0 -z-10 overflow-hidden">
    <div class="absolute left-[-12rem] top-[-10rem] h-80 w-80 rounded-full bg-cyan-500/20 blur-3xl"></div>
    <div class="absolute right-[-8rem] top-[8rem] h-72 w-72 rounded-full bg-fuchsia-500/20 blur-3xl"></div>
</div>

<main class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="mb-8 flex items-center justify-between gap-4">
        <div>
            <p class="text-sm uppercase tracking-[0.35em] text-cyan-300/80">Eolithic</p>
            <h1 class="mt-2 text-3xl font-semibold text-white">{{ $title ?? 'Admin' }}</h1>
        </div>
        <a href="{{ route('admin.tech-accounts.index') }}" class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/10">Accounts</a>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-2xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @yield('content')
</main>
</body>
</html>