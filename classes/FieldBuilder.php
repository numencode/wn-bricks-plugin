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
     * @return array<string, array<string, mixed>>
     */
    public static function groups(array $types): array
    {
        $groups = [];

        foreach ($types as $code => $type) {
            $groups[$code] = [
                'name'        => $type->name,
                'description' => $type->description,
                'icon'        => $type->icon,
                'fields'      => $type->fields,
            ];
        }

        return $groups;
    }

    /**
     * The repeater field replacing a bricks field. Options given in the definition win over the defaults
     * (prompt, style, label), except the type and the groups.
     *
     * @param array<string, mixed> $config The bricks field definition
     * @param array<string, BrickType> $types The brick types offered in this field
     * @return array<string, mixed>
     */
    public static function field(array $config, array $types): array
    {
        $field = array_diff_key($config, array_flip(self::OWN_OPTIONS));

        return array_merge(
            ['label' => self::DEFAULT_LABEL, 'prompt' => self::DEFAULT_PROMPT, 'style' => 'collapsed'],
            array_filter($field, fn ($value) => $value !== null && $value !== ''),
            ['type' => 'repeater', 'groups' => self::groups($types)],
        );
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
