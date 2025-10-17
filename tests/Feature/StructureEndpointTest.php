<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StructureEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_404_when_structure_absent(): void
    {
        $exam = Exam::factory()->create();

        $this->getJson("/api/exams/{$exam->id}/structure")
            ->assertStatus(404)
            ->assertJson(['code' => 'structure_not_ready']);
    }

    public function test_returns_structure_when_task_completed(): void
    {
        $exam = Exam::factory()->create();

        GenerationTask::create([
            'exam_id' => $exam->id,
            'type'    => 'research',
            'status'  => 'completed',
            'result'  => [
                'exam_name' => 'ielts',
                'sources'   => [
                    ['url'=>'https://example.com','title'=>'Spec','publisher'=>'Example'],
                ],
                'archetypes'=> [
                    ['id'=>'reading_tfn','name'=>'Reading — T/F/N','section'=>'reading'],
                    ['id'=>'listening_mcq','name'=>'Listening — MCQ','weights'=>['Listening'=>1.0]],
                ],
                'total_score' => ['min'=>0,'max'=>100],
            ],
        ]);

        $this->getJson("/api/exams/{$exam->id}/structure")
            ->assertOk()
            ->assertJsonStructure([
                'exam' => ['id','slug','title','research_status'],
                'sources' => [['url','title','publisher']],
                'archetypes',
                'sections' => [['key','archetype_count']],
                'total_score' => ['min','max'],
                'task_id',
            ]);
    }
}
