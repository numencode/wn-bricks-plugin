<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Cms\Classes\ComponentBase;
use Cms\Classes\ComponentHelpers;
use Cms\Classes\Controller;
use Cms\Classes\Page as CmsPage;
use Cms\Classes\Theme;
use Config;
use Log;
use Str;
use System\Classes\PluginManager;
use Winter\Pages\Classes\Page;
use Winter\Pages\Classes\SnippetManager;

/**
 * Component bricks: a brick that runs a CMS component with the brick's values as its properties.
 *
 * - Brick types come from snippet components (config "snippets") and from definitions with a "component" key.
 *   A snippet brick's icon is the component's "icon" detail (e.g. 'icon' => 'icon-star' in componentDetails()).
 * - Their form fields are generated from the component's properties; the definition's "fields" override them.
 * - On the frontend the components of the static page's bricks are added in cms.page.initComponents (like Pages
 *   adds snippet components), so their onRun() and AJAX handlers work; renderBrick() renders them.
 */
class ComponentBricks
{
    public const DEFAULT_ICON = 'icon-puzzle-piece';

    /**
     * Inspector property types => backend form field types. Other types become text fields.
     */
    public const FIELD_TYPES = [
        'string'   => 'text',
        'text'     => 'textarea',
        'dropdown' => 'dropdown',
        'checkbox' => 'checkbox',
        'set'      => 'checkboxlist',
    ];

    /**
     * Brick codes of snippet components start with this, so they can't collide with explicitly defined types
     * (e.g. the Widgets "gallery" snippet and the base set's "gallery" brick).
     */
    public const SNIPPET_PREFIX = 'snippet-';

    /**
     * Brick types for the snippet components, by code: SNIPPET_PREFIX + the snippet code in kebab case.
     *
     * @return array<string, BrickType>
     */
    public static function snippetTypes(?Theme $theme): array
    {
        $setting = Config::get('numencode.bricks::snippets', true);

        if ($setting === false || !$theme || !class_exists(SnippetManager::class)) {
            return [];
        }

        $allowed = is_array($setting) ? array_map(fn ($class) => ltrim((string) $class, '\\'), $setting) : null;
        $types = [];

        foreach (SnippetManager::instance()->listSnippets($theme) as $snippet) {
            if (!$class = $snippet->getComponentClass()) {
                continue;
            }

            $class = ltrim($class, '\\');

            if (($allowed !== null && !in_array($class, $allowed, true)) || !$component = static::makeComponent($class)) {
                continue;
            }

            $code = self::SNIPPET_PREFIX . Str::kebab((string) $snippet->code);
            $details = $component->componentDetails();
            $plugin = static::pluginOf($class);

            $types[$code] = BrickType::fromArray($code, [
                'name'        => $details['name'] ?? class_basename($class),
                'description' => $details['description'] ?? '',
                'icon'        => static::icon($details, $plugin),
                'component'   => $class,
            ], null, 'snippet ' . $class)->withPlugin($plugin === 'NumenCode.Bricks' ? '' : $plugin);
        }

        return $types;
    }

    /**
     * The plugin of a component class (Author.Plugin), or '' when no plugin has that namespace.
     */
    public static function pluginOf(string $class): string
    {
        $manager = PluginManager::instance();
        $identifier = $manager->getIdentifier($class);

        return $manager->findByIdentifier($identifier) ? $identifier : '';
    }

    /**
     * The icon of a snippet brick: the component's "icon" detail (componentDetails()), else its plugin's icon, else
     * DEFAULT_ICON.
     *
     * @param array<string, mixed> $details
     */
    public static function icon(array $details, string $plugin): string
    {
        $icon = $details['icon'] ?? null;

        if ((!is_string($icon) || $icon === '') && $plugin !== '') {
            $icon = PluginManager::instance()->findByIdentifier($plugin)?->pluginDetails()['icon'] ?? null;
        }

        return is_string($icon) && $icon !== '' ? $icon : self::DEFAULT_ICON;
    }

