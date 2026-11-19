<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Console;

use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\BrickSource;
use NumenCode\Bricks\Classes\InvalidBrickException;
use Winter\Storm\Exception\ApplicationException;

/**
 * bricks:list – every brick type of a theme, where it comes from and what it replaces.
 */
class ListBricks extends BricksCommand
{
    protected $signature = 'bricks:list
        {--theme= : The theme (default: the active theme)}
        {--overrides : Only the types that replace another definition or markup}';

    protected $description = 'List the brick types, where each one comes from and which ones override others.';

    public function handle(): int
    {
        try {
            $theme = $this->theme();
        } catch (ApplicationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = [];
        $invalid = 0;
        $overrides = 0;

        foreach (BrickRegistry::instance()->sources($theme) as $code => $sources) {
            $code = (string) $code;
            $active = end($sources);
            $partial = BrickRegistry::themePartial($theme, $code);

            try {
                $type = $active->makeType();
            } catch (InvalidBrickException) {
                $type = null;
                $invalid++;
            }

            // A theme partial replaces the markup of any type except a component brick
            $usesPartial = $partial !== null && !$type?->isComponent();
            $replaces = array_map(fn (BrickSource $source) => $source->describe(), array_slice($sources, 0, -1));

            if ($usesPartial && $active->markup !== null && $active->markup !== $partial) {
                $replaces[] = 'markup of ' . $active->describe();
            }

            if ($replaces) {
                $overrides++;
            } elseif ($this->option('overrides')) {
                continue;
            }

            $rows[] = [
                $code,
                $type ? $type->label() : '<fg=red>invalid</> (bricks:validate)',
                $active->describe(),
                $active->definition !== null ? $this->relativePath($active->definition) : '–',
                match (true) {
                    (bool) $type?->isComponent() => 'component ' . class_basename((string) $type->component),
                    $usesPartial => $this->relativePath($partial),
                    default => $this->relativePath($active->markup) ?: '–',
                },
                implode(', ', $replaces),
            ];
        }

        if (!$rows) {
            $this->info($this->option('overrides') ? 'No brick type overrides another one.' : 'There are no brick types.');

            return self::SUCCESS;
        }

        $this->table(['Code', 'Name', 'Provided by', 'Definition', 'Markup', 'Replaces'], $rows);

        $this->line(sprintf(
            'Theme %s: %d brick type(s), %d override(s)%s.',
            $theme->getDirName(),
            count($rows),
            $overrides,
            $invalid ? ", <fg=red>{$invalid} invalid</> (run bricks:validate)" : '',
        ));

        return self::SUCCESS;
    }
}
