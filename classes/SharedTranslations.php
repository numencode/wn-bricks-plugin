<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

/**
 * Translations of a bricks field with a shared structure (translation: fields).
 *
 * The structure is the default language's list of bricks. Every other language stores only the values of the fields
 * marked `translatable: true`, per id:
 *
 *     {"k3v9q0x2ma": {"text": "Naslov"}, "p8d1w5rj4c": {"content": "<p>Besedilo</p>"}}
 *
 * An empty value means "not translated": the default language's value is used (fallback per field).
 *
 * What is translated (a "unit", units()): every brick with an id – also bricks inside bricks (e.g. in the columns of
 * Columns, nestedLists()) – and every item with an id of a brick's repeater whose form has translatable fields (e.g.
 * the gallery's images with their captions, itemLists(); FieldBuilder gives these items an id field). All ids are unique
 * in the whole field, so one flat map holds them all. The translatable fields are looked up by the brick's type code,
 * for items by "<code>.<repeater field>" (translatableOf()). Values without an id never take part.
 */
class SharedTranslations
{
    /**
     * Separates a brick type's code and a repeater field's name in the key of the repeater items' translatable fields.
     */
    public const ITEM_SEPARATOR = '.';

    /**
     * A field option: `machineTranslation: false` keeps a translatable field (e.g. a link) away from machine
     * translation – it is translated by hand only.
     */
    public const MACHINE_OPTION = 'machineTranslation';

    /**
     * Set by FieldBuilder::groups() on a translatable field that must not be machine-translated, in place of
     * `translatable` (Winter.Translate machine-translates every `translatable` field of a repeater's groups on "copy
     * from"). The field stays translatable in a shared structure.
     */
    public const MANUAL_OPTION = 'bricksTranslatable';

    /**
     * Whether a field definition is translatable (`translatable: true`, also when only translated by hand).
     *
     * @param array<string, mixed> $config
     */
    public static function isTranslatable(array $config): bool
    {
        return !empty($config['translatable']) || !empty($config[self::MANUAL_OPTION]);
    }

    /**
     * Whether a translatable field may be machine-translated (not `machineTranslation: false`).
     *
     * @param array<string, mixed> $config
     */
    public static function isMachineTranslatable(array $config): bool
    {
        return static::isTranslatable($config)
            && empty($config[self::MANUAL_OPTION])
            && ($config[self::MACHINE_OPTION] ?? true) !== false;
    }

    /**
     * The names of a brick type's translatable fields (its own fields); $machine: only those that may be
     * machine-translated.
     *
     * @param array<string, mixed> $fields A repeater group's (or brick type's) field definitions
     * @return array<int, string>
     */
    public static function translatableFields(array $fields, bool $machine = false): array
    {
        $names = [];

        foreach ($fields as $name => $config) {
            if (
                is_array($config)
                && ($machine ? static::isMachineTranslatable($config) : static::isTranslatable($config))
                && !in_array($name, BrickType::RESERVED_FIELDS, true)
            ) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * The translatable fields of the items of a brick type's repeaters (an ordinary repeater with its form inline, not
     * a bricks field): repeater field name => the item fields marked `translatable: true`.
     *
     * @param array<string, mixed> $fields
     * @return array<string, array<int, string>>
     */
    public static function itemFields(array $fields, bool $machine = false): array
    {
        $items = [];

        foreach ($fields as $name => $config) {
            if (
                !is_array($config)
                || ($config['type'] ?? null) !== 'repeater'
                || isset($config['groups'])
                || isset($config['containerAttributes']['data-bricks'])
                || !is_array($config['form']['fields'] ?? null)
            ) {
                continue;
            }

            if ($names = static::translatableFields($config['form']['fields'], $machine)) {
                $items[(string) $name] = $names;
            }
        }

        return $items;
    }

    /**
     * The translatable fields of a brick type: its code => its fields, "<code>.<repeater>" => the repeater items' fields.
     *
     * @param array<string, mixed> $fields
     * @return array<string, array<int, string>>
     */
    public static function translatableOf(string $code, array $fields, bool $machine = false): array
    {
        $translatable = [$code => static::translatableFields($fields, $machine)];

        foreach (static::itemFields($fields, $machine) as $name => $names) {
            $translatable[$code . self::ITEM_SEPARATOR . $name] = $names;
        }

        return $translatable;
    }

    /**
     * The translatable fields of every repeater group (see translatableOf()); $machine: only those that may be
     * machine-translated.
     *
     * @param array<string, array<string, mixed>> $groups code => group definition (with "fields")
     * @return array<string, array<int, string>>
     */
    public static function translatableByGroup(array $groups, bool $machine = false): array
    {
        $translatable = [];

        foreach ($groups as $code => $group) {
            $translatable += static::translatableOf((string) $code, (array) ($group['fields'] ?? []), $machine);
        }

        return $translatable;
    }

    /**
     * Whether a value counts as not translated: null, an empty list, or text without content (whitespace, or HTML
     * with no text and no media, e.g. an empty rich editor's "<p><br></p>").
     */
    public static function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === [] || $value === '') {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        $text = strip_tags($value, '<img><iframe><video><audio><embed><object><svg>');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($text, " \t\n\r\0\x0B\u{A0}") === '';
    }

