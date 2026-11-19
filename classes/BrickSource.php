<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

/**
 * One definition of a brick code found by the registry. A code can have several (e.g. a plugin type and a theme
 * definition replacing it); the last one is used (BrickRegistry::sources()).
 */
final class BrickSource
{
    /**
     * A Winter.Pages snippet component (config "snippets").
     */
    public const SNIPPET = 'snippet';

    /**
     * A plugin's registerBricks() (NumenCode.Bricks' own base set too).
     */
    public const PLUGIN = 'plugin';

    /**
     * BrickRegistry::registerCallback().
     */
    public const CALLBACK = 'callback';

    /**
     * The theme's bricks/<code>.yaml.
     */
    public const THEME = 'theme';

    /**
     * @param string $origin One of the constants
     * @param string $owner Who registered it: Author.Plugin (a snippet: the component's plugin; a callback: its owner,
     *                      maybe ''), or the theme's directory name
     * @param string $plugin The plugin the type is listed under in the "Add brick" list ('' = general)
     * @param string|null $definition The YAML definition file (null for a snippet)
     * @param string|null $markup The markup file used when the theme has no partials/bricks/<code>.htm
     * @param BrickType|null $type The type, when it's not read from a definition file (a snippet)
     */
    public function __construct(
        public readonly string $code,
        public readonly string $origin,
        public readonly string $owner,
        public readonly string $plugin = '',
        public readonly ?string $definition = null,
        public readonly ?string $markup = null,
        public readonly ?BrickType $type = null,
    ) {
    }

    /**
     * The brick type of this definition.
     *
     * @throws InvalidBrickException
     */
    public function makeType(): BrickType
    {
        if ($this->type !== null) {
            return $this->type;
        }

        return BrickType::fromYaml($this->code, (string) $this->definition, $this->markup)->withPlugin($this->plugin);
    }

    /**
     * Who provides it, for people: "NumenCode.Bricks", "theme demo", "snippet (NumenCode.Widgets)", "callback".
     */
    public function describe(): string
    {
        return match ($this->origin) {
            self::THEME    => 'theme ' . $this->owner,
            self::SNIPPET  => 'snippet' . ($this->owner === '' ? '' : ' (' . $this->owner . ')'),
            self::CALLBACK => 'callback' . ($this->owner === '' ? '' : ' (' . $this->owner . ')'),
            default        => $this->owner,
        };
    }
}
