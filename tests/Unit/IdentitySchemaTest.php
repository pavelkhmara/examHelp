<?php

namespace Tests\Unit;

use App\Services\LanguageApp\Validators\JsonSchemaExamIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentitySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_accepts_valid_payload(): void
    {
        $v = new JsonSchemaExamIdentity;
        $ok = [
            'status' => 'certain',
            'confidence' => 0.9,
            'canonical' => [
                'family' => 'IELTS',
                'name' => 'IELTS',
                'provider' => 'Cambridge',
                'variant' => null,
                'language_of_test' => 'English',
            ],
            'candidates' => [
                ['family' => 'IELTS', 'name' => 'IELTS', 'provider' => 'Cambridge', 'score' => 0.9],
            ],
            'followups' => [],
            'need_fields' => [],
            'anchors' => [['page' => null, 'snippet' => '...']],
        ];
        $this->assertIsArray($v->validate($ok));
    }

    public function test_schema_rejects_invalid_payload(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $v = new JsonSchemaExamIdentity;
        $bad = [
            'status' => 'maybe', // wrong enum
            'confidence' => 1.3, // too big
        ];
        $v->validate($bad);
    }
}
