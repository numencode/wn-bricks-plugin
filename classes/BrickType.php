<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Lang;
use Yaml;

/**
 * One brick type: its code, labels, form fields and markup, read from <code>.yaml (+ <code>.htm).
 *
 * The name and description may be lang keys; label() and comment() translate them.
 */
final class BrickType
{
    /**
     * Keys allowed in a definition file.
     */
    public const KEYS = ['name', 'description', 'icon', 'fields', 'component', 'permissions', 'titleFrom', 'editUrl'];

    /**
     * Field names a brick type cannot use: "_group" stores the type code, "_hidden" marks a brick hidden on the website,
     * "_anchor" and "_class" are every brick's anchor and CSS class (brick.anchor, brick.class), "brick" is a template
     * variable.
     */
    public const RESERVED_FIELDS = ['_group', '_hidden', '_anchor', '_class', '_id', 'brick'];

    public const DEFAULT_ICON = 'icon-cube';

    /**
     * The field type of a bricks field (a layout variable, a model field or a field of a brick type: nested bricks).
     */
    public const BRICKS_FIELD_TYPE = 'bricks';

    /**
     * @param array<string, array<string, mixed>> $fields Backend form field definitions
     * @param string|null $component Component class of a component brick
     * @param string|null $markupPath The type's own markup file (a theme partial takes precedence when rendering)
     * @param string $source Where the definition comes from, for error messages
     * @param array<int, string> $permissions Backend permissions needed to add or edit the brick (any of them)
     * @param string|null $titleFrom The field whose value is shown on a collapsed brick (default: the first filled field)
     * @param string|null $editUrl Backend URL (pattern) where the brick's content is edited, {field} = a field's value
     * @param string $plugin The plugin that provides the type (Author.Plugin), FieldBuilder::THEME_SECTION . <theme> for
     *                       a theme's own types, '' for the general types (NumenCode.Bricks' base set and callbacks
     *                       without an owner); the "Add brick" list groups the types by it
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $description = '',
        public readonly string $icon = self::DEFAULT_ICON,
        public readonly array $fields = [],
        public readonly ?string $component = null,
        public readonly ?string $markupPath = null,
        public readonly string $source = '',
        public readonly array $permissions = [],
        public readonly ?string $titleFrom = null,
        public readonly ?string $editUrl = null,
        public readonly string $plugin = '',
    ) {
    }

    /**
     * Reads a definition file.
     *
     * @throws InvalidBrickException
     */
    public static function fromYaml(string $code, string $yamlPath, ?string $markupPath = null): self
    {
        if (!is_file($yamlPath)) {
            throw InvalidBrickException::make($code, $yamlPath, 'the definition file does not exist');
        }

        try {
            $config = Yaml::parseFile($yamlPath);
        } catch (\Throwable $exception) {
            throw InvalidBrickException::make($code, $yamlPath, $exception->getMessage());
        }

        return self::fromArray($code, is_array($config) ? $config : [], $markupPath, $yamlPath);
    }

