<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>System Diagnostics Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-100" x-data="diagnostics()">
    <div class="min-h-screen py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900">🔧 System Diagnostics Dashboard</h1>
                <p class="mt-2 text-sm text-gray-600">Monitor and manage exam research pipeline</p>
                <div class="mt-4">
                    <a href="/nova" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                        ← Back to Nova
                    </a>
                    <button @click="refreshData" class="ml-2 inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700">
                        🔄 Refresh
                    </button>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-blue-100 rounded-md p-3">
                            <span class="text-2xl">📚</span>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Total Exams</p>
                            <p class="text-2xl font-semibold text-gray-900">{{ $stats['exams']['total'] }}</p>
                        </div>
                    </div>
                    <div class="mt-4 text-xs text-gray-600">
                        <p>✅ Active: {{ $stats['exams']['active'] }}</p>
                        <p>🎯 Completed: {{ $stats['exams']['research_completed'] }}</p>
                        <p>🔄 Running: {{ $stats['exams']['research_running'] }}</p>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-green-100 rounded-md p-3">
                            <span class="text-2xl">✅</span>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Completed Tasks</p>
                            <p class="text-2xl font-semibold text-green-600">{{ $stats['tasks']['by_status']['completed'] ?? 0 }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-red-100 rounded-md p-3">
                            <span class="text-2xl">❌</span>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Failed Tasks</p>
                            <p class="text-2xl font-semibold text-red-600">{{ $stats['tasks']['by_status']['failed'] ?? 0 }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-yellow-100 rounded-md p-3">
                            <span class="text-2xl">⏸</span>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Pending</p>
                            <p class="text-2xl font-semibold text-yellow-600">
                                {{ ($stats['tasks']['by_status']['pending_confirmation'] ?? 0) + ($stats['tasks']['by_status']['pending_clarification'] ?? 0) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- All Task Statuses -->
            <div class="bg-white rounded-lg shadow mb-8">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Task Status Breakdown</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        @foreach(['queued', 'running', 'completed', 'failed', 'pending_confirmation', 'pending_clarification', 'cancelled'] as $status)
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
                                <span class="text-sm font-medium text-gray-700">{{ ucfirst(str_replace('_', ' ', $status)) }}</span>
                                <span class="text-lg font-bold text-gray-900">{{ $stats['tasks']['by_status'][$status] ?? 0 }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Stuck Tasks -->
            @if($stuckTasks->count() > 0)
            <div class="bg-red-50 border-l-4 border-red-500 rounded-lg shadow mb-8">
                <div class="px-6 py-4 border-b border-red-200">
                    <h2 class="text-lg font-semibold text-red-900">🚨 Stuck Tasks ({{ $stuckTasks->count() }})</h2>
                    <p class="text-sm text-red-700 mt-1">Tasks running/queued for more than 1 hour</p>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        @foreach($stuckTasks as $task)
                        <div class="bg-white p-4 rounded border border-red-200" x-data="{ loading: false }">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <p class="font-medium text-gray-900">
                                        Task #{{ $task->id }} - {{ $task->type }}
                                        <span class="ml-2 px-2 py-1 text-xs font-semibold rounded bg-red-100 text-red-800">{{ $task->status }}</span>
                                    </p>
                                    <p class="text-sm text-gray-600 mt-1">
                                        <a href="/nova/resources/exams/{{ $task->exam_id }}" class="text-blue-600 hover:underline" target="_blank">
                                            {{ $task->exam->title ?? 'Unknown Exam' }}
                                        </a>
                                    </p>
                                    <p class="text-xs text-gray-500 mt-1">Stuck for {{ now()->diffForHumans($task->updated_at, true) }}</p>
                                </div>
                                <div class="flex space-x-2">
                                    <button @click="cancelTask({{ $task->id }})"
                                            :disabled="loading"
                                            class="px-3 py-2 bg-red-600 text-white text-sm rounded hover:bg-red-700 disabled:opacity-50">
                                        Cancel
                                    </button>
                                    <a href="/nova/resources/generation-tasks/{{ $task->id }}"
                                       target="_blank"
                                       class="px-3 py-2 bg-gray-600 text-white text-sm rounded hover:bg-gray-700">
                                        View
                                    </a>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            <!-- Pending Tasks -->
            @if($pendingTasks->count() > 0)
            <div class="bg-yellow-50 border-l-4 border-yellow-500 rounded-lg shadow mb-8">
                <div class="px-6 py-4 border-b border-yellow-200">
                    <h2 class="text-lg font-semibold text-yellow-900">⏸ Pending Tasks ({{ $pendingTasks->count() }})</h2>
                    <p class="text-sm text-yellow-700 mt-1">Tasks waiting for user confirmation or clarification</p>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        @foreach($pendingTasks as $task)
                        <div class="bg-white p-4 rounded border border-yellow-200">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <p class="font-medium text-gray-900">
                                        Task #{{ $task->id }} - {{ $task->type }}
                                        <span class="ml-2 px-2 py-1 text-xs font-semibold rounded bg-yellow-100 text-yellow-800">{{ $task->status }}</span>
                                    </p>
                                    <p class="text-sm text-gray-600 mt-1">
                                        <a href="/nova/resources/exams/{{ $task->exam_id }}" class="text-blue-600 hover:underline" target="_blank">
                                            {{ $task->exam->title ?? 'Unknown Exam' }}
                                        </a>
                                    </p>
                                    <p class="text-xs text-gray-500 mt-1">Updated {{ $task->updated_at->diffForHumans() }}</p>
                                </div>
                                <div class="flex space-x-2">
                                    <a href="/nova/resources/exams/{{ $task->exam_id }}"
                                       target="_blank"
                                       class="px-3 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                                        Resolve
                                    </a>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            <!-- Recent Failures -->
            @if($recentFailures->count() > 0)
            <div class="bg-white rounded-lg shadow mb-8">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">❌ Recent Failures ({{ $recentFailures->count() }})</h2>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        @foreach($recentFailures->take(10) as $task)
                        <div class="bg-gray-50 p-4 rounded border border-gray-200" x-data="{ loading: false }">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <p class="font-medium text-gray-900">
                                        Task #{{ $task->id }} - {{ $task->type }}
                                    </p>
                                    <p class="text-sm text-gray-600 mt-1">
                                        <a href="/nova/resources/exams/{{ $task->exam_id }}" class="text-blue-600 hover:underline" target="_blank">
                                            {{ $task->exam->title ?? 'Unknown Exam' }}
                                        </a>
                                    </p>
                                    @if($task->error)
                                    <p class="text-xs text-red-600 mt-2 font-mono">{{ Str::limit($task->error, 200) }}</p>
                                    @endif
                                    <p class="text-xs text-gray-500 mt-1">Failed {{ $task->updated_at->diffForHumans() }}</p>
                                </div>
                                <div class="flex space-x-2">
                                    <button @click="retryTask({{ $task->id }})"
                                            :disabled="loading"
                                            class="px-3 py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700 disabled:opacity-50">
                                        Retry
                                    </button>
                                    <a href="/nova/resources/generation-tasks/{{ $task->id }}"
                                       target="_blank"
                                       class="px-3 py-2 bg-gray-600 text-white text-sm rounded hover:bg-gray-700">
                                        View
                                    </a>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            <!-- Recent Activity -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">📊 Recent Activity</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Exam</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Updated</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($recentActivity as $task)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ $task->id }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <a href="/nova/resources/exams/{{ $task->exam_id }}" class="text-blue-600 hover:underline" target="_blank">
                                        {{ Str::limit($task->exam->title ?? 'Unknown', 30) }}
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $task->type }}</td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-semibold rounded
                                        @if($task->status === 'completed') bg-green-100 text-green-800
                                        @elseif($task->status === 'failed') bg-red-100 text-red-800
                                        @elseif($task->status === 'running') bg-blue-100 text-blue-800
                                        @elseif(in_array($task->status, ['pending_confirmation', 'pending_clarification'])) bg-yellow-100 text-yellow-800
                                        @elseif($task->status === 'cancelled') bg-gray-100 text-gray-800
                                        @else bg-gray-100 text-gray-800
                                        @endif">
                                        {{ $task->status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $task->updated_at->diffForHumans() }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <a href="/nova/resources/generation-tasks/{{ $task->id }}"
                                       target="_blank"
                                       class="text-blue-600 hover:underline">
                                        View
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Footer -->
            <div class="mt-8 text-center text-sm text-gray-500">
                <p>Last updated: <span x-text="new Date().toLocaleString()"></span></p>
                <p class="mt-2">API Endpoints: <a href="/api/diagnostics/stats" class="text-blue-600 hover:underline" target="_blank">/api/diagnostics/*</a></p>
            </div>
        </div>
    </div>

    <script>
        function diagnostics() {
            return {
                async cancelTask(taskId) {
                    if (!confirm(`Cancel task #${taskId}?`)) return;

                    try {
                        const response = await fetch(`/api/tasks/${taskId}/cancel`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({
                                reason: 'Cancelled via Diagnostics Dashboard'
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            alert('Task cancelled successfully!');
                            window.location.reload();
                        } else {
                            alert(`Error: ${data.message}`);
                        }
                    } catch (error) {
                        alert(`Error: ${error.message}`);
                    }
                },

                async retryTask(taskId) {
                    if (!confirm(`Retry task #${taskId}?`)) return;

                    try {
                        const response = await fetch(`/api/tasks/${taskId}/retry`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            }
                        });

                        const data = await response.json();

                        if (data.success) {
                            alert('Task queued for retry!');
                            window.location.reload();
                        } else {
                            alert(`Error: ${data.message}`);
                        }
                    } catch (error) {
                        alert(`Error: ${error.message}`);
                    }
                },

                refreshData() {
                    window.location.reload();
                }
            }
        }
    </script>
</body>
</html>
