<?php

namespace Tests\Unit;

use App\Facades\TaskDispatcher;
use App\Jobs\RunExamResearchJob;
use App\Models\Exam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskDispatcherIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_idempotency_key_prevents_duplicates(): void
    {
        $exam = Exam::query()->create([
            'id' => (string) Str::uuid(),
            'slug' => 'idem-exam',
            'title' => 'Idem Exam',
            'level' => 'B1',
            'is_active' => true,
        ]);

        $idem = 'exam:'.$exam->id.':research:v1';

        $t1 = TaskDispatcher::enqueue('exam.research', $exam, ['exam_id' => $exam->id], RunExamResearchJob::class, $idem);
        $t2 = TaskDispatcher::enqueue('exam.research', $exam, ['exam_id' => $exam->id], RunExamResearchJob::class, $idem);

        $this->assertSame($t1->id, $t2->id);
    }
}
