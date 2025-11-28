<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationLog;
use App\Models\GenerationPlan;
use App\Models\GenerationTask;
use App\Models\Question;
use App\Models\QuestionGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * API endpoints for monitoring synthesis pipeline and generation tasks
 *
 * Provides JSON equivalents of monitoring scripts:
 * - scripts/monitoring_new_exams.php -> GET /api/monitoring/overview
 * - scripts/monitor_queue.php -> GET /api/monitoring/queue
 * - scripts/check_synthesis_tasks.php -> GET /api/monitoring/exams/{exam}/synthesis
 * - scripts/analyze_synthesis.php -> GET /api/monitoring/exams/{exam}/analysis
 */
class MonitoringController extends Controller
{
    /**
     * Monitoring overview (equivalent to monitoring_new_exams.php)
     *
     * GET /api/monitoring/overview?hours=24
     */
    public function overview(Request $request): JsonResponse
    {
        $hours = (int) $request->input('hours', 24);
        $since = now()->subHours($hours);
        $monitoringStartDate = now()->startOfDay();

        // 1. Synthesis tasks statistics
        $tasks = GenerationTask::where('type', 'synthesis')
            ->where('created_at', '>=', $since)
            ->get();

        $taskStats = [
            'total' => $tasks->count(),
            'completed' => $tasks->where('status', 'completed')->count(),
            'failed' => $tasks->where('status', 'failed')->count(),
            'running' => $tasks->where('status', 'running')->count(),
            'queued' => $tasks->where('status', 'queued')->count(),
        ];

        $successRate = $taskStats['total'] > 0
            ? round(($taskStats['completed'] / $taskStats['total']) * 100, 2)
            : 0;

        // 2. ID Mismatches (only new exams)
        $mismatches = Question::whereNotNull('question_group_id')
            ->with('questionGroup') // Eager loading to avoid N+1
            ->whereHas('exam', function ($q) use ($monitoringStartDate) {
                $q->where('created_at', '>=', $monitoringStartDate);
            })
            ->get()
            ->filter(function ($q) {
                $group = $q->questionGroup;
                if (! $group) {
                    return false;
                }

                $expectedPrefix = $group->group_id.'_';

                return ! str_starts_with($q->question_id, $expectedPrefix);
            });

        // 3. Duplicates (only new exams)
        /** @var \Illuminate\Support\Collection<int, object{question_id: string, count: int}> $duplicates */
        $duplicates = Question::select('question_id', DB::raw('COUNT(*) as count'))
            ->whereHas('exam', function ($q) use ($monitoringStartDate) {
                $q->where('created_at', '>=', $monitoringStartDate);
            })
            ->groupBy('question_id')
            ->having('count', '>', 1)
            ->get();

        // 4. Empty Skeletons (new completed exams)
        $emptySkeletons = Question::whereRaw('JSON_LENGTH(interaction) = 0')
            ->whereHas('exam', function ($q) use ($monitoringStartDate) {
                $q->where('created_at', '>=', $monitoringStartDate)
                    ->where('research_status', 'completed');
            })
            ->count();

        // 5. New Exams Statistics
        $newExams = Exam::where('created_at', '>=', $monitoringStartDate)->get();
        $examsByStatus = $newExams->groupBy('research_status')
            ->map(fn ($exams) => $exams->count())
            ->toArray();

        // Check if all checks passed
        $allPass = $successRate >= 95
            && $mismatches->count() === 0
            && $duplicates->count() === 0
            && $emptySkeletons === 0;

        return response()->json([
            'status' => $allPass ? 'pass' : 'fail',
            'timestamp' => now()->toIso8601String(),
            'period' => [
                'hours' => $hours,
                'since' => $since->toIso8601String(),
                'monitoring_start' => $monitoringStartDate->toIso8601String(),
            ],
            'checks' => [
                'success_rate' => [
                    'value' => $successRate,
                    'target' => 95,
                    'pass' => $successRate >= 95,
                ],
                'id_mismatches' => [
                    'count' => $mismatches->count(),
                    'target' => 0,
                    'pass' => $mismatches->count() === 0,
                    'samples' => $mismatches->take(10)->map(fn ($q) => [
                        'question_id' => $q->question_id,
                        'group_id' => $q->questionGroup?->group_id,
                        'expected_prefix' => $q->questionGroup?->group_id.'_',
                    ])->values(),
                ],
                'duplicates' => [
                    'count' => $duplicates->count(),
                    'target' => 0,
                    'pass' => $duplicates->count() === 0,
                    'samples' => $duplicates->take(10)->map(
                        /** @param object{question_id: string, count: int} $d */
                        fn ($d) => [
                            'question_id' => $d->question_id,
                            'instances' => $d->count,
                        ]
                    )->values()->all(),
                ],
                'empty_skeletons' => [
                    'count' => $emptySkeletons,
                    'target' => 0,
                    'pass' => $emptySkeletons === 0,
                ],
            ],
            'tasks' => $taskStats,
            'exams' => [
                'total' => $newExams->count(),
                'by_status' => $examsByStatus,
            ],
        ]);
    }

