<?php

namespace Tests\Unit;

use App\Services\LanguageApp\Validators\JsonSchemaExamOverview;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class JsonSchemaExamOverviewTest extends TestCase
{
    public function test_valid_overview_passes(): void
    {
        $data = [
          "exam_name" => "ielts",
          "sources" => [
                ["url" => "https://www.ielts.org/about-the-test","title" =>"About the test","publisher" =>"IELTS"],
                ["url" => "https://takeielts.britishcouncil.org/","title" =>"Take IELTS","publisher" =>"British Council"]
              ],
          "archetypes" => [
            [
              "id" => "reading_true_false_notgiven",
              "name" => "Reading — True/False/Not Given",
              "question_types" => ["True/False/Not Given","Matching"],
              "typical_distractors" => ["Paraphrase traps"],
              "verbs" => ["decide","select"],
              "numeric_ranges" => ["word_limits" => [1,3]],
              "weights" => ["Reading" => 1.0],
              "difficulty" => "medium"
            ],
          ],
            'sections' => [
                ['key' => 'listening', 'title'=>'Listening', 'count' => 20, 'time_per_question_sec'=>30],
                ['key' => 'reading', 'count' => 20],
            ],
            'total_score' => ['min'=>0,'max'=>100],
        ];
        $v = new JsonSchemaExamOverview();
        $out = $v->validate($data);

        $this->assertEquals(2, count($out['sources']));
        // $this->assertEquals(['min'=>0,'max'=>100], $out['total_score']);
    }

    public function test_invalid_missing_sections_fails(): void
    {
        $this->expectException(ValidationException::class);
        (new JsonSchemaExamOverview())->validate(['total_score' => ['min'=>0,'max'=>100]]);
    }

    public function test_invalid_types_fails(): void
    {
      $this->expectException(ValidationException::class);
      (new JsonSchemaExamOverview())->validate([
          'sections' => [['key'=>123, 'count'=>'twenty']],
          'total_score' => ['min'=>0,'max'=>100],
      ]);
    }
    
    public function test_overview_like_in_logs_passes()
    {
        $json = <<<JSON
    {
      "exam_name": "ielts",
      "exam_description": "",
      "timebox_minutes": 3,
      "sources": [
        {"url":"https://www.ielts.org/about-the-test","title":"About the test","publisher":"IELTS"},
        {"url":"https://takeielts.britishcouncil.org/","title":"Take IELTS","publisher":"British Council"}
      ],
      "archetypes": [
        {
          "id":"reading_true_false_notgiven",
          "name":"Reading — True/False/Not Given",
          "question_types":["True/False/Not Given","Matching"],
          "typical_distractors":["Paraphrase traps"],
          "verbs":["decide","select"],
          "numeric_ranges":{"word_limits":[1,3]},
          "weights":{"Reading":1.0},
          "difficulty":"medium"
        },
        {
          "id":"listening_mcq_single",
          "name":"Listening — MCQ single",
          "pattern":"Short audio...",
          "verbs":["choose"],
          "numeric_ranges":["times (e.g., 8:30)","percentages (0–100)"],
          "category_weights":{"Listening":1.0},
          "difficulty_band":"approx. 5–8"
        }
      ],
      "exam_matrix_provided": false
    }
    JSON;
    
        $data = json_decode($json, true);
        $v = new \App\Services\LanguageApp\Validators\JsonSchemaExamOverview();
        $out = $v->validate($data);
    
        $this->assertEquals('ielts', $out['exam_name']);
        $this->assertCount(2, $out['sources']);
        $this->assertCount(2, $out['archetypes']);
    }
}


