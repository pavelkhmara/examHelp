<?php

namespace App\Nova\Fields;

use Laravel\Nova\Fields\Field;

/**
 * Custom Nova field that renders content in a collapsible panel.
 * Content is collapsed by default and can be expanded by the user.
 */
class CollapsiblePanel extends Field
{
    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'collapsible-panel';

    /**
     * Indicates if the field should be shown on the index view.
     *
     * @var bool
     */
    public $showOnIndex = false;

    /**
     * Indicates if the field should be shown on the detail view.
     *
     * @var bool
     */
    public $showOnDetail = true;

    /**
     * Indicates if the field should be shown on the creation view.
     *
     * @var bool
     */
    public $showOnCreation = false;

    /**
     * Indicates if the field should be shown on the update view.
     *
     * @var bool
     */
    public $showOnUpdate = false;

    /**
     * Create a new field.
     *
     * @param  string  $name
     * @param  string|callable|null  $attribute
     * @return void
     */
    public function __construct($name, $attribute = null, ?callable $resolveCallback = null)
    {
        parent::__construct($name, $attribute, $resolveCallback);

        // Set default collapsed state
        $this->withMeta(['collapsed' => true]);
    }

    /**
     * Set the panel heading/title
     *
     * @param  string  $heading
     * @return $this
     */
    public function heading($heading)
    {
        return $this->withMeta(['heading' => $heading]);
    }

    /**
     * Set the panel content
     *
     * @param  string|array  $content
     * @return $this
     */
    public function content($content)
    {
        return $this->withMeta(['content' => $content]);
    }

    /**
     * Set whether the panel should be collapsed by default
     *
     * @param  bool  $collapsed
     * @return $this
     */
    public function collapsed($collapsed = true)
    {
        return $this->withMeta(['collapsed' => $collapsed]);
    }

    /**
     * Set the panel to be expanded by default
     *
     * @return $this
     */
    public function expanded()
    {
        return $this->collapsed(false);
    }
}
