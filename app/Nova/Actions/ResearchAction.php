<?php

namespace App\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class ResearchAction extends Action
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function name()
    {
        return 'Run Exam Research';
    }

    public function handle(ActionFields $fields, $models)
    {
        $base = $this->resolveInternalApiBase();

        /** @var \App\Models\Exam $exam */
        foreach ($models as $exam) {
            $json = (string) ($fields->get('user_json') ?? '');
            $url = rtrim($base, '/')."/api/exams/{$exam->id}/research";

            $uploaded = $fields->get('document_upload');

            $http = Http::timeout(15)->retry(1, 150);

            if ($uploaded) {
                // UploadedFile или путь — поддержим оба
                $stream = null;
                $filename = 'document.bin';

                if ($uploaded instanceof UploadedFile) {
                    $stream = fopen($uploaded->getRealPath(), 'r');
                    $filename = $uploaded->getClientOriginalName() ?: $filename;
                } else {
                    $path = (string) $uploaded;
                    $absolute = base_path($path);
                    if (! is_file($absolute)) {
                        $absolute = storage_path('app/'.ltrim($path, '/'));
                    }
                    $stream = fopen($absolute, 'r');
                    $filename = basename($absolute) ?: $filename;
                }

                $response = $http
                    ->attach('document', $stream, $filename)
                    ->asMultipart()
                    ->post($url, [
                        'user_input' => $json !== '' ? $json : '{}',
                    ]);
            } else {
                $response = $http
                    ->asForm()
                    ->post($url, [
                        'user_input' => $json !== '' ? $json : '{}',
                    ]);
            }

            if (! $response->ok() && $response->status() !== 202) {
                return Action::danger('Failed: '.$response->body());
            }
        }

        return Action::message('✅ Research task started! 🔄 Refresh this page to see progress and results.');
    }

    public function fields(NovaRequest $request)
    {
        return [
            Textarea::make('User Input (JSON)', 'user_json')
                ->rules('nullable', 'string')
                ->help('Вставьте JSON с первичной информацией: {"language":"English","where":{"country":"PL","city":"Warsaw","modality":"online"},"target":{"level":"B2"},"exam_name":"IELTS"}'),

            File::make('Document (optional)', 'document_upload')
                ->help('Загрузите PDF/DOC/TXT. Если пусто — пойдём без документа.')
                ->rules('nullable', 'file', 'mimes:pdf,doc,docx,txt', 'max:10240'),
        ];
    }

    private function resolveInternalApiBase(): string
    {
        // 1) .env приоритетно
        $env = env('INTERNAL_API_BASE');
        if ($env && is_string($env)) {
            return rtrim($env, '/');
        }

        // 2) app.url — если localhost → подставим nginx (docker network)
        $appUrl = config('app.url', '');
        if (is_string($appUrl) && $appUrl !== '') {
            $parts = parse_url($appUrl);
            $host = $parts['host'] ?? '';
            if (in_array($host, ['localhost', '127.0.0.1'], true)) {
                return 'http://nginx';
            }

            return rtrim($appUrl, '/');
        }

        // 3) дефолт — nginx
        return 'http://nginx';
    }
}
