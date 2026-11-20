<?php

declare(strict_types=1);

namespace NumenCode\Bricks\FormWidgets;

use Backend\FormWidgets\Repeater;

/**
 * The editor of a bricks field with a shared structure (translation: fields): one list of bricks for every language.
 *
 * The structure (which bricks, their order, their non-translatable values) is edited in the default language, like
 * a plain repeater in group mode. The per-language values of the fields marked `translatable: true` are stored per
 * brick id in the attribute FieldBuilder::translationsKey().
 *
 * Renders the core repeater's partials and assets (its view and asset paths come after this widget's own).
 */
class SharedBricks extends Repeater
{
    protected $defaultAlias = 'sharedbricks';

    public function init()
    {
        parent::init();

        $this->viewPath = array_values(array_unique(array_merge(
            (array) $this->viewPath,
            [$this->guessViewPathFrom(Repeater::class, '/partials')],
        )));
    }

    /**
     * The core repeater's assets (called by the constructor, before init()).
     */
    protected function loadAssets()
    {
        $this->assetPath = $this->guessViewPathFrom(Repeater::class, '/assets', true);

        parent::loadAssets();
    }
}
