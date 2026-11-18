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
 * own types, the theme's new types and callbacks without an owner are general (''). A theme definition that replaces a
 * type stays in that type's section.
 *
 * The types are kept per theme for the request; Winter caches the parsed YAML files by their modification time.
 */
class BrickRegistry
{
    use Singleton;

    /**
     * @var array<int, callable(self): void>
     */
    protected array $callbacks = [];

    /**
     * Registrations collected while building: code => [path without extension, owner].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected array $registrations = [];

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
            $this->registrations[(string) $code] = [(string) $path, $owner];
        }
    }

    /**
     * All brick types of the theme (default: the active theme).
     *
     * @return array<string, BrickType>
     */
    public function all(?Theme $theme = null): array
    {
        $theme ??= Theme::getActiveTheme();
        $key = $theme ? $theme->getDirName() : '';

        return $this->types[$key] ??= $this->build($theme);
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
    }

    /**
     * @return array<string, BrickType>
     */
    protected function build(?Theme $theme): array
    {
        $this->registrations = [];

        $values = PluginManager::instance()->getRegistrationMethodValues('registerBricks');
        $this->registerBricks((array) ($values['NumenCode.Bricks'] ?? []), 'NumenCode.Bricks');
        unset($values['NumenCode.Bricks']);

        foreach ($values as $pluginId => $definitions) {
            if (is_array($definitions)) {
                $this->registerBricks($definitions, $pluginId);
            }
        }

        foreach ($this->callbacks as $callback) {
            $callback($this);
        }

        $types = ComponentBricks::snippetTypes($theme);

        foreach ($this->registrations as $code => [$path, $owner]) {
            $base = preg_replace('/\.(yaml|htm)$/', '', File::symbolizePath($path));
            $markup = is_file($base . '.htm') ? $base . '.htm' : null;

            $types[$code] = BrickType::fromYaml($code, $base . '.yaml', $markup)
                ->withPlugin($owner === 'NumenCode.Bricks' ? '' : $owner);
        }

        $this->registrations = [];

        return $theme ? array_replace($types, $this->themeTypes($theme, $types)) : $types;
    }

    /**
     * The theme's bricks/<code>.yaml definitions.
     *
     * @param array<string, BrickType> $pluginTypes
     * @return array<string, BrickType>
     */
    protected function themeTypes(Theme $theme, array $pluginTypes): array
    {
        $types = [];
        $files = glob($theme->getPath() . '/bricks/*.yaml') ?: [];
        sort($files);

        foreach ($files as $file) {
            $code = basename($file, '.yaml');
            $partial = $theme->getPath() . '/partials/bricks/' . $code . '.htm';
            $markup = is_file($partial) ? $partial : ($pluginTypes[$code]->markupPath ?? null);

            $types[$code] = BrickType::fromYaml($code, $file, $markup)->withPlugin($pluginTypes[$code]->plugin ?? '');
        }

        return $types;
    }
}