    /**
     * The translations in a list of bricks posted in another language (the editor's items in translation mode).
     *
     * @param array<int|string, mixed> $items
     * @param array<string, array<int, string>> $translatable key => field names (translatableOf())
     * @return array<string, array<string, mixed>> id => [field => value]
     */
    public static function extract(array $items, array $translatable): array
    {
        $map = [];

        foreach (static::units($items) as [$key, $unit]) {
            if (!BrickIds::isValid($id = $unit[FieldBuilder::ID_FIELD] ?? null)) {
                continue;
            }

            foreach ($translatable[$key] ?? [] as $field) {
                if (array_key_exists($field, $unit) && !static::isEmpty($unit[$field])) {
                    $map[$id][$field] = $unit[$field];
                }
            }
        }

        return $map;
    }

    /**
     * Keeps the translations of the units in the structure, only of their translatable fields, without empty values.
     *
     * @param mixed $map id => [field => value] (stored data: anything)
     * @param array<int|string, mixed> $structure
     * @param array<string, array<int, string>> $translatable key => field names (translatableOf())
     * @param array<int, string> $keep Codes whose translations are kept as they are (types that no longer exist: their
     *        fields are unknown, and they come back when the type does)
     * @return array<string, array<string, mixed>>
     */
    public static function clean(mixed $map, array $structure, array $translatable, array $keep = []): array
    {
        $map = is_array($map) ? $map : [];
        $cleaned = [];

        foreach (static::units($structure) as [$key, $unit]) {
            if (!BrickIds::isValid($id = $unit[FieldBuilder::ID_FIELD] ?? null)) {
                continue;
            }

            $values = is_array($map[$id] ?? null) ? $map[$id] : [];
            $code = explode(self::ITEM_SEPARATOR, $key)[0];
            $fields = in_array($code, $keep, true)
                ? array_diff(array_map('strval', array_keys($values)), BrickType::RESERVED_FIELDS)
                : $translatable[$key] ?? [];

            foreach ($fields as $field) {
                if (array_key_exists($field, $values) && !static::isEmpty($values[$field])) {
                    $cleaned[$id][$field] = $values[$field];
                }
            }
        }

        return $cleaned;
    }

    /**
     * The structure with the translations applied.
     *
     * @param array<int|string, mixed> $structure
     * @param mixed $map id => [field => value]
     * @param array<string, array<int, string>>|null $translatable key => field names (translatableOf()); null: any
     *        field of the map (the stored translations were cleaned on save)
     * @param bool $fallback Untranslated fields keep the default value (website); false: they are emptied (editor)
     * @return array<int|string, mixed>
     */
    public static function merge(array $structure, mixed $map, ?array $translatable = null, bool $fallback = true): array
    {
        $map = is_array($map) ? $map : [];

        foreach ($structure as $index => $brick) {
            if (!is_array($brick)) {
                continue;
            }

            $code = (string) ($brick['_group'] ?? '');
            $brick = static::apply($brick, $map, $translatable === null ? null : ($translatable[$code] ?? []), $fallback);

            foreach (static::itemLists($brick) as $name => $items) {
                $fields = $translatable === null ? null : ($translatable[$code . self::ITEM_SEPARATOR . $name] ?? []);

                foreach ($items as $key => $item) {
                    $items[$key] = static::apply($item, $map, $fields, $fallback);
                }

                $brick[$name] = $items;
            }

            foreach (static::nestedLists($brick) as $name => $list) {
                $brick[$name] = static::merge($list, $map, $translatable, $fallback);
            }

            $structure[$index] = $brick;
        }

        return $structure;
    }

