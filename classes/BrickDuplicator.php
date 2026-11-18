<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Backend\FormWidgets\Repeater;
use Lang;
use Request;
use Winter\Storm\Exception\ApplicationException;
use Winter\Storm\Html\Helper as HtmlHelper;

/**
 * Duplicates a brick in the editor (the copy button): the AJAX handler onDuplicateBrick on the bricks field's repeater
 * (also Winter.Translate's MLRepeater, a subclass).
 *
 * Like the repeater's own onAddItem, it renders a new item at the next index, but filled with the posted values of
 * the source brick (_bricks_source = its index), so every field type (rich editor, media, nested repeaters) is copied
 * by the server, exactly as the form would save it. assets/js/bricks.js inserts the copy after the source brick.
 */
class BrickDuplicator
{
    public const HANDLER = 'onDuplicateBrick';

    public static function register(): void
    {
        Repeater::extend(function (Repeater $repeater) {
            $repeater->addDynamicMethod(self::HANDLER, fn () => static::duplicate($repeater));
        });
    }

    /**
     * @return array<string, string> The copy's item markup ("item") and its index ("index")
     * @throws ApplicationException
     */
    public static function duplicate(Repeater $repeater): array
    {
        // The repeater's protected API (as in its own onAddItem), in the scope of its class (MLRepeater's too)
        $handler = function (): array {
            /** @var Repeater $this */
            $this->abortIfPreviewMode();

            $config = (array) ($this->formField->config ?? []);

            if (!isset($config['containerAttributes']['data-bricks'])) {
                throw new ApplicationException('Only bricks can be duplicated.');
            }

            $name = $this->formField->getName();
            $items = post($name);
            $source = post('_bricks_source');

            if (!is_array($items) || !is_scalar($source) || !is_array($copy = $items[$source] ?? null)) {
                throw new ApplicationException(Lang::get('numencode.bricks::lang.actions.duplicate_failed'));
            }

            if ($this->maxItems && count($items) >= $this->maxItems) {
                throw new ApplicationException(Lang::get('numencode.bricks::lang.actions.duplicate_max'));
            }

            $index = $this->getNextIndex();

            // The new item's form reads its values from the posted field at its index
            $parts = HtmlHelper::nameToArray($name);
            $root = array_shift($parts);
            $value = Request::post($root);
            $value = is_array($value) ? $value : [];
            array_set($value, implode('.', array_merge($parts, [(string) $index])), $copy);
            Request::merge([$root => $value]);

            // Winter.Translate's MLRepeater renders the repeater's own partials like this (see its onAddItem)
            if (method_exists($this, 'actAsParent')) {
                $this->actAsParent();
            }

            $this->prepareVars();
            $this->vars['widget'] = $this->makeItemFormWidget($index, $copy['_group'] ?? null);
            $this->vars['indexValue'] = $index;

            return ['item' => $this->makePartial('repeater_item'), 'index' => (string) $index];
        };

        return \Closure::bind($handler, $repeater, get_class($repeater))();
    }
}
