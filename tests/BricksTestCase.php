<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests;

use Cms\Classes\Theme;
use NumenCode\Bricks\Classes\BrickRegistry;
use System\Tests\Bootstrap\PluginTestCase;

/**
 * Base test case: loads Winter.Pages and Winter.Translate when they are installed, and resets the brick registry.
 */
abstract class BricksTestCase extends PluginTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        if (class_exists(\Winter\Pages\Plugin::class)) {
            $this->instantiatePlugin('Winter.Pages');
        }

        if (class_exists(\Winter\Translate\Plugin::class)) {
            $this->instantiatePlugin('Winter.Translate');
        }

        BrickRegistry::forgetInstance();
    }

    public function tearDown(): void
    {
        BrickRegistry::forgetInstance();

        parent::tearDown();
    }

    /**
     * Makes a theme from tests/fixtures/themes the active theme.
     */
    protected function useFixtureTheme(string $name = 'bricktest'): Theme
    {
        $this->app->setThemesPath(plugins_path('numencode/bricks/tests/fixtures/themes'));
        config(['cms.activeTheme' => $name]);
        Theme::resetCache();

        return Theme::getActiveTheme();
    }

    protected function fixturePath(string $path = ''): string
    {
        return plugins_path('numencode/bricks/tests/fixtures' . ($path === '' ? '' : '/' . $path));
    }
}
