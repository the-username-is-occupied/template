<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WikiFileService;
use Illuminate\Console\Command;

class WikiInit extends Command
{
    protected $signature = 'wiki:init {userspace}';

    protected $description = 'Initialize a new wiki userspace with directory structure and seed files';

    public function handle(WikiFileService $fileService): int
    {
        $userspace = $this->argument('userspace');

        $fileService->initUserspace($userspace);

        $this->info("Проект '{$userspace}' успешно инициализирован.");
        $this->line("  Создано: {$userspace}/raw/");
        $this->line("  Создано: {$userspace}/wiki/");
        $this->line("  Создано: {$userspace}/schema/");
        $this->line("  Создан:  {$userspace}/wiki/index.md");
        $this->line("  Создан:  {$userspace}/wiki/log.md");
        $this->line("  Создан:  {$userspace}/schema/AGENTS.md");

        return self::SUCCESS;
    }
}
