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

            <!-- System Configuration -->
            <div class="bg-white rounded-lg shadow mb-8">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">⚙️ System Configuration</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div>
                            <p class="text-xs text-gray-500">Queue Driver</p>
                            <p class="text-sm font-semibold text-gray-900">{{ $systemConfig['queue_driver'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Environment</p>
                            <p class="text-sm font-semibold text-gray-900">{{ $systemConfig['app_env'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">PHP Version</p>
                            <p class="text-sm font-semibold text-gray-900">{{ $systemConfig['php_version'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Laravel Version</p>
                            <p class="text-sm font-semibold text-gray-900">{{ $systemConfig['laravel_version'] }}</p>
                        </div>
                    </div>

                    @if($systemConfig['queue_driver'] === 'sync')
                    <div class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded">
                        <p class="text-sm text-yellow-800">
                            ⚠️ <strong>Warning:</strong> Queue driver is set to 'sync'. Jobs will run synchronously, which may cause timeouts.
                            Consider using 'database' or 'redis' for production and running <code class="bg-yellow-100 px-1 rounded">php artisan queue:work</code>.
                        </p>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Exam Diagnostics Tool -->
            <div class="bg-white rounded-lg shadow mb-8" x-data="examDiagnostics()">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">🔍 Exam Diagnostics</h2>
                    <p class="text-sm text-gray-600 mt-1">Search for a specific exam to see detailed task status and troubleshooting info</p>
                </div>
                <div class="p-6">
                    <!-- Search Form -->
                    <div class="flex gap-2 mb-4">
                        <input type="text"
                               x-model="examId"
                               @keyup.enter="diagnose"
                               placeholder="Enter Exam ID (UUID)"
                               class="flex-1 px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <button @click="diagnose"
                                :disabled="loading || !examId"
                                class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span x-show="!loading">Diagnose</span>
                            <span x-show="loading" x-cloak>Analyzing...</span>
                        </button>
                    </div>

                    <!-- Error -->
                    <div x-show="error" x-cloak class="mt-6 p-4 bg-red-50 border border-red-200 rounded-md mb-4">
                        <p class="text-sm text-red-800" x-text="error"></p>
                    </div>

                    <!-- Results -->
                    <div x-show="result && !error" x-cloak class="mt-6">
                        <!-- Success -->
                        <div>
                            <!-- Exam Info -->
                            <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-4">
                                <h3 class="font-semibold text-blue-900">Exam Information</h3>
                                <div class="mt-2 text-sm text-blue-800">
                                    <p><strong>Title:</strong> <span x-text="result.exam?.title"></span></p>
                                    <p><strong>ID:</strong> <code class="bg-blue-100 px-1 rounded" x-text="result.exam?.id"></code></p>
                                    <p><strong>Research Status:</strong> <span x-text="result.exam?.research_status"></span></p>
                                    <p><strong>Created:</strong> <span x-text="result.exam?.created_ago"></span></p>
                                </div>
                                <div class="mt-3">
                                    <a :href="`/nova/resources/exams/${result.exam?.id}`"
                                       target="_blank"
                                       class="inline-flex items-center px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                                        Open in Nova →
                                    </a>
                                </div>
                            </div>

                            <!-- Task Statistics -->
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                                <div class="bg-gray-50 p-3 rounded border border-gray-200">
                                    <p class="text-xs text-gray-500">Total Tasks</p>
                                    <p class="text-2xl font-bold text-gray-900" x-text="result.tasks?.total || 0"></p>
                                </div>
                                <div class="bg-gray-50 p-3 rounded border border-gray-200">
                                    <p class="text-xs text-gray-500">Research Tasks</p>
                                    <p class="text-2xl font-bold text-gray-900" x-text="result.tasks?.research_total || 0"></p>
                                </div>
                                <div class="bg-green-50 p-3 rounded border border-green-200">
                                    <p class="text-xs text-green-600">Completed</p>
                                    <p class="text-2xl font-bold text-green-700" x-text="result.tasks?.by_status?.completed || 0"></p>
                                </div>
                                <div class="bg-red-50 p-3 rounded border border-red-200">
                                    <p class="text-xs text-red-600">Failed</p>
                                    <p class="text-2xl font-bold text-red-700" x-text="result.tasks?.by_status?.failed || 0"></p>
                                </div>
                            </div>

                            <!-- Issue & Suggestions -->
                            <div x-show="result.issue" x-cloak class="mb-4">
                                <div :class="result.issue === 'no_tasks' ? 'bg-yellow-50 border-yellow-500' : 'bg-orange-50 border-orange-500'"
                                     class="border-l-4 p-4 rounded-md">
                                    <h4 class="font-semibold" :class="result.issue === 'no_tasks' ? 'text-yellow-900' : 'text-orange-900'">
                                        ⚠️ Issue Detected
                                    </h4>
                                    <ul class="mt-2 space-y-1 text-sm" :class="result.issue === 'no_tasks' ? 'text-yellow-800' : 'text-orange-800'">
                                        <template x-for="suggestion in result.suggestions" :key="suggestion">
                                            <li class="flex items-start">
                                                <span class="mr-2">•</span>
                                                <span x-text="suggestion"></span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </div>

                            <!-- Active Tasks -->
                            <div x-show="result.active_tasks?.length > 0" x-cloak class="mb-4">
                                <h4 class="font-semibold text-gray-900 mb-2">🔄 Active Tasks</h4>
                                <div class="space-y-2">
                                    <template x-for="task in result.active_tasks" :key="task.id">
                                        <div class="bg-orange-50 border border-orange-200 p-3 rounded">
                                            <div class="flex items-center justify-between">
                                                <div class="flex-1">
                                                    <p class="text-sm font-medium">
                                                        Task #<span x-text="task.id"></span>
                                                        <span class="ml-2 px-2 py-0.5 text-xs rounded bg-orange-100" x-text="task.status"></span>
                                                        <span x-show="task.is_stuck" x-cloak class="ml-2 px-2 py-0.5 text-xs rounded bg-red-100 text-red-800">STUCK!</span>
                                                    </p>
                                                    <p class="text-xs text-gray-600 mt-1">
                                                        Created <span x-text="task.created_ago"></span> •
                                                        Last updated <span x-text="task.updated_ago"></span>
                                                        (<span x-text="task.stuck_minutes"></span> minutes ago)
                                                    </p>
                                                </div>
                                                <div class="flex space-x-2">
                                                    <button @click="cancelTask(task.id)"
                                                            class="px-3 py-1 bg-red-600 text-white text-xs rounded hover:bg-red-700">
                                                        Cancel
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Recent Tasks -->
                            <div x-show="result.recent_tasks?.length > 0" x-cloak>
                                <h4 class="font-semibold text-gray-900 mb-2">📋 Recent Tasks (Last 5)</h4>
                                <div class="space-y-2">
                                    <template x-for="task in result.recent_tasks" :key="task.id">
                                        <div class="bg-gray-50 border border-gray-200 p-3 rounded">
                                            <div class="flex items-center justify-between">
                                                <div class="flex-1">
                                                    <p class="text-sm font-medium">
                                                        Task #<span x-text="task.id"></span> - <span x-text="task.type"></span>
                                                        <span class="ml-2 px-2 py-0.5 text-xs rounded"
                                                              :class="{
                                                                  'bg-green-100 text-green-800': task.status === 'completed',
                                                                  'bg-red-100 text-red-800': task.status === 'failed',
                                                                  'bg-blue-100 text-blue-800': task.status === 'running',
                                                                  'bg-gray-100 text-gray-800': task.status === 'queued',
                                                                  'bg-yellow-100 text-yellow-800': task.status.includes('pending')
                                                              }"
                                                              x-text="task.status"></span>
                                                    </p>
                                                    <p class="text-xs text-gray-600 mt-1">
                                                        Created <span x-text="task.created_ago"></span> •
                                                        Duration: <span x-text="task.duration_minutes"></span> min •
                                                        Attempts: <span x-text="task.attempts"></span>
                                                    </p>
                                                    <p x-show="task.error" x-cloak class="text-xs text-red-600 mt-1 font-mono" x-text="task.error?.substring(0, 150)"></p>
                                                </div>
                                                <div>
                                                    <a :href="`/nova/resources/generation-tasks/${task.id}`"
                                                       target="_blank"
                                                       class="px-3 py-1 bg-gray-600 text-white text-xs rounded hover:bg-gray-700">
                                                        View
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="mt-6 p-4 bg-gray-50 rounded-md border border-gray-200">
                                <h4 class="font-semibold text-gray-900 mb-3">🔧 Quick Actions</h4>
                                <div class="flex gap-2">
                                    <button @click="forceStartResearch(result.exam?.id)"
                                            class="px-4 py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700">
                                        🚀 Force Start Research
                                    </button>
                                    <button @click="cancelAllTasks(result.exam?.id)"
                                            x-show="result.active_tasks?.length > 0"
                                            x-cloak
                                            class="px-4 py-2 bg-red-600 text-white text-sm rounded hover:bg-red-700">
                                        ❌ Cancel All Active Tasks
                                    </button>
                                    <button @click="diagnose"
                                            class="px-4 py-2 bg-gray-600 text-white text-sm rounded hover:bg-gray-700">
                                        🔄 Refresh
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Example IDs hint -->
                    <div x-show="!result" class="mt-4 text-xs text-gray-500">
                        <p>💡 Tip: Copy an exam ID from Nova or use the example: <code class="bg-gray-100 px-1 rounded">a0470751-3473-41c7-a796-d69ad7f6b851</code></p>
                    </div>
                </div>
            </div>

            <!-- Exam Quality Comparison -->
            <div class="bg-white rounded-lg shadow mb-8" x-data="examComparison()">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">📊 Exam Quality Comparison</h2>
                    <p class="text-sm text-gray-600 mt-1">Compare generated exam with reference exam to evaluate quality</p>
                </div>
                <div class="p-6">
                    <!-- Form -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Generated Exam ID</label>
                            <input type="text"
                                   x-model="genId"
                                   placeholder="Enter generated exam UUID"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Reference Exam</label>
                            <select x-model="refId" class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                                <option value="">-- Select reference exam --</option>
                                <template x-for="ref in referenceExams" :key="ref.id">
                                    <option :value="ref.id" x-text="`${ref.title} (${ref.level}) - ${ref.questions} Q`"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <button @click="compare"
                            :disabled="loading || !genId || !refId"
                            class="px-6 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        <span x-show="!loading">🔍 Compare</span>
                        <span x-show="loading" x-cloak>Comparing...</span>
                    </button>

                    <!-- Error -->
                    <div x-show="error" x-cloak class="mt-4 p-4 bg-red-50 border border-red-200 rounded-md">
                        <p class="text-sm text-red-800" x-text="error"></p>
                    </div>

                    <!-- Results -->
                    <div x-show="result && !error" x-cloak class="mt-6">
                        <!-- Overall Score -->
                        <div class="mb-6 p-6 rounded-lg text-center"
                             :class="{
                                 'bg-green-50 border border-green-200': result.scores?.grade === 'excellent',
                                 'bg-yellow-50 border border-yellow-200': result.scores?.grade === 'acceptable',
                                 'bg-orange-50 border border-orange-200': result.scores?.grade === 'needs_improvement',
                                 'bg-red-50 border border-red-200': result.scores?.grade === 'poor'
                             }">
                            <p class="text-sm text-gray-600 mb-2">Overall Score</p>
                            <p class="text-5xl font-bold"
                               :class="{
                                   'text-green-600': result.scores?.grade === 'excellent',
                                   'text-yellow-600': result.scores?.grade === 'acceptable',
                                   'text-orange-600': result.scores?.grade === 'needs_improvement',
                                   'text-red-600': result.scores?.grade === 'poor'
                               }"
                               x-text="result.scores?.overall + '%'"></p>
                            <p class="text-lg mt-2 font-medium"
                               :class="{
                                   'text-green-700': result.scores?.grade === 'excellent',
                                   'text-yellow-700': result.scores?.grade === 'acceptable',
                                   'text-orange-700': result.scores?.grade === 'needs_improvement',
                                   'text-red-700': result.scores?.grade === 'poor'
                               }"
                               x-text="result.scores?.grade?.replace('_', ' ').toUpperCase()"></p>
                        </div>

                        <!-- Exam Info Cards -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                                <h4 class="font-semibold text-blue-900 mb-2">Generated Exam</h4>
                                <p class="text-sm text-blue-800" x-text="result.generated?.title"></p>
                                <p class="text-xs text-blue-600 mt-1">
                                    <span x-text="result.generated?.level"></span> |
                                    <span x-text="result.generated?.sections"></span> sections |
                                    <span x-text="result.generated?.groups"></span> groups |
                                    <span x-text="result.generated?.questions"></span> questions
                                </p>
                            </div>
                            <div class="bg-green-50 p-4 rounded-lg border border-green-200">
                                <h4 class="font-semibold text-green-900 mb-2">Reference Exam</h4>
                                <p class="text-sm text-green-800" x-text="result.reference?.title"></p>
                                <p class="text-xs text-green-600 mt-1">
                                    <span x-text="result.reference?.level"></span> |
                                    <span x-text="result.reference?.sections"></span> sections |
                                    <span x-text="result.reference?.groups"></span> groups |
                                    <span x-text="result.reference?.questions"></span> questions
                                </p>
                            </div>
                        </div>

                        <!-- Score Breakdown -->
                        <div class="grid grid-cols-3 gap-4 mb-6">
                            <div class="bg-gray-50 p-4 rounded-lg border text-center">
                                <p class="text-xs text-gray-500">Structure</p>
                                <p class="text-2xl font-bold" x-text="result.scores?.structure + '%'"></p>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg border text-center">
                                <p class="text-xs text-gray-500">Groups</p>
                                <p class="text-2xl font-bold" x-text="result.scores?.groups + '%'"></p>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg border text-center">
                                <p class="text-xs text-gray-500">Sections</p>
                                <p class="text-2xl font-bold" x-text="result.scores?.sections + '%'"></p>
                            </div>
                        </div>

                        <!-- Section Comparison -->
                        <div class="mb-6">
                            <h4 class="font-semibold text-gray-900 mb-3">📑 Section Comparison</h4>
                            <div class="space-y-2">
                                <template x-for="(data, key) in result.section_comparison" :key="key">
                                    <div class="flex items-center justify-between p-3 rounded-lg"
                                         :class="{
                                             'bg-green-50 border border-green-200': data.status === 'good',
                                             'bg-yellow-50 border border-yellow-200': data.status === 'warning',
                                             'bg-red-50 border border-red-200': data.status === 'bad' || data.status === 'missing'
                                         }">
                                        <div class="flex-1">
                                            <span class="font-medium" x-text="key"></span>
                                            <span class="text-xs text-gray-500 ml-2" x-text="data.name"></span>
                                        </div>
                                        <div class="text-sm">
                                            <span>Q: <span x-text="data.gen_questions"></span>/<span x-text="data.ref_questions"></span></span>
                                            <span class="mx-2">|</span>
                                            <span>G: <span x-text="data.gen_groups"></span>/<span x-text="data.ref_groups"></span></span>
                                            <span class="ml-3 font-bold" x-text="data.avg_score + '%'"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Question Types -->
                        <div class="mb-6">
                            <h4 class="font-semibold text-gray-900 mb-3">🎯 Question Types</h4>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                                <template x-for="(data, type) in result.type_comparison" :key="type">
                                    <div class="flex items-center justify-between p-2 rounded text-sm"
                                         :class="{
                                             'bg-green-50': data.status === 'ok',
                                             'bg-red-50': data.status === 'missing',
                                             'bg-blue-50': data.status === 'extra'
                                         }">
                                        <span x-text="type"></span>
                                        <span>
                                            <span x-text="data.gen"></span>/<span x-text="data.ref"></span>
                                            <span x-show="data.status === 'missing'" class="text-red-600 ml-1">❌</span>
                                            <span x-show="data.status === 'extra'" class="text-blue-600 ml-1">➕</span>
                                        </span>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Recommendations -->
                        <div x-show="result.recommendations?.length > 0" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                            <h4 class="font-semibold text-yellow-900 mb-2">💡 Recommendations</h4>
                            <ul class="space-y-1 text-sm text-yellow-800">
                                <template x-for="rec in result.recommendations" :key="rec">
                                    <li class="flex items-start">
                                        <span class="mr-2">•</span>
                                        <span x-text="rec"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>

                        <!-- TODO Note -->
                        <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                            <p class="text-sm text-blue-800">
                                📝 <strong>Coming soon:</strong> AI-based content quality evaluation.
                                See <a href="/docs/roadmap/exam-quality-evaluation.md" class="underline">roadmap</a> for details.
                            </p>
                        </div>
                    </div>
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

            <!-- Structure Recovery Section -->
            @if($recoveryStats['needing_recovery'] > 0)
            <div class="bg-orange-50 border-l-4 border-orange-500 rounded-lg shadow mb-8" x-data="structureRecovery()">
                <div class="px-6 py-4 border-b border-orange-200">
                    <h2 class="text-lg font-semibold text-orange-900">🔧 Exam Structure Recovery ({{ $recoveryStats['needing_recovery'] }})</h2>
                    <p class="text-sm text-orange-700 mt-1">
                        Found {{ $recoveryStats['needing_recovery'] }} exam(s) with incomplete exam_structure data.
                        This can cause empty "Category Template Data" and "Questions Archetypes" in Nova.
                    </p>
                </div>
                <div class="p-6">
                    <!-- Actions -->
                    <div class="mb-6 flex gap-3">
                        <button @click="recoverAll"
                                :disabled="loading"
                                class="px-4 py-2 bg-orange-600 text-white rounded-md hover:bg-orange-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span x-show="!loading">🚀 Recover All ({{ $recoveryStats['needing_recovery'] }} exams)</span>
                            <span x-show="loading" x-cloak>Processing...</span>
                        </button>
                        <button @click="scanExams"
                                :disabled="loading"
                                class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span x-show="!loading">🔄 Refresh Scan</span>
                            <span x-show="loading" x-cloak>Scanning...</span>
                        </button>
                    </div>

                    <!-- Success message -->
                    <div x-show="successMessage" x-cloak class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
                        <p class="text-sm text-green-800" x-text="successMessage"></p>
                    </div>

                    <!-- Error message -->
                    <div x-show="errorMessage" x-cloak class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
                        <p class="text-sm text-red-800" x-text="errorMessage"></p>
                    </div>

                    <!-- Exams List -->
                    <div class="space-y-3">
                        @foreach($recoveryNeeded as $exam)
                        <div class="bg-white p-4 rounded border border-orange-200" x-data="{ recoveryLoading: false, recovered: false }">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <p class="font-medium text-gray-900">
                                        {{ Str::limit($exam->title, 60) }}
                                        <span x-show="recovered" x-cloak class="ml-2 px-2 py-0.5 text-xs rounded bg-green-100 text-green-800">✓ Recovered</span>
                                    </p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        ID: <code class="bg-gray-100 px-1 rounded">{{ $exam->id }}</code> •
                                        Status: <span class="font-semibold">{{ $exam->research_status }}</span> •
                                        Categories: <span class="font-semibold">{{ $exam->categories->count() }}</span>
                                    </p>
                                </div>
                                <div class="flex space-x-2">
                                    <button @click="recoverOne('{{ $exam->id }}')"
                                            :disabled="recoveryLoading || recovered"
                                            class="px-3 py-2 bg-orange-600 text-white text-sm rounded hover:bg-orange-700 disabled:opacity-50 disabled:cursor-not-allowed">
                                        <span x-show="!recoveryLoading">Recover</span>
                                        <span x-show="recoveryLoading" x-cloak>...</span>
                                    </button>
                                    <a href="/nova/resources/exams/{{ $exam->id }}"
                                       target="_blank"
                                       class="px-3 py-2 bg-gray-600 text-white text-sm rounded hover:bg-gray-700">
                                        View
                                    </a>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>

                    <!-- Info -->
                    <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-md">
                        <p class="text-sm text-blue-800">
                            <strong>ℹ️ What this does:</strong> Reconstructs exam_structure from existing category.meta['steps'] or ExamExampleQuestion records.
                            This fixes empty "Category Template Data" and "Questions Archetypes" panels in Nova.
                        </p>
                        <p class="text-xs text-blue-700 mt-2">
                            Recovery strategies: 1) From category.meta['question_archetypes'], 2) From category.meta['steps'], 3) From ExamExampleQuestion records
                        </p>
                    </div>
                </div>
            </div>
            @endif

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

            <!-- Queue Status -->
            @if($queueInfo['driver'] === 'database')
            <div class="bg-white rounded-lg shadow mb-8">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">🔄 Laravel Queue Status</h2>
                        <p class="text-sm text-gray-600 mt-1">Jobs waiting in queue and failed jobs</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-600">Total: <span class="font-semibold">{{ $queueInfo['total_pending'] }}</span> pending, <span class="font-semibold text-red-600">{{ $queueInfo['total_failed'] }}</span> failed</p>
                    </div>
                </div>

                <!-- Pending Jobs -->
                @if($queueInfo['pending_jobs']->count() > 0)
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-md font-semibold text-gray-900 mb-4">⏳ Pending Jobs ({{ $queueInfo['total_pending'] }})</h3>
                    <div class="space-y-3">
                        @foreach($queueInfo['pending_jobs']->take(10) as $job)
                        <div class="bg-gray-50 p-3 rounded border border-gray-200">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900">
                                        Job #{{ $job->id }} - {{ $job->job_class }}
                                        @if($job->task_id)
                                        <a href="/nova/resources/generation-tasks/{{ $job->task_id }}"
                                           class="ml-2 text-xs text-blue-600 hover:underline"
                                           target="_blank">
                                            (Task #{{ $job->task_id }})
                                        </a>
                                        @endif
                                    </p>
                                    <div class="flex items-center mt-1 text-xs text-gray-600 space-x-4">
                                        <span>Queue: <span class="font-semibold">{{ $job->queue }}</span></span>
                                        <span>Attempts: <span class="font-semibold">{{ $job->attempts }}</span></span>
                                        <span>Waiting: <span class="font-semibold">{{ $job->created_at->diffForHumans() }}</span></span>
                                        <span>Created: <span class="font-semibold">{{ $job->created_at->format('Y-m-d H:i:s') }}</span></span>
                                    </div>
                                    @if($job->reserved_at)
                                    <p class="text-xs text-orange-600 mt-1">🔒 Reserved at {{ $job->reserved_at->format('Y-m-d H:i:s') }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @endforeach

                        @if($queueInfo['total_pending'] > 10)
                        <p class="text-sm text-gray-600 text-center pt-2">
                            ... and {{ $queueInfo['total_pending'] - 10 }} more jobs
                        </p>
                        @endif
                    </div>
                </div>
                @else
                <div class="p-6 border-b border-gray-200">
                    <p class="text-sm text-gray-600 text-center">✅ No pending jobs in queue</p>
                </div>
                @endif

                <!-- Failed Jobs -->
                @if($queueInfo['failed_jobs']->count() > 0)
                <div class="p-6">
                    <h3 class="text-md font-semibold text-red-900 mb-4">❌ Failed Jobs ({{ $queueInfo['total_failed'] }})</h3>
                    <div class="space-y-3">
                        @foreach($queueInfo['failed_jobs']->take(5) as $job)
                        <div class="bg-red-50 p-3 rounded border border-red-200">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900">
                                        Job #{{ $job->id }} - {{ $job->job_class }}
                                    </p>
                                    <div class="flex items-center mt-1 text-xs text-gray-600 space-x-4">
                                        <span>Queue: <span class="font-semibold">{{ $job->queue }}</span></span>
                                        <span>Failed: <span class="font-semibold">{{ $job->failed_at->diffForHumans() }}</span></span>
                                    </div>
                                    <details class="mt-2">
                                        <summary class="text-xs text-red-600 cursor-pointer hover:text-red-800">Show exception</summary>
                                        <pre class="text-xs text-gray-700 mt-2 p-2 bg-white rounded border overflow-x-auto">{{ Str::limit($job->exception, 500) }}</pre>
                                    </details>
                                </div>
                            </div>
                        </div>
                        @endforeach

                        @if($queueInfo['total_failed'] > 5)
                        <p class="text-sm text-gray-600 text-center pt-2">
                            ... and {{ $queueInfo['total_failed'] - 5 }} more failed jobs
                        </p>
                        @endif
                    </div>
                </div>
                @endif
            </div>
            @elseif($queueInfo['driver'] === 'sync')
            <div class="bg-blue-50 border-l-4 border-blue-500 rounded-lg shadow mb-8 p-6">
                <p class="text-sm text-blue-800">
                    ℹ️ <strong>Sync Queue Driver:</strong> Jobs execute immediately without queueing. No queue monitoring available.
                </p>
            </div>
            @endif

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
                                    <button @click="forceStartResearch('{{ $task->exam_id }}')"
                                            :disabled="loading"
                                            class="px-3 py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700 disabled:opacity-50">
                                        🚀 Force Start
                                    </button>
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
        function examDiagnostics() {
            return {
                examId: '',
                result: null,
                error: null,
                loading: false,

                async diagnose() {
                    const trimmedId = this.examId.trim();

                    if (!trimmedId) {
                        this.error = 'Please enter an exam ID';
                        return;
                    }

                    this.loading = true;
                    this.error = null;
                    this.result = null;

                    try {
                        const response = await fetch(`/diagnostics-dashboard/exam/${encodeURIComponent(trimmedId)}`);
                        const data = await response.json();

                        if (!response.ok || !data.success) {
                            this.error = data.error || 'Failed to diagnose exam';
                            return;
                        }

                        this.result = data;
                    } catch (error) {
                        this.error = `Error: ${error.message}`;
                    } finally {
                        this.loading = false;
                    }
                },

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
                                reason: 'Cancelled via Exam Diagnostics'
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            alert('Task cancelled successfully!');
                            this.diagnose(); // Refresh
                        } else {
                            alert(`Error: ${data.message}`);
                        }
                    } catch (error) {
                        alert(`Error: ${error.message}`);
                    }
                },

                async cancelAllTasks(examId) {
                    if (!confirm(`Cancel ALL active tasks for exam ${examId}?`)) return;

                    try {
                        const response = await fetch(`/api/exams/${examId}/tasks/cancel-all`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({
                                reason: 'Cancelled via Exam Diagnostics'
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            alert(`Cancelled ${data.cancelled_count} task(s)!`);
                            this.diagnose(); // Refresh
                        } else {
                            alert(`Error: ${data.message}`);
                        }
                    } catch (error) {
                        alert(`Error: ${error.message}`);
                    }
                },

                async forceStartResearch(examId) {
                    if (!confirm(`Force start research for exam ${examId}?\n\nThis will:\n1. Cancel all active tasks\n2. Create new research task\n3. Start pipeline immediately`)) return;

                    try {
                        const response = await fetch(`/api/exams/${examId}/research/force-start`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({
                                without_confirmation: true,
                                overview_model: 'gpt-5-mini',
                                cancel_active: true
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            alert(`✅ Success!\n\nNew task created: #${data.task.id}\nQueue driver: ${data.queue_driver}\n\nRefreshing diagnostics...`);
                            this.diagnose(); // Refresh
                        } else {
                            alert(`❌ Error: ${data.message}`);
                        }
                    } catch (error) {
                        alert(`❌ Error: ${error.message}`);
                    }
                }
            }
        }

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

                async forceStartResearch(examId) {
                    if (!confirm(`Force start research for exam ${examId}?\n\nThis will:\n1. Cancel all active tasks\n2. Create new research task\n3. Start pipeline immediately`)) return;

                    try {
                        const response = await fetch(`/api/exams/${examId}/research/force-start`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({
                                without_confirmation: true,
                                overview_model: 'gpt-5-mini',
                                cancel_active: true
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            alert(`✅ Success!\n\nNew task created: #${data.task.id}\nQueue driver: ${data.queue_driver}\n\nRefreshing page...`);
                            window.location.reload();
                        } else {
                            alert(`❌ Error: ${data.message}`);
                        }
                    } catch (error) {
                        alert(`❌ Error: ${error.message}`);
                    }
                },

                refreshData() {
                    window.location.reload();
                }
            }
        }

        function examComparison() {
            return {
                genId: '',
                refId: '',
                referenceExams: [],
                result: null,
                error: null,
                loading: false,

                async init() {
                    try {
                        const response = await fetch('/diagnostics-dashboard/reference-exams');
                        const data = await response.json();
                        if (data.success) {
                            this.referenceExams = data.exams;
                        }
                    } catch (error) {
                        console.error('Failed to load reference exams:', error);
                    }
                },

                async compare() {
                    if (!this.genId.trim() || !this.refId) {
                        this.error = 'Please enter generated exam ID and select reference exam';
                        return;
                    }

                    this.loading = true;
                    this.error = null;
                    this.result = null;

                    try {
                        const response = await fetch(`/diagnostics-dashboard/compare/${encodeURIComponent(this.genId.trim())}/${encodeURIComponent(this.refId)}`);
                        const data = await response.json();

                        if (!response.ok || !data.success) {
                            this.error = data.error || 'Comparison failed';
                            return;
                        }

                        this.result = data;
                    } catch (error) {
                        this.error = `Error: ${error.message}`;
                    } finally {
                        this.loading = false;
                    }
                }
            }
        }

        function structureRecovery() {
            return {
                loading: false,
                successMessage: null,
                errorMessage: null,

                async scanExams() {
                    this.loading = true;
                    this.errorMessage = null;
                    this.successMessage = null;

                    try {
                        const response = await fetch('/api/diagnostics/structure-recovery/scan');
                        const data = await response.json();

                        if (data.success) {
                            this.successMessage = `Scan complete: ${data.needing_recovery} exam(s) need recovery out of ${data.total_exams} total`;
                            setTimeout(() => window.location.reload(), 2000);
                        } else {
                            this.errorMessage = data.error || 'Scan failed';
                        }
                    } catch (error) {
                        this.errorMessage = `Error: ${error.message}`;
                    } finally {
                        this.loading = false;
                    }
                },

                async recoverAll() {
                    if (!confirm('Recover structure for all exams?\n\nThis will reconstruct exam_structure for all exams with missing data.')) return;

                    this.loading = true;
                    this.errorMessage = null;
                    this.successMessage = null;

                    try {
                        const response = await fetch('/api/diagnostics/structure-recovery/recover-all', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({ dry_run: false })
                        });

                        const data = await response.json();

                        if (data.success) {
                            const results = data.results;
                            this.successMessage = `✅ Recovery complete! Recovered: ${results.recovered}, Skipped: ${results.skipped}, Failed: ${results.failed}`;

                            if (results.recovered > 0) {
                                setTimeout(() => window.location.reload(), 3000);
                            }
                        } else {
                            this.errorMessage = data.error || 'Recovery failed';
                        }
                    } catch (error) {
                        this.errorMessage = `Error: ${error.message}`;
                    } finally {
                        this.loading = false;
                    }
                },

                async recoverOne(examId) {
                    const element = event.target.closest('[x-data]');
                    const alpine = element.__x;

                    alpine.$data.recoveryLoading = true;
                    this.errorMessage = null;
                    this.successMessage = null;

                    try {
                        const response = await fetch(`/api/diagnostics/structure-recovery/recover/${examId}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({ dry_run: false })
                        });

                        const data = await response.json();

                        if (data.success) {
                            const result = data.result;
                            if (result.needed_recovery) {
                                alpine.$data.recovered = true;
                                this.successMessage = `✅ Recovered exam "${data.exam_title}": ${result.recovered_archetypes} archetypes, ${result.recovered_sections} sections`;
                            } else {
                                this.successMessage = `ℹ️ Exam "${data.exam_title}" did not need recovery`;
                            }
                        } else {
                            this.errorMessage = `Error recovering exam: ${data.error}`;
                        }
                    } catch (error) {
                        this.errorMessage = `Error: ${error.message}`;
                    } finally {
                        alpine.$data.recoveryLoading = false;
                    }
                }
            }
        }
    </script>
</body>
</html>
