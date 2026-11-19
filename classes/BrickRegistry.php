<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Cms\Classes\Theme;
use File;
use System\Classes\PluginManager;
use Winter\Storm\Support\Traits\Singleton;

/**
 * Collects the brick types. Later sources replace earlier ones with the same code:
 *
 * 1. snippet components (Winter.Pages snippets, see config "snippets" and ComponentBricks)
 * 2. NumenCode.Bricks' own base set (Plugin::registerBricks())
 * 3. other plugins' registerBricks(): ['code' => '$/author/plugin/bricks/code'] (path without extension)
 * 4. callbacks added with registerCallback() (they call registerBricks() on the registry)
 * 5. the theme's bricks/<code>.yaml
 *
 * Markup: a theme's partials/bricks/<code>.htm is used before the type's own <code>.htm. A theme definition that
 * replaces a plugin type keeps that type's markup as the fallback.
 *
 * Each type knows the plugin that provides it (BrickType::$plugin, for the sections of the "Add brick" list): a snippet's
 * component plugin, or the plugin whose registerBricks() (or callback, by its owner) registered it. NumenCode.Bricks'
 * own types and callbacks without an owner are general (''); the theme's new types are in the theme's section
 * (FieldBuilder::THEME_SECTION). A theme definition that replaces a type stays in that type's section.
 *
 * sources() lists every definition of each code (also the replaced ones), for bricks:list and bricks:validate.
 *
 * The types are kept per theme for the request; Winter caches the parsed YAML files by their modification time.
 */
class BrickRegistry
{
    use Singleton;

    /**
     * Markup partials of a theme that replace a type's markup: partials/bricks/<code>.htm.
     */
    public const THEME_PARTIALS = 'partials/bricks';

    /**
     * The theme's brick type definitions: bricks/<code>.yaml.
     */
    public const THEME_DEFINITIONS = 'bricks';

    /**
     * @var array<int, callable(self): void>
     */
    protected array $callbacks = [];

    /**
     * Registrations collected while collecting the sources, in order: [code, path without extension, owner, origin].
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    protected array $registrations = [];

    /**
     * The origin of registerBricks() calls while collecting (BrickSource::PLUGIN or CALLBACK).
     */
    protected string $origin = BrickSource::PLUGIN;

    /**
     * Sources per theme directory ('' without a theme).
     *
     * @var array<string, array<string, array<int, BrickSource>>>
     */
    protected array $sources = [];

    /**
     * Built types per theme directory ('' without a theme).
     *
     * @var array<string, array<string, BrickType>>
     */
    protected array $types = [];

    /**
     * Adds a callback that registers brick types: fn (BrickRegistry $registry) => $registry->registerBricks([...]).
     */
    public function registerCallback(callable $callback): void
    {
        $this->callbacks[] = $callback;
        $this->clearCache();
    }

    /**
     * Registers brick types: code => path of the definition without extension (<path>.yaml + <path>.htm).
     * Path symbols ($/ plugins, ~/ base path) are allowed. Only valid while the registry is being built
     * (from registerBricks() or a callback). $owner is the providing plugin (Author.Plugin; '' = general).
     *
     * @param array<string, string> $definitions
     */
    public function registerBricks(array $definitions, string $owner = ''): void
    {
        foreach ($definitions as $code => $path) {
            $this->registrations[] = [(string) $code, (string) $path, $owner, $this->origin];
        }
    }

    /**
     * All brick types of the theme (default: the active theme).
     *
     * @return array<string, BrickType>
     * @throws InvalidBrickException
     */
    public function all(?Theme $theme = null): array
    {
        $theme ??= Theme::getActiveTheme();

        return $this->types[$this->key($theme)] ??= $this->build($theme);
    }

    public function get(string $code, ?Theme $theme = null): ?BrickType
    {
        return $this->all($theme)[$code] ?? null;
    }

    public function has(string $code, ?Theme $theme = null): bool
    {
        return $this->get($code, $theme) !== null;
    }

    /**
     * Every definition of each brick code, in the order they were found; the last one is used, the others are replaced
     * by it. The definition files are not read, so this works with invalid definitions too.
     *
     * @return array<string, array<int, BrickSource>>
     */
    public function sources(?Theme $theme = null): array
    {
        $theme ??= Theme::getActiveTheme();

        return $this->sources[$this->key($theme)] ??= $this->collect($theme);
    }

