<?php

namespace App\Console\Commands;

use App\Models\OriginalItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TableSizes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:table-sizes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $total = DB::select("SELECT pg_size_pretty(pg_total_relation_size('original_items')) as s")[0]->s;
        $count = OriginalItem::count();

        Log::channel('table-sizes')->info('original_items table size', [
            'date' => now()->toDateTimeString(),
            'total' => $total,
            'rows' => $count,
        ]);

        $this->info("original_items table size: {$total}, rows: {$count}");

        return Command::SUCCESS;
    }
}
