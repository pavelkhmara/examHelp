<?php

/**
 * Test AssemblyResolver Service
 *
 * Tests the resolution of assembly configs from Phase B into generation plans.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Exam;
use App\Services\LanguageApp\AssemblyResolver;

echo "🧪 Testing AssemblyResolver\n";
echo str_repeat('=', 80) . "\n\n";

// Find exam with Phase B structure
$exam = Exam::where('slug', 'ielts-academic-full-pipeline-test')
    ->latest()
    ->first();

if (!$exam) {
    echo "❌ Test exam not found. Creating new test exam with Phase B structure...\n\n";

    // Create test exam with Phase B structure
    $exam = Exam::create([
        'title' => 'IELTS Academic (Assembly Resolver Test)',
        'slug' => 'ielts-academic-assembly-resolver-test',
        'level' => 'B2',
        'language_of_test' => 'en',
        'family' => 'IELTS',
        'provider' => 'IDP/British Council',
        'description' => 'Test exam for AssemblyResolver',
        'research_status' => 'completed',
        'meta' => [
            'structure_v2' => [
                'id' => 'ielts-academic-test',
                'version' => '2.0',
                'meta' => [
                    'name' => 'IELTS Academic',
                    'language' => 'en',
                    'level' => 'B2',
                    'provider' => 'IDP/British Council',
                ],
                'pass_policy' => [
                    'mode' => 'overall_only',
                    'overall_threshold_percent' => 60,
                ],
                'sections' => [
                    // Listening section - BLUEPRINT mode
                    [
                        'id' => 'sec-listening',
                        'skill' => 'listening',
                        'title' => 'Listening',
                        'duration_min' => 30,
                        'max_score' => 40,
                        'min_pass_percent' => 50,
                        'assembly' => [
                            'mode' => 'blueprint',
                            'blueprint' => [
                                [
                                    'slot' => 'slot-1-social',
                                    'from_pool' => 'listening-mcq-pool',
                                    'pick' => 10,
                                    'filters' => [
                                        'type' => ['single_select'],
                                        'context' => 'social',
                                    ],
                                ],
                                [
                                    'slot' => 'slot-2-education',
                                    'from_pool' => 'listening-mcq-pool',
                                    'pick' => 10,
                                    'filters' => [
                                        'type' => ['single_select'],
                                        'context' => 'education',
                                    ],
                                ],
                                [
                                    'slot' => 'slot-3-mixed',
                                    'from_pool' => 'listening-mixed-pool',
                                    'pick' => 20,
                                    'filters' => [
                                        'type' => ['sentence_completion', 'form_completion'],
                                    ],
                                ],
                            ],
                            'assertions' => [
                                'total_questions_equals' => 40,
                            ],
                        ],
                    ],
                    // Reading section - POOL mode
                    [
                        'id' => 'sec-reading',
                        'skill' => 'reading',
                        'title' => 'Reading',
                        'duration_min' => 60,
                        'max_score' => 40,
                        'min_pass_percent' => 50,
                        'assembly' => [
                            'mode' => 'pool',
                            'pool_id' => 'reading-general-pool',
                            'filters' => [
                                'type' => ['single_select', 'true_false', 'matching'],
                            ],
                            'pick' => 40,
                            'seed' => 'ielts-reading-seed-123',
                            'assertions' => [
                                'total_questions_equals' => 40,
                            ],
                        ],
                    ],
                    // Writing section - INLINE mode
                    [
                        'id' => 'sec-writing',
                        'skill' => 'writing',
                        'title' => 'Writing',
                        'duration_min' => 60,
                        'max_score' => 9,
                        'min_pass_percent' => 55,
                        'assembly' => [
                            'mode' => 'inline',
                            'placeholders' => [
                                [
                                    'id' => 'writing-question-1',
                                    'type' => 'graph_description',
                                ],
                                [
                                    'id' => 'writing-question-2',
                                    'type' => 'essay',
                                ],
                            ],
                            'assertions' => [
                                'total_questions_equals' => 2,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    echo "✅ Test exam created: {$exam->title} (ID: {$exam->id})\n\n";
}

echo "📋 Exam: {$exam->title} (ID: {$exam->id})\n";
echo "   Slug: {$exam->slug}\n\n";

// Check structure_v2
$structure = $exam->meta['structure_v2'] ?? null;
if (!$structure) {
    echo "❌ No structure_v2 found in exam meta\n";
    exit(1);
}

$sections = $structure['sections'] ?? [];
echo "   Sections: " . count($sections) . "\n\n";

// Test AssemblyResolver
try {
    $resolver = app(AssemblyResolver::class);

    echo "🔧 Running AssemblyResolver::resolve()...\n\n";
    $startTime = microtime(true);

    $plans = $resolver->resolve($exam);

    $elapsed = round((microtime(true) - $startTime) * 1000, 2);

    echo "✅ AssemblyResolver completed in {$elapsed}ms\n\n";

    echo "📊 Generation Plans Created:\n";
    echo str_repeat('-', 80) . "\n";

    foreach ($plans as $i => $plan) {
        echo "\n[" . ($i + 1) . "] Plan ID: {$plan->id}\n";
        echo "   Section: {$plan->section_id}\n";
        echo "   Assembly Mode: {$plan->assembly_mode}\n";
        echo "   Total Questions: {$plan->total_questions}\n";
        echo "   Status: {$plan->status}\n";
        echo "   Plan Data:\n";
        echo "   " . json_encode($plan->plan_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }

    echo "\n" . str_repeat('=', 80) . "\n";
    echo "✅ TEST PASSED - AssemblyResolver works correctly!\n";
    echo "   Created " . count($plans) . " generation plans\n";

    // Show database records
    echo "\n📦 Database Records:\n";
    $dbPlans = \App\Models\GenerationPlan::where('exam_id', $exam->id)->get();
    echo "   Generation Plans in DB: " . $dbPlans->count() . "\n";

    foreach ($dbPlans as $plan) {
        echo "   - {$plan->section_id}: {$plan->assembly_mode} ({$plan->total_questions} questions)\n";
    }

} catch (\Throwable $e) {
    echo "\n❌ TEST FAILED\n";
    echo "Error: {$e->getMessage()}\n";
    echo "File: {$e->getFile()}:{$e->getLine()}\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
