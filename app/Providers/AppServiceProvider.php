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
        $this->app->bind(AiProvider::class, function ($app) {
            $cfg = config('ai', []);

            return AiProviderFactory::make($cfg['provider'] ?? null, $cfg);
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
