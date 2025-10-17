<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\LanguageApp\AiProvider;
use App\Services\LanguageApp\AiProviderFactory;

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