    /**
     * Form fields of a component brick: its properties as fields, with the definition's fields merged over them
     * (e.g. `layout: {hidden: true}`, or a different label).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(BrickType $type): array
    {
        if (!$type->isComponent() || !$component = static::makeComponent($type->component)) {
            return $type->fields;
        }

        $fields = [];

        foreach (ComponentHelpers::getComponentsPropertyConfig($component, false, true) as $property) {
            $name = $property['property'];

            if (!in_array($name, BrickType::RESERVED_FIELDS, true)) {
                $fields[$name] = static::propertyField($property, $component);
            }
        }

        foreach ($type->fields as $name => $override) {
            $fields[$name] = array_replace($fields[$name] ?? [], $override);
        }

        return $fields;
    }

    /**
     * A backend form field for one component property (Inspector format, already translated by ComponentHelpers).
     *
     * @param array<string, mixed> $property
     * @return array<string, mixed>
     */
    public static function propertyField(array $property, ComponentBase $component): array
    {
        $type = self::FIELD_TYPES[$property['type'] ?? 'string'] ?? 'text';
        $field = ['label' => $property['title'] ?? $property['property'], 'type' => $type];

        foreach (['description' => 'comment', 'default' => 'default', 'placeholder' => 'placeholder'] as $from => $to) {
            if (isset($property[$from]) && $property[$from] !== '') {
                $field[$to] = $property[$from];
            }
        }

        if (in_array($type, ['dropdown', 'checkboxlist'], true)) {
            $field['options'] = $property['options'] ?? static::propertyOptions($component, $property['property']);
        }

        if (!empty($property['required']) || isset($property['validation']['required'])) {
            $field['required'] = true;
        }

        return $field;
    }

    /**
     * Dynamic options of a property, the same way the Inspector gets them.
     *
     * @return array<int|string, string>
     */
    public static function propertyOptions(ComponentBase $component, string $property): array
    {
        $method = 'get' . ucfirst($property) . 'Options';

        $options = method_exists($component, $method) ? $component->{$method}() : $component->getPropertyOptions($property);

        return is_array($options) ? $options : [];
    }

    /**
     * Adds the components of the static page's component bricks to the CMS page (cms.page.initComponents).
     */
    public static function addComponents(Controller $controller, CmsPage $page): void
    {
        $staticPage = $page->apiBag['staticPage'] ?? null;

        if (!$staticPage instanceof Page) {
            return;
        }

        foreach (BrickRenderer::fieldNames($staticPage) as $field) {
            static::addListComponents($controller, BrickRenderer::bricks($staticPage, $field), $field);
        }
    }

    /**
     * Adds the components of a list's component bricks, also those inside other bricks (not inside hidden ones).
     *
     * @param array<int, array<string, mixed>> $bricks
     * @param string $field The list's name (a nested list's: its path, BrickRenderer::path())
     */
    protected static function addListComponents(Controller $controller, array $bricks, string $field): void
    {
        $registry = BrickRegistry::instance();

        foreach ($bricks as $index => $brick) {
            $type = $registry->get((string) ($brick['_group'] ?? ''), $controller->getTheme());

            if (!$type || BrickRenderer::isHidden($brick)) {
                continue;
            }

            if ($type->isComponent()) {
                $properties = array_diff_key($brick, array_flip(BrickType::RESERVED_FIELDS));
                $controller->addComponent($type->component, static::alias($field, $index), $properties, true);

                continue;
            }

            foreach (array_keys($type->bricksFields()) as $name) {
                static::addListComponents($controller, BrickRenderer::listOf($brick[$name] ?? []), BrickRenderer::path($field, $index, $name));
            }
        }
    }

    /**
     * The alias of a component brick's component on the CMS page, e.g. brick_bricks_2 or (nested)
     * brick_bricks_2_column_1_0.
     */
    public static function alias(string $field, int $index): string
    {
        return 'brick_' . preg_replace('/\W/', '_', $field) . '_' . $index;
    }

    protected static function makeComponent(string $class): ?ComponentBase
    {
        if (!class_exists($class) || !is_subclass_of($class, ComponentBase::class)) {
            return null;
        }

        try {
            return new $class();
        } catch (\Throwable $exception) {
            Log::warning(sprintf('NumenCode.Bricks: component %s could not be created: %s', $class, $exception->getMessage()));

            return null;
        }
    }
}
