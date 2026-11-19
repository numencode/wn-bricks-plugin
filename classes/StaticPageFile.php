<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Cms\Classes\Layout;
use Cms\Classes\Theme;
use File;
use Winter\Storm\Halcyon\Processors\SectionParser;
use Winter\Storm\Parse\Syntax\Parser as SyntaxParser;

/**
 * One file of a Winter.Pages static page: the default language's content/static-pages/<name>.htm, or a translation in
 * content/static-pages-<locale>/<name>.htm (Winter.Translate). Read and written like Halcyon does (SectionParser), so
 * the viewBag (INI settings) and the content (markup) keep their format.
 *
 * Used by bricks:convert-pages and bricks:clear-markup.
 */
class StaticPageFile
{
    public const DIRECTORY = 'content/static-pages';

    /**
     * @var array{settings: array<string, mixed>, code: string|null, markup: string|null}
     */
    protected array $data;

    /**
     * @param string $locale The language code ('' for the default language's file)
     */
    public function __construct(public readonly Theme $theme, public readonly string $name, public readonly string $path, public readonly string $locale = '')
    {
        $this->data = SectionParser::parse((string) file_get_contents($path));
    }

    /**
     * The files of the theme's static pages: name => [locale => file] ('' = the default language first, then the
     * translations by locale code).
     *
     * @return array<string, array<string, static>>
     */
    public static function all(Theme $theme): array
    {
        $pages = [];
        $root = $theme->getPath() . '/' . self::DIRECTORY;
        $files = glob($root . '/*.htm') ?: [];
        sort($files);

        foreach ($files as $path) {
            $name = basename($path, '.htm');
            $pages[$name][''] = new static($theme, $name, $path);
        }

        $directories = glob($root . '-*', GLOB_ONLYDIR) ?: [];
        sort($directories);

        foreach ($directories as $directory) {
            $locale = substr(basename($directory), strlen(basename($root)) + 1);

            foreach (array_keys($pages) as $name) {
                if (is_file($path = $directory . '/' . $name . '.htm')) {
                    $pages[$name][$locale] = new static($theme, $name, $path, $locale);
                }
            }
        }

        return $pages;
    }

    /**
     * @return array<string, mixed>
     */
    public function viewBag(): array
    {
        $viewBag = $this->data['settings']['viewBag'] ?? [];

        return is_array($viewBag) ? $viewBag : [];
    }

    public function markup(): string
    {
        return (string) $this->data['markup'];
    }

    /**
     * The bricks stored in a field of this file.
     *
     * @return array<int, mixed>
     */
    public function bricks(string $field): array
    {
        $bricks = $this->viewBag()[$field] ?? [];

        return is_array($bricks) ? $bricks : [];
    }

    /**
     * @param array<int, array<string, mixed>> $bricks
     */
    public function setBricks(string $field, array $bricks): void
    {
        $this->data['settings']['viewBag'] = array_merge($this->viewBag(), [$field => array_values($bricks)]);
    }

    public function setMarkup(string $markup): void
    {
        $this->data['markup'] = $markup;
    }

    public function save(): void
    {
        File::put($this->path, SectionParser::render($this->data));
    }

    /**
     * The layout's bricks fields ({variable type="bricks" name="..."}), from the default language's file; null when
     * the layout can't be found.
     *
     * @return array<int, string>|null
     */
    public function layoutBricksFields(?string $layoutName): ?array
    {
        $layout = $layoutName ? Layout::load($this->theme, $layoutName) : null;

        if (!$layout) {
            return null;
        }

        $fields = [];

        foreach (SyntaxParser::parse((string) $layout->markup, ['tagPrefix' => 'page:'])->toEditor() as $name => $config) {
            if (($config['type'] ?? null) === PagesIntegration::FIELD_TYPE) {
                $fields[] = (string) $name;
            }
        }

        return $fields;
    }

    /**
     * "about" for the default language, "about (sl)" for a translation.
     */
    public function label(): string
    {
        return $this->name . ($this->locale === '' ? '' : " ({$this->locale})");
    }
}
