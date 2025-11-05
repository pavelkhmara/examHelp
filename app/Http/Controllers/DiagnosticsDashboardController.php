<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\GenerationLog;
use App\Models\GenerationTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiagnosticsDashboardController extends Controller
{
    /**
     * Show diagnostics dashboard
     * GET /diagnostics-dashboard
     */
    public function index()
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

        return view('diagnostics.dashboard', compact(
            'stats',
            'stuckTasks',
            'recentFailures',
            'pendingTasks',
            'recentActivity'
        ));
    }
}
