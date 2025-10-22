<?php

namespace App\Providers;

use App\Services\LanguageApp\AiProvider;
use App\Services\LanguageApp\AiProviderFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('testing')) {
            // Когда сервисы просят AiProvider, отдаём наш мок
            $this->app->singleton(\App\Services\LanguageApp\AiProvider::class, function ($app) {
                return new \App\Services\LanguageApp\Providers\MockAiProvider();
            });
        }
        
        $this->app->bind(AiProvider::class, function ($app) {
            $cfg = config('ai', []);

            return AiProviderFactory::make($cfg['provider'] ?? null, $cfg);
        });

        $this->app->singleton('task-dispatcher', function ($app) {
            return new \App\Support\Queue\TaskDispatcher();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
