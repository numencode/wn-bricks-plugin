<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Console;

use Artisan;
use Cms\Classes\Theme;
use File;
use Illuminate\Contracts\Console\Kernel;
use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Console\ClearMarkup;
use NumenCode\Bricks\Console\ConvertPages;
use NumenCode\Bricks\Console\CreateBrick;
use NumenCode\Bricks\Console\ListBricks;
use NumenCode\Bricks\Console\ValidateBricks;
use NumenCode\Bricks\Tests\BricksTestCase;

/**
 * Runs the plugin's console commands; a writable scratch theme for the commands that write files.
 */
abstract class CommandTestCase extends BricksTestCase
{
    protected ?string $scratch = null;

    public function setUp(): void
    {
        parent::setUp();

        // PluginTestCase loads the plugin after Artisan is created, so register the commands explicitly
        foreach ([ListBricks::class, ValidateBricks::class, CreateBrick::class, ConvertPages::class, ClearMarkup::class] as $command) {
            $this->app[Kernel::class]->registerCommand($this->app->make($command));
        }
    }

    public function tearDown(): void
    {
        if ($this->scratch !== null) {
            File::deleteDirectory($this->scratch);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{0: int, 1: string}
     */
    protected function command(string $command, array $parameters = []): array
    {
        BrickRegistry::instance()->clearCache();
        $code = Artisan::call($command, $parameters);

        // The command filled the registry before it wrote files
        BrickRegistry::instance()->clearCache();

        return [$code, Artisan::output()];
    }

    /**
     * An empty, writable theme "scratch" as the active theme (in a temporary themes directory).
     */
    protected function useScratchTheme(): Theme
    {
        $this->scratch = storage_path('temp/bricks-tests-' . uniqid());
        File::makeDirectory($this->scratch . '/scratch', 0755, true);
        File::put($this->scratch . '/scratch/theme.yaml', "name: Scratch\n");

        $this->app->setThemesPath($this->scratch);
        config(['cms.activeTheme' => 'scratch']);
        Theme::resetCache();

        return Theme::getActiveTheme();
    }

    /**
     * Writes a file of the scratch theme (path relative to the theme).
     */
    protected function themeFile(string $path, string $content): string
    {
        $file = $this->scratch . '/scratch/' . $path;
        File::makeDirectory(dirname($file), 0755, true, true);
        File::put($file, $content);

        return $file;
    }
}
