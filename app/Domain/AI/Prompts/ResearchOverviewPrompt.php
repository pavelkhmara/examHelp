<?php

namespace App\Domain\AI\Prompts;

use App\Domain\AI\Contracts\Prompt;
use App\Domain\AI\Schemas\ResearchOverviewSchema;

final class ResearchOverviewPrompt implements Prompt
{
    public function __construct(
        private readonly string $examTitle,
        private readonly string $examLevel,
        private readonly string $userNotes = ''
    ) {}

    public function id(): string
    {
        return 'research_overview';
    }

    public function system(): string
    {
        // Источники разделены явно: USER_DOCS vs WEB
        return <<<SYS
You are an educational researcher building a typed exam structure.

SOURCES:
- USER_DOCS: user-provided documents (if any). Treat as primary for exam-specific rules.
- WEB: reputable official pages (.gov/.edu/official exam bodies/publishers). Use to cross-check patterns only.

WEIGHTING:
- If USER_DOCS contradict WEB, prefer USER_DOCS for exam-specific constraints.
- Otherwise, use WEB consensus and cite at least 3 diverse sources.

OUTPUT POLICY:
- Return ONLY a single JSON object strictly following the provided JSON schema.
- Map every task to a strict whitelist question type. If the pattern doesn't match the whitelist, exclude it.
- Be conservative when unsure and explain rationale in each task.
SYS;
    }

    public function user(): string
    {
        return <<<USR
Exam: {$this->examTitle}
Level: {$this->examLevel}

User notes:
{$this->userNotes}

Goal: Mine archetypes and propose typed tasks with rationale, step order if applicable, and expected_payload shape per task. Provide 'sources' with url/title/publisher and confidence.
USR;
    }

    public function jsonSchema(): array
    {
        return ResearchOverviewSchema::make();
    }

    public function opts(): array
    {
        return [
            'temperature' => 0.2,
            'web_search'  => true,     // executor will pass through to provider
        ];
    }
}
