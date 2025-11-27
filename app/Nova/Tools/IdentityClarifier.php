<?php

namespace App\Nova\Tools;

use Laravel\Nova\ResourceTool;

class IdentityClarifier extends ResourceTool
{
    /**
     * Get the displayable name of the resource tool.
     *
     * @return string
     */
    public function name()
    {
        return 'Identity Clarifier';
    }

    /**
     * Get the component name for the resource tool.
     *
     * @return string
     */
    public function component()
    {
        return 'identity-clarifier';
    }

    /**
     * Prepare the tool for JSON serialization.
     */
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'component' => 'identity-clarifier',
        ]);
    }
}
