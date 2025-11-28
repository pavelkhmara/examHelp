<?php

namespace Tests\Feature\Generation;

use App\Models\Exam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FullGenerationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_scaffold(): void
    {
        $this->markTestIncomplete('E2E mock wiring for AI provider to be implemented.');

        $exam = Exam::factory()->create([
            'title' => 'Test Exam Full Flow',
            'level' => 'B1',
        ]);

        $this->assertNotNull($exam->id);
    }
}
