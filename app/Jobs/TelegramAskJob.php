<?php

namespace App\Jobs;

use App\Services\TelegramBot\AskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TelegramAskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(protected int $user_id, protected string $question, protected ?int $placeholderId = null) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            app()->make(AskService::class)->handle($this->user_id, $this->question, $this->placeholderId);
        } catch (\Throwable $e) {
            Log::error('Ошибка в Job при отправке ответа в ТГ: '.$e->getMessage());
            // Если нужно, чтобы очередь попробовала запустить задачу снова:
            $this->fail($e);
        }
    }
}
