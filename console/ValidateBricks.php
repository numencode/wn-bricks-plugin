<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Console;

use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\BrickValidator;
use Winter\Storm\Exception\ApplicationException;

/**
 * bricks:validate – checks the brick type definitions and markup of a theme; exits with 1 on errors (for CI).
 */
class ValidateBricks extends BricksCommand
{
    protected $signature = 'bricks:validate
        {--theme= : The theme (default: the active theme)}
        {--theme-only : Only report problems in the theme\'s files (bricks/*.yaml, partials/bricks/*.htm)}
        {--strict : Fail on warnings too}';

    protected $description = 'Check the brick type definitions and their markup (exits with 1 on errors, for CI).';

    public function handle(): int
    {
        try {
            $theme = $this->theme();
        } catch (ApplicationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $validator = new BrickValidator($theme);
        $issues = $validator->validate();

        if ($this->option('theme-only')) {
            $issues = array_values(array_filter($issues, fn (array $issue) => $validator->isThemeFile($issue['file'])));
        }

        $errors = 0;
        $warnings = 0;

        foreach ($issues as $issue) {
            $where = sprintf('<options=bold>%s</> %s', $issue['code'], $this->relativePath($issue['file']));

            if ($issue['level'] === BrickValidator::ERROR) {
                $errors++;
                $this->line("<fg=red>✘</> {$where}");
                $this->line("    <fg=red>{$issue['message']}</>");
            } else {
                $warnings++;
                $this->line("<fg=yellow>⚠</> {$where}");
                $this->line("    <fg=yellow>{$issue['message']}</>");
            }
        }

        $types = count(BrickRegistry::instance()->sources($theme));
        $failed = $errors > 0 || ($warnings > 0 && $this->option('strict'));

        if ($issues) {
            $this->newLine();
        }

        $summary = sprintf(
            'Theme %s: %d brick type(s) checked, %d error(s), %d warning(s).',
            $theme->getDirName(),
            $types,
            $errors,
            $warnings,
        );

        $failed ? $this->error($summary) : $this->info(($issues ? '' : '✔ ') . $summary);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
