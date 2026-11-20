<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Backend\Classes\AuthManager;
use Backend\Classes\FormWidgetBase;
use Backend\Classes\WidgetManager;
use Cms\Classes\ComponentBase;
use Cms\Classes\ComponentManager;
use Cms\Classes\Controller;
use Cms\Classes\Partial;
use Cms\Classes\Theme;
use Lang;
use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Source;

/**
 * Checks the brick types of a theme (bricks:validate): every definition of every code (also replaced ones), their
 * markup and the theme's markup partials. Reads the files directly, so one invalid definition doesn't hide the others.
 *
 * Errors break the editor or the website (invalid definitions, unknown field types, Twig syntax errors, missing or
 * unregistered components). Warnings are likely mistakes (missing lang keys, unregistered permissions, unused
 * partials, overrides of properties a component doesn't have).
 */
class BrickValidator
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    /**
     * A lang key: author.plugin::lang.key or module::lang.key.
     */
    protected const LANG_KEY = '/^[a-z0-9_-]+(\.[a-z0-9_-]+)?::[a-z0-9_.-]+$/i';

    /**
     * @var array<int, array{level: string, code: string, file: string, message: string}>
     */
    protected array $issues = [];

    /**
     * Markup files already checked (a theme definition can share the markup of the type it replaces).
     *
     * @var array<string, bool>
     */
    protected array $checked = [];

    /**
     * @var array<string, bool>|null
     */
    protected ?array $permissions = null;

    /**
     * @var array<int, string>|null
     */
    protected ?array $fieldTypes = null;

    protected ?Environment $twig = null;

    public function __construct(protected Theme $theme)
    {
    }

    /**
     * @return array<int, array{level: string, code: string, file: string, message: string}>
     */
    public function validate(): array
    {
        $this->issues = [];
        $this->checked = [];

        $sources = BrickRegistry::instance()->sources($this->theme);

        foreach ($sources as $code => $list) {
            foreach ($list as $source) {
                $this->checkSource($source);
            }

            $this->checkThemePartial((string) $code, end($list));
        }

        $this->checkUnusedPartials(array_map('strval', array_keys($sources)));
        $this->checkLayouts();

        return $this->issues;
    }

    /**
     * The bricks fields of the theme's layouts ({variable type="bricks"}): their "translation" option.
     */
    protected function checkLayouts(): void
    {
        foreach (\Cms\Classes\Layout::listInTheme($this->theme, true) as $layout) {
            $file = $layout->getFilePath();

            try {
                $fields = \Winter\Storm\Parse\Syntax\Parser::parse((string) $layout->markup, ['tagPrefix' => 'page:'])->toEditor();
            } catch (\Throwable $exception) {
                continue;
            }

            foreach ($fields as $name => $config) {
                if (!is_array($config) || ($config['type'] ?? null) !== PagesIntegration::FIELD_TYPE) {
                    continue;
                }

                try {
                    FieldBuilder::isShared($config);
                } catch (\Winter\Storm\Exception\SystemException $exception) {
                    $this->error((string) $name, $file, 'the bricks field: ' . $exception->getMessage());
                }
            }
        }
    }

    /**
     * Whether a file belongs to the theme (for --theme-only).
     */
    public function isThemeFile(string $file): bool
    {
        $themePath = str_replace('\\', '/', $this->theme->getPath()) . '/';

        return str_starts_with(str_replace('\\', '/', $file), $themePath);
    }

    protected function checkSource(BrickSource $source): void
    {
        try {
            $type = $source->makeType();
        } catch (InvalidBrickException $exception) {
            $this->error($source->code, (string) $source->definition, $exception->problem ?: $exception->getMessage());

            return;
        }

        $file = $source->definition ?? $type->source;

        $this->checkLabel($type->code, $file, '"name"', $type->name);
        $this->checkLabel($type->code, $file, '"description"', $type->description);
        $this->checkPermissions($type, $file);

        if ($type->isComponent()) {
            $this->checkComponent($type, $file);

            return;
        }

        $this->checkFields($type->code, $file, $type->fields);

        if ($source->markup !== null) {
            $this->checkMarkup($type->code, $source->markup);
        }
    }

    protected function checkComponent(BrickType $type, string $file): void
    {
        $class = ltrim((string) $type->component, '\\');

        if (!class_exists($class)) {
            $this->error($type->code, $file, "the component class {$class} doesn't exist");

            return;
        }

        if (!is_subclass_of($class, ComponentBase::class)) {
            $this->error($type->code, $file, "{$class} is not a CMS component");

            return;
        }

        if (!ComponentManager::instance()->hasComponent($class)) {
            $this->error($type->code, $file, "the component {$class} is not registered (a plugin's registerComponents())");

            return;
        }

        $fields = ComponentBricks::fields($type);

        foreach (array_keys($type->fields) as $name) {
            if (!$this->isPropertyOf($class, (string) $name)) {
                $this->warning($type->code, $file, "the field \"{$name}\" is not a property of the component {$class}");
            }
        }

        if ($type->titleFrom !== null && !isset($fields[$type->titleFrom])) {
            $this->warning($type->code, $file, "\"titleFrom\" names \"{$type->titleFrom}\", which is not a field or property of the brick");
        }

        $this->checkFields($type->code, $file, $fields);
    }

    protected function isPropertyOf(string $class, string $name): bool
    {
        try {
            return array_key_exists($name, (new $class())->defineProperties());
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Field types and lang keys of the fields, nested forms included (repeater "form" and "groups").
     *
     * @param array<mixed> $fields
     */
    protected function checkFields(string $code, string $file, array $fields, string $prefix = ''): void
    {
        foreach ($fields as $name => $config) {
            if (!is_array($config)) {
                continue;
            }

            $label = $prefix . $name;
            $fieldType = $config['type'] ?? 'text';

            if (!is_string($fieldType) || !$this->isFieldType($fieldType)) {
                $this->error($code, $file, sprintf('the field "%s" has an unknown type "%s"', $label, is_string($fieldType) ? $fieldType : gettype($fieldType)));
            }

            foreach (['label', 'comment', 'commentAbove', 'placeholder', 'prompt', 'emptyOption'] as $key) {
                if (isset($config[$key]) && is_string($config[$key])) {
                    $this->checkLabel($code, $file, "\"{$key}\" of the field \"{$label}\"", $config[$key]);
                }
            }

            if (isset($config['options']) && is_array($config['options'])) {
                array_walk_recursive($config['options'], function ($value) use ($code, $file, $label) {
                    if (is_string($value)) {
                        $this->checkLabel($code, $file, "an option of the field \"{$label}\"", $value);
                    }
                });
            }

            if (isset($config['form']['fields']) && is_array($config['form']['fields'])) {
                $this->checkFields($code, $file, $config['form']['fields'], $label . '.');
            }

            foreach ((array) ($config['groups'] ?? []) as $group => $groupConfig) {
                if (is_array($groupConfig['fields'] ?? null)) {
                    $this->checkFields($code, $file, $groupConfig['fields'], $label . '.' . $group . '.');
                }
            }
        }
    }

    /**
     * A core field type (a partial of the backend Form widget), a core form widget or a registered form widget.
     *
     * The backend registers its own form widgets (richeditor, repeater ...) only for backend requests, so in the console
     * they are found by their class: the lowercase class name or the widget's default alias.
     */
    protected function isFieldType(string $type): bool
    {
        if ($this->fieldTypes === null) {
            $this->fieldTypes = array_map(
                fn ($path) => substr(basename($path, '.php'), strlen('_field_')),
                glob(base_path('modules/backend/widgets/form/partials/_field_*.php')) ?: [],
            );

            foreach (glob(base_path('modules/backend/formwidgets/*.php')) ?: [] as $path) {
                $class = 'Backend\\FormWidgets\\' . basename($path, '.php');

                if (class_exists($class) && is_subclass_of($class, FormWidgetBase::class)) {
                    $this->fieldTypes[] = strtolower(class_basename($class));
                    $this->fieldTypes[] = (string) ((new \ReflectionClass($class))->getDefaultProperties()['defaultAlias'] ?? '');
                }
            }
        }

        if (in_array($type, $this->fieldTypes, true)) {
            return true;
        }

        $class = WidgetManager::instance()->resolveFormWidget($type);

        return class_exists($class) && is_subclass_of($class, FormWidgetBase::class);
    }

    /**
     * A text that looks like a lang key must exist.
     */
    protected function checkLabel(string $code, string $file, string $what, string $text): void
    {
        if (preg_match(self::LANG_KEY, $text) && !Lang::has($text)) {
            $this->warning($code, $file, "{$what} is the lang key \"{$text}\", which doesn't exist");
        }
    }

    protected function checkPermissions(BrickType $type, string $file): void
    {
        if (!$type->permissions) {
            return;
        }

        if ($this->permissions === null) {
            $this->permissions = [];

            foreach (AuthManager::instance()->listPermissions() as $permission) {
                $this->permissions[$permission->code] = true;
            }
        }

        foreach ($type->permissions as $permission) {
            if (!isset($this->permissions[$permission]) && !str_contains($permission, '*')) {
                $this->warning($type->code, $file, "the permission \"{$permission}\" is not registered");
            }
        }
    }

    /**
     * The theme's partials/bricks/<code>.htm: used instead of the type's markup (not for component bricks).
     */
    protected function checkThemePartial(string $code, BrickSource $active): void
    {
        if (!$path = BrickRegistry::themePartial($this->theme, $code)) {
            return;
        }

        try {
            $type = $active->makeType();
        } catch (InvalidBrickException) {
            $type = null;
        }

        if ($type?->isComponent()) {
            $this->warning($code, $path, 'the partial is not used: a component brick renders its component');

            return;
        }

        $this->checkMarkup($code, $path);
    }

    /**
     * @param array<int, string> $codes
     */
    protected function checkUnusedPartials(array $codes): void
    {
        $files = glob($this->theme->getPath() . '/' . BrickRegistry::THEME_PARTIALS . '/*.htm') ?: [];
        sort($files);

        foreach ($files as $file) {
            $code = basename($file, '.htm');

            // "_name" partials are helpers included by other partials
            if (!str_starts_with($code, '_') && !in_array($code, $codes, true)) {
                $this->warning($code, $file, 'the partial is not used: there is no brick type with this code');
            }
        }
    }

    /**
     * Compiles the markup with the CMS Twig environment: syntax errors, unknown filters, functions and tags.
     */
    protected function checkMarkup(string $code, string $path): void
    {
        if (isset($this->checked[$path])) {
            return;
        }

        $this->checked[$path] = true;

        try {
            $content = $this->isThemeFile($path)
                ? (string) Partial::load($this->theme, 'bricks/' . basename($path))?->getTwigContent()
                : (string) file_get_contents($path);

            $twig = $this->twig();
            $twig->parse($twig->tokenize(new Source($content, basename($path), $path)));
        } catch (TwigError $exception) {
            $line = $exception->getTemplateLine();
            $this->error($code, $path, 'Twig: ' . $exception->getRawMessage() . ($line > 0 ? " (line {$line})" : ''));
        } catch (\Throwable $exception) {
            $this->error($code, $path, $exception->getMessage());
        }
    }

    protected function twig(): Environment
    {
        return $this->twig ??= (new Controller($this->theme))->getTwig();
    }

    protected function error(string $code, string $file, string $message): void
    {
        $this->issues[] = ['level' => self::ERROR, 'code' => $code, 'file' => $file, 'message' => $message];
    }

    protected function warning(string $code, string $file, string $message): void
    {
        $this->issues[] = ['level' => self::WARNING, 'code' => $code, 'file' => $file, 'message' => $message];
    }
}
