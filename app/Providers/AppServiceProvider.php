<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\NotebookLM\Jobs\CheckAccountHealth;
use App\Domain\Telegram\TGScraperService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerTelescopeLocally();

        $this->app->singleton(TGScraperService::class, function ($app): TGScraperService {
            return new TGScraperService;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        JsonResource::withoutWrapping();
        $this->schedule();

    }

    private function registerTelescopeLocally(): void
    {
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    private function schedule(): void
    {
        // Schedule::command('pulse:check')->everyMinute();
        // Schedule::command('pulse:ingest')->everyMinute();
        // Schedule::command('telescope:prune --hours=72')->daily();
        Schedule::job(new CheckAccountHealth)->everyMinute();
        Schedule::command('app:table-sizes')->dailyAt('00:00');

    }
}
