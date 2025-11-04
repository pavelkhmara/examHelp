<?php

namespace App\Nova\Cards;

use Laravel\Nova\Card;

class IdentityClarifierCard extends Card
{
    /**
     * The width of the card (1/3, 1/2, full).
     *
     * @var string
     */
    public $width = 'full';

    /**
     * Get the component name for the card.
     *
     * @return string
     */
    public function component()
    {
        return 'identity-clarifier-card';
    }

    /**
     * Prepare the card for JSON serialization.
     *
     * @return array<string, array>
     */
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'examId' => $this->meta()['examId'] ?? null,
        ]);
    }
}