    /**
     * Queue status (equivalent to monitor_queue.php)
     *
     * GET /api/monitoring/queue
     */
    public function queue(): JsonResponse
    {
        // Redis queue length
        $redis = app('redis')->connection();
        $queueLength = $redis->llen('queues:default');

        // Generation tasks by status
        $statuses = GenerationTask::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Recent tasks
        $recentTasks = GenerationTask::orderBy('created_at', 'desc')
            ->take(10)
            ->get(['id', 'type', 'status', 'created_at', 'updated_at'])
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'type' => $task->type,
                    'status' => $task->status,
                    'created_at' => $task->created_at->toIso8601String(),
                    'updated_at' => $task->updated_at?->toIso8601String(),
                    'age' => $task->created_at->diffForHumans(),
                ];
            });

        // Running tasks details
        $runningTasks = GenerationTask::whereIn('status', ['running', 'queued'])
            ->orderBy('created_at', 'desc')
            ->get(['id', 'type', 'status', 'created_at', 'updated_at', 'heartbeat_at'])
            ->map(function ($task) {
                $stale = $task->heartbeat_at
                    ? $task->heartbeat_at->diffInMinutes(now()) > 10
                    : false;

                return [
                    'id' => $task->id,
                    'type' => $task->type,
                    'status' => $task->status,
                    'created_at' => $task->created_at->toIso8601String(),
                    'heartbeat_at' => $task->heartbeat_at?->toIso8601String(),
                    'stale' => $stale,
                ];
            });

        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'redis' => [
                'queue_length' => $queueLength,
            ],
            'tasks' => [
                'by_status' => $statuses,
                'recent' => $recentTasks,
                'running' => $runningTasks,
            ],
        ]);
    }

    /**
     * Exam synthesis tasks (equivalent to check_synthesis_tasks.php)
     *
     * GET /api/monitoring/exams/{exam}/synthesis?limit=5
     */
    public function examSynthesis(Request $request, string $examId): JsonResponse
    {
        $exam = Exam::find($examId);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found'], 404);
        }

        $limit = (int) $request->input('limit', 5);

        $tasks = GenerationTask::where('exam_id', $examId)
            ->where('type', 'synthesize_questions')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($task) {
                $result = $task->result ?? [];

                return [
                    'id' => $task->id,
                    'status' => $task->status,
                    'error' => $task->error,
                    'created_at' => $task->created_at->toIso8601String(),
                    'updated_at' => $task->updated_at?->toIso8601String(),
                    'result' => [
                        'success' => $result['success'] ?? null,
                        'plans_processed' => $result['plans_processed'] ?? 0,
                        'total_generated' => $result['total_generated'] ?? 0,
                        'total_attached' => $result['total_attached'] ?? 0,
                        'sections' => array_values(array_map(
                            fn (array $r): array => [
                                'section_id' => $r['section_id'] ?? null,
                                'plan_id' => $r['plan_id'] ?? null,
                                'success' => $r['success'] ?? false,
                                'error' => $r['error'] ?? null,
                            ],
                            $result['results'] ?? []
                        )),
                    ],
                ];
            });

        $questionsCount = Question::where('exam_id', $examId)->count();

        return response()->json([
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'research_status' => $exam->research_status,
            ],
            'tasks' => $tasks,
            'questions_total' => $questionsCount,
        ]);
    }

    /**
     * Detailed exam analysis (equivalent to analyze_synthesis.php)
     *
     * GET /api/monitoring/exams/{exam}/analysis?task_id=123&include_logs=true
     */
    public function examAnalysis(Request $request, string $examId): JsonResponse
    {
        $exam = Exam::find($examId);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found'], 404);
        }

        // Find task
        $taskId = $request->input('task_id');
        if ($taskId) {
            $task = GenerationTask::find($taskId);
            if (! $task || $task->exam_id !== $exam->id) {
                return response()->json(['error' => 'Task not found for this exam'], 404);
            }
        } else {
            $task = GenerationTask::where('exam_id', $exam->id)
                ->whereIn('type', ['question_synthesis', 'synthesize_questions'])
                ->orderByDesc('id')
                ->first();

            if (! $task) {
                return response()->json(['error' => 'No synthesis task found for this exam'], 404);
            }
        }

        // Calculate duration
        $duration = null;
        if ($task->created_at && $task->updated_at) {
            $duration = abs($task->updated_at->diffInSeconds($task->created_at));
        }

        // Load related data with eager loading
        $plans = GenerationPlan::where('exam_id', $exam->id)
            ->with('section') // Eager load section relationship
            ->orderBy('id')
            ->get();
        $questions = Question::where('exam_id', $exam->id)->get();
        $questionGroups = QuestionGroup::where('exam_id', $exam->id)->count();

        // Plans analysis
        $completedPlans = 0;
        $failedPlans = 0;
        $totalExpected = 0;
        $issues = [];

        $plansData = $plans->map(function ($plan) use (&$completedPlans, &$failedPlans, &$totalExpected, &$issues) {
            $sectionName = $plan->section ? $plan->section->name : "Section #{$plan->section_id}";

            $meta = $plan->meta ?? [];
            $filtersCompleted = $meta['filters_completed'] ?? $meta['slots_completed'] ?? 0;
            $totalFilters = $meta['total_filters'] ?? $meta['total_slots'] ?? '?';
            $questionsGenerated = $meta['questions_generated'] ?? $plan->generated_questions ?? 0;

            $totalExpected += $plan->total_questions;

            if ($plan->status === 'completed') {
                $completedPlans++;

                // Check under/over generation
                if ($questionsGenerated < $plan->total_questions * 0.8) {
                    $deviation = round(($plan->total_questions - $questionsGenerated) / $plan->total_questions * 100);
                    $issues[] = [
                        'type' => 'under_generation',
                        'plan_id' => $plan->id,
                        'section' => $sectionName,
                        'expected' => $plan->total_questions,
                        'generated' => $questionsGenerated,
                        'deviation' => "-{$deviation}%",
                    ];
                } elseif ($questionsGenerated > $plan->total_questions * 1.2) {
                    $deviation = round(($questionsGenerated - $plan->total_questions) / $plan->total_questions * 100);
                    $issues[] = [
                        'type' => 'over_generation',
                        'plan_id' => $plan->id,
                        'section' => $sectionName,
                        'expected' => $plan->total_questions,
                        'generated' => $questionsGenerated,
                        'deviation' => "+{$deviation}%",
                    ];
                }
            } elseif ($plan->status === 'failed') {
                $failedPlans++;
                $issues[] = [
                    'type' => 'plan_failed',
                    'plan_id' => $plan->id,
                    'section' => $sectionName,
                    'error' => $plan->error,
                ];
            }

            return [
                'id' => $plan->id,
                'section' => $sectionName,
                'mode' => $plan->assembly_mode,
                'status' => $plan->status,
                'expected' => $plan->total_questions,
                'generated' => $questionsGenerated,
                'filters_completed' => $filtersCompleted,
                'total_filters' => $totalFilters,
                'error' => $plan->error,
            ];
        });

        // Questions analysis
        $questionCount = $questions->count();
        $accuracy = $totalExpected > 0 ? round($questionCount / $totalExpected * 100, 1) : 0;

        $byType = $questions->groupBy('type')->map->count()->sortDesc()->toArray();
        $bySection = $questions->groupBy('section_id')->map->count()->toArray();

        // Check orphaned questions
        $validSectionIds = $plans->pluck('section_id')->unique()->toArray();
        $orphanedQuestions = $questions->filter(fn ($q) => ! in_array($q->section_id, $validSectionIds));
        if ($orphanedQuestions->isNotEmpty()) {
            $issues[] = [
                'type' => 'orphaned_questions',
                'count' => $orphanedQuestions->count(),
            ];
        }

        // Task error
        if ($task->error) {
            $issues[] = [
                'type' => 'task_error',
                'message' => $task->error,
            ];
        }

        // Logs (optional)
        $logsData = [];
        if ($request->boolean('include_logs')) {
            $logs = GenerationLog::where('generation_task_id', $task->id)->orderBy('id')->get();
            $logsData = $logs->map(function ($log) use (&$issues) {
                $responseSize = is_array($log->response)
                    ? strlen(json_encode($log->response))
                    : strlen($log->response ?? '');

                // Check for errors (log->response can be array or string based on model cast)
                $responseData = $log->response;
                if (is_array($responseData) && isset($responseData['error'])) {
                    $issues[] = [
                        'type' => 'log_error',
                        'stage' => $log->stage,
                        'error' => $responseData['error'],
                    ];
                }

                return [
                    'id' => $log->id,
                    'stage' => $log->stage,
                    'prompt_tokens' => $log->prompt_tokens,
                    'completion_tokens' => $log->completion_tokens,
                    'response_size' => $responseSize,
                    'created_at' => $log->created_at->toIso8601String(),
                ];
            })->values();
        }

        // Overall success
        $planCompletionRate = $plans->count() > 0 ? round($completedPlans / $plans->count() * 100) : 0;
        $overallSuccess = $task->status === 'completed' && $planCompletionRate >= 80 && $accuracy >= 80;

        return response()->json([
            'success' => $overallSuccess,
            'timestamp' => now()->toIso8601String(),
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'research_status' => $exam->research_status,
            ],
            'task' => [
                'id' => $task->id,
                'type' => $task->type,
                'status' => $task->status,
                'error' => $task->error,
                'created_at' => $task->created_at->toIso8601String(),
                'updated_at' => $task->updated_at?->toIso8601String(),
                'duration_seconds' => $duration,
                'result' => $task->result,
                'activities' => $task->activities ?? [],
            ],
            'plans' => [
                'total' => $plans->count(),
                'completed' => $completedPlans,
                'failed' => $failedPlans,
                'completion_rate' => $planCompletionRate,
                'data' => $plansData->values(),
            ],
            'questions' => [
                'total' => $questionCount,
                'expected' => $totalExpected,
                'accuracy' => $accuracy,
                'by_type' => $byType,
                'by_section' => $bySection,
            ],
            'question_groups' => $questionGroups,
            'issues' => $issues,
            'logs' => $logsData,
        ]);
    }

    /**
     * Generation tasks list with filters
     *
     * GET /api/monitoring/tasks?type=synthesis&status=completed&limit=20&exam_id=xxx
     */
    public function tasks(Request $request): JsonResponse
    {
        $query = GenerationTask::query();

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('exam_id')) {
            $query->where('exam_id', $request->input('exam_id'));
        }

        if ($request->has('since')) {
            $since = \Carbon\Carbon::parse($request->input('since'));
            $query->where('created_at', '>=', $since);
        }

        $limit = min((int) $request->input('limit', 20), 100);

        $tasks = $query->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($task) {
                $duration = null;
                if ($task->created_at && $task->updated_at) {
                    $duration = abs($task->updated_at->diffInSeconds($task->created_at));
                }

                return [
                    'id' => $task->id,
                    'type' => $task->type,
                    'status' => $task->status,
                    'exam_id' => $task->exam_id,
                    'error' => $task->error,
                    'created_at' => $task->created_at->toIso8601String(),
                    'updated_at' => $task->updated_at?->toIso8601String(),
                    'heartbeat_at' => $task->heartbeat_at?->toIso8601String(),
                    'duration_seconds' => $duration,
                ];
            });

        return response()->json([
            'tasks' => $tasks,
            'count' => $tasks->count(),
            'filters' => [
                'type' => $request->input('type'),
                'status' => $request->input('status'),
                'exam_id' => $request->input('exam_id'),
                'since' => $request->input('since'),
                'limit' => $limit,
            ],
        ]);
    }

    /**
     * Generation logs for a task
     *
     * GET /api/monitoring/tasks/{task}/logs
     */
    public function taskLogs(int $taskId): JsonResponse
    {
        $task = GenerationTask::find($taskId);
        if (! $task) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        $logs = GenerationLog::where('generation_task_id', $taskId)
            ->orderBy('id')
            ->get()
            ->map(function ($log) {
                $responseSize = is_array($log->response)
                    ? strlen(json_encode($log->response))
                    : strlen($log->response ?? '');

                return [
                    'id' => $log->id,
                    'stage' => $log->stage,
                    'prompt_tokens' => $log->prompt_tokens,
                    'completion_tokens' => $log->completion_tokens,
                    'total_tokens' => $log->total_tokens,
                    'response_size_bytes' => $responseSize,
                    'created_at' => $log->created_at->toIso8601String(),
                    // Sanitized: don't expose full request/response for security
                    'has_request' => ! empty($log->request),
                    'has_response' => ! empty($log->response),
                    // For debugging only - add ?debug=1 parameter to see truncated versions
                ];
            });

        $totalTokens = $logs->sum('total_tokens');
        $totalPromptTokens = $logs->sum('prompt_tokens');
        $totalCompletionTokens = $logs->sum('completion_tokens');

        return response()->json([
            'task' => [
                'id' => $task->id,
                'type' => $task->type,
                'status' => $task->status,
            ],
            'logs' => $logs->values(),
            'stats' => [
                'count' => $logs->count(),
                'total_tokens' => $totalTokens,
                'prompt_tokens' => $totalPromptTokens,
                'completion_tokens' => $totalCompletionTokens,
            ],
        ]);
    }

    /**
     * Generation plan details
     *
     * GET /api/monitoring/plans/{plan}
     */
    public function planDetails(int $planId): JsonResponse
    {
        $plan = GenerationPlan::find($planId);
        if (! $plan) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        $category = ExamCategory::find($plan->section_id);
        $questions = Question::where('exam_id', $plan->exam_id)
            ->where('section_id', $plan->section_id)
            ->get();

        return response()->json([
            'plan' => [
                'id' => $plan->id,
                'exam_id' => $plan->exam_id,
                'section_id' => $plan->section_id,
                'section_name' => $category?->name,
                'assembly_mode' => $plan->assembly_mode,
                'status' => $plan->status,
                'total_questions' => $plan->total_questions,
                'generated_questions' => $plan->generated_questions,
                'error' => $plan->error,
                'plan_data' => $plan->plan_data,
                'meta' => $plan->meta,
                'created_at' => $plan->created_at->toIso8601String(),
                'updated_at' => $plan->updated_at->toIso8601String(),
            ],
            'questions' => [
                'total' => $questions->count(),
                'by_type' => $questions->groupBy('type')->map->count(),
                'by_group' => $questions->whereNotNull('question_group_id')->count(),
                'ungrouped' => $questions->whereNull('question_group_id')->count(),
            ],
        ]);
    }
}
