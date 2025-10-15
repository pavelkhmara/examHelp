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
        $this->app->singleton(AiProvider::class, function () {
            return AiProviderFactory::make();
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
