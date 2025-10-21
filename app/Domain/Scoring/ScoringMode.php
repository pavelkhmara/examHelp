<?php

namespace App\Domain\Scoring;

enum ScoringMode: string
{
    case EXACT = 'exact';
    case PARTIAL = 'partial';
    case FUZZY = 'fuzzy';
    case REGEX = 'regex';
    case RUBRIC = 'rubric';
}