    /**
     * Builds a type from a parsed definition and validates it.
     *
     * @param array<string, mixed> $config
     * @throws InvalidBrickException
     */
    public static function fromArray(string $code, array $config, ?string $markupPath = null, string $source = ''): self
    {
        $fail = fn (string $problem) => InvalidBrickException::make($code, $source ?: 'inline', $problem);

        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $code)) {
            throw $fail('the code must start with a lowercase letter and contain only a-z, 0-9, "_" and "-"');
        }

        if ($unknown = array_diff(array_keys($config), self::KEYS)) {
            throw $fail('unknown key(s) ' . implode(', ', $unknown) . '; allowed: ' . implode(', ', self::KEYS));
        }

        if (!is_string($config['name'] ?? null) || trim($config['name']) === '') {
            throw $fail('"name" is required');
        }

        foreach (['description', 'icon', 'component', 'titleFrom', 'editUrl'] as $key) {
            if (isset($config[$key]) && !is_string($config[$key])) {
                throw $fail("\"{$key}\" must be a string");
            }
        }

        $fields = $config['fields'] ?? [];
        $component = $config['component'] ?? null;

        if (!is_array($fields)) {
            throw $fail('"fields" must be a list of form fields');
        }

        foreach ($fields as $name => $field) {
            if (!is_string($name) || !is_array($field)) {
                throw $fail('each field must be a name with a field definition');
            }

            if (in_array($name, self::RESERVED_FIELDS, true)) {
                throw $fail("the field name \"{$name}\" is reserved");
            }
        }

        // A component's properties are only known when its fields are built
        if (isset($config['titleFrom']) && $component === null && !isset($fields[$config['titleFrom']])) {
            throw $fail("\"titleFrom\" names the field \"{$config['titleFrom']}\", which the type doesn't have");
        }

        // A type without fields (e.g. a divider) only needs markup
        if ($component === null && $markupPath === null) {
            throw $fail('no markup found (a <code>.htm next to the definition or a theme partial bricks/<code>.htm)');
        }

        $permissions = $config['permissions'] ?? [];
        $permissions = is_string($permissions) ? [$permissions] : $permissions;

        if (!is_array($permissions) || array_filter($permissions, fn ($permission) => !is_string($permission))) {
            throw $fail('"permissions" must be a permission code or a list of them');
        }

        return new self(
            code: $code,
            name: $config['name'],
            description: $config['description'] ?? '',
            icon: $config['icon'] ?? self::DEFAULT_ICON,
            fields: $fields,
            component: $component,
            markupPath: $markupPath,
            source: $source,
            permissions: array_values($permissions),
            titleFrom: $config['titleFrom'] ?? null,
            editUrl: $config['editUrl'] ?? null,
        );
    }

    /**
     * The same type, provided by another plugin ('' = general).
     */
    public function withPlugin(string $plugin): self
    {
        return new self(...['plugin' => $plugin] + get_object_vars($this));
    }

    public function isComponent(): bool
    {
        return $this->component !== null;
    }

    /**
     * The type's nested bricks fields (`type: bricks` among its own fields; not inside other fields): name =>
     * definition. Component bricks have none.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bricksFields(): array
    {
        if ($this->isComponent()) {
            return [];
        }

        return array_filter($this->fields, fn (array $field) => ($field['type'] ?? null) === self::BRICKS_FIELD_TYPE);
    }

    /**
     * Whether bricks of this type hold other bricks (a nested bricks field, e.g. the columns of Columns).
     */
    public function isContainer(): bool
    {
        return $this->bricksFields() !== [];
    }

    /**
     * Whether a backend user may add and edit bricks of this type (types without permissions: everyone).
     */
    public function isAllowedFor(?\Backend\Models\User $user): bool
    {
        return !$this->permissions || ($user !== null && $user->hasAnyAccess($this->permissions));
    }

    /**
     * Where the brick's content is edited, as a URL pattern ({field} is replaced with the field's value in the
     * browser): the definition's editUrl, else the component's static bricksEditUrl() (a component brick's plugin
     * declares it, e.g. NumenCode.Widgets: "numencode/widgets/features/update/{group}"). A path is a backend URL.
     * Null: no link (also when the component returns null, e.g. without the permission to edit it).
     */
    public function editUrlPattern(): ?string
    {
        $pattern = $this->editUrl;

        if ($pattern === null && $this->component !== null && method_exists($this->component, 'bricksEditUrl')) {
            $pattern = $this->component::bricksEditUrl();
        }

        if (!is_string($pattern) || trim($pattern) === '') {
            return null;
        }

        $url = preg_match('#^(https?:)?//#i', $pattern) ? $pattern : \Backend::url(ltrim($pattern, '/'));

        // The URL generator encodes the placeholders' braces
        return str_ireplace(['%7B', '%7D'], ['{', '}'], $url);
    }

    /**
     * The translated name.
     */
    public function label(): string
    {
        return Lang::get($this->name);
    }

    /**
     * The translated description.
     */
    public function comment(): string
    {
        return $this->description === '' ? '' : Lang::get($this->description);
    }
}
