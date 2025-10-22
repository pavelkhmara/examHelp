<?php

namespace Tests\Unit\Ai;

use App\Domain\AI\Schemas\ResearchOverviewSchema;
use App\Services\LanguageApp\Validators\AiJsonValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaStrictnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_research_overview_schema_rejects_unknown_type(): void
    {
        $schema = ResearchOverviewSchema::make();

        $ok = [
            'summary' => 'fine',
            'tasks' => [[
                'type' => 'single_select',
                'name' => 'T',
                'rationale' => 'R',
                'expected_payload' => [],
            ]],
            'sources' => [[ 'url' => 'https://ex.org', 'title' => 'X' ]],
        ];

        AiJsonValidator::validate($schema, $ok);

        $bad = $ok;
        $bad['tasks'][0]['type'] = 'SOMETHING_NEW';

        $this->expectException(\App\Support\LiteValidationException::class);
        AiJsonValidator::validate($schema, $bad);
    }
}
