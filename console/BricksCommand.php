<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Console;

use Cms\Classes\Theme;
use NumenCode\Bricks\Classes\StaticPageFile;
use Winter\Storm\Console\Command;
use Winter\Storm\Exception\ApplicationException;

/**
 * Shared helpers of the Bricks console commands.
 */
abstract class BricksCommand extends Command
{
    /**
     * The theme of the --theme option, else the active theme.
     *
     * @throws ApplicationException
     */
    protected function theme(): Theme
    {
        $name = $this->option('theme');

        if (is_string($name) && $name !== '') {
            if (!Theme::exists($name)) {
                throw new ApplicationException("The theme \"{$name}\" doesn't exist.");
            }

            return Theme::load($name);
        }

        return Theme::getActiveTheme() ?? throw new ApplicationException('There is no active theme; use --theme.');
    }

    /**
     * The static page files chosen with the "pages" argument (file names or URLs) or --all, limited to the --locale
     * options ("default" or a language code): name => [locale => file].
     *
     * @return array<string, array<string, StaticPageFile>>
     * @throws ApplicationException
     */
    protected function pageFiles(Theme $theme): array
    {
        $all = StaticPageFile::all($theme);
        $wanted = array_map('strval', (array) $this->argument('pages'));

        if ($this->option('all') === (bool) $wanted) {
            throw new ApplicationException('Name the pages (file names, e.g. "about", or URLs, e.g. "/about"), or use --all.');
        }

        if ($wanted) {
            $selected = [];

            foreach ($wanted as $page) {
                $name = $this->findPage($all, $page) ?? throw new ApplicationException("There is no static page \"{$page}\" in the theme {$theme->getDirName()}.");
                $selected[$name] = $all[$name];
            }

            $all = $selected;
        }

        // The default language's file is "default" or the default locale's code
        $default = class_exists(\Winter\Translate\Classes\Translator::class)
            ? (string) \Winter\Translate\Classes\Translator::instance()->getDefaultLocale()
            : 'default';
        $locales = array_map(fn ($locale) => in_array($locale, ['default', $default], true) ? '' : (string) $locale, (array) $this->option('locale'));

        if ($locales) {
            $all = array_filter(array_map(fn (array $files) => array_intersect_key($files, array_flip($locales)), $all));
        }

        return $all;
    }

    /**
     * @param array<string, array<string, StaticPageFile>> $pages
     */
    protected function findPage(array $pages, string $page): ?string
    {
        $name = preg_replace('/\.htm$/', '', $page);

        if (isset($pages[$name])) {
            return $name;
        }

        foreach ($pages as $name => $files) {
            if (str_starts_with($page, '/') && ($files['']->viewBag()['url'] ?? null) === $page) {
                return (string) $name;
            }
        }

        return null;
    }

    /**
     * A path relative to the project, with forward slashes.
     */
    protected function relativePath(?string $path): string
    {
        if ($path === null || $path === '') {
            return '';
        }

        $path = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', base_path()), '/') . '/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
