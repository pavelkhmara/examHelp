<?php

namespace App\Nova\Cards;

use Laravel\Nova\Card;

/**
 * StalledTaskCard - Nova Card for notifying about stalled generation tasks
 *
 * Displayed when a GenerationTask has been running for too long without heartbeat.
 * Shows:
 * - Task type and ID
 * - Time since last heartbeat
 * - Suggested actions (Cancel and restart, Force continue, etc.)
 *
 * Uses the existing IdentityClarifierCard Vue component for consistency.
 */
class StalledTaskCard extends Card
{
    /**
     * The width of the card (1/3, 1/2, 2/3, full).
     *
     * @var string
     */
    public $width = 'full';

    /**
     * Get the component name for the element.
     *
     * @return string
     */
    public function component()
    {
        // Reuse existing IdentityClarifierCard component
        // The frontend component will render different content based on cardType
        return 'identity-clarifier-card';
    }

    /**
     * Prepare the card for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'examId' => $this->meta()['examId'] ?? null,
            'cardType' => 'stalled_task',
            'taskId' => $this->meta()['taskId'] ?? null,
            'taskType' => $this->meta()['taskType'] ?? null,
            'stalledSince' => $this->meta()['stalledSince'] ?? null,
            'lastHeartbeat' => $this->meta()['lastHeartbeat'] ?? null,
            'suggestedActions' => $this->meta()['suggestedActions'] ?? [],
        ]);
    }
}
