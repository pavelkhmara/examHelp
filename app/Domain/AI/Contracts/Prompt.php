<?php

namespace App\Domain\AI\Contracts;

interface Prompt
{
    /** Stable identifier for logging (e.g. "research_overview") */
    public function id(): string;

    /** System message: role=system */
    public function system(): string;

    /** User message: role=user */
    public function user(): string;

    /** JSON Schema that model must obey */
    public function jsonSchema(): array;

    /** Extra opts for provider: temperature, web_search, etc. */
    public function opts(): array;
}
