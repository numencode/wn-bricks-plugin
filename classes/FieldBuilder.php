<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use System\Classes\PluginManager;

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
     * The value marking a brick hidden on the website (1, or not stored). Every group gets this field: a hidden input
     * that assets/js/bricks.js enables (hidden) or disables (shown; disabled inputs aren't posted, so nothing is stored).
     */
    public const HIDDEN_FIELD = '_hidden';

    /**
     * Every brick's optional anchor (HTML id) and CSS class, in the brick form's "Advanced" part (BrickRenderer passes
     * them cleaned as brick.anchor and brick.class).
     */
    public const ANCHOR_FIELD = '_anchor';

    public const CLASS_FIELD = '_class';

    /**
     * Repeater groups: code => [name, description, icon, fields]. Names and descriptions stay lang keys; the
     * repeater translates them. Every group also gets the advanced fields (anchor, CSS class) and the HIDDEN_FIELD.
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

            $fields += static::advancedFields();
            $fields[self::HIDDEN_FIELD] = [
                'type'     => 'partial',
                'path'     => '$/numencode/bricks/classes/fieldbuilder/_hidden.php',
                'cssClass' => 'bricks-hidden-flag',
            ];

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
     * The anchor and CSS class fields of every brick, shown with the "Advanced" toggle (assets/js/bricks.js; open when
     * one of them has a value).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function advancedFields(): array
    {
        return [
            '_advanced'        => [
                'type' => 'partial',
                'path' => '$/numencode/bricks/classes/fieldbuilder/_advanced.php',
            ],
            self::ANCHOR_FIELD => [
                'label'    => 'numencode.bricks::lang.fields.anchor',
                'comment'  => 'numencode.bricks::lang.fields.anchor_comment',
                'type'     => 'text',
                'span'     => 'left',
                'cssClass' => 'bricks-advanced',
            ],
            self::CLASS_FIELD  => [
                'label'    => 'numencode.bricks::lang.fields.css_class',
                'comment'  => 'numencode.bricks::lang.fields.css_class_comment',
                'type'     => 'text',
                'span'     => 'right',
                'cssClass' => 'bricks-advanced',
            ],
        ];
    }

    /**
     * The repeater field replacing a bricks field. Options given in the definition win over the defaults
     * (prompt, style, label), except the type and the groups.
     *
     * The repeater gets a group for every type in $all, not only the offered ones: a brick whose type has no group
     * would be saved without its values. The types that are not offered are hidden in the "Add brick" list (the
     * container attribute data-bricks-hidden, read by assets/js/bricks.js).
     *
     * The groups are ordered by section (see sections()): the general types first, then each plugin's types.
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
        $types = static::sortBySection($offered + ($all ?? []));
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
     * each type that has one, the edit links (URL pattern and label), the plugin sections of the "Add brick" list
     * (label + codes; the general types have no heading), the labels of the "Expand all" / "Collapse all" buttons and
     * of the brick actions (lang "actions").
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

        $sections = [];

        foreach (static::sections($types) as $plugin => $codes) {
            if ($plugin !== '') {
                $sections[] = ['label' => static::sectionLabel((string) $plugin), 'codes' => $codes];
            }
        }

        return json_encode([
            'titleFrom'   => (object) $titleFrom,
            'edit'        => (object) $edit,
            'sections'    => $sections,
            'expandAll'   => \Lang::get('numencode.fundamentals::lang.repeater.expand_all'),
            'collapseAll' => \Lang::get('numencode.fundamentals::lang.repeater.collapse_all'),
            'labels'      => \Lang::get('numencode.bricks::lang.actions'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * The type codes per section of the "Add brick" list: plugin => codes, the general types ('') first, then the
     * plugins by their label. The types keep their order within a section.
     *
     * @param array<string, BrickType> $types
     * @return array<string, array<int, string>>
     */
    public static function sections(array $types): array
    {
        $sections = [];

        foreach ($types as $code => $type) {
            $sections[$type->plugin][] = (string) $code;
        }

        $general = $sections[''] ?? null;
        unset($sections['']);

        uksort($sections, fn (string $a, string $b) => strnatcasecmp(static::sectionLabel($a), static::sectionLabel($b)));

        return $general === null ? $sections : ['' => $general] + $sections;
    }

    /**
     * The types ordered by section (see sections()).
     *
     * @param array<string, BrickType> $types
     * @return array<string, BrickType>
     */
    public static function sortBySection(array $types): array
    {
        $sorted = [];

        foreach (static::sections($types) as $codes) {
            foreach ($codes as $code) {
                $sorted[$code] = $types[$code];
            }
        }

        return $sorted;
    }

    /**
     * The heading of a plugin's section: the plugin's author namespace and name, e.g. "NumenCode Widgets" (just the
     * name when it already starts with the author); the identifier when no such plugin is installed.
     */
    public static function sectionLabel(string $plugin): string
    {
        $details = PluginManager::instance()->findByIdentifier($plugin)?->pluginDetails() ?? [];
        $name = is_string($details['name'] ?? null) ? trim(\Lang::get($details['name'])) : '';

        if ($name === '') {
            return str_replace('.', ' ', $plugin);
        }

        $author = strstr($plugin, '.', true) ?: '';

        return $author === '' || stripos($name, $author) === 0 ? $name : $author . ' ' . $name;
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
