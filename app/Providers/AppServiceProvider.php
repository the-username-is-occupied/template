<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerTelescopeLocally();

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (filled($appUrl = config('app.url'))) {
            URL::forceRootUrl($appUrl);

            $scheme = parse_url($appUrl, PHP_URL_SCHEME);
            if (is_string($scheme) && $scheme !== '') {
                URL::forceScheme($scheme);
            }
        }
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
        Schedule::command('pulse:check')->everyMinute();
        Schedule::command('pulse:ingest')->everyMinute();
        Schedule::command('telescope:prune --hours=72')->daily();
    }
}
