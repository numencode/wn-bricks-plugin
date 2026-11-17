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

        $theme = Theme::getActiveTheme();

        // CMS objects without their own datasource (e.g. $page->theme) use the resolver's default, which was set
        // to the test environment's theme when the app booted
        \Cms\Classes\CmsObject::getDatasourceResolver()->setDefaultDatasource($theme->getDirName());

        return $theme;
    }

    /**
     * Registers the fixture component Greeting (code "greeting").
     */
    protected function registerGreeting(): void
    {
        require_once $this->fixturePath('components/Greeting.php');

        // Load the registered components first; registering one before that would skip all the others
        $manager = \Cms\Classes\ComponentManager::instance();
        $manager->listComponents();
        $manager->registerComponent(\NumenCode\Bricks\Tests\Fixtures\Components\Greeting::class, 'greeting');
    }

    protected function fixturePath(string $path = ''): string
    {
        return plugins_path('numencode/bricks/tests/fixtures' . ($path === '' ? '' : '/' . $path));
    }
}