    /**
     * A unit (brick or item) with its translations applied.
     *
     * @param array<string, mixed> $unit
     * @param array<string, mixed> $map
     * @param array<int, string>|null $fields The translatable fields; null: any field of the unit's translations
     * @return array<string, mixed>
     */
    protected static function apply(array $unit, array $map, ?array $fields, bool $fallback): array
    {
        $id = $unit[FieldBuilder::ID_FIELD] ?? null;
        $values = BrickIds::isValid($id) && is_array($map[$id] ?? null) ? $map[$id] : [];

        foreach ($fields ?? array_keys($values) as $field) {
            if (in_array($field, BrickType::RESERVED_FIELDS, true)) {
                continue;
            }

            if (array_key_exists($field, $values) && !static::isEmpty($values[$field])) {
                $unit[$field] = $values[$field];
            } elseif (!$fallback) {
                $unit[$field] = '';
            }
        }

        return $unit;
    }

    /**
     * The bricks of a list and of the lists inside them, depth first.
     *
     * @param array<int|string, mixed> $bricks
     * @return array<int, array<string, mixed>>
     */
    public static function flatten(array $bricks): array
    {
        return array_values(array_map(
            fn (array $unit) => $unit[1],
            array_filter(static::units($bricks), fn (array $unit) => !str_contains($unit[0], self::ITEM_SEPARATOR)),
        ));
    }

    /**
     * Everything translated in a list of bricks: [key, value] of every brick (key: its type code) and of every
     * repeater item with an id (key: "<code>.<repeater field>"), depth first.
     *
     * @param array<int|string, mixed> $bricks
     * @return array<int, array{0: string, 1: array<string, mixed>}>
     */
    public static function units(array $bricks): array
    {
        $units = [];

        foreach ($bricks as $brick) {
            if (!is_array($brick)) {
                continue;
            }

            $code = (string) ($brick['_group'] ?? '');
            $units[] = [$code, $brick];

            foreach (static::itemLists($brick) as $name => $items) {
                foreach ($items as $item) {
                    $units[] = [$code . self::ITEM_SEPARATOR . $name, $item];
                }
            }

            foreach (static::nestedLists($brick) as $list) {
                array_push($units, ...static::units($list));
            }
        }

        return $units;
    }

    /**
     * A brick's lists of bricks (its values whose items all have a "_group"): field name => list.
     *
     * @param array<string, mixed> $brick
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    public static function nestedLists(array $brick): array
    {
        return static::listsOf($brick, true);
    }

    /**
     * A brick's repeater items that can be translated (its values whose items are arrays without a "_group", at least
     * one with an id): field name => items.
     *
     * @param array<string, mixed> $brick
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    public static function itemLists(array $brick): array
    {
        return static::listsOf($brick, false);
    }

    /**
     * @param array<string, mixed> $brick
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    protected static function listsOf(array $brick, bool $bricks): array
    {
        $lists = [];

        foreach ($brick as $name => $value) {
            if (!is_array($value) || $value === [] || in_array($name, BrickType::RESERVED_FIELDS, true)) {
                continue;
            }

            $withId = false;

            foreach ($value as $item) {
                if (!is_array($item) || is_string($item['_group'] ?? null) !== $bricks) {
                    continue 2;
                }

                $withId = $withId || BrickIds::isValid($item[FieldBuilder::ID_FIELD] ?? null);
            }

            if ($bricks || $withId) {
                $lists[(string) $name] = $value;
            }
        }

        return $lists;
    }
}
