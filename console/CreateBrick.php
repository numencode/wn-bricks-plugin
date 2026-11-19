<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Console;

use File;
use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\BrickSource;
use NumenCode\Bricks\Classes\BrickType;
use NumenCode\Bricks\Classes\InvalidBrickException;
use Str;
use System\Classes\PluginManager;
use Winter\Storm\Exception\ApplicationException;

/**
 * create:brick – scaffolds a brick type (definition + markup) in a theme or a plugin, or copies an existing type into
 * the theme to change it there (--override, --markup).
 */
class CreateBrick extends BricksCommand
{
    protected $signature = 'create:brick
        {code : The brick code <info>(eg: call-to-action)</info>}
        {--plugin= : Create it in this plugin <info>(eg: Acme.Shop)</info> instead of the theme}
        {--theme= : The theme (default: the active theme)}
        {--override : Copy an existing brick type into the theme, to change its fields and markup there}
        {--markup : Copy only the markup of an existing brick type into the theme (partials/bricks/<code>.htm)}
        {--name= : The name in the editor (default: from the code)}
        {--icon=icon-cube : The icon class}
        {--f|force : Overwrite existing files}';

    protected $description = 'Create a brick type (definition and markup) in the theme or a plugin, or copy an existing one into the theme.';

    public function handle(): int
    {
        try {
            $code = (string) $this->argument('code');

            if (!preg_match('/^[a-z][a-z0-9_-]*$/', $code)) {
                throw new ApplicationException('The code must start with a lowercase letter and contain only a-z, 0-9, "_" and "-".');
            }

            if ($this->option('plugin')) {
                return $this->createInPlugin($code);
            }

            return $this->option('override') || $this->option('markup')
                ? $this->override($code)
                : $this->createInTheme($code);
        } catch (ApplicationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    protected function createInTheme(string $code): int
    {
        $theme = $this->theme();

        // The theme's own type: write() refuses to overwrite its files without --force
        if (($existing = $this->existing($code)) && $existing->origin !== BrickSource::THEME) {
            throw new ApplicationException(sprintf(
                'There is already a brick type "%s" (from %s). Use --override to copy it into the theme, or choose another code.',
                $code,
                $existing->describe(),
            ));
        }

        $this->write([
            $theme->getPath() . '/' . BrickRegistry::THEME_DEFINITIONS . '/' . $code . '.yaml' => $this->definitionStub($code),
            $theme->getPath() . '/' . BrickRegistry::THEME_PARTIALS . '/' . $code . '.htm'     => $this->markupStub($code),
        ]);

        $this->info("Brick type \"{$code}\" created in the theme {$theme->getDirName()}; it's in the \"Add brick\" list now.");

        return self::SUCCESS;
    }

    protected function createInPlugin(string $code): int
    {
        if ($this->option('override') || $this->option('markup')) {
            throw new ApplicationException('--override and --markup copy a brick type into a theme; leave out --plugin.');
        }

        $manager = PluginManager::instance();
        $identifier = $manager->normalizeIdentifier((string) $this->option('plugin'));
        $plugin = $manager->findByIdentifier($identifier);

        if (!$plugin) {
            throw new ApplicationException("The plugin \"{$this->option('plugin')}\" doesn't exist.");
        }

        if (($existing = $this->existing($code)) && !$this->option('force')) {
            throw new ApplicationException(sprintf(
                'There is already a brick type "%s" (from %s); a plugin type with the same code replaces it. Use --force to create it anyway.',
                $code,
                $existing->describe(),
            ));
        }

        $path = $plugin->getPluginPath() . '/bricks/' . $code;

        $this->write([
            $path . '.yaml' => $this->definitionStub($code),
            $path . '.htm'  => $this->markupStub($code),
        ]);

        $symbol = '$/' . $this->relativePath($path);
        $symbol = str_replace('$/plugins/', '$/', $symbol);

        $this->info("Brick type \"{$code}\" created in {$identifier}. Register it in the plugin's registerBricks():");
        $this->line('');
        $this->line('    public function registerBricks(): array');
        $this->line('    {');
        $this->line('        return [');
        $this->line("            '{$code}' => '{$symbol}',");
        $this->line('        ];');
        $this->line('    }');

        return self::SUCCESS;
    }

    /**
     * Copies the brick type that is used now into the theme: its definition (bricks/<code>.yaml) and markup
     * (partials/bricks/<code>.htm), or only the markup.
     */
    protected function override(string $code): int
    {
        $theme = $this->theme();
        $source = $this->existing($code);

        if (!$source) {
            throw new ApplicationException("There is no brick type \"{$code}\" to override.");
        }

        if ($source->origin === BrickSource::THEME) {
            throw new ApplicationException("The brick type \"{$code}\" is already defined by the theme: {$this->relativePath($source->definition)}");
        }

        try {
            $type = $source->makeType();
        } catch (InvalidBrickException $exception) {
            throw new ApplicationException($exception->getMessage());
        }

        $markupOnly = (bool) $this->option('markup');
        $files = [];

        if ($type->isComponent() && $markupOnly) {
            throw new ApplicationException("\"{$code}\" is a component brick: it renders its component, so it has no markup to copy.");
        }

        if (!$markupOnly) {
            $files[$theme->getPath() . '/' . BrickRegistry::THEME_DEFINITIONS . '/' . $code . '.yaml'] = $source->definition !== null
                ? (string) file_get_contents($source->definition)
                : $this->componentStub($type);
        }

        if (!$type->isComponent() && $source->markup !== null && !BrickRegistry::themePartial($theme, $code)) {
            $files[$theme->getPath() . '/' . BrickRegistry::THEME_PARTIALS . '/' . $code . '.htm'] = (string) file_get_contents($source->markup);
        } elseif ($markupOnly) {
            throw new ApplicationException('The theme already has the markup: ' . $this->relativePath(BrickRegistry::themePartial($theme, $code)));
        }

        $this->write($files);

        $this->info(sprintf(
            'Copied the %s "%s" from %s into the theme %s; the theme\'s files are used from now on.',
            $markupOnly ? 'markup of the brick type' : 'brick type',
            $code,
            $source->describe(),
            $theme->getDirName(),
        ));

        return self::SUCCESS;
    }

    /**
     * The definition used now for the code, or null.
     */
    protected function existing(string $code): ?BrickSource
    {
        $sources = BrickRegistry::instance()->sources($this->theme())[$code] ?? [];

        return $sources ? end($sources) : null;
    }

    /**
     * Writes the files; refuses to overwrite any of them without --force.
     *
     * @param array<string, string> $files path => content
     */
    protected function write(array $files): void
    {
        if (!$this->option('force')) {
            foreach (array_keys($files) as $path) {
                if (file_exists($path)) {
                    throw new ApplicationException("The file {$this->relativePath($path)} already exists. Use --force to overwrite it.");
                }
            }
        }

        foreach ($files as $path => $content) {
            File::makeDirectory(dirname($path), 0755, true, true);
            File::put($path, $content);
            $this->line('<fg=green>File generated:</> ' . $this->relativePath($path));
        }
    }

    protected function definitionStub(string $code): string
    {
        $name = $this->option('name') ?: Str::ucfirst(str_replace(['-', '_'], ' ', $code));
        $icon = $this->option('icon') ?: BrickType::DEFAULT_ICON;

        return <<<YAML
            # Brick type "{$code}". Fields are backend form fields (https://wintercms.com/docs/v1.2/docs/backend/forms).
            # Mark text fields "translatable: true" so Winter.Translate machine-translates them on "copy from".
            name: {$this->yamlString($name)}
            # description: Shown in the "Add brick" list
            icon: {$this->yamlString($icon)}
            titleFrom: title
            fields:
                title:
                    label: Title
                    type: text
                    translatable: true
                content:
                    label: Content
                    type: richeditor
                    size: large
                    translatable: true

            YAML;
    }

    protected function markupStub(string $code): string
    {
        return <<<TWIG
            {#
                Brick "{$code}". Variables: the fields (title, content), the page's variables and
                brick = {code, index, field, anchor, class}.
            #}
            <section class="brick brick--{$code}{% if brick.class %} {{ brick.class }}{% endif %}"{% if brick.anchor %} id="{{ brick.anchor }}"{% endif %}>
                {% if title %}
                    <h2 class="brick__title">{{ title }}</h2>
                {% endif %}
                {% if content %}
                    <div class="brick__content">{{ content|raw }}</div>
                {% endif %}
            </section>

            TWIG;
    }

    /**
     * A definition for a component brick without a definition file (a snippet), to override its fields.
     */
    protected function componentStub(BrickType $type): string
    {
        return <<<YAML
            # Brick type "{$type->code}": the component's properties are its fields. Override them here, e.g.:
            #   layout: { hidden: true }
            #   group: { label: Choose a group }
            name: {$this->yamlString($type->name)}
            icon: {$this->yamlString($type->icon)}
            component: {$this->yamlString((string) $type->component)}
            fields: {}

            YAML;
    }

    /**
     * A YAML scalar: plain when that's safe, else double-quoted.
     */
    protected function yamlString(string $value): string
    {
        $plain = preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .:_\/()\\\\-]*$/u', $value)
            && !str_contains($value, ': ')
            && !str_ends_with($value, ':')
            && !is_numeric($value)
            && !preg_match('/^(true|false|null|yes|no|on|off|~)$/i', $value);

        return $plain
            ? $value
            : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