    /**
     * The theme's markup partial of a brick code (partials/bricks/<code>.htm), or null.
     */
    public static function themePartial(?Theme $theme, string $code): ?string
    {
        if (!$theme) {
            return null;
        }

        $path = $theme->getPath() . '/' . self::THEME_PARTIALS . '/' . $code . '.htm';

        return is_file($path) ? $path : null;
    }

    /**
     * The types limited to the allowed codes (in that order; all when empty), without the ignored ones.
     * Unknown codes are skipped.
     *
     * @param array<int, string> $allow
     * @param array<int, string> $ignore
     * @return array<string, BrickType>
     */
    public function filter(array $allow = [], array $ignore = [], ?Theme $theme = null): array
    {
        $types = $this->all($theme);

        if ($allow) {
            $allowed = [];

            foreach ($allow as $code) {
                if (isset($types[$code])) {
                    $allowed[$code] = $types[$code];
                }
            }

            $types = $allowed;
        }

        return array_diff_key($types, array_flip($ignore));
    }

    /**
     * Forgets the built types (they are built again on the next call).
     */
    public function clearCache(): void
    {
        $this->types = [];
        $this->sources = [];
    }

    protected function key(?Theme $theme): string
    {
        return $theme ? $theme->getDirName() : '';
    }

    /**
     * @return array<string, BrickType>
     * @throws InvalidBrickException
     */
    protected function build(?Theme $theme): array
    {
        $types = [];

        foreach ($this->sources($theme) as $code => $sources) {
            $types[$code] = end($sources)->makeType();
        }

        return $types;
    }

    /**
     * @return array<string, array<int, BrickSource>>
     */
    protected function collect(?Theme $theme): array
    {
        $sources = [];

        foreach (ComponentBricks::snippetTypes($theme) as $code => $type) {
            $sources[$code][] = new BrickSource(
                code: $code,
                origin: BrickSource::SNIPPET,
                owner: ComponentBricks::pluginOf((string) $type->component),
                plugin: $type->plugin,
                type: $type,
            );
        }

        foreach ($this->registrations() as [$code, $path, $owner, $origin]) {
            $base = preg_replace('/\.(yaml|htm)$/', '', File::symbolizePath($path));

            $sources[$code][] = new BrickSource(
                code: $code,
                origin: $origin,
                owner: $owner,
                plugin: $owner === 'NumenCode.Bricks' ? '' : $owner,
                definition: $base . '.yaml',
                markup: is_file($base . '.htm') ? $base . '.htm' : null,
            );
        }

        return $theme ? $this->themeSources($theme, $sources) : $sources;
    }

    /**
     * The registrations of all plugins' registerBricks() and the callbacks, in order.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    protected function registrations(): array
    {
        $this->registrations = [];
        $this->origin = BrickSource::PLUGIN;

        $values = PluginManager::instance()->getRegistrationMethodValues('registerBricks');
        $this->registerBricks((array) ($values['NumenCode.Bricks'] ?? []), 'NumenCode.Bricks');
        unset($values['NumenCode.Bricks']);

        foreach ($values as $pluginId => $definitions) {
            if (is_array($definitions)) {
                $this->registerBricks($definitions, $pluginId);
            }
        }

        $this->origin = BrickSource::CALLBACK;

        try {
            foreach ($this->callbacks as $callback) {
                $callback($this);
            }
        } finally {
            $this->origin = BrickSource::PLUGIN;
        }

        $registrations = $this->registrations;
        $this->registrations = [];

        return $registrations;
    }

    /**
     * Adds the theme's bricks/<code>.yaml definitions.
     *
     * @param array<string, array<int, BrickSource>> $sources
     * @return array<string, array<int, BrickSource>>
     */
    protected function themeSources(Theme $theme, array $sources): array
    {
        $files = glob($theme->getPath() . '/' . self::THEME_DEFINITIONS . '/*.yaml') ?: [];
        sort($files);

        foreach ($files as $file) {
            $code = basename($file, '.yaml');
            $replaced = isset($sources[$code]) ? end($sources[$code]) : null;

            $sources[$code][] = new BrickSource(
                code: $code,
                origin: BrickSource::THEME,
                owner: $theme->getDirName(),
                plugin: $replaced ? $replaced->plugin : FieldBuilder::THEME_SECTION . $theme->getDirName(),
                definition: $file,
                markup: static::themePartial($theme, $code) ?? $replaced?->markup,
            );
        }

        return $sources;
    }
}
