<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

/**
 * Translations of a bricks field with a shared structure (translation: fields).
 *
 * The structure is the default language's list of bricks. Every other language stores only the values of the fields
 * marked `translatable: true` (top-level fields of the brick type), per brick id:
 *
 *     {"k3v9q0x2ma": {"text": "Naslov"}, "p8d1w5rj4c": {"content": "<p>Besedilo</p>"}}
 *
 * An empty value means "not translated": the default language's value is used (fallback per field).
 */
class SharedTranslations
{
    /**
     * The names of a brick type's translatable fields.
     *
     * @param array<string, mixed> $fields A repeater group's (or brick type's) field definitions
     * @return array<int, string>
     */
    public static function translatableFields(array $fields): array
    {
        $names = [];

        foreach ($fields as $name => $config) {
            if (
                is_array($config)
                && !empty($config['translatable'])
                && !in_array($name, BrickType::RESERVED_FIELDS, true)
            ) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * The translatable fields of every repeater group.
     *
     * @param array<string, array<string, mixed>> $groups code => group definition (with "fields")
     * @return array<string, array<int, string>> code => field names
     */
    public static function translatableByGroup(array $groups): array
    {
        return array_map(fn ($group) => static::translatableFields((array) ($group['fields'] ?? [])), $groups);
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
     * @param array<string, array<int, string>> $translatable code => field names
     * @return array<string, array<string, mixed>> id => [field => value]
     */
    public static function extract(array $items, array $translatable): array
    {
        $map = [];

        foreach ($items as $item) {
            if (!is_array($item) || !BrickIds::isValid($id = $item[FieldBuilder::ID_FIELD] ?? null)) {
                continue;
            }

            foreach ($translatable[(string) ($item['_group'] ?? '')] ?? [] as $field) {
                if (array_key_exists($field, $item) && !static::isEmpty($item[$field])) {
                    $map[$id][$field] = $item[$field];
                }
            }
        }

        return $map;
    }

    /**
     * Keeps the translations of the bricks in the structure, only of their type's translatable fields, without empty
     * values.
     *
     * @param mixed $map id => [field => value] (stored data: anything)
     * @param array<int|string, mixed> $structure
     * @param array<string, array<int, string>> $translatable code => field names
     * @return array<string, array<string, mixed>>
     */
    public static function clean(mixed $map, array $structure, array $translatable): array
    {
        $map = is_array($map) ? $map : [];
        $cleaned = [];

        foreach ($structure as $brick) {
            if (!is_array($brick) || !BrickIds::isValid($id = $brick[FieldBuilder::ID_FIELD] ?? null)) {
                continue;
            }

            $values = is_array($map[$id] ?? null) ? $map[$id] : [];

            foreach ($translatable[(string) ($brick['_group'] ?? '')] ?? [] as $field) {
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
     * @param array<string, array<int, string>>|null $translatable code => field names; null: any field of the map
     *        (the stored translations were cleaned on save)
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

            $id = $brick[FieldBuilder::ID_FIELD] ?? null;
            $values = BrickIds::isValid($id) && is_array($map[$id] ?? null) ? $map[$id] : [];
            $fields = $translatable === null
                ? array_keys($values)
                : ($translatable[(string) ($brick['_group'] ?? '')] ?? []);

            foreach ($fields as $field) {
                if (in_array($field, BrickType::RESERVED_FIELDS, true)) {
                    continue;
                }

                if (array_key_exists($field, $values) && !static::isEmpty($values[$field])) {
                    $brick[$field] = $values[$field];
                } elseif (!$fallback) {
                    $brick[$field] = '';
                }
            }

            $structure[$index] = $brick;
        }

        return $structure;
    }
}
