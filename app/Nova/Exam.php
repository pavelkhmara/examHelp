<?php

namespace App\Nova;

use App\Nova\Actions\ConfirmIdentityAction;
use App\Nova\Actions\ProvideAnswersAction;
use App\Nova\Actions\ResearchAction;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class Exam extends Resource
{
    public static $model = \App\Models\Exam::class;

    public static $title = 'title';

    public static $search = ['id', 'slug', 'title'];

    public static function label()
    {
        return 'Exams';
    }

    public static function singularLabel()
    {
        return 'Exam';
    }

    public static $group = 'Language App';

    public static function indexQuery(NovaRequest $request, $query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public static function refreshInterval()
    {
        // NOTE: This only works for INDEX page, NOT detail page
        // Users must manually refresh detail page to see updates
        return 5; // seconds - auto-refresh index page
    }

    public function fields(NovaRequest $request)
    {
        $fields = [
            ID::make()->sortable()->hideFromIndex(),
            Text::make('ID', 'id')->onlyOnIndex(),

            // Явный индикатор когда идет обработка (только на detail page)
            // Badge::make('⚠️ Processing', function () {
            //     return in_array($this->research_status, ['queued', 'running', 'running_overview'], true)
            //         ? 'REFRESH PAGE TO SEE UPDATES'
            //         : null;
            // })
            //     ->map(['REFRESH PAGE TO SEE UPDATES' => 'warning'])
            //     ->onlyOnDetail()
            //     ->exceptOnForms(),
            // ->hideWhenNull(),

            Text::make('Slug')->rules('required')->sortable(),
            Text::make('Title')->rules('required')->sortable(),
            Select::make('Level')->options([
                'A1' => 'A1', 'A2' => 'A2', 'B1' => 'B1', 'B2' => 'B2', 'C1' => 'C1', 'C2' => 'C2',
            ])->displayUsingLabels()->sortable(),
            Boolean::make('Is Active'),
            Badge::make('Research Status', 'research_status')
                ->map([
                    'queued' => 'info',
                    'running' => 'warning',
                    'running_overview' => 'warning',
                    'completed' => 'success',
                    'failed' => 'danger',
                ])
                ->labels([
                    'queued' => 'Queued',
                    'running' => 'Running',
                    'running_overview' => 'In Progress',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                ])
                ->sortable()
                ->help(function () {
                    if (in_array($this->research_status, ['queued', 'running', 'running_overview'], true)) {
                        return '🔄 <strong>Task is processing. Refresh this page to see updates.</strong>';
                    }

                    return null;
                }),
        ];

        // Показываем счетчики только если есть данные
        if ($this->categories_count > 0) {
            $fields[] = Number::make('Categories', 'categories_count')->onlyOnIndex();
        }
        if ($this->examples_count > 0) {
            $fields[] = Number::make('Examples', 'examples_count')->onlyOnIndex();
        }

        return array_merge($fields, $this->getConditionalFields());
    }

    /**
     * Условные поля в зависимости от стадии исследования
     */
    private function getConditionalFields(): array
    {
        $fields = [];
        $task = $this->generationTasks()->latest()->first();
        $identity = $task ? ($task->result['identity'] ?? null) : null;

        // ============== STAGE 1: Identity Verification ==============
        if ($task && $identity) {
            $fields[] = new Panel('🔍 Stage 1: Identity Verification', $this->buildIdentityFields($task, $identity));

            // Показываем вопросы, если они есть
            if (! empty($identity['followups']) || ! empty($identity['need_fields'])) {
                $fields[] = $this->buildFollowupPanel($identity, $task);
            }

            // Показываем "Why We Trust" только если identity есть
            $fields[] = new Panel('📋 Trust Reasons', [
                Code::make('Why We Trust This Identity')
                    ->resolveUsing(function () use ($identity) {
                        return $this->buildTrustReasons($identity);
                    })
                    ->onlyOnDetail(),
            ]);
        }

        // ============== STAGE 2: Overview & Structure ==============
        if ($this->research_status === 'completed' && ! empty($this->structure_sections)) {
            $fields[] = new Panel('📚 Stage 2: Exam Structure', [
                Number::make('Categories Count', 'categories_count')->onlyOnDetail(),
                Number::make('Total Exam Duration (min)', 'total_exam_duration')->onlyOnDetail(),
                Number::make('Total Tokens Used', function () {
                    $sum = $this->generationLogs()->sum('total_tokens');

                    return $sum ? (int) $sum : 0;
                })
                    ->onlyOnDetail(),

                Code::make('Sections (compact)')
                    ->resolveUsing(function () {
                        $sections = $this->structure_sections ?? [];
                        $compact = array_map(function ($s) {
                            return [
                                'name' => $s['name'] ?? $s['key'] ?? '',
                                'order' => $s['order'] ?? null,
                                'steps' => array_map(fn ($st) => [
                                    'name' => $st['name'] ?? '',
                                    'order' => $st['order'] ?? null,
                                    'duration_min' => $st['duration_min'] ?? null,
                                ], $s['steps'] ?? []),
                            ];
                        }, $sections);

                        return json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    })
                    ->json()
                    ->onlyOnDetail(),

                Code::make('Full Structure JSON')
                    ->json(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    ->resolveUsing(fn () => json_encode($this->exam_structure ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
                    ->onlyOnDetail(),
            ]);
        }

        // ============== STAGE 3: Categories ==============
        // Always show, even if count is 0 - Nova will show empty state
        $fields[] = HasMany::make('Categories', 'categories', ExamCategory::class);

        // ============== STAGE 4: Examples ==============
        // Always show, even if count is 0 - Nova will show empty state
        $fields[] = HasMany::make('Examples', 'examples', ExamExampleQuestion::class);

        // ============== Sources (если есть) ==============
        if (! empty($this->sources)) {
            $fields[] = new Panel('📚 Sources', [
                Code::make('Sources JSON')
                    ->json(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    ->resolveUsing(fn () => json_encode($this->sources ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
                    ->onlyOnDetail(),
            ]);
        }

        // ============== Generation Logs (всегда показываем, если есть) ==============
        $fields[] = new Panel('🔧 Generation Pipeline', [
            HasMany::make('Generation Tasks', 'generationTasks', \App\Nova\GenerationTask::class),
            HasMany::make('Generation Logs', 'generationLogs', \App\Nova\GenerationLog::class),
        ]);

        return $fields;
    }

    /**
     * Построить поля Identity Verification
     */
    private function buildIdentityFields($task, $identity): array
    {
        $fields = [
            Badge::make('Task Status')
                ->resolveUsing(fn () => $task->status)
                ->map([
                    'queued' => 'info',
                    'running' => 'warning',
                    'pending_confirmation' => 'warning',
                    'pending_clarification' => 'warning',
                    'completed' => 'success',
                    'failed' => 'danger',
                ])
                ->labels([
                    'queued' => 'Queued',
                    'running' => 'Running',
                    'pending_confirmation' => '⏸ Waiting for Confirmation',
                    'pending_clarification' => '⏸ Needs Clarification',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                ])
                ->onlyOnDetail(),

            Badge::make('Identity Status')
                ->resolveUsing(fn () => $identity['status'] ?? 'unknown')
                ->map([
                    'certain' => 'success',
                    'uncertain' => 'warning',
                    'unknown' => 'danger',
                ])
                ->labels([
                    'certain' => '✓ Certain',
                    'uncertain' => '? Uncertain',
                    'unknown' => 'Unknown',
                ])
                ->onlyOnDetail(),

            Badge::make('Confidence')
                ->resolveUsing(function () use ($identity) {
                    $conf = $identity['confidence'] ?? 0;
                    $percent = number_format($conf * 100, 0);

                    // Определяем тип на основе процента
                    if ($percent >= 90) {
                        $type = 'high';
                    } elseif ($percent >= 70) {
                        $type = 'medium-high';
                    } elseif ($percent >= 50) {
                        $type = 'medium';
                    } else {
                        $type = 'low';
                    }

                    return $type;
                })
                ->map([
                    'high' => 'success',
                    'medium-high' => 'info',
                    'medium' => 'warning',
                    'low' => 'danger',
                ])
                ->labels([
                    'high' => '90%+',
                    'medium-high' => '70-89%',
                    'medium' => '50-69%',
                    'low' => '0-49%',
                ])
                ->onlyOnDetail(),
        ];

        // Show user input (what user provided)
        if (! empty($task->request['user_input'])) {
            $fields[] = Code::make('User Input')
                ->resolveUsing(function () use ($task) {
                    return json_encode($task->request['user_input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                })
                ->json()
                ->onlyOnDetail()
                ->help('Information provided by the user');
        }

        // Show evidence from document
        if (! empty($identity['anchors'])) {
            $fields[] = Number::make('Evidence Count')
                ->resolveUsing(fn () => count($identity['anchors']))
                ->onlyOnDetail()
                ->help('Number of evidence anchors found in document');

            $fields[] = Code::make('Evidence Anchors (first 5)')
                ->resolveUsing(function () use ($identity) {
                    $anchors = array_slice($identity['anchors'], 0, 5);

                    return json_encode($anchors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                })
                ->json()
                ->onlyOnDetail()
                ->help('Key phrases and evidence found in the uploaded document');
        }

        // Show candidates if multiple options were considered
        if (! empty($identity['candidates']) && count($identity['candidates']) > 1) {
            $fields[] = Number::make('Candidates Considered')
                ->resolveUsing(fn () => count($identity['candidates']))
                ->onlyOnDetail()
                ->help('Number of possible exam matches AI considered');

            $fields[] = Code::make('All Candidates')
                ->resolveUsing(function () use ($identity) {
                    return json_encode($identity['candidates'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                })
                ->json()
                ->onlyOnDetail()
                ->help('All possible exam matches with their scores');
        }

        // Add disclaimer if auto-confirmed or auto-clarified
        if ($identity['auto_confirmed'] ?? false) {
            $fields[] = Badge::make('Auto-Confirmed')
                ->resolveUsing(fn () => '⚠️ AI Auto-Confirmed')
                ->map(['⚠️ AI Auto-Confirmed' => 'warning'])
                ->onlyOnDetail();

            $fields[] = Textarea::make('AI Disclaimer')
                ->resolveUsing(fn () => $identity['disclaimer'] ?? 'Identity auto-confirmed by AI without user confirmation')
                ->readonly()
                ->rows(2)
                ->onlyOnDetail()
                ->help('This exam structure was generated without user confirmation');
        }

        if ($identity['auto_clarified'] ?? false) {
            $fields[] = Badge::make('Auto-Clarified')
                ->resolveUsing(fn () => '⚠️ AI Auto-Clarified')
                ->map(['⚠️ AI Auto-Clarified' => 'warning'])
                ->onlyOnDetail();

            $fields[] = Textarea::make('AI Disclaimer')
                ->resolveUsing(fn () => $identity['disclaimer'] ?? 'Missing information was inferred by AI')
                ->readonly()
                ->rows(2)
                ->onlyOnDetail()
                ->help('AI made inferences to fill missing data');

            if (! empty($identity['auto_clarified_data'])) {
                $fields[] = Code::make('AI-Inferred Data')
                    ->resolveUsing(fn () => json_encode($identity['auto_clarified_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                    ->json()
                    ->onlyOnDetail()
                    ->help('Data that was automatically inferred by AI');
            }

            if (! empty($identity['ai_reasoning'])) {
                $fields[] = Textarea::make('AI Reasoning')
                    ->resolveUsing(fn () => $identity['ai_reasoning'])
                    ->readonly()
                    ->rows(4)
                    ->onlyOnDetail();
            }
        }

        $fields[] = Code::make('Identified Exam')
            ->resolveUsing(function () use ($identity) {
                return json_encode($identity['canonical'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            })
            ->json()
            ->onlyOnDetail();

        return $fields;
    }

    /**
     * Построить панель с вопросами для пользователя
     */
    private function buildFollowupPanel($identity, $task): Panel
    {
        return new Panel('❓ Questions from AI', [
            Textarea::make('Missing Fields')
                ->resolveUsing(function () use ($identity) {
                    $fields = $identity['need_fields'] ?? [];

                    return ! empty($fields) ? implode("\n", array_map(fn ($f) => "• $f", $fields)) : 'None';
                })
                ->readonly()
                ->rows(3)
                ->onlyOnDetail(),

            Textarea::make('Follow-up Questions')
                ->resolveUsing(function () use ($identity) {
                    $followups = $identity['followups'] ?? [];

                    return ! empty($followups) ? implode("\n\n", array_map(fn ($q, $i) => ($i + 1).". $q", $followups, array_keys($followups))) : 'None';
                })
                ->readonly()
                ->rows(8)
                ->onlyOnDetail()
                ->help('AI needs more information. Use "Provide Answers" action to respond.'),

            Badge::make('Action Required')
                ->resolveUsing(function () use ($task) {
                    return in_array($task->status, ['pending_confirmation', 'pending_clarification'])
                        ? '⚠ Waiting for your response'
                        : '✓ No action needed';
                })
                ->map([
                    '⚠ Waiting for your response' => 'warning',
                    '✓ No action needed' => 'success',
                ])
                ->onlyOnDetail(),
        ]);
    }

    /**
     * Построить текст "Why We Trust"
     */
    private function buildTrustReasons($identity): string
    {
        $reasons = [];

        // Confidence level
        $conf = $identity['confidence'] ?? 0;
        if ($conf >= 0.97) {
            $reasons[] = "✓ VERY HIGH confidence score: {$conf}";
        } elseif ($conf >= 0.90) {
            $reasons[] = "~ HIGH confidence score: {$conf} (needs user confirmation)";
        } elseif ($conf >= 0.70) {
            $reasons[] = "~ MEDIUM confidence score: {$conf}";
        } else {
            $reasons[] = "✗ LOW confidence score: {$conf}";
        }

        // Confidence boost information
        if (isset($identity['confidence_boost'])) {
            $boost = $identity['confidence_boost'];
            $reasons[] = '';
            $reasons[] = 'CONFIDENCE BOOST APPLIED:';
            $reasons[] = "  Original: {$boost['original_confidence']}";
            $reasons[] = "  Boosted: {$boost['boosted_confidence']}";
            $reasons[] = "  Reason: {$boost['adjustment_reason']}";
            $reasons[] = "  Evidence Quality: {$boost['evidence_quality']}";

            $checks = $boost['checks_performed'] ?? [];
            $reasons[] = '';
            $reasons[] = 'CHECKS PERFORMED:';
            $reasons[] = '  Sections match: '.($checks['sections_match'] === true ? '✓ YES' : ($checks['sections_match'] === false ? '✗ NO' : '~ '.strtoupper((string) $checks['sections_match'])));
            $reasons[] = '  Timing match: '.($checks['timing_match'] === true ? '✓ YES' : ($checks['timing_match'] === false ? '✗ NO' : '~ '.strtoupper((string) $checks['timing_match'])));
            $reasons[] = '  Scoring match: '.($checks['scoring_match'] === true ? '✓ YES' : ($checks['scoring_match'] === false ? '✗ NO' : '~ '.strtoupper((string) $checks['scoring_match'])));
            $reasons[] = '  Signatures found: '.($checks['signatures_found'] ?? 0);

            if (! empty($checks['red_flags'])) {
                $reasons[] = '  Red flags: '.implode(', ', $checks['red_flags']);
            }
        }

        $reasons[] = '';

        // Status
        if (($identity['status'] ?? '') === 'certain') {
            $reasons[] = '✓ AI marked as CERTAIN';
        } else {
            $reasons[] = '✗ AI marked as UNCERTAIN';
        }

        // User confirmation
        if ($identity['user_confirmed'] ?? false) {
            $reasons[] = '✓ USER CONFIRMED';
            if (isset($identity['confirmation_notes'])) {
                $reasons[] = "  Notes: {$identity['confirmation_notes']}";
            }
        }

        if ($identity['user_rejected'] ?? false) {
            $reasons[] = '✗ USER REJECTED';
            if (isset($identity['rejection_notes'])) {
                $reasons[] = "  Notes: {$identity['rejection_notes']}";
            }
        }

        // Evidence anchors
        if (! empty($identity['anchors'])) {
            $reasons[] = '✓ '.count($identity['anchors']).' evidence anchors from document';
        }

        // Missing fields
        if (! empty($identity['need_fields'])) {
            $reasons[] = '⚠ Missing required fields: '.implode(', ', $identity['need_fields']);
        }

        // Red flags
        if (! empty($identity['red_flags'])) {
            $reasons[] = '⚠ RED FLAGS: '.implode(', ', $identity['red_flags']);
        }

        return implode("\n", $reasons);
    }

    public function actions(NovaRequest $request)
    {
        return [
            new ResearchAction,
            new ConfirmIdentityAction,
            new ProvideAnswersAction,
        ];
    }

    public function filters(NovaRequest $request)
    {
        return [];
    }
}
