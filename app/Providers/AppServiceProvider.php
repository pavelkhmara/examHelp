<?php

namespace App\Providers;

use App\Services\LanguageApp\AiProvider;
use App\Services\LanguageApp\AiProviderFactory;
use App\Services\LanguageApp\FileAttachmentService;
use App\Services\LanguageApp\Providers\OpenAiProvider;
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
                return new \App\Services\LanguageApp\Providers\MockAiProvider;
            });
        }

        $this->app->bind(AiProvider::class, function ($app) {
            $cfg = config('ai', []);

            return AiProviderFactory::make($cfg['provider'] ?? null, $cfg);
        });

        $this->app->singleton('task-dispatcher', function ($app) {
            return new \App\Support\Queue\TaskDispatcher;
        });

        // FileAttachmentService for OpenAI file uploads
        $this->app->singleton(FileAttachmentService::class, function ($app) {
            $cfg = config('ai.openai', []);
            $http = new \GuzzleHttp\Client([
                'base_uri' => rtrim($cfg['base_url'] ?? 'https://api.openai.com/v1', '/').'/',
                'timeout' => $cfg['timeout'] ?? 180,
            ]);
            $provider = new OpenAiProvider(
                $http,
                $cfg['api_key'] ?? '',
                $cfg['base_url'] ?? 'https://api.openai.com/v1',
                $cfg['model'] ?? 'gpt-5-mini'
            );

            return new FileAttachmentService($provider);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register Exam observer
        \App\Models\Exam::observe(\App\Observers\ExamObserver::class);

        // Register ExamDocument observer for automatic document analysis
        \App\Models\ExamDocument::observe(\App\Observers\ExamDocumentObserver::class);

        // Register ExamExampleQuestion observer for automatic exam_structure recovery
        \App\Models\ExamExampleQuestion::observe(\App\Observers\ExamExampleQuestionObserver::class);
    }
}
