<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\GenerationLog;
use App\Models\GenerationTask;
use App\Models\Question;
use App\Services\Golden\GoldenLoader;
use App\Services\Golden\SnapshotManager;
use App\Services\LanguageApp\ExamStructureRecoveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiagnosticsDashboardController extends Controller
{
    /**
     * Show diagnostics dashboard
     * GET /diagnostics-dashboard
     */
    public function index(ExamStructureRecoveryService $recovery)
    {
        // Get system statistics
        $taskStatusCounts = GenerationTask::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $stats = [
            'exams' => [
                'total' => Exam::count(),
                'active' => Exam::where('is_active', true)->count(),
                'research_completed' => Exam::where('research_status', 'completed')->count(),
                'research_running' => Exam::whereIn('research_status', ['queued', 'running', 'running_overview'])->count(),
            ],
            'tasks' => [
                'total' => GenerationTask::count(),
                'by_status' => $taskStatusCounts,
            ],
            'content' => [
                'categories' => ExamCategory::count(),
                'examples' => ExamExampleQuestion::count(),
            ],
            'logs' => [
                'total' => GenerationLog::count(),
                'total_tokens_used' => GenerationLog::sum('total_tokens'),
            ],
        ];

        // Get stuck tasks
        $stuckTasks = GenerationTask::query()
            ->whereIn('status', ['running', 'queued'])
            ->where('updated_at', '<', now()->subHour())
            ->with('exam:id,title')
            ->orderBy('updated_at')
            ->get();

        // Get recent failures
        $recentFailures = GenerationTask::query()
            ->where('status', 'failed')
            ->with('exam:id,title')
            ->orderBy('updated_at', 'desc')
            ->limit(20)
            ->get();

        // Get pending tasks
        $pendingTasks = GenerationTask::query()
            ->whereIn('status', ['pending_confirmation', 'pending_clarification'])
            ->with('exam:id,title')
            ->orderBy('updated_at', 'desc')
            ->get();

        // Get recent activity
        $recentActivity = GenerationTask::query()
            ->with('exam:id,title')
            ->orderBy('updated_at', 'desc')
            ->limit(30)
            ->get();

        // Get system configuration
        $systemConfig = [
            'queue_driver' => config('queue.default'),
            'app_env' => app()->environment(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];

        // Get queue information
        $queueInfo = $this->getQueueInfo();

        // Get structure recovery information
        $recoveryNeeded = collect();
        $exams = Exam::where('research_status', 'completed')
            ->with('categories')
            ->get();

        foreach ($exams as $exam) {
            if ($recovery->needsRecovery($exam)) {
                $recoveryNeeded->push($exam);
            }
        }

        $recoveryStats = [
            'total_exams' => $exams->count(),
            'needing_recovery' => $recoveryNeeded->count(),
        ];

        return view('diagnostics.dashboard', compact(
            'stats',
            'stuckTasks',
            'recentFailures',
            'pendingTasks',
            'recentActivity',
            'systemConfig',
            'queueInfo',
            'recoveryNeeded',
            'recoveryStats'
        ));
    }

    /**
     * Diagnose a specific exam
     * GET /diagnostics-dashboard/exam/{examId}
     */
    public function diagnoseExam(string $examId)
    {
        $exam = Exam::find($examId);

        if (!$exam) {
            return response()->json([
                'success' => false,
                'error' => 'Exam not found',
                'exam_id' => $examId,
            ], 404);
        }

        // Get all tasks for this exam
        $tasks = GenerationTask::where('exam_id', $exam->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Get research tasks only
        $researchTasks = $tasks->where('type', 'research');

        // Group by status
        $tasksByStatus = $researchTasks->groupBy('status')
            ->map(function ($group) {
                return $group->count();
            })
            ->toArray();

        // Get active tasks
        $activeTasks = $researchTasks->whereIn('status', ['queued', 'running'])
            ->map(function ($task) {
                $stuckMinutes = now()->diffInMinutes($task->updated_at);
                return [
                    'id' => $task->id,
                    'status' => $task->status,
                    'created_at' => $task->created_at->toISOString(),
                    'created_ago' => $task->created_at->diffForHumans(),
                    'updated_at' => $task->updated_at->toISOString(),
                    'updated_ago' => $task->updated_at->diffForHumans(),
                    'stuck_minutes' => $stuckMinutes,
                    'is_stuck' => $stuckMinutes > 60,
                    'idempotency_key' => $task->idempotency_key,
                ];
            })
            ->values();

        // Get recent tasks (last 5)
        $recentTasks = $tasks->take(5)->map(function ($task) {
            $duration = $task->updated_at->diffInMinutes($task->created_at);
            return [
                'id' => $task->id,
                'type' => $task->type,
                'status' => $task->status,
                'created_at' => $task->created_at->toISOString(),
                'created_ago' => $task->created_at->diffForHumans(),
                'updated_at' => $task->updated_at->toISOString(),
                'duration_minutes' => $duration,
                'attempts' => $task->attempts,
                'error' => $task->error,
                'idempotency_key' => $task->idempotency_key,
            ];
        });

        // Determine issue and suggestions
        $issue = null;
        $suggestions = [];

        if ($tasks->isEmpty()) {
            $issue = 'no_tasks';
            $suggestions = [
                'No tasks found for this exam. This explains why nothing happens when you click "Run Exam Research".',
                'The action likely tries to return an existing task, but there are none.',
                'Use the "Force Start" button below to create a new task.',
            ];
        } elseif ($activeTasks->count() > 0) {
            $issue = 'active_tasks_blocking';
            $suggestions = [
                "Found {$activeTasks->count()} active task(s). This explains why new tasks are not created.",
                'TaskDispatcher prevents duplicate tasks when one is already running.',
                'Wait for the task to complete, or cancel stuck tasks and force start a new one.',
            ];

            foreach ($activeTasks as $task) {
                if ($task['is_stuck']) {
                    $suggestions[] = "Task #{$task['id']} appears to be STUCK (last updated {$task['stuck_minutes']} minutes ago). Consider cancelling it.";
                }
            }
        }

        return response()->json([
            'success' => true,
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'slug' => $exam->slug,
                'research_status' => $exam->research_status,
                'analysis_status' => $exam->analysis_status,
                'created_at' => $exam->created_at->toISOString(),
                'created_ago' => $exam->created_at->diffForHumans(),
            ],
            'tasks' => [
                'total' => $tasks->count(),
                'research_total' => $researchTasks->count(),
                'by_status' => $tasksByStatus,
            ],
            'active_tasks' => $activeTasks,
            'recent_tasks' => $recentTasks,
            'issue' => $issue,
            'suggestions' => $suggestions,
            'system' => [
                'queue_driver' => config('queue.default'),
                'environment' => app()->environment(),
            ],
        ]);
    }

    /**
     * Get queue information
     */
    private function getQueueInfo(): array
    {
        $queueDriver = config('queue.default');
        $queueInfo = [
            'driver' => $queueDriver,
            'pending_jobs' => collect([]),
            'failed_jobs' => collect([]),
            'total_pending' => 0,
            'total_failed' => 0,
        ];

        try {
            if ($queueDriver === 'database') {
                // Get pending jobs
                if (\Schema::hasTable('jobs')) {
                    $queueInfo['pending_jobs'] = \DB::table('jobs')
                        ->orderBy('created_at')
                        ->limit(50)
                        ->get()
                        ->map(function ($job) {
                            $payload = json_decode($job->payload, true);

                            // Try to extract task ID from job data
                            $taskId = null;
                            try {
                                $jobData = unserialize($payload['data']['command'] ?? '');
                                if (is_object($jobData) && property_exists($jobData, 'taskId')) {
                                    $taskId = $jobData->taskId;
                                }
                            } catch (\Exception $e) {
                                // Ignore unserialize errors
                            }

                            return (object) [
                                'id' => $job->id,
                                'queue' => $job->queue,
                                'attempts' => $job->attempts,
                                'reserved_at' => $job->reserved_at ? \Carbon\Carbon::createFromTimestamp($job->reserved_at) : null,
                                'available_at' => \Carbon\Carbon::createFromTimestamp($job->available_at),
                                'created_at' => \Carbon\Carbon::createFromTimestamp($job->created_at),
                                'job_class' => $payload['displayName'] ?? 'Unknown',
                                'task_id' => $taskId,
                            ];
                        });

                    $queueInfo['total_pending'] = \DB::table('jobs')->count();
                }

                // Get failed jobs
                if (\Schema::hasTable('failed_jobs')) {
                    $queueInfo['failed_jobs'] = \DB::table('failed_jobs')
                        ->orderBy('failed_at', 'desc')
                        ->limit(20)
                        ->get()
                        ->map(function ($job) {
                            $payload = json_decode($job->payload, true);

                            return (object) [
                                'id' => $job->id,
                                'uuid' => $job->uuid ?? null,
                                'connection' => $job->connection,
                                'queue' => $job->queue,
                                'job_class' => $payload['displayName'] ?? 'Unknown',
                                'exception' => $job->exception,
                                'failed_at' => \Carbon\Carbon::parse($job->failed_at),
                            ];
                        });

                    $queueInfo['total_failed'] = \DB::table('failed_jobs')->count();
                }
            }
        } catch (\Exception $e) {
            $queueInfo['error'] = $e->getMessage();
        }

        return $queueInfo;
    }

    /**
     * Scan all exams for structure recovery needs
     * GET /api/diagnostics/structure-recovery/scan
     */
    public function scanStructureRecovery(ExamStructureRecoveryService $recovery)
    {
        $exams = Exam::with('categories')->get();
        $needingRecovery = [];

        foreach ($exams as $exam) {
            if ($recovery->needsRecovery($exam)) {
                $needingRecovery[] = [
                    'id' => $exam->id,
                    'title' => $exam->title,
                    'research_status' => $exam->research_status,
                    'categories_count' => $exam->categories->count(),
                    'created_at' => $exam->created_at->toISOString(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'total_exams' => $exams->count(),
            'needing_recovery' => count($needingRecovery),
            'exams' => $needingRecovery,
        ]);
    }

    /**
     * Recover structure for a single exam
     * POST /api/diagnostics/structure-recovery/recover/{examId}
     */
    public function recoverStructure(string $examId, Request $request, ExamStructureRecoveryService $recovery)
    {
        $exam = Exam::find($examId);

        if (!$exam) {
            return response()->json([
                'success' => false,
                'error' => 'Exam not found',
            ], 404);
        }

        $dryRun = $request->boolean('dry_run', false);

        try {
            $result = $recovery->recover($exam, $dryRun);

            return response()->json([
                'success' => true,
                'exam_id' => $examId,
                'exam_title' => $exam->title,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => app()->environment('local') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Diagnose structure for a single exam
     * GET /api/diagnostics/structure-recovery/diagnose/{examId}
     */
    public function diagnoseStructure(string $examId, ExamStructureRecoveryService $recovery)
    {
        $exam = Exam::find($examId);

        if (!$exam) {
            return response()->json([
                'success' => false,
                'error' => 'Exam not found',
            ], 404);
        }

        try {
            $diagnostics = $recovery->diagnose($exam);

            return response()->json([
                'success' => true,
                'exam_id' => $examId,
                'exam_title' => $exam->title,
                'diagnostics' => $diagnostics,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => app()->environment('local') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Compare generated exam with reference exam
     * GET /diagnostics-dashboard/compare/{genId}/{refId}
     */
    public function compareExams(string $genId, string $refId)
    {
        $gen = Exam::find($genId);
        $ref = Exam::find($refId);

        if (!$gen) {
            return response()->json(['success' => false, 'error' => "Generated exam not found: $genId"], 404);
        }
        if (!$ref) {
            return response()->json(['success' => false, 'error' => "Reference exam not found: $refId"], 404);
        }

        // Basic info
        $genSections = $gen->categories()->count();
        $refSections = $ref->categories()->count();
        $genQuestions = $gen->questions()->count();
        $refQuestions = $ref->questions()->count();
        $genGroups = $gen->questionGroups()->count();
        $refGroups = $ref->questionGroups()->count();

        // Section comparison
        $refCats = $ref->categories->keyBy('key');
        $genCats = $gen->categories->keyBy('key');

        $keyMapping = [
            'sec-listening' => 'listening',
            'sec-reading' => 'reading',
            'sec-writing' => 'writing',
            'sec-speaking' => 'speaking',
            'sec-grammar' => 'grammar',
            'sec-grammar-lexis' => 'grammar',
        ];

        $sectionComparison = [];
        foreach ($refCats as $refKey => $refCat) {
            $refQCount = $refCat->questions()->count();
            $refGCount = $refCat->questionGroups()->count();

            $genKey = array_search($refKey, $keyMapping) ?: $refKey;
            $genCat = $genCats->get($genKey) ?? $genCats->get("sec-{$refKey}");

            if ($genCat) {
                $genQCount = $genCat->questions()->count();
                $genGCount = $genCat->questionGroups()->count();
                $qScore = $refQCount > 0 ? min(100, round($genQCount / $refQCount * 100)) : 100;
                $gScore = $refGCount > 0 ? min(100, round($genGCount / $refGCount * 100)) : ($genGCount == 0 ? 100 : 50);
                $avgScore = round(($qScore + $gScore) / 2);

                $sectionComparison[$refKey] = [
                    'name' => $refCat->name,
                    'gen_questions' => $genQCount,
                    'ref_questions' => $refQCount,
                    'q_score' => $qScore,
                    'gen_groups' => $genGCount,
                    'ref_groups' => $refGCount,
                    'g_score' => $gScore,
                    'avg_score' => $avgScore,
                    'status' => $avgScore >= 80 ? 'good' : ($avgScore >= 50 ? 'warning' : 'bad'),
                ];
            } else {
                $sectionComparison[$refKey] = [
                    'name' => $refCat->name,
                    'gen_questions' => 0,
                    'ref_questions' => $refQCount,
                    'q_score' => 0,
                    'gen_groups' => 0,
                    'ref_groups' => $refGCount,
                    'g_score' => 0,
                    'avg_score' => 0,
                    'status' => 'missing',
                ];
            }
        }

        // Question types
        $genTypes = Question::whereHas('section', fn($q) => $q->where('exam_id', $genId))
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $refTypes = Question::whereHas('section', fn($q) => $q->where('exam_id', $refId))
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $allTypes = array_unique(array_merge(array_keys($genTypes), array_keys($refTypes)));
        sort($allTypes);

        $typeComparison = [];
        foreach ($allTypes as $type) {
            $g = $genTypes[$type] ?? 0;
            $r = $refTypes[$type] ?? 0;
            $typeComparison[$type] = [
                'gen' => $g,
                'ref' => $r,
                'status' => $g == 0 && $r > 0 ? 'missing' : ($g > 0 && $r == 0 ? 'extra' : 'ok'),
            ];
        }

        // Calculate scores
        $structureScore = $refQuestions > 0 ? min(100, round($genQuestions / $refQuestions * 100)) : 0;
        $groupScore = $refGroups > 0 ? min(100, round($genGroups / $refGroups * 100)) : 0;
        $sectionScores = array_column($sectionComparison, 'avg_score');
        $sectionAvg = count($sectionScores) > 0 ? round(array_sum($sectionScores) / count($sectionScores)) : 0;
        $overallScore = round(($structureScore * 0.4 + $groupScore * 0.3 + $sectionAvg * 0.3));

        // Recommendations
        $recommendations = [];
        if ($genGroups == 0 && $refGroups > 0) {
            $recommendations[] = "Question groups are missing - need to implement group generation";
        }
        if ($genQuestions < $refQuestions * 0.7) {
            $missing = $refQuestions - $genQuestions;
            $recommendations[] = "Need ~{$missing} more questions to match reference";
        }
        foreach ($sectionComparison as $key => $data) {
            if ($data['avg_score'] < 50) {
                $recommendations[] = "Section '{$key}' needs more content (target: {$data['ref_questions']} questions)";
            }
        }
        $missingTypes = array_keys(array_filter($typeComparison, fn($t) => $t['status'] === 'missing'));
        if (!empty($missingTypes)) {
            $recommendations[] = "Missing question types: " . implode(', ', $missingTypes);
        }

        return response()->json([
            'success' => true,
            'generated' => [
                'id' => $gen->id,
                'title' => $gen->title,
                'level' => $gen->level,
                'language' => $gen->language_of_test,
                'sections' => $genSections,
                'groups' => $genGroups,
                'questions' => $genQuestions,
            ],
            'reference' => [
                'id' => $ref->id,
                'title' => $ref->title,
                'level' => $ref->level,
                'language' => $ref->language_of_test,
                'sections' => $refSections,
                'groups' => $refGroups,
                'questions' => $refQuestions,
            ],
            'section_comparison' => $sectionComparison,
            'type_comparison' => $typeComparison,
            'scores' => [
                'structure' => $structureScore,
                'groups' => $groupScore,
                'sections' => $sectionAvg,
                'overall' => $overallScore,
                'grade' => $overallScore >= 80 ? 'excellent' : ($overallScore >= 60 ? 'acceptable' : ($overallScore >= 40 ? 'needs_improvement' : 'poor')),
            ],
            'recommendations' => $recommendations,
        ]);
    }

    /**
     * Get list of reference exams
     * GET /diagnostics-dashboard/reference-exams
     */
    public function referenceExams()
    {
        $refs = Exam::whereJsonContains('meta->is_reference_exam', true)
            ->get()
            ->map(fn($exam) => [
                'id' => $exam->id,
                'title' => $exam->title,
                'level' => $exam->level,
                'language' => $exam->language_of_test,
                'questions' => $exam->questions()->count(),
                'groups' => $exam->questionGroups()->count(),
            ]);

        return response()->json([
            'success' => true,
            'exams' => $refs,
        ]);
    }

    /**
     * Recover all exams that need it
     * POST /api/diagnostics/structure-recovery/recover-all
     */
    public function recoverAll(Request $request, ExamStructureRecoveryService $recovery)
    {
        $dryRun = $request->boolean('dry_run', false);
        $exams = Exam::all();
        $examIds = [];

        foreach ($exams as $exam) {
            if ($recovery->needsRecovery($exam)) {
                $examIds[] = $exam->id;
            }
        }

        if (empty($examIds)) {
            return response()->json([
                'success' => true,
                'message' => 'No exams need recovery',
                'results' => [
                    'total' => 0,
                    'recovered' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                ],
            ]);
        }

        try {
            $results = $recovery->recoverBatch($examIds, $dryRun);

            return response()->json([
                'success' => true,
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => app()->environment('local') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    // ========================================
    // Golden Stage Fixtures & Snapshot Methods
    // ========================================

    /**
     * Get available golden fixtures
     * GET /diagnostics-dashboard/golden-fixtures
     */
    public function goldenFixtures(GoldenLoader $loader)
    {
        $fixtures = $loader->listFixtures();

        return response()->json([
            'success' => true,
            'fixtures' => $fixtures,
        ]);
    }

    /**
     * Compare exam with golden fixture (all stages)
     * GET /diagnostics-dashboard/golden-compare/{examId}/{fixtureId}
     */
    public function goldenCompare(string $examId, string $fixtureId, SnapshotManager $manager, GoldenLoader $loader)
    {
        $exam = Exam::find($examId);

        if (!$exam) {
            return response()->json(['success' => false, 'error' => "Exam not found: $examId"], 404);
        }

        $availableStages = $loader->getAvailableStages($fixtureId);
        if (empty($availableStages)) {
            return response()->json(['success' => false, 'error' => "Golden fixture not found: $fixtureId"], 404);
        }

        $results = [];
        $totalSimilarity = 0;
        $stageCount = 0;

        foreach ($availableStages as $stage) {
            try {
                $golden = $loader->loadStage($fixtureId, $stage);
                if (!$golden) {
                    continue;
                }

                $comparison = $manager->compareWithGolden($exam, $stage, $golden);

                $results[$stage] = [
                    'similarity' => round($comparison->similarity * 100),
                    'passed' => $comparison->similarity >= 0.8,
                    'diffs_count' => count($comparison->diffs),
                    'diffs' => array_slice($comparison->diffs, 0, 5), // First 5 diffs
                    'message' => $comparison->message,
                ];

                $totalSimilarity += $comparison->similarity;
                $stageCount++;
            } catch (\Throwable $e) {
                $results[$stage] = [
                    'similarity' => 0,
                    'passed' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $overallSimilarity = $stageCount > 0 ? round(($totalSimilarity / $stageCount) * 100) : 0;
        $passedCount = count(array_filter($results, fn($r) => $r['passed'] ?? false));

        // Determine grade
        $grade = match (true) {
            $overallSimilarity >= 90 => 'excellent',
            $overallSimilarity >= 75 => 'good',
            $overallSimilarity >= 60 => 'acceptable',
            $overallSimilarity >= 40 => 'needs_improvement',
            default => 'poor',
        };

        return response()->json([
            'success' => true,
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'level' => $exam->level,
                'research_status' => $exam->research_status,
            ],
            'fixture' => [
                'id' => $fixtureId,
                'stages' => $availableStages,
            ],
            'stages' => $results,
            'summary' => [
                'overall_similarity' => $overallSimilarity,
                'grade' => $grade,
                'passed_stages' => $passedCount,
                'total_stages' => $stageCount,
            ],
        ]);
    }

    /**
     * Compare single stage with golden fixture
     * GET /diagnostics-dashboard/golden-compare/{examId}/{fixtureId}/{stage}
     */
    public function goldenCompareStage(string $examId, string $fixtureId, string $stage, SnapshotManager $manager, GoldenLoader $loader)
    {
        $exam = Exam::find($examId);

        if (!$exam) {
            return response()->json(['success' => false, 'error' => "Exam not found: $examId"], 404);
        }

        $golden = $loader->loadStage($fixtureId, $stage);
        if (!$golden) {
            return response()->json(['success' => false, 'error' => "Golden stage not found: {$fixtureId}/{$stage}"], 404);
        }

        try {
            $comparison = $manager->compareWithGolden($exam, $stage, $golden);

            return response()->json([
                'success' => true,
                'exam_id' => $examId,
                'fixture_id' => $fixtureId,
                'stage' => $stage,
                'similarity' => round($comparison->similarity * 100),
                'passed' => $comparison->similarity >= 0.8,
                'diffs' => $comparison->diffs,
                'message' => $comparison->message,
                'current_data' => $comparison->current,
                'golden_data' => $golden,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => app()->environment('local') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Capture snapshot of exam stage
     * POST /diagnostics-dashboard/snapshot/capture/{examId}
     */
    public function snapshotCapture(string $examId, Request $request, SnapshotManager $manager)
    {
        $exam = Exam::find($examId);

        if (!$exam) {
            return response()->json(['success' => false, 'error' => "Exam not found: $examId"], 404);
        }

        $stage = $request->input('stage');
        $label = $request->input('label', 'baseline');
        $captureAll = $request->boolean('all', false);

        if (!$captureAll && !$stage) {
            return response()->json(['success' => false, 'error' => 'Either stage or all=true is required'], 400);
        }

        $stages = $captureAll ? SnapshotManager::getAvailableStages() : [$stage];
        $results = [];

        foreach ($stages as $stg) {
            try {
                $snapshot = $manager->capture($exam, $stg, $label);
                $results[$stg] = [
                    'success' => true,
                    'label' => $snapshot->label,
                    'hash' => $snapshot->getShortHash(),
                ];
            } catch (\Throwable $e) {
                $results[$stg] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'exam_id' => $examId,
            'label' => $label,
            'stages' => $results,
        ]);
    }

    /**
     * List snapshots for exam
     * GET /diagnostics-dashboard/snapshot/list/{examId?}
     */
    public function snapshotList(?string $examId = null, SnapshotManager $manager)
    {
        if ($examId) {
            $snapshots = $manager->list($examId);

            return response()->json([
                'success' => true,
                'exam_id' => $examId,
                'snapshots' => $snapshots,
            ]);
        }

        $exams = $manager->listExams();

        return response()->json([
            'success' => true,
            'exams' => $exams,
        ]);
    }

    /**
     * Compare exam with its baseline snapshot
     * GET /diagnostics-dashboard/snapshot/compare/{examId}/{stage}
     */
    public function snapshotCompare(string $examId, string $stage, Request $request, SnapshotManager $manager)
    {
        $exam = Exam::find($examId);

        if (!$exam) {
            return response()->json(['success' => false, 'error' => "Exam not found: $examId"], 404);
        }

        $label = $request->input('label');

        try {
            $comparison = $manager->compare($exam, $stage, $label);

            return response()->json([
                'success' => true,
                'exam_id' => $examId,
                'stage' => $stage,
                'has_baseline' => $comparison->hasBaseline,
                'similarity' => $comparison->hasBaseline ? round($comparison->similarity * 100) : null,
                'passed' => $comparison->hasBaseline ? $comparison->similarity >= 0.8 : null,
                'baseline_label' => $comparison->baseline?->label,
                'baseline_hash' => $comparison->baseline?->getShortHash(),
                'diffs' => $comparison->diffs,
                'message' => $comparison->message,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
