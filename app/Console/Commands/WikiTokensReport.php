<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TokenUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class WikiTokensReport extends Command
{
    protected $signature = 'wiki:tokens:report
        {userspace : The userspace/project slug}
        {--period=all : Period to report on: today, week, month, all}
        {--by-model : Group results by model}';

    protected $description = 'Show a token usage and cost report for a userspace';

    public function handle(): int
    {
        $userspace = $this->argument('userspace');
        $period = $this->option('period');
        $byModel = $this->option('by-model');

        $query = TokenUsage::query()->where('userspace', $userspace);

        $query = match ($period) {
            'today' => $query->whereDate('created_at', Carbon::today()),
            'week' => $query->where('created_at', '>=', Carbon::now()->startOfWeek()),
            'month' => $query->where('created_at', '>=', Carbon::now()->startOfMonth()),
            default => $query,
        };

        if ($byModel) {
            $rows = $query
                ->selectRaw('model, provider, operation, COUNT(*) as requests, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(total_cost) as total_cost')
                ->groupBy('model', 'provider', 'operation')
                ->orderBy('total_cost', 'desc')
                ->get();

            $this->table(
                ['Модель', 'Провайдер', 'Операция', 'Запросов', 'Токенов in', 'Токенов out', 'Стоимость USD'],
                $rows->map(fn ($r) => [
                    $r->model,
                    $r->provider,
                    $r->operation,
                    number_format($r->requests),
                    number_format($r->input_tokens),
                    number_format($r->output_tokens),
                    sprintf('$%.6f', $r->total_cost),
                ])->toArray()
            );
        } else {
            $rows = $query
                ->selectRaw('operation, COUNT(*) as requests, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(total_cost) as total_cost')
                ->groupBy('operation')
                ->orderBy('total_cost', 'desc')
                ->get();

            $this->table(
                ['Операция', 'Запросов', 'Токенов in', 'Токенов out', 'Стоимость USD'],
                $rows->map(fn ($r) => [
                    $r->operation,
                    number_format($r->requests),
                    number_format($r->input_tokens),
                    number_format($r->output_tokens),
                    sprintf('$%.6f', $r->total_cost),
                ])->toArray()
            );
        }

        $totalsQuery = TokenUsage::query()->where('userspace', $userspace);

        $totalsQuery = match ($period) {
            'today' => $totalsQuery->whereDate('created_at', Carbon::today()),
            'week' => $totalsQuery->where('created_at', '>=', Carbon::now()->startOfWeek()),
            'month' => $totalsQuery->where('created_at', '>=', Carbon::now()->startOfMonth()),
            default => $totalsQuery,
        };

        $totals = $totalsQuery->selectRaw('COUNT(*) as total_requests, SUM(input_tokens) as total_in, SUM(output_tokens) as total_out, SUM(total_cost) as total_cost')->first();

        $this->newLine();
        $this->line(sprintf(
            'Итого: %s запросов, %s in / %s out токенов, $%.6f',
            number_format($totals->total_requests ?? 0),
            number_format($totals->total_in ?? 0),
            number_format($totals->total_out ?? 0),
            $totals->total_cost ?? 0.0
        ));

        return self::SUCCESS;
    }
}
