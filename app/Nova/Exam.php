<?php

namespace App\Nova;

use App\Nova\Actions\ConfirmIdentityAction;
use App\Nova\Actions\ProvideAnswersAction;
use App\Nova\Actions\ResearchAction;
use App\Nova\Fields\CollapsiblePanel;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Illuminate\Support\Facades\Log;

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

    /**
     * Determine if the user can update the resource.
     */
    public function authorizedToUpdate($request)
    {
        Log::info('[Exam Resource] authorizedToUpdate called', [
            'user' => $request->user()->email ?? 'no-user',
            'exam' => $this->resource->id ?? 'no-exam',
            'result' => true,
        ]);
        return true;
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

            // Full Exam View Link
            Text::make('Full Exam View', function () {
                $url = '/nova/resources/exam-full-view/' . $this->id;
                return '<a href="' . $url . '" style="
                    display: inline-flex;
                    align-items: center;
                    padding: 0.5rem 1rem;
                    background-color: #2563eb;
                    color: white;
                    font-weight: 600;
                    border-radius: 0.5rem;
                    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
                    text-decoration: none;
                    transition: all 0.15s ease-in-out;
                " onmouseover="this.style.backgroundColor=\'#1d4ed8\'" onmouseout="this.style.backgroundColor=\'#2563eb\'">
                    <svg style="width: 1.25rem; height: 1.25rem; margin-right: 0.5rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    View Full Exam Preview
                </a>';
            })
                ->asHtml()
                ->onlyOnDetail()
                ->help('View complete exam with all sections and questions'),


            // Phase B Assembly Config (читабельное отображение)
            CollapsiblePanel::make('🔧 Phase B: Assembly Config', 'phase_b_assembly_html')
                ->heading('🔧 Phase B: Assembly Config')
                ->content($this->buildPhaseBAssemblyHtml())
                ->collapsed(true)
                ->onlyOnDetail()
                ->help('Human-readable view of assembly configurations from Phase B'),

            // Generation Plans
            CollapsiblePanel::make('📋 Generation Plans', 'generation_plans_html')
                ->heading('📋 Generation Plans')
                ->content($this->buildGenerationPlansHtml())
                ->collapsed(true)
                ->onlyOnDetail()
                ->help('Plans for question generation based on assembly configs'),

            // Text::make('ID', 'id')->onlyOnIndex(),

            // === CREATION FORM FIELDS ===
            // Title (required)
            Text::make('Title')
                ->rules('required')
                ->sortable()
                ->displayUsing(function ($value) {
                    return \Illuminate\Support\Str::limit($value, 50);
                })
                ->help('Name of the exam'),

            // Is Active & Level in one row
            Boolean::make('Is Active')
                ->default(true)
                ->help('Active exams are visible to users')
                ->hideFromIndex(),

            Select::make('Level')
                ->options([
                    'A1' => 'A1', 'A2' => 'A2', 'B1' => 'B1', 'B2' => 'B2', 'C1' => 'C1', 'C2' => 'C2',
                ])
                ->default('B1')
                ->displayUsingLabels()
                ->sortable()
                ->help('CEFR level of the exam'),

            // Exam Family (from family_ruleset.json)
            Select::make('Exam Family', 'identity->exam_family')
                ->options($this->getExamFamilyOptions())
                ->displayUsingLabels()
                ->nullable()
                ->hideFromIndex()
                ->help('Select the exam family from our database, or choose "Unknown"'),

            // User Input
            Textarea::make('User Input', 'user_input')
                ->nullable()
                ->rows(6)
                ->hideFromIndex()
                ->help('Strictly user information as-is, including in user\'s language. Can be plain text or JSON.'),

            // Single document upload (for multiple documents, use the "Documents" relationship below)
            File::make('Upload Document', 'document_upload')
                ->disk('local')
                ->path('documents')
                ->nullable()
                ->onlyOnForms()
                ->help('Upload a single exam document (max 10MB). Supported: PDF, DOCX, TXT, ZIP. ZIP files will be automatically extracted.')
                ->acceptedTypes('.pdf,.doc,.docx,.txt,.zip')
                ->prunable(),

            // Provider
            Text::make('Provider', 'identity->provider')
                ->nullable()
                ->hideFromIndex()
                ->help('Exam provider (e.g., British Council, ETS, Goethe-Institut)'),

            // Exam Code
            Text::make('Exam Code', 'identity->exam_code')
                ->nullable()
                ->hideFromIndex()
                ->help('Official exam code for identification'),

            // Slug (auto-generated if empty)
            Text::make('Slug')
                ->nullable()
                ->sortable()
                ->creationRules('max:20')
                ->help('Leave empty to auto-generate (max 20 characters)')
                ->hideFromIndex(),

            // Description
            Textarea::make('Description')
                ->nullable()
                ->rows(4)
                ->hideFromIndex()
                ->help('Optional description of the exam'),

            // === STATUS BADGES (shown on index and detail) ===
            // Badge::make('Analysis Status', 'analysis_status')
            //     ->map([
            //         'pending' => 'info',
            //         'running' => 'warning',
            //         'completed' => 'success',
            //         'failed' => 'danger',
            //     ])
            //     ->labels([
            //         'pending' => 'Analyzing...',
            //         'running' => 'In Progress',
            //         'completed' => 'Analyzed',
            //         'failed' => 'Failed',
            //     ])
            //     ->sortable()
            //     ->exceptOnForms()
            //     ->help(function () {
            //         if ($this->analysis_status === 'running') {
            //             return '🔄 <strong>AI is analyzing metadata. Refresh to see updates.</strong>';
            //         }
            //         return null;
            //     }),

            Badge::make('Analysis Status', 'analysis_status')
                ->resolveUsing(fn ($value) => $value ?? 'pending')
                ->map([
                    'pending' => 'info',
                    'running' => 'warning',
                    'completed' => 'success',
                    'failed' => 'danger',
                ])
                ->labels([
                    'pending' => 'Pending',
                    'running' => 'Running',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                ])
                ->sortable()
                ->help(function () {
                    if ($this->analysis_status === 'running') {
                        return '⏳ <strong>Metadata analysis in progress. Please wait before starting research.</strong>';
                    }
                    if ($this->analysis_status === 'failed') {
                        return '❌ <strong>Metadata analysis failed. Check logs for details.</strong>';
                    }

                    return null;
                }),

            Badge::make('Research Status', 'research_status')
                ->resolveUsing(fn ($value) => $value ?? 'queued')
                ->map([
                    'queued' => 'info',
                    'running' => 'warning',
                    'running_overview' => 'warning',
                    'running_phase_a' => 'warning',
                    'phase_a_completed' => 'info',
                    'running_phase_b' => 'warning',
                    'phase_b_completed' => 'info',
                    'running_categories' => 'warning',
                    'running_examples' => 'warning',
                    'running_rubrics' => 'warning',
                    'completed' => 'success',
                    'failed' => 'danger',
                    'need_info' => 'warning',
                    'pending_clarification' => 'warning',
                ])
                ->labels([
                    'queued' => 'Queued',
                    'running' => 'Running',
                    'running_overview' => 'In Progress',
                    'running_phase_a' => 'Running Phase A',
                    'phase_a_completed' => 'Phase A Done',
                    'running_phase_b' => 'Running Phase B',
                    'phase_b_completed' => 'Phase B Done',
                    'running_categories' => 'Running Categories',
                    'running_examples' => 'Running Examples',
                    'running_rubrics' => 'Running Rubrics',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                    'need_info' => 'Need Info',
                    'pending_clarification' => 'Pending Clarification',
                ])
                ->sortable()
                ->help(function () {
                    if (in_array($this->research_status, ['queued', 'running', 'running_overview', 'running_phase_a', 'running_phase_b', 'running_categories', 'running_examples', 'running_rubrics'], true)) {
                        return '🔄 <strong>Task is processing. Refresh this page to see updates.</strong>';
                    }
                    if (in_array($this->research_status, ['phase_a_completed', 'phase_b_completed'], true)) {
                        return '✅ <strong>Phase completed. Run the next action to continue.</strong>';
                    }
                    if ($this->research_status === 'need_info') {
                        return '⚠️ <strong>Additional information required. Please review Quick Check panel.</strong>';
                    }
                    if ($this->research_status === 'pending_clarification') {
                        return '❓ <strong>Awaiting your response to clarification questions. Please answer the questions in the card below.</strong>';
                    }

                    return null;
                }),

            Text::make('Created At', 'created_at')
                ->displayUsing(function ($value) {
                    return $value ? $value->format('Y-m-d H:i') : null;
                })
                ->onlyOnIndex(),

            Number::make('Categories', 'categories_count')->default(0)->onlyOnIndex(),
            Number::make('Examples', 'examples_count')->default(0)->onlyOnIndex(),
        ];


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

        // ============== Quick Check for Identity ==============
        // Показываем, если исследование еще не завершено
        if ($this->research_status !== 'completed') {
            // Refresh model to get latest identity data from Identity stage
            $this->resource->refresh();

            $quickCheckService = app(\App\Services\LanguageApp\QuickCheckService::class);
            $checkResult = $quickCheckService->check($this->resource);

            $fields[] = new Panel('✅ Quick Check for Identity', [
                \Laravel\Nova\Fields\Text::make('Readiness Check', 'quick_check_html')
                    ->asHtml()
                    ->resolveUsing(fn() => $quickCheckService->getChecklistHtml($checkResult))
                    ->onlyOnDetail()
                    ->help(
                        $checkResult['ready']
                            ? '✅ Ready to run research'
                            : '⚠️ ' . $quickCheckService->getMissingFieldsMessage($checkResult)
                    ),
            ]);
        }

        // ============== STAGE 0: Initial Metadata Analysis ==============
        if ($this->analysis_status === 'completed' && (!empty($this->user_meta) || !empty($this->system_analysis))) {
            $fields[] = CollapsiblePanel::make('🤖 Initial Metadata Analysis', 'metadata_analysis_html')
                ->heading('🤖 Initial Metadata Analysis')
                ->content($this->buildMetadataAnalysisHtml())
                ->collapsed(true)
                ->onlyOnDetail();
        }

        // Documents relationship
        $fields[] = HasMany::make('Documents', 'documents', ExamDocument::class);

        // ============== STAGE 1: Identity Verification ==============
        if ($task && $identity) {
            // System Analysis Panel (collapsible) - shows anchors and enrichments
            $systemAnalysisHtml = $this->buildSystemAnalysisHtml($identity, $task);
            if ($systemAnalysisHtml) {
                $fields[] = CollapsiblePanel::make('🤖 System Analysis', 'system_analysis_html')
                    ->heading('🤖 System Analysis')
                    ->content($systemAnalysisHtml)
                    ->collapsed(true)
                    ->onlyOnDetail();
            }

            $identityFields = $this->buildIdentityFields($task, $identity);

            // Add issues/warnings/red_flags if present
            $issuesHtml = $this->buildIssuesHtml($identity, 'identity');
            if ($issuesHtml) {
                array_unshift($identityFields,
                    \Laravel\Nova\Fields\Text::make('Issues & Warnings', 'identity_issues')
                        ->asHtml()
                        ->resolveUsing(fn() => $issuesHtml)
                        ->onlyOnDetail()
                );
            }

            $fields[] = new Panel('🔍 Stage 1: Identity Verification', $identityFields);

            // Показываем вопросы, если они есть И research еще не завершен
            $hasQuestions = ! empty($identity['followups']) || ! empty($identity['need_fields']);
            $researchNotCompleted = $this->research_status !== 'completed';

            if ($hasQuestions && $researchNotCompleted) {
                $fields[] = $this->buildFollowupPanel($identity, $task);
            }

            // Показываем "Why We Trust" только если identity есть
            // $fields[] = new Panel('📋 Trust Reasons', [
            //     Code::make('Why We Trust This Identity')
            //         ->resolveUsing(function () use ($identity) {
            //             return $this->buildTrustReasons($identity);
            //         })
            //         ->onlyOnDetail(),
            // ]);
        }

        // ============== STAGE 2: Overview & Structure ==============
        if ($this->research_status === 'completed' && ! empty($this->structure_sections)) {
            $structureFields = [
                Number::make('Categories Count', 'categories_count')->onlyOnDetail(),
                Number::make('Total Exam Duration (min)', 'total_exam_duration')->onlyOnDetail(),
                Number::make('Total Tokens Used', function () {
                    $sum = $this->generationLogs()->sum('total_tokens');

                    return $sum ? (int) $sum : 0;
                })
                    ->onlyOnDetail(),

                Textarea::make('Structure Summary')
                    ->resolveUsing(function () {
                        return $this->buildStructureSummary();
                    })
                    ->readonly()
                    ->rows(15)
                    ->onlyOnDetail()
                    ->help('Human-readable summary of exam structure'),
                    
                Code::make('Structure V2', function () {
                    return json_encode($this->structure_v2 ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                })
                    ->language('json')
                    ->onlyOnDetail()
                    ->help('Exam skeleton/assembly (v2)'),
                    
                Code::make('Sections (compact)')
                    ->resolveUsing(function () {
                        $sections = $this->structure_sections ?? [];
                        $compact = array_map(function ($s) {
                            // V2 uses 'title', V1 uses 'name'
                            $sectionName = $s['title'] ?? $s['name'] ?? $s['key'] ?? '';
                            // V2 uses 'question_archetypes' or 'questions', V1 uses 'tasks' or 'steps'
                            $steps = $s['question_archetypes'] ?? $s['questions'] ?? $s['tasks'] ?? $s['steps'] ?? [];

                            return [
                                'name' => $sectionName,
                                'order' => $s['order'] ?? null,
                                'steps' => array_map(fn ($st) => [
                                    'name' => $st['name'] ?? '',
                                    'order' => $st['order'] ?? null,
                                    'duration_min' => $st['duration_min'] ?? null,
                                ], $steps),
                            ];
                        }, $sections);

                        return json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    })
                    ->json()
                    ->onlyOnDetail(),

                // Code::make('Full Structure JSON')
                //     ->json(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                //     ->resolveUsing(fn () => json_encode($this->exam_structure ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                //     ->onlyOnDetail(),
            ];

            // Add issues/warnings/notes if present in exam_structure
            $structureIssuesHtml = $this->buildIssuesHtml($this->exam_structure ?? [], 'structure');
            if ($structureIssuesHtml) {
                array_unshift($structureFields,
                    \Laravel\Nova\Fields\Text::make('Issues & Notes', 'structure_issues')
                        ->asHtml()
                        ->resolveUsing(fn() => $structureIssuesHtml)
                        ->onlyOnDetail()
                );
            }

            $fields[] = new Panel('📚 Stage 2: Exam Structure', $structureFields);
        }

        // ============== STAGE 3: Categories ==============
        // Always show, even if count is 0 - Nova will show empty state
        $fields[] = HasMany::make('Categories', 'categories', ExamCategory::class);
        $fields[] = HasMany::make('Question Groups', 'questionGroups', QuestionGroup::class);
        $fields[] = HasMany::make('Questions', 'questions', \App\Nova\Question::class);

        // ============== STAGE 4: Examples ==============
        // Always show, even if count is 0 - Nova will show empty state
        $fields[] = HasMany::make('Examples', 'examples', ExamExampleQuestion::class);

        // ============== Sources (если есть) ==============
        if (! empty($this->sources)) {
            $fields[] = CollapsiblePanel::make('📚 Sources', 'sources_panel_html')
                ->heading('📚 Sources')
                ->content($this->buildSourcesHtml())
                ->collapsed(true)
                ->onlyOnDetail();
        }

        // ============== Activity Timeline (для running tasks) ==============
        $runningTasks = $this->generationTasks()->where('status', 'running')->get();
        if ($runningTasks->isNotEmpty()) {
            $fields[] = new Panel('⏱️ Activity Timeline', [
                \Laravel\Nova\Fields\Text::make('Current Progress', 'current_progress_html')
                    ->asHtml()
                    ->resolveUsing(function () use ($runningTasks) {
                        $html = '';
                        foreach ($runningTasks as $task) {
                            $progress = $task->getCurrentProgress();
                            if ($progress) {
                                $html .= '<div style="font-weight: bold; margin-bottom: 10px;">' . htmlspecialchars($progress) . '</div>';
                            }

                            $formatted = $task->getFormattedActivities();
                            if (!empty($formatted)) {
                                $html .= '<div style="font-family: monospace; line-height: 1.6;">';
                                foreach ($formatted as $activity) {
                                    $html .= '<div>' . htmlspecialchars($activity['timestamp'] . ' ' . $activity['message']) . '</div>';
                                }
                                $html .= '</div>';
                            }
                        }
                        return $html ?: '<em>No activities yet</em>';
                    })
                    ->onlyOnDetail()
                    ->help('Real-time progress of running tasks'),
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
     * Build metadata analysis as HTML content for collapsible panel
     */
    private function buildMetadataAnalysisHtml(): string
    {
        $html = '<div class="space-y-4">';

        // Changes section (if any)
        if (!empty($this->system_analysis['changes'])) {
            $changes = $this->system_analysis['changes'];
            $html .= '<div class="mb-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded">';
            $html .= '<h4 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-3">🔄 Changes Detected</h4>';
            $html .= '<div class="space-y-2">';

            foreach ($changes as $change) {
                $field = $change['field'] ?? '';
                $label = $change['label'] ?? $field;
                $type = $change['type'] ?? 'unknown';
                $oldValue = $change['old_value'] ?? null;
                $newValue = $change['new_value'] ?? null;

                if ($type === 'added') {
                    $html .= '<div class="text-sm text-blue-700 dark:text-blue-300">';
                    $html .= '<span class="font-semibold">➕ ' . htmlspecialchars($label) . ':</span> ';
                    $html .= 'set to <span class="font-mono bg-blue-100 dark:bg-blue-900 px-1 rounded">' . htmlspecialchars($this->formatValueForDisplay($newValue)) . '</span>';
                    $html .= '</div>';
                } elseif ($type === 'changed') {
                    $html .= '<div class="text-sm text-blue-700 dark:text-blue-300">';
                    $html .= '<span class="font-semibold">🔄 ' . htmlspecialchars($label) . ':</span> ';
                    $html .= 'changed from <span class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded line-through">' . htmlspecialchars($this->formatValueForDisplay($oldValue)) . '</span> ';
                    $html .= 'to <span class="font-mono bg-blue-100 dark:bg-blue-900 px-1 rounded">' . htmlspecialchars($this->formatValueForDisplay($newValue)) . '</span>';
                    $html .= '</div>';
                } elseif ($type === 'removed') {
                    $html .= '<div class="text-sm text-yellow-700 dark:text-yellow-300">';
                    $html .= '<span class="font-semibold">➖ ' . htmlspecialchars($label) . ':</span> ';
                    $html .= 'removed (was: <span class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">' . htmlspecialchars($this->formatValueForDisplay($oldValue)) . '</span>)';
                    $html .= '</div>';
                }
            }

            $html .= '</div></div>';
        }

        // User Meta
        if (!empty($this->user_meta)) {
            $userMeta = $this->user_meta;
            $html .= '<div class="border-b border-gray-200 dark:border-gray-700 pb-4">';
            $html .= '<h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3">User Information</h4>';
            $html .= '<dl class="grid grid-cols-1 gap-3">';

            if (isset($userMeta['user_language'])) {
                $html .= '<div><dt class="text-sm font-medium text-gray-500 dark:text-gray-400">User Language:</dt>';
                $html .= '<dd class="text-sm text-gray-900 dark:text-gray-100">' . htmlspecialchars($userMeta['user_language']) . '</dd></div>';
            }

            if (isset($userMeta['user_gender'])) {
                $html .= '<div><dt class="text-sm font-medium text-gray-500 dark:text-gray-400">User Gender:</dt>';
                $html .= '<dd class="text-sm text-gray-900 dark:text-gray-100">' . htmlspecialchars($userMeta['user_gender']) . '</dd></div>';
            }

            if (isset($userMeta['exam_language'])) {
                $html .= '<div><dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Exam Language:</dt>';
                $html .= '<dd class="text-sm text-gray-900 dark:text-gray-100">' . htmlspecialchars($userMeta['exam_language']) . '</dd></div>';
            }

            if (isset($userMeta['target_score'])) {
                $html .= '<div><dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Target Score:</dt>';
                $html .= '<dd class="text-sm text-gray-900 dark:text-gray-100">' . htmlspecialchars($userMeta['target_score']) . '</dd></div>';
            }

            if (isset($userMeta['exam_purpose'])) {
                $html .= '<div><dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Exam Purpose:</dt>';
                $html .= '<dd class="text-sm text-gray-900 dark:text-gray-100">' . htmlspecialchars($userMeta['exam_purpose']) . '</dd></div>';
            }

            if (isset($userMeta['needs_certificate'])) {
                $needs = $userMeta['needs_certificate'] ? 'Yes' : 'No';
                $html .= '<div><dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Needs Certificate:</dt>';
                $html .= '<dd class="text-sm text-gray-900 dark:text-gray-100">' . $needs . '</dd></div>';
            }

            $html .= '</dl>';
            $html .= '<details class="mt-3"><summary class="text-sm text-gray-600 dark:text-gray-400 cursor-pointer hover:text-gray-900 dark:hover:text-gray-200">View Full JSON</summary>';
            $html .= '<pre class="mt-2 text-xs bg-gray-500 dark:bg-gray-900 text-white p-3 rounded overflow-auto">' . htmlspecialchars(json_encode($this->user_meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
            $html .= '</details></div>';
        }

        // System Analysis
        if (!empty($this->system_analysis)) {
            $analysis = $this->system_analysis;
            $html .= '<div class="pt-2">';
            $html .= '<h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3">System Analysis</h4>';

            // Warnings
            if (!empty($analysis['warning'])) {
                $html .= '<div class="mb-3 p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded">';
                $html .= '<p class="text-sm font-semibold text-yellow-800 dark:text-yellow-200 mb-2">⚠ Warnings (' . count($analysis['warning']) . '):</p>';
                $html .= '<ul class="list-disc list-inside text-sm text-yellow-700 dark:text-yellow-300 space-y-1">';
                foreach ($analysis['warning'] as $warning) {
                    $html .= '<li>' . htmlspecialchars($warning) . '</li>';
                }
                $html .= '</ul></div>';
            }

            // Critical issues
            if (!empty($analysis['critical'])) {
                $html .= '<div class="mb-3 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded">';
                $html .= '<p class="text-sm font-semibold text-red-800 dark:text-red-200 mb-2">❌ Critical Issues (' . count($analysis['critical']) . '):</p>';
                $html .= '<ul class="list-disc list-inside text-sm text-red-700 dark:text-red-300 space-y-1">';
                foreach ($analysis['critical'] as $critical) {
                    $html .= '<li>' . htmlspecialchars($critical) . '</li>';
                }
                $html .= '</ul></div>';
            }

            // Info
            if (!empty($analysis['info'])) {
                $html .= '<div class="mb-3 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded">';
                $html .= '<p class="text-sm font-semibold text-blue-800 dark:text-blue-200 mb-2">ℹ Information:</p>';
                $html .= '<ul class="list-disc list-inside text-sm text-blue-700 dark:text-blue-300 space-y-1">';
                foreach (array_slice($analysis['info'], 0, 5) as $info) {
                    $html .= '<li>' . htmlspecialchars($info) . '</li>';
                }
                if (count($analysis['info']) > 5) {
                    $html .= '<li class="text-xs italic">... and ' . (count($analysis['info']) - 5) . ' more</li>';
                }
                $html .= '</ul></div>';
            }

            $html .= '<details class="mt-3"><summary class="text-sm text-gray-600 dark:text-gray-400 cursor-pointer hover:text-gray-900 dark:hover:text-gray-200">View Full JSON</summary>';
            $html .= '<pre class="mt-2 text-xs bg-gray-500 dark:bg-gray-900 text-white p-3 rounded overflow-auto">' . htmlspecialchars(json_encode($this->system_analysis, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
            $html .= '</details></div>';
        }

        $html .= '</div>';
        return $html;
    }


    /**
     * Построить поля Identity Verification
     */
    private function buildIdentityFields($task, $identity): array
    {
        $fields = [
            Badge::make('Task Status')
                ->resolveUsing(fn () => $task->status ?? 'unknown')
                ->map([
                    'unknown' => 'info',
                    'queued' => 'info',
                    'running' => 'warning',
                    'pending_confirmation' => 'warning',
                    'pending_clarification' => 'warning',
                    'waiting_for_confirmation' => 'warning',
                    'completed' => 'success',
                    'failed' => 'danger',
                    'cancelled' => 'warning',
                ])
                ->labels([
                    'unknown' => 'Unknown',
                    'queued' => 'Queued',
                    'running' => 'Running',
                    'pending_confirmation' => '⏸ Waiting for Confirmation',
                    'pending_clarification' => '⏸ Needs Clarification',
                    'waiting_for_confirmation' => '⏸ Waiting for Confirmation',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                    'cancelled' => '🚫 Cancelled',
                ])
                ->onlyOnDetail(),

            Badge::make('Identity Status')
                ->resolveUsing(fn () => $identity['status'] ?? 'unknown')
                ->map([
                    'certain' => 'success',
                    'confirmed' => 'success',
                    'uncertain' => 'warning',
                    'needs_clarification' => 'warning',
                    'unknown' => 'danger',
                    'rejected' => 'danger',
                ])
                ->labels([
                    'certain' => '✓ Certain',
                    'confirmed' => '✓ Confirmed',
                    'uncertain' => '? Uncertain',
                    'needs_clarification' => '❓ Needs Clarification',
                    'unknown' => 'Unknown',
                    'rejected' => '❌ Rejected',
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
        // if (! empty($task->request['user_input'])) {
        //     $fields[] = Code::make('User Input')
        //         ->resolveUsing(function () use ($task) {
        //             return json_encode($task->request['user_input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        //         })
        //         ->json()
        //         ->onlyOnDetail()
        //         ->help('Information provided by the user');
        // }

        // Show evidence from document
        // if (! empty($identity['anchors'])) {
        //     $fields[] = Number::make('Evidence Count')
        //         ->resolveUsing(fn () => count($identity['anchors']))
        //         ->onlyOnDetail()
        //         ->help('Number of evidence anchors found in document');

        //     $fields[] = Code::make('Evidence Anchors (first 5)')
        //         ->resolveUsing(function () use ($identity) {
        //             $anchors = array_slice($identity['anchors'], 0, 5);

        //             return json_encode($anchors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        //         })
        //         ->json()
        //         ->onlyOnDetail()
        //         ->help('Key phrases and evidence found in the uploaded document');
        // }

        // Show candidates if multiple options were considered
        if (! empty($identity['candidates']) && count($identity['candidates']) > 1) {
            $fields[] = Number::make('Candidates Considered')
                ->resolveUsing(fn () => count($identity['candidates']))
                ->onlyOnDetail()
                ->help('Number of possible exam matches AI considered');

            $fields[] = Code::make('All Candidates')
                ->resolveUsing(function () use ($identity) {
                    return json_encode($identity['candidates'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                    ->resolveUsing(fn () => json_encode($identity['auto_clarified_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
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
                return json_encode($identity['canonical'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

                    if (empty($followups)) {
                        return 'None';
                    }

                    // Handle both formats: simple strings or arrays with 'q' and 'why' keys
                    $formatted = array_map(function ($q, $i) {
                        if (is_array($q)) {
                            // Format: ['q' => 'Question?', 'why' => 'Reason']
                            $question = $q['q'] ?? $q['question'] ?? 'Unknown question';
                            $reason = isset($q['why']) ? " (Reason: {$q['why']})" : '';
                            return ($i + 1).". {$question}{$reason}";
                        }
                        // Simple string format
                        return ($i + 1).". {$q}";
                    }, $followups, array_keys($followups));

                    return implode("\n\n", $formatted);
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
     * Build human-readable structure summary
     */
    private function buildStructureSummary(): string
    {
        $sections = $this->structure_sections ?? [];
        $examStructure = $this->exam_structure ?? [];

        if (empty($sections)) {
            return 'No structure information available';
        }

        $lines = [];

        // Header with exam title and level
        $lines[] = "📋 EXAM: {$this->title} ({$this->level})";
        $lines[] = str_repeat('=', 60);
        $lines[] = '';

        // Total duration
        if ($this->total_exam_duration) {
            $lines[] = "⏱ Total Duration: {$this->total_exam_duration} minutes";
            $lines[] = '';
        }

        // Scoring scale (if available)
        if (!empty($examStructure['scoring_scale'])) {
            $lines[] = "📊 Scoring: {$examStructure['scoring_scale']}";
            $lines[] = '';
        }

        // Categories summary
        $lines[] = "📚 SECTIONS: {$this->categories_count} total";
        $lines[] = str_repeat('-', 60);

        // Iterate through sections
        foreach ($sections as $idx => $section) {
            $sectionNum = $idx + 1;
            // V2 uses 'title', V1 uses 'name'
            $sectionName = $section['title'] ?? $section['name'] ?? $section['key'] ?? 'Unnamed Section';
            // V2 uses 'question_archetypes' or 'questions', V1 uses 'tasks' or 'steps'
            $steps = $section['question_archetypes'] ?? $section['questions'] ?? $section['tasks'] ?? $section['steps'] ?? [];
            $stepCount = count($steps);

            $lines[] = '';
            $lines[] = "{$sectionNum}. {$sectionName}";

            // Section duration (sum of all steps)
            $sectionDuration = 0;
            foreach ($steps as $step) {
                $sectionDuration += $step['duration_min'] ?? 0;
            }
            if ($sectionDuration > 0) {
                $lines[] = "   Duration: {$sectionDuration} min total";
            }

            $lines[] = "   Tasks: {$stepCount}";

            // List each step/archetype
            if (!empty($steps)) {
                $lines[] = '';
                foreach ($steps as $stepIdx => $step) {
                    $stepNum = $stepIdx + 1;
                    $stepName = $step['name'] ?? 'Unnamed Task';
                    $stepDuration = $step['duration_min'] ?? null;
                    $taskType = $step['task_type'] ?? null;

                    $stepLine = "   {$sectionNum}.{$stepNum}. {$stepName}";

                    $details = [];
                    if ($stepDuration) {
                        $details[] = "{$stepDuration} min";
                    }
                    if ($taskType) {
                        $details[] = "type: {$taskType}";
                    }

                    if (!empty($details)) {
                        $stepLine .= ' [' . implode(', ', $details) . ']';
                    }

                    $lines[] = $stepLine;

                    // Add description if available
                    if (!empty($step['description'])) {
                        $desc = $step['description'];
                        // Truncate long descriptions
                        if (strlen($desc) > 100) {
                            $desc = substr($desc, 0, 97) . '...';
                        }
                        $lines[] = "      → {$desc}";
                    }
                }
            }
        }

        $lines[] = '';
        $lines[] = str_repeat('=', 60);
        $lines[] = "✓ Structure extracted successfully";

        return implode("\n", $lines);
    }

    /**
     * Построить текст "Why We Trust"
     */
    private function buildTrustReasons($identity): string
    {
        $reasons = [];

        // Confidence level
        $conf = $identity['confidence'] ?? 0;
        if ($conf >= 0.90) {
            $reasons[] = "✓ VERY HIGH confidence score: {$conf}";
        } elseif ($conf >= 0.80) {
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

    /**
     * Build HTML for issues, warnings, red_flags, notes from AI response
     */
    private function buildIssuesHtml(array $data, string $context = 'general'): ?string
    {
        $hasContent = false;
        $html = '<div class="space-y-2">';

        // Red flags (critical issues)
        if (!empty($data['red_flags']) && is_array($data['red_flags'])) {
            $hasContent = true;
            $html .= '<div class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded">';
            $html .= '<p class="text-sm font-semibold text-red-800 dark:text-red-200 mb-2">🚩 Red Flags:</p>';
            $html .= '<ul class="list-disc list-inside text-sm text-red-700 dark:text-red-300 space-y-1">';
            foreach ($data['red_flags'] as $flag) {
                $html .= '<li>' . htmlspecialchars(is_string($flag) ? $flag : json_encode($flag)) . '</li>';
            }
            $html .= '</ul></div>';
        }

        // Issues
        if (!empty($data['issues']) && is_array($data['issues'])) {
            $hasContent = true;
            $html .= '<div class="p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded">';
            $html .= '<p class="text-sm font-semibold text-yellow-800 dark:text-yellow-200 mb-2">⚠ Issues:</p>';
            $html .= '<ul class="list-disc list-inside text-sm text-yellow-700 dark:text-yellow-300 space-y-1">';
            foreach ($data['issues'] as $issue) {
                $html .= '<li>' . htmlspecialchars(is_string($issue) ? $issue : json_encode($issue)) . '</li>';
            }
            $html .= '</ul></div>';
        }

        // Warnings
        if (!empty($data['warnings']) && is_array($data['warnings'])) {
            $hasContent = true;
            $html .= '<div class="p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded">';
            $html .= '<p class="text-sm font-semibold text-yellow-800 dark:text-yellow-200 mb-2">⚠ Warnings:</p>';
            $html .= '<ul class="list-disc list-inside text-sm text-yellow-700 dark:text-yellow-300 space-y-1">';
            foreach ($data['warnings'] as $warning) {
                $html .= '<li>' . htmlspecialchars(is_string($warning) ? $warning : json_encode($warning)) . '</li>';
            }
            $html .= '</ul></div>';
        }

        // Notes
        if (!empty($data['notes']) && is_array($data['notes'])) {
            $hasContent = true;
            $html .= '<div class="p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded">';
            $html .= '<p class="text-sm font-semibold text-blue-800 dark:text-blue-200 mb-2">📝 Notes:</p>';
            $html .= '<ul class="list-disc list-inside text-sm text-blue-700 dark:text-blue-300 space-y-1">';
            foreach ($data['notes'] as $note) {
                $html .= '<li>' . htmlspecialchars(is_string($note) ? $note : json_encode($note)) . '</li>';
            }
            $html .= '</ul></div>';
        }

        // Single note (string)
        if (!empty($data['note']) && is_string($data['note'])) {
            $hasContent = true;
            $html .= '<div class="p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded">';
            $html .= '<p class="text-sm text-blue-700 dark:text-blue-300">' . htmlspecialchars($data['note']) . '</p>';
            $html .= '</div>';
        }

        // Rationale (for structure)
        if ($context === 'structure' && !empty($data['rationale']) && is_string($data['rationale'])) {
            $hasContent = true;
            $html .= '<div class="p-3 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded">';
            $html .= '<p class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-2">📋 Rationale:</p>';
            $html .= '<p class="text-sm text-gray-700 dark:text-gray-300">' . nl2br(htmlspecialchars($data['rationale'])) . '</p>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $hasContent ? $html : null;
    }

    /**
     * Format a value for display in HTML
     */
    private function formatValueForDisplay($value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * Get exam family options from family_ruleset.json
     */
    private function getExamFamilyOptions(): array
    {
        $rulesetPath = config_path('family_ruleset.json');

        if (!file_exists($rulesetPath)) {
            return ['Unknown' => 'Unknown'];
        }

        $json = file_get_contents($rulesetPath);
        $data = json_decode($json, true);

        if (!isset($data['families'])) {
            return ['Unknown' => 'Unknown'];
        }

        $families = array_keys($data['families']);
        $options = array_combine($families, $families);

        // Add "Unknown" option at the beginning
        return ['Unknown' => 'Unknown'] + $options;
    }

    /**
     * Build HTML for System Analysis panel showing PDF facts and enrichments
     */
    private function buildSystemAnalysisHtml(array $identity, $task): ?string
    {
        $anchors = $identity['anchors'] ?? [];
        $userInput = $task->request['user_input'] ?? null;
        $documentHints = $task->request['document_hints'] ?? [];

        if (empty($anchors) && empty($userInput) && empty($documentHints)) {
            return null;
        }

        $html = '<div class="space-y-4">';

        // Section 1: Facts from PDF (Anchors)
        if (!empty($anchors)) {
            $html .= '<div class="border-b border-gray-200 dark:border-gray-700 pb-4">';
            $html .= '<h4 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">📄 Facts from Documents</h4>';
            $html .= '<div class="space-y-2">';

            foreach ($anchors as $idx => $anchor) {
                $phrase = $anchor['phrase'] ?? $anchor['text'] ?? 'Unknown';
                $source = $anchor['source'] ?? null;
                $page = $anchor['page'] ?? null;
                $confidence = $anchor['confidence'] ?? $anchor['score'] ?? null;

                // Confidence badge
                $confidenceBadge = '';
                if ($confidence !== null) {
                    $confClass = 'bg-gray-100 text-gray-700';
                    $confLabel = 'Unknown';

                    if (is_numeric($confidence)) {
                        if ($confidence >= 0.9) {
                            $confClass = 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300';
                            $confLabel = number_format($confidence * 100, 0) . '%';
                        } elseif ($confidence >= 0.7) {
                            $confClass = 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300';
                            $confLabel = number_format($confidence * 100, 0) . '%';
                        } else {
                            $confClass = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300';
                            $confLabel = number_format($confidence * 100, 0) . '%';
                        }
                    }

                    $confidenceBadge = "<span class=\"{$confClass} text-xs font-medium px-2 py-0.5 rounded ml-2\">{$confLabel}</span>";
                }

                $html .= '<div class="p-3 bg-gray-50 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700">';
                $html .= '<div class="flex items-start justify-between mb-1">';
                $html .= '<span class="text-xs font-medium text-gray-500 dark:text-gray-400">';
                if ($source) {
                    $html .= htmlspecialchars($source);
                }
                if ($page) {
                    $html .= $source ? ' · ' : '';
                    $html .= "Page {$page}";
                }
                if (!$source && !$page) {
                    $html .= 'Document';
                }
                $html .= '</span>';
                $html .= $confidenceBadge;
                $html .= '</div>';
                $html .= '<p class="text-sm text-gray-800 dark:text-gray-200">"' . htmlspecialchars($phrase) . '"</p>';
                $html .= '</div>';
            }

            $html .= '</div></div>';
        }

        // Section 2: Enrichments from User Input
        if ($userInput) {
            $html .= '<div class="border-b border-gray-200 dark:border-gray-700 pb-4">';
            $html .= '<h4 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">👤 User Input Enrichments</h4>';
            $html .= '<div class="p-3 bg-blue-50 dark:bg-blue-900/20 rounded border border-blue-200 dark:border-blue-800">';
            $html .= '<p class="text-sm text-blue-900 dark:text-blue-100 whitespace-pre-wrap">';
            $html .= htmlspecialchars(is_string($userInput) ? $userInput : json_encode($userInput, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $html .= '</p>';
            $html .= '</div></div>';
        }

        // Section 3: Document Hints Summary
        if (!empty($documentHints)) {
            $html .= '<div>';
            $html .= '<h4 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">📚 Document Hints</h4>';
            $html .= '<div class="space-y-2">';

            foreach ($documentHints as $hint) {
                $docName = $hint['filename'] ?? $hint['name'] ?? 'Unknown';
                $charCount = isset($hint['text']) ? mb_strlen($hint['text']) : 0;

                $html .= '<div class="flex items-center justify-between p-2 bg-gray-50 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700">';
                $html .= '<span class="text-sm text-gray-800 dark:text-gray-200">' . htmlspecialchars($docName) . '</span>';
                $html .= '<span class="text-xs text-gray-500 dark:text-gray-400">' . number_format($charCount) . ' chars</span>';
                $html .= '</div>';
            }

            $html .= '</div></div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Build HTML for sources panel with clickable links and trust badges
     */
    private function buildSourcesHtml(): string
    {
        $sources = $this->sources ?? [];

        if (empty($sources)) {
            return '<p class="text-sm text-gray-500">No sources available</p>';
        }

        $html = '<div class="space-y-4">';

        foreach ($sources as $idx => $source) {
            $sourceNum = $idx + 1;
            $url = $source['url'] ?? $source['link'] ?? null;
            $title = $source['title'] ?? $source['name'] ?? 'Untitled Source';
            $publisher = $source['publisher'] ?? null;
            $provenance = $source['provenance'] ?? null;
            $tier = $source['tier'] ?? null;
            $contribution = $source['contribution'] ?? null;
            $rationale = $source['rationale'] ?? $source['reason'] ?? null;
            $trustScore = $source['trust_score'] ?? $source['quality'] ?? null;
            $page = $source['page'] ?? null;
            $fragment = $source['fragment'] ?? $source['excerpt'] ?? null;

            // Determine trust badge color (based on tier if available, otherwise trust_score)
            $trustBadgeClass = 'bg-gray-100 text-gray-700';
            $trustLabel = 'Unknown';

            if ($tier !== null) {
                // New tier system: 1=official, 2=trusted, 3=supplementary
                if ($tier === 1 || $tier === '1') {
                    $trustBadgeClass = 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300';
                    $trustLabel = 'Tier 1: Official';
                } elseif ($tier === 2 || $tier === '2') {
                    $trustBadgeClass = 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300';
                    $trustLabel = 'Tier 2: Trusted';
                } elseif ($tier === 3 || $tier === '3') {
                    $trustBadgeClass = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300';
                    $trustLabel = 'Tier 3: Supplementary';
                }
            } elseif ($trustScore !== null) {
                // Legacy trust_score system
                if (is_numeric($trustScore)) {
                    if ($trustScore >= 0.9) {
                        $trustBadgeClass = 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300';
                        $trustLabel = 'High Trust';
                    } elseif ($trustScore >= 0.7) {
                        $trustBadgeClass = 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300';
                        $trustLabel = 'Medium Trust';
                    } elseif ($trustScore >= 0.5) {
                        $trustBadgeClass = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300';
                        $trustLabel = 'Low Trust';
                    } else {
                        $trustBadgeClass = 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300';
                        $trustLabel = 'Very Low Trust';
                    }
                } elseif (is_string($trustScore)) {
                    $trustLabel = ucfirst($trustScore);
                    if (stripos($trustScore, 'high') !== false) {
                        $trustBadgeClass = 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300';
                    } elseif (stripos($trustScore, 'medium') !== false) {
                        $trustBadgeClass = 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300';
                    } elseif (stripos($trustScore, 'low') !== false) {
                        $trustBadgeClass = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300';
                    }
                }
            } elseif ($provenance) {
                // Fallback to provenance-based badge
                if ($provenance === 'web') {
                    $trustBadgeClass = 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300';
                    $trustLabel = 'Web Source';
                } elseif ($provenance === 'document' || $provenance === 'file') {
                    $trustBadgeClass = 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300';
                    $trustLabel = 'Document';
                }
            }

            $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:shadow-md transition-shadow">';

            // Header with source number and trust badge
            $html .= '<div class="flex items-start justify-between mb-2">';
            $html .= '<h4 class="text-base font-semibold text-gray-900 dark:text-gray-100">';
            $html .= "Source #{$sourceNum}";
            if ($page) {
                $html .= " <span class=\"text-sm font-normal text-gray-500\">(Page {$page})</span>";
            }
            $html .= '</h4>';
            $html .= "<span class=\"{$trustBadgeClass} text-xs font-medium px-2.5 py-0.5 rounded\">{$trustLabel}</span>";
            $html .= '</div>';

            // Title with link
            if ($url) {
                $html .= '<div class="mb-2">';
                $html .= '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener noreferrer" ';
                $html .= 'class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 hover:underline text-sm font-medium">';
                $html .= htmlspecialchars($title);
                $html .= ' <span class="text-xs">↗</span></a>';
                $html .= '</div>';
            } else {
                $html .= '<div class="mb-2 text-sm font-medium text-gray-800 dark:text-gray-200">';
                $html .= htmlspecialchars($title);
                $html .= '</div>';
            }

            // Publisher
            if ($publisher) {
                $html .= '<div class="mb-2 text-xs text-gray-600 dark:text-gray-400">';
                $html .= '<span class="font-semibold">Publisher:</span> ' . htmlspecialchars($publisher);
                $html .= '</div>';
            }

            // Contribution (what was taken from this source)
            if ($contribution) {
                $html .= '<div class="mb-2 text-sm text-gray-700 dark:text-gray-300">';
                $html .= '<span class="font-semibold">Contribution:</span> ' . htmlspecialchars($contribution);
                $html .= '</div>';
            }

            // Rationale (legacy field, similar to contribution)
            if ($rationale) {
                $html .= '<div class="mb-2 text-sm text-gray-700 dark:text-gray-300 italic">';
                $html .= '"' . htmlspecialchars($rationale) . '"';
                $html .= '</div>';
            }

            // Fragment/excerpt
            if ($fragment) {
                $html .= '<details class="text-xs text-gray-600 dark:text-gray-400">';
                $html .= '<summary class="cursor-pointer hover:text-gray-900 dark:hover:text-gray-200">View excerpt</summary>';
                $html .= '<div class="mt-2 p-2 bg-gray-50 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700">';
                $html .= '<pre class="whitespace-pre-wrap font-mono">' . htmlspecialchars(mb_substr($fragment, 0, 300)) . '</pre>';
                if (mb_strlen($fragment) > 300) {
                    $html .= '<p class="text-xs italic mt-1">... (excerpt truncated)</p>';
                }
                $html .= '</div>';
                $html .= '</details>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        // Add full JSON at the bottom
        $html .= '<details class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">';
        $html .= '<summary class="text-sm text-gray-600 dark:text-gray-400 cursor-pointer hover:text-gray-900 dark:hover:text-gray-200">View Full JSON</summary>';
        $html .= '<pre class="mt-2 text-xs bg-gray-500 dark:bg-gray-900 text-white p-3 rounded overflow-auto">';
        $html .= htmlspecialchars(json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $html .= '</pre>';
        $html .= '</details>';

        return $html;
    }

    /**
     * Build HTML for Generation Plans panel
     */
    protected function buildGenerationPlansHtml(): string
    {
        $plans = \App\Models\GenerationPlan::where('exam_id', $this->id)->get();

        if ($plans->isEmpty()) {
            return '<div class="text-sm text-gray-500 dark:text-gray-400">No generation plans yet. Run "Resolve Generation Plans" action first.</div>';
        }

        $html = '<div class="space-y-4">';

        foreach ($plans as $plan) {
            $statusColor = match($plan->status) {
                'pending' => 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-300',
                'in_progress' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
                'completed' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
                'attached' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
                'failed' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
                default => 'bg-gray-100 text-gray-800',
            };

            $modeColor = match($plan->assembly_mode) {
                'pool' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300',
                'blueprint' => 'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300',
                'inline' => 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-300',
                default => 'bg-gray-100 text-gray-800',
            };

            $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">';

            // Header
            $html .= '<div class="flex items-start justify-between mb-3">';
            $html .= '<div>';
            $html .= '<h4 class="text-base font-semibold text-gray-900 dark:text-gray-100">';
            $html .= htmlspecialchars($plan->section_id);
            $html .= '</h4>';
            $html .= '<div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Plan ID: ' . $plan->id . '</div>';
            $html .= '</div>';
            $html .= '<div class="flex gap-2">';
            $html .= "<span class=\"{$modeColor} text-xs font-medium px-2.5 py-0.5 rounded\">" . strtoupper($plan->assembly_mode) . "</span>";
            $html .= "<span class=\"{$statusColor} text-xs font-medium px-2.5 py-0.5 rounded\">" . strtoupper($plan->status) . "</span>";
            $html .= '</div>';
            $html .= '</div>';

            // Questions count
            $html .= '<div class="mb-3">';
            $html .= '<span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Questions: </span>';
            $html .= '<span class="text-sm text-gray-900 dark:text-gray-100">';
            $html .= $plan->generated_questions . ' / ' . $plan->total_questions;

            if ($plan->total_questions > 0) {
                $percentage = round(($plan->generated_questions / $plan->total_questions) * 100);
                $html .= " ({$percentage}%)";

                if ($plan->generated_questions >= $plan->total_questions) {
                    $html .= ' <span class="text-green-600 dark:text-green-400">✓</span>';
                } elseif ($plan->generated_questions > 0) {
                    $html .= ' <span class="text-yellow-600 dark:text-yellow-400">⏳</span>';
                }
            }
            $html .= '</span>';
            $html .= '</div>';

            // Plan data summary
            $html .= '<div class="text-sm text-gray-700 dark:text-gray-300">';
            $html .= '<span class="font-semibold">Config: </span>';
            $html .= $this->formatPlanDataSummary($plan->assembly_mode, $plan->plan_data);
            $html .= '</div>';

            // Timestamps
            if ($plan->started_at || $plan->completed_at || $plan->attached_at) {
                $html .= '<div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400">';
                if ($plan->started_at) {
                    $html .= '<div>Started: ' . $plan->started_at->format('d.m.Y H:i') . '</div>';
                }
                if ($plan->completed_at) {
                    $html .= '<div>Completed: ' . $plan->completed_at->format('d.m.Y H:i') . '</div>';
                }
                if ($plan->attached_at) {
                    $html .= '<div>Attached: ' . $plan->attached_at->format('d.m.Y H:i') . '</div>';
                }
                $html .= '</div>';
            }

            // Error if any
            if ($plan->error) {
                $html .= '<div class="mt-3 p-2 bg-red-50 dark:bg-red-900/20 text-red-800 dark:text-red-300 text-sm rounded">';
                $html .= '<strong>Error:</strong> ' . htmlspecialchars($plan->error);
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Format plan_data into human-readable summary
     */
    protected function formatPlanDataSummary(string $mode, array $planData): string
    {
        switch ($mode) {
            case 'pool':
                $poolId = $planData['pool_id'] ?? 'unknown';
                $pick = $planData['pick'] ?? 0;
                $filters = $planData['filters'] ?? [];
                $summary = "Pool: {$poolId}, Pick: {$pick}";
                if (!empty($filters)) {
                    $filterKeys = implode(', ', array_keys($filters));
                    $summary .= ", Filters: {$filterKeys}";
                }
                return $summary;

            case 'blueprint':
                $slots = $planData['slots'] ?? [];
                $slotCount = count($slots);
                $totalPick = array_sum(array_map(fn($slot) => $slot['pick'] ?? 0, $slots));
                return "Blueprint: {$slotCount} slots, Total pick: {$totalPick}";

            case 'inline':
                $placeholders = $planData['placeholders'] ?? [];
                $count = count($placeholders);
                return "Inline: {$count} placeholders";

            default:
                return json_encode($planData, JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Build HTML for Phase B Assembly Config panel
     */
    protected function buildPhaseBAssemblyHtml(): string
    {
        $structure = $this->structure_v2;
        if (!$structure || empty($structure['sections'])) {
            return '<div class="text-sm text-gray-500 dark:text-gray-400">No Phase B assembly data yet.</div>';
        }

        $sections = $structure['sections'] ?? [];
        $phaseBSections = array_filter($sections, fn($section) => isset($section['assembly']));

        if (empty($phaseBSections)) {
            return '<div class="text-sm text-gray-500 dark:text-gray-400">Run Phase B to generate assembly configs.</div>';
        }

        $html = '<div class="space-y-4">';

        foreach ($phaseBSections as $section) {
            $assembly = $section['assembly'] ?? [];
            $mode = $assembly['mode'] ?? 'unknown';

            $modeColor = match($mode) {
                'pool' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300',
                'blueprint' => 'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300',
                'inline' => 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-300',
                default => 'bg-gray-100 text-gray-800',
            };

            $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">';

            // Header
            $html .= '<div class="flex items-start justify-between mb-3">';
            $html .= '<h4 class="text-base font-semibold text-gray-900 dark:text-gray-100">';
            $html .= htmlspecialchars($section['id'] ?? 'Unknown Section');
            $html .= ' <span class="text-sm font-normal text-gray-500">(' . ($section['skill'] ?? '') . ')</span>';
            $html .= '</h4>';
            $html .= "<span class=\"{$modeColor} text-xs font-medium px-2.5 py-0.5 rounded\">" . strtoupper($mode) . "</span>";
            $html .= '</div>';

            // Assembly details
            $html .= '<div class="text-sm text-gray-700 dark:text-gray-300 space-y-2">';
            $html .= $this->formatAssemblyDetails($mode, $assembly);
            $html .= '</div>';

            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Format assembly details into human-readable HTML
     */
    protected function formatAssemblyDetails(string $mode, array $assembly): string
    {
        $html = '';

        switch ($mode) {
            case 'pool':
                $poolId = $assembly['pool_id'] ?? 'unknown';
                $pick = $assembly['pick'] ?? 0;
                $filters = $assembly['filters'] ?? [];

                $html .= '<div><strong>Pool ID:</strong> ' . htmlspecialchars($poolId) . '</div>';
                $html .= '<div><strong>Pick:</strong> ' . $pick . ' questions</div>';

                if (!empty($filters)) {
                    $html .= '<div><strong>Filters:</strong></div>';
                    $html .= '<ul class="list-disc list-inside ml-4">';
                    foreach ($filters as $key => $value) {
                        $valueStr = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
                        $html .= '<li>' . htmlspecialchars($key) . ': ' . htmlspecialchars($valueStr) . '</li>';
                    }
                    $html .= '</ul>';
                }
                break;

            case 'blueprint':
                $slots = $assembly['blueprint'] ?? [];
                $html .= '<div><strong>Slots:</strong> ' . count($slots) . '</div>';

                if (!empty($slots)) {
                    $html .= '<div class="mt-2"><strong>Slot Details:</strong></div>';
                    $html .= '<ul class="list-disc list-inside ml-4">';
                    foreach ($slots as $idx => $slot) {
                        $slotNum = $idx + 1;
                        $pick = $slot['pick'] ?? 0;
                        $typesStr = isset($slot['types']) ? implode(', ', $slot['types']) : 'any';
                        $html .= "<li>Slot {$slotNum}: {$pick} questions, Types: {$typesStr}</li>";
                    }
                    $html .= '</ul>';
                }
                break;

            case 'inline':
                $placeholders = $assembly['placeholders'] ?? [];
                $html .= '<div><strong>Placeholders:</strong> ' . count($placeholders) . '</div>';

                if (!empty($placeholders)) {
                    $html .= '<div class="mt-2"><strong>Placeholder Details:</strong></div>';
                    $html .= '<ul class="list-disc list-inside ml-4">';
                    foreach ($placeholders as $idx => $placeholder) {
                        $phNum = $idx + 1;
                        $type = $placeholder['type'] ?? 'unknown';
                        $promptHint = $placeholder['prompt_hint'] ?? '';
                        $promptShort = mb_substr($promptHint, 0, 60);
                        $html .= "<li>#{$phNum}: Type {$type}";
                        if ($promptShort) {
                            $html .= " - " . htmlspecialchars($promptShort) . (mb_strlen($promptHint) > 60 ? '...' : '');
                        }
                        $html .= '</li>';
                    }
                    $html .= '</ul>';
                }
                break;

            default:
                $html .= '<pre class="text-xs bg-gray-100 dark:bg-gray-900 p-2 rounded overflow-auto">';
                $html .= htmlspecialchars(json_encode($assembly, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $html .= '</pre>';
        }

        return $html;
    }

    public function cards(NovaRequest $request)
    {
        // In Nova, cards() is called before model is loaded to this resource instance
        // So we need to get the model from the request
        if (!$request->resourceId) {
            return [];
        }

        $exam = \App\Models\Exam::find($request->resourceId);

        if (!$exam) {
            return [];
        }

        \Illuminate\Support\Facades\Log::info('Exam::cards() called', [
            'exam_id' => $exam->id,
            'request_resource_id' => $request->resourceId,
        ]);

        $cards = [];

        // ExamWorkflowHub - умная карточка-хаб (всегда первая, сверху)
        // Сама определяет режим отображения (missing_fields, pending_confirmation, status, etc.)
        $cards[] = (new \App\Nova\Cards\ExamWorkflowHub($exam->id))
            ->onlyOnDetail();

        // Add v2 cards if structure_v2 exists (ниже ExamWorkflowHub)
        if (!empty($exam->meta['structure_v2'])) {
            $cards[] = (new \App\Nova\Cards\OverviewStatusCard($exam))->width('1/2');
            $cards[] = (new \App\Nova\Cards\GenerationProgressCard($exam))->width('1/2');
        }

        return $cards;
    }

    public function actions(NovaRequest $request)
    {
        $actions = [
            // Full pipeline
            new ResearchAction,

            // Identity stage actions
            new ConfirmIdentityAction,
            new \App\Nova\Actions\ConfidenceBoostAction,
            new \App\Nova\Actions\RejectAllVariantsAction,

            // Structure generation (Phase A & B)
            (new \App\Nova\Actions\RunOverviewPhaseAAction())->onlyOnDetail(),
            (new \App\Nova\Actions\RunOverviewPhaseBAction())->onlyOnDetail(),

            // Examples generation
            (new \App\Nova\Actions\GenerateExamplesAction())->onlyOnDetail(),

            // Question synthesis pipeline
            (new \App\Nova\Actions\ResolveGenerationPlanAction())->onlyOnDetail(),
            (new \App\Nova\Actions\SynthesizeQuestionsAction())->onlyOnDetail(),
            (new \App\Nova\Actions\ValidateAttachQuestionsAction())->onlyOnDetail(),

            // Utility actions
            new \App\Nova\Actions\CancelStalledTaskAction,
        ];

        return $actions;
    }

    public function filters(NovaRequest $request)
    {
        return [];
    }

    /**
     * Handle single document upload after exam creation
     */
    public static function afterCreate(NovaRequest $request, $model)
    {
        static::handleSingleDocumentUpload($request, $model);
    }

    /**
     * Handle single document upload after exam update
     */
    public static function afterUpdate(NovaRequest $request, $model)
    {
        static::handleSingleDocumentUpload($request, $model);
    }

    /**
     * Process single uploaded document and create ExamDocument record
     */
    protected static function handleSingleDocumentUpload(NovaRequest $request, $model)
    {
        if (!$request->hasFile('document_upload')) {
            return;
        }

        $file = $request->file('document_upload');

        // Validate file size (10MB)
        if ($file->getSize() > 10 * 1024 * 1024) {
            \Illuminate\Support\Facades\Log::warning("File exceeds 10MB limit, skipping", [
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ]);
            return;
        }

        // Store file in documents directory
        $path = $file->store('documents', 'local');

        // Check if file is a ZIP archive
        $mimeType = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());
        $isZip = $mimeType === 'application/zip' || $extension === 'zip';

        if ($isZip) {
            // Dispatch ZIP extraction job
            \App\Jobs\UnzipAndAttachDocumentsJob::dispatch($model->id, $path, 'local');

            \Illuminate\Support\Facades\Log::info("ZIP file uploaded, dispatched extraction job", [
                'exam_id' => $model->id,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'size' => $file->getSize(),
            ]);
        } else {
            // Create ExamDocument record for non-ZIP files
            $document = $model->documents()->create([
                'original_name' => $file->getClientOriginalName(),
                'disk' => 'local',
                'path' => $path,
                'mime' => $mimeType,
                'size' => $file->getSize(),
                'status' => 'uploaded',
            ]);

            \Illuminate\Support\Facades\Log::info("ExamDocument created via Nova", [
                'exam_id' => $model->id,
                'document_id' => $document->id,
                'original_name' => $document->original_name,
                'size' => $document->size,
            ]);

            // Dispatch extraction job (always enabled unless explicitly faked)
            if (!config('doc.extractor.fake', false)) {
                \App\Jobs\ExtractExamDocumentTextJob::dispatch($document->id);
            }
        }
    }
}
