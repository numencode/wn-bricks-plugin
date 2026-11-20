<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Backend\FormWidgets\Repeater;
use Lang;
use Request;
use Winter\Storm\Exception\ApplicationException;
use Winter\Storm\Html\Helper as HtmlHelper;

/**
 * Duplicates and pastes bricks in the editor: the AJAX handler onDuplicateBrick on the bricks field's repeater (also
 * Winter.Translate's MLRepeater, a subclass).
 *
 * Like the repeater's own onAddItem, it renders a new item at the next index, but filled with values:
 * - duplicate (the copy button): the posted values of the brick at the index _bricks_source;
 * - paste (copied to the browser's local storage, from any page or language): _bricks_paste, the brick's values as JSON.
 *
 * So every field type (rich editor, media, nested repeaters) is filled by the server, exactly as the form would save it.
 * A pasted brick must be of a type the field offers. assets/js/bricks.js inserts the new item (a duplicate after its
 * source, a pasted brick at the end).
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
     * The values of a pasted brick (JSON from the browser), or null when they aren't a brick.
     *
     * @return array<string, mixed>|null
     */
    public static function pastedValues(mixed $json): ?array
    {
        $values = is_string($json) ? json_decode($json, true) : null;

        if (!is_array($values) || !is_string($values['_group'] ?? null) || $values['_group'] === '') {
            return null;
        }

        unset($values['brick']);

        return $values;
    }

    /**
     * @return array<string, string> The new item's markup ("item") and its index ("index")
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
            $items = is_array($items) ? $items : [];

            if (($paste = post('_bricks_paste')) !== null) {
                $copy = BrickDuplicator::pastedValues($paste);
                $hidden = explode(',', (string) ($config['containerAttributes']['data-bricks-hidden'] ?? ''));

                // Only types this field offers (not ones hidden by allow/ignore or permissions, or unknown here)
                if (!$copy || !isset($this->groupDefinitions[$copy['_group']]) || in_array($copy['_group'], $hidden, true)) {
                    throw new ApplicationException(Lang::get('numencode.bricks::lang.actions.paste_unavailable'));
                }
            } else {
                $source = post('_bricks_source');
                $copy = is_scalar($source) ? ($items[$source] ?? null) : null;

                if (!is_array($copy)) {
                    throw new ApplicationException(Lang::get('numencode.bricks::lang.actions.duplicate_failed'));
                }
            }

            // The copy is a new brick: it gets its own id on save
            unset($copy[FieldBuilder::ID_FIELD]);

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
