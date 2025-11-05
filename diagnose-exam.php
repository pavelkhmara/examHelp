#!/usr/bin/env php
<?php

/**
 * Diagnostic script for exam research tasks
 * Usage: php diagnose-exam.php <exam-id>
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Exam;
use App\Models\GenerationTask;

if ($argc < 2) {
    echo "Usage: php diagnose-exam.php <exam-id>\n";
    echo "Example: php diagnose-exam.php a0470751-3473-41c7-a796-d69ad7f6b851\n";
    exit(1);
}

$examId = $argv[1];

echo "=== Exam Diagnostics ===\n\n";

// Find exam
$exam = Exam::find($examId);

if (!$exam) {
    echo "❌ Exam not found: {$examId}\n";
    exit(1);
}

echo "✅ Exam found: {$exam->title}\n";
echo "   ID: {$exam->id}\n";
echo "   Research Status: {$exam->research_status}\n";
echo "   Analysis Status: {$exam->analysis_status}\n";
echo "   Created: {$exam->created_at}\n";
echo "\n";

// Get all tasks for this exam
$tasks = GenerationTask::where('exam_id', $exam->id)
    ->orderBy('created_at', 'desc')
    ->get();

echo "📋 Total tasks: {$tasks->count()}\n\n";

if ($tasks->isEmpty()) {
    echo "⚠️  No tasks found for this exam.\n";
    echo "   This explains why nothing happens when you click 'Run Exam Research'.\n";
    echo "   The action likely returns an existing task, but there are none.\n\n";
    echo "🔧 Suggested action:\n";
    echo "   Use the force-start API endpoint to create a new task:\n";
    echo "   POST /api/exams/{$examId}/research/force-start\n";
    exit(0);
}

// Get research tasks only
$researchTasks = $tasks->where('type', 'research');

echo "🔬 Research tasks: {$researchTasks->count()}\n\n";

// Group by status
$byStatus = $researchTasks->groupBy('status');

foreach ($byStatus as $status => $statusTasks) {
    echo "  {$status}: {$statusTasks->count()}\n";
}

echo "\n=== Recent Tasks (last 5) ===\n\n";

foreach ($tasks->take(5) as $task) {
    $age = $task->created_at->diffForHumans();
    $duration = $task->updated_at->diffInMinutes($task->created_at);

    echo "Task #{$task->id}\n";
    echo "  Type: {$task->type}\n";
    echo "  Status: {$task->status}\n";
    echo "  Created: {$age}\n";
    echo "  Duration: {$duration} minutes\n";
    echo "  Attempts: {$task->attempts}\n";

    if ($task->error) {
        echo "  Error: {$task->error}\n";
    }

    if ($task->idempotency_key) {
        echo "  Idempotency Key: {$task->idempotency_key}\n";
    }

    // Check if task is stuck
    if (in_array($task->status, ['queued', 'running'])) {
        $stuckMinutes = now()->diffInMinutes($task->updated_at);
        if ($stuckMinutes > 60) {
            echo "  ⚠️  STUCK! Last updated {$stuckMinutes} minutes ago\n";
        }
    }

    echo "\n";
}

// Check for active tasks
$activeTasks = $researchTasks->whereIn('status', ['queued', 'running']);

if ($activeTasks->isNotEmpty()) {
    echo "⚠️  ACTIVE TASKS FOUND ({$activeTasks->count()})\n";
    echo "   This explains why new tasks are not created.\n";
    echo "   TaskDispatcher prevents duplicate tasks when one is already running.\n\n";

    foreach ($activeTasks as $task) {
        $age = $task->created_at->diffForHumans();
        $stuckMinutes = now()->diffInMinutes($task->updated_at);

        echo "   Task #{$task->id}\n";
        echo "     Status: {$task->status}\n";
        echo "     Created: {$age}\n";
        echo "     Last updated: {$stuckMinutes} minutes ago\n";

        if ($stuckMinutes > 60) {
            echo "     ⚠️  This task appears to be STUCK!\n";
            echo "     Consider cancelling it:\n";
            echo "       POST /api/tasks/{$task->id}/cancel\n";
        }

        echo "\n";
    }

    echo "🔧 Suggested actions:\n";
    echo "   1. Wait for the task to complete (check status in Nova)\n";
    echo "   2. Cancel stuck tasks: POST /api/exams/{$examId}/tasks/cancel-all\n";
    echo "   3. Force start new research: POST /api/exams/{$examId}/research/force-start\n";
} else {
    echo "✅ No active tasks blocking new research\n\n";

    // Check if last task is recent
    $lastTask = $researchTasks->first();

    if ($lastTask && $lastTask->created_at->gt(now()->subMinutes(5))) {
        echo "ℹ️  Last task was created {$lastTask->created_at->diffForHumans()}\n";
        echo "   Status: {$lastTask->status}\n";

        if ($lastTask->status === 'completed') {
            echo "   ✅ Research completed successfully!\n";
        } elseif ($lastTask->status === 'failed') {
            echo "   ❌ Research failed: {$lastTask->error}\n";
            echo "   Consider retrying: POST /api/tasks/{$lastTask->id}/retry\n";
        }
    }
}

echo "\n=== System Configuration ===\n\n";
echo "Queue Driver: " . config('queue.default') . "\n";
echo "Environment: " . app()->environment() . "\n";
echo "Debug Mode: " . (config('app.debug') ? 'enabled' : 'disabled') . "\n";

echo "\n=== Diagnostics Complete ===\n";
