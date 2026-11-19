<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Console;

use Winter\Storm\Exception\ApplicationException;

/**
 * bricks:clear-markup – removes the old content (rich editor HTML) of static pages that were converted into bricks,
 * once the bricks look right. Only files with their own bricks, on layouts with that bricks field.
 */
class ClearMarkup extends BricksCommand
{
    protected $signature = 'bricks:clear-markup
        {pages?* : Page file names <info>(eg: about)</info> or URLs <info>(eg: /about)</info>}
        {--all : All static pages}
        {--theme= : The theme (default: the active theme)}
        {--field=bricks : The bricks field the pages must have}
        {--locale=* : Only these languages ("default" or a language code; default: all)}
        {--dry-run : Show what would be removed without saving}
        {--force : Don\'t ask for confirmation}';

    protected $description = 'Remove the old content (rich editor HTML) of static pages that have bricks.';

    public function handle(): int
    {
        try {
            $theme = $this->theme();
            $pages = $this->pageFiles($theme);
        } catch (ApplicationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $field = (string) $this->option('field');
        $clear = [];

        foreach ($pages as $files) {
            $default = $files[''] ?? null;
            $layout = $default ? (string) ($default->viewBag()['layout'] ?? '') : '';
            $layoutFields = $default?->layoutBricksFields($layout);

            foreach ($files as $file) {
                if (trim($file->markup()) === '') {
                    continue;
                }

                if (!$file->bricks($field)) {
                    $this->line("<fg=gray>– {$file->label()}: no bricks of its own, content kept</>");
                    continue;
                }

                // The layout would show the old content ({% page %}) or nothing of the bricks: removing it would empty the page
                if ($layoutFields !== null && !in_array($field, $layoutFields, true)) {
                    $this->warn("⚠ {$file->label()}: the layout \"{$layout}\" has no bricks field \"{$field}\", content kept");
                    continue;
                }

                $this->line(sprintf('<fg=green>✔</> %s: %s characters of content', $file->label(), number_format(mb_strlen(trim($file->markup())))));
                $clear[] = $file;
            }
        }

        if (!$clear) {
            $this->info('There is no content to remove.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('Would remove the content of %d page file(s). Nothing was saved (--dry-run).', count($clear)));

            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm(sprintf('Remove the old content of %d page file(s)? This can\'t be undone (except from a backup or git).', count($clear)))) {
            $this->warn('Nothing was removed.');

            return self::FAILURE;
        }

        foreach ($clear as $file) {
            $file->setMarkup('');
            $file->save();
        }

        $this->info(sprintf('Removed the old content of %d page file(s).', count($clear)));

        return self::SUCCESS;
    }
}
