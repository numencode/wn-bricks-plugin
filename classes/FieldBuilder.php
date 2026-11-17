<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

/**
 * Turns a "bricks" field definition into a core repeater in group mode (one group per brick type).
 */
class FieldBuilder
{
    /**
     * Options of a bricks field that are handled here and not passed to the repeater.
     */
    public const OWN_OPTIONS = ['allow', 'ignore'];

    public const DEFAULT_PROMPT = 'numencode.bricks::lang.field.prompt';

    public const DEFAULT_LABEL = 'numencode.bricks::lang.field.label';

    /**
     * Repeater groups: code => [name, description, icon, fields]. Names and descriptions stay lang keys; the
     * repeater translates them.
     *
     * @param array<string, BrickType> $types
     * @param array<int, string> $readOnly Codes of the types whose fields are read-only
     * @return array<string, array<string, mixed>>
     */
    public static function groups(array $types, array $readOnly = []): array
    {
        $groups = [];

        foreach ($types as $code => $type) {
            $fields = $type->isComponent() ? ComponentBricks::fields($type) : $type->fields;

            if (in_array($code, $readOnly, true)) {
                $fields = array_map(fn (array $field) => ['readOnly' => true] + $field, $fields);
            }

            $groups[$code] = [
                'name'        => $type->name,
                'description' => $type->description,
                'icon'        => $type->icon,
                'fields'      => $fields,
            ];
        }

        return $groups;
    }

    /**
     * The repeater field replacing a bricks field. Options given in the definition win over the defaults
     * (prompt, style, label), except the type and the groups.
     *
     * The repeater gets a group for every type in $all, not only the offered ones: a brick whose type has no group
     * would be saved without its values. The types that are not offered are hidden in the "Add brick" list (the
     * container attribute data-bricks-hidden, read by assets/js/bricks.js).
     *
     * @param array<string, mixed> $config The bricks field definition
     * @param array<string, BrickType> $offered The brick types offered in the "Add brick" list (in this order)
     * @param array<string, BrickType>|null $all All brick types (default: the offered ones)
     * @param array<int, string> $readOnly Codes of the types the user may not edit
     * @return array<string, mixed>
     */
    public static function field(array $config, array $offered, ?array $all = null, array $readOnly = []): array
    {
        $field = array_diff_key($config, array_flip(self::OWN_OPTIONS));
        $types = $offered + ($all ?? []);
        $hidden = array_keys(array_diff_key($types, $offered));

        $field = array_merge(
            ['label' => self::DEFAULT_LABEL, 'prompt' => self::DEFAULT_PROMPT, 'style' => 'collapsed'],
            array_filter($field, fn ($value) => $value !== null && $value !== ''),
            ['type' => 'repeater', 'groups' => self::groups($types, $readOnly)],
        );

        $attributes = ['data-bricks' => static::clientConfig($types)];

        if ($hidden) {
            $attributes['data-bricks-hidden'] = implode(',', $hidden);
        }

        $field['containerAttributes'] = array_merge((array) ($field['containerAttributes'] ?? []), $attributes);

        return $field;
    }

    /**
     * The settings assets/js/bricks.js reads from the field's container (data-bricks, JSON): the titleFrom field of
     * each type that has one, the edit links (URL pattern and label) and the labels of the "Expand all" / "Collapse all"
     * buttons.
     *
     * @param array<string, BrickType> $types
     */
    public static function clientConfig(array $types): string
    {
        $titleFrom = [];
        $edit = [];

        foreach ($types as $code => $type) {
            if ($type->titleFrom !== null) {
                $titleFrom[$code] = $type->titleFrom;
            }

            if ($url = $type->editUrlPattern()) {
                $edit[$code] = [
                    'url'   => $url,
                    'label' => \Lang::get('numencode.bricks::lang.edit_link', ['type' => $type->label()]),
                ];
            }
        }

        return json_encode([
            'titleFrom'   => (object) $titleFrom,
            'edit'        => (object) $edit,
            'expandAll'   => \Lang::get('numencode.fundamentals::lang.repeater.expand_all'),
            'collapseAll' => \Lang::get('numencode.fundamentals::lang.repeater.collapse_all'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Reads an allow/ignore option: a comma separated string (layout syntax) or a list (YAML).
     *
     * @return array<int, string>
     */
    public static function codes(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($code) => trim((string) $code), $value), 'strlen'));
    }
}
