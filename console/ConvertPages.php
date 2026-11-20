<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Console;

use NumenCode\Bricks\Classes\BrickIds;
use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\BrickType;
use NumenCode\Bricks\Classes\PageConverter;
use NumenCode\Bricks\Classes\StaticPageFile;
use Winter\Storm\Exception\ApplicationException;

/**
 * bricks:convert-pages – turns the content of static pages (Winter.Pages' rich editor, with snippets) into bricks, in
 * every language file. The old content is kept (bricks:clear-markup removes it once the bricks look right).
 */
class ConvertPages extends BricksCommand
{
    protected $signature = 'bricks:convert-pages
        {pages?* : Page file names <info>(eg: about)</info> or URLs <info>(eg: /about)</info>}
        {--all : Convert all static pages}
        {--theme= : The theme (default: the active theme)}
        {--field=bricks : The bricks field to fill}
        {--locale=* : Only these languages ("default" or a language code; default: all)}
        {--no-split : Put headings, images and dividers in text bricks too (only component and text bricks)}
        {--dry-run : Show the bricks without saving them}
        {--force : Also convert pages that already have bricks (replaces them)}';

    protected $description = 'Convert the content of static pages (rich editor HTML and snippets) into bricks. The old content is kept.';

    public function handle(): int
    {
        try {
            $theme = $this->theme();
            $pages = $this->pageFiles($theme);
            $converter = new PageConverter(BrickRegistry::instance()->all($theme), !$this->option('no-split'));
        } catch (ApplicationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $field = (string) $this->option('field');
        $dryRun = (bool) $this->option('dry-run');
        $types = BrickRegistry::instance()->all($theme);
        $converted = 0;
        $skipped = 0;

        foreach ($pages as $files) {
            $this->checkLayout($files, $field);

            foreach ($files as $file) {
                if (trim($file->markup()) === '') {
                    $this->line("<fg=gray>– {$file->label()}: no content</>");
                    continue;
                }

                if ($file->bricks($field) && !$this->option('force')) {
                    $this->line("<fg=yellow>– {$file->label()}: already has bricks, skipped (use --force to replace them)</>");
                    $skipped++;
                    continue;
                }

                try {
                    $bricks = $converter->convert($file->markup());
                } catch (ApplicationException $exception) {
                    $this->error($exception->getMessage());

                    return self::FAILURE;
                }

                $this->line(sprintf('<fg=green>✔</> %s: %s', $file->label(), $this->summary($bricks, $types)));

                if ($dryRun || $this->getOutput()->isVerbose()) {
                    foreach ($bricks as $index => $brick) {
                        $this->line(sprintf('    %d. %s', $index + 1, $this->describe($brick, $types)));
                    }
                }

                foreach ($converter->warnings() as $warning) {
                    $this->warn("    ⚠ {$warning}");
                }

                if (!$dryRun) {
                    // The file is written directly (not saved by a model), so the ids are assigned here
                    $file->setBricks($field, BrickIds::bricks($bricks));
                    $file->save();
                }

                $converted++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d page file(s)%s. %s',
            $dryRun ? 'Would convert' : 'Converted',
            $converted,
            $skipped ? ", skipped {$skipped} with bricks" : '',
            $dryRun ? 'Nothing was saved (--dry-run).' : 'The old content is kept: check the pages, then remove it with bricks:clear-markup.',
        ));

        return self::SUCCESS;
    }

    /**
     * Warns when the page's layout has no such bricks field: the bricks are saved, but not edited or shown.
     *
     * @param array<string, StaticPageFile> $files
     */
    protected function checkLayout(array $files, string $field): void
    {
        $default = $files[''] ?? null;

        if (!$default) {
            return;
        }

        $layout = (string) ($default->viewBag()['layout'] ?? '');
        $fields = $default->layoutBricksFields($layout);

        if ($fields !== null && !in_array($field, $fields, true)) {
            $this->warn(sprintf(
                '⚠ %s: the layout "%s" has no bricks field "%s" ({variable type="bricks" name="%s"}); the bricks are not shown until it has.',
                $default->name,
                $layout,
                $field,
                $field,
            ));
        }
    }

    /**
     * "6 bricks: 2 Text, 1 Heading, …"
     *
     * @param array<int, array<string, mixed>> $bricks
     * @param array<string, BrickType> $types
     */
    protected function summary(array $bricks, array $types): string
    {
        $counts = [];

        foreach ($bricks as $brick) {
            $label = $this->typeLabel((string) $brick['_group'], $types);
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        $parts = [];

        foreach ($counts as $label => $count) {
            $parts[] = "{$count} {$label}";
        }

        return count($bricks) . ' brick(s)' . ($parts ? ': ' . implode(', ', $parts) : '');
    }

    /**
     * "Heading: Our team", "Text: First paragraph …", "Counters: group=1, layout=@default"
     *
     * @param array<string, mixed> $brick
     * @param array<string, BrickType> $types
     */
    protected function describe(array $brick, array $types): string
    {
        $values = [];

        foreach ($brick as $key => $value) {
            if ($key === '_group' || !is_scalar($value)) {
                continue;
            }

            $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('/<[^>]+>/', ' $0', (string) $value))));
            $values[] = (isset($types[$brick['_group']]) && $types[$brick['_group']]->isComponent()) ? "{$key}={$text}" : $text;
        }

        $summary = implode(', ', array_filter($values, fn ($value) => $value !== ''));

        return $this->typeLabel((string) $brick['_group'], $types) . ($summary === '' ? '' : ': ' . \Str::limit($summary, 80));
    }

    /**
     * @param array<string, BrickType> $types
     */
    protected function typeLabel(string $code, array $types): string
    {
        return isset($types[$code]) ? $types[$code]->label() : $code;
    }
}
