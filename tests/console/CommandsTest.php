<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Console;

use Event;
use File;
use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\BrickType;
use NumenCode\Bricks\Tests\Fixtures\Components\Greeting;
use Winter\Pages\Classes\Snippet;
use Winter\Pages\Classes\SnippetManager;

/**
 * bricks:list, bricks:validate and create:brick.
 */
class CommandsTest extends CommandTestCase
{
    public function testListShowsWhereTypesComeFromAndWhatTheyReplace(): void
    {
        $this->registerGreeting();
        $this->useFixtureTheme('bricktest');

        [$code, $output] = $this->command('bricks:list');

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('#\| heading +\| Theme heading +\| theme bricktest +\| .*themes/bricktest/bricks/heading\.yaml +\| plugins/numencode/bricks/bricks/heading\.htm +\| NumenCode\.Bricks +\|#', $output);
        $this->assertMatchesRegularExpression('#\| quote +\| Quote +\| theme bricktest +\| .*bricks/quote\.yaml +\| .*partials/bricks/quote\.htm +\| NumenCode\.Bricks +\|#', $output);
        $this->assertMatchesRegularExpression('#\| greeting +\| Greeting brick +\| theme bricktest +\| .*greeting\.yaml +\| component Greeting +\|#', $output);
        $this->assertMatchesRegularExpression('#\| text +\| Text +\| NumenCode\.Bricks +\|#', $output);
        $this->assertStringContainsString('Theme bricktest: ' . (count(\NumenCode\Bricks\Plugin::BASE_SET) + 1) . ' brick type(s), 2 override(s).', $output);

        [, $output] = $this->command('bricks:list', ['--overrides' => true]);

        $this->assertStringContainsString('| heading', $output);
        $this->assertStringNotContainsString('| text', $output);
    }

    public function testListShowsAThemePartialReplacingAPluginsMarkup(): void
    {
        $theme = $this->useScratchTheme();
        File::makeDirectory($theme->getPath() . '/partials/bricks', 0755, true);
        File::put($theme->getPath() . '/partials/bricks/text.htm', '{{ content|raw }}');

        [, $output] = $this->command('bricks:list', ['--overrides' => true]);

        $this->assertMatchesRegularExpression('#\| text +\| Text +\| NumenCode\.Bricks +\| .*bricks/text\.yaml +\| .*scratch/partials/bricks/text\.htm +\| markup of NumenCode\.Bricks +\|#', $output);
    }

    public function testListMarksInvalidTypes(): void
    {
        $this->useFixtureTheme('broken');

        [$code, $output] = $this->command('bricks:list');

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('#\| oops +\| invalid \(bricks:validate\)#', $output);
        $this->assertStringContainsString('1 invalid', $output);
    }

    public function testValidateExitCodes(): void
    {
        $this->registerGreeting();
        $this->useFixtureTheme('bricktest');

        [$code, $output] = $this->command('bricks:validate');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('0 error(s), 0 warning(s)', $output);

        $this->useFixtureTheme('validation');

        [$code, $output] = $this->command('bricks:validate');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('unknown type "txt"', $output);
        $this->assertStringContainsString('5 error(s), 8 warning(s)', $output);

        [$code, $output] = $this->command('bricks:validate', ['--theme' => 'nope']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('The theme "nope" doesn\'t exist.', $output);
    }

    public function testValidateWarningsFailOnlyWhenStrict(): void
    {
        $theme = $this->useScratchTheme();
        File::makeDirectory($theme->getPath() . '/partials/bricks', 0755, true);
        File::put($theme->getPath() . '/partials/bricks/nothing.htm', '<p>Unused</p>');

        [$code, $output] = $this->command('bricks:validate');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('0 error(s), 1 warning(s)', $output);

        [$code] = $this->command('bricks:validate', ['--strict' => true]);
        $this->assertSame(1, $code);
    }

    public function testValidateThemeOnlySkipsPluginFiles(): void
    {
        $this->useScratchTheme();

        // A broken plugin definition (registered by a callback, outside the theme)
        BrickRegistry::instance()->registerCallback(fn (BrickRegistry $registry) => $registry->registerBricks([
            'oops' => $this->fixturePath('themes/broken/bricks/oops'),
        ]));

        [$code] = $this->command('bricks:validate');
        $this->assertSame(1, $code);

        [$code, $output] = $this->command('bricks:validate', ['--theme-only' => true]);
        $this->assertSame(0, $code, $output);
    }

    public function testCreateInTheTheme(): void
    {
        $theme = $this->useScratchTheme();

        [$code, $output] = $this->command('create:brick', ['code' => 'call-to-action', '--icon' => 'icon-bullhorn']);

        $this->assertSame(0, $code, $output);
        $this->assertFileExists($theme->getPath() . '/bricks/call-to-action.yaml');
        $this->assertFileExists($theme->getPath() . '/partials/bricks/call-to-action.htm');

        // The scaffold is a valid brick type and its markup renders the fields
        $type = BrickRegistry::instance()->get('call-to-action', $theme);
        $this->assertSame('Call to action', $type->label());
        $this->assertSame('icon-bullhorn', $type->icon);
        $this->assertSame('title', $type->titleFrom);
        $this->assertSame(['title', 'content'], array_keys($type->fields));

        [$code, $output] = $this->command('bricks:validate', ['--strict' => true]);
        $this->assertSame(0, $code, $output);

        // Not again, unless forced
        [$code, $output] = $this->command('create:brick', ['code' => 'call-to-action']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('already exists. Use --force', $output);

        [$code] = $this->command('create:brick', ['code' => 'call-to-action', '--force' => true, '--name' => 'Ask: now']);
        $this->assertSame(0, $code);
        $this->assertSame('Ask: now', BrickRegistry::instance()->get('call-to-action', $theme)->label());
    }

    public function testCreateRefusesExistingAndInvalidCodes(): void
    {
        $this->useScratchTheme();

        [$code, $output] = $this->command('create:brick', ['code' => 'image']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('There is already a brick type "image" (from NumenCode.Bricks). Use --override', $output);

        [$code, $output] = $this->command('create:brick', ['code' => 'Call']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('The code must start with a lowercase letter', $output);
    }

    public function testOverrideCopiesTheTypeIntoTheTheme(): void
    {
        $theme = $this->useScratchTheme();

        [$code, $output] = $this->command('create:brick', ['code' => 'quote', '--override' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertFileEquals(plugins_path('numencode/bricks/bricks/quote.yaml'), $theme->getPath() . '/bricks/quote.yaml');
        $this->assertFileEquals(plugins_path('numencode/bricks/bricks/quote.htm'), $theme->getPath() . '/partials/bricks/quote.htm');

        $type = BrickRegistry::instance()->get('quote', $theme);
        $this->assertStringEndsWith('scratch/partials/bricks/quote.htm', str_replace('\\', '/', $type->markupPath));

        [$code, $output] = $this->command('create:brick', ['code' => 'quote', '--override' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('already defined by the theme', $output);
    }

    public function testMarkupCopiesOnlyTheMarkup(): void
    {
        $theme = $this->useScratchTheme();

        [$code, $output] = $this->command('create:brick', ['code' => 'divider', '--markup' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertFileExists($theme->getPath() . '/partials/bricks/divider.htm');
        $this->assertFileDoesNotExist($theme->getPath() . '/bricks/divider.yaml');

        [$code, $output] = $this->command('create:brick', ['code' => 'divider', '--markup' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('The theme already has the markup', $output);

        [$code, $output] = $this->command('create:brick', ['code' => 'nope', '--override' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('There is no brick type "nope" to override.', $output);
    }

    public function testOverrideOfAComponentBrickCopiesOnlyItsDefinition(): void
    {
        $this->registerGreeting();
        $theme = $this->useScratchTheme();

        $file = $this->scratch . '/greeting';
        File::put($file . '.yaml', "name: Greeting\ncomponent: " . Greeting::class . "\n");
        BrickRegistry::instance()->registerCallback(fn (BrickRegistry $registry) => $registry->registerBricks(['greeting' => $file]));

        [$code, $output] = $this->command('create:brick', ['code' => 'greeting', '--markup' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('is a component brick', $output);

        [$code, $output] = $this->command('create:brick', ['code' => 'greeting', '--override' => true]);
        $this->assertSame(0, $code, $output);
        $this->assertFileEquals($file . '.yaml', $theme->getPath() . '/bricks/greeting.yaml');
        $this->assertFileDoesNotExist($theme->getPath() . '/partials/bricks/greeting.htm');
    }

    public function testOverrideOfASnippetBrickWritesADefinition(): void
    {
        $this->registerGreeting();
        SnippetManager::forgetInstance();
        $theme = $this->useScratchTheme();

        Event::listen('pages.snippets.listSnippets', function (SnippetManager $manager) {
            $snippet = new Snippet();
            $snippet->initFromComponentInfo(Greeting::class, 'greeter');
            $manager->addSnippet($snippet);
        });

        [$code, $output] = $this->command('create:brick', ['code' => 'snippet-greeter', '--override' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('from snippet (NumenCode.Bricks)', str_replace("\n", ' ', $output));

        $type = BrickRegistry::instance()->get('snippet-greeter', $theme);
        $this->assertSame(Greeting::class, $type->component);
        $this->assertSame('Greeting', $type->label());
        $this->assertStringEndsWith('scratch/bricks/snippet-greeter.yaml', str_replace('\\', '/', $type->source));
    }

    public function testCreateInAPlugin(): void
    {
        $this->useScratchTheme();
        $base = plugins_path('numencode/bricks/bricks/zz-scaffold-test');

        try {
            [$code, $output] = $this->command('create:brick', ['code' => 'zz-scaffold-test', '--plugin' => 'numencode.bricks']);

            $this->assertSame(0, $code, $output);
            $this->assertFileExists($base . '.yaml');
            $this->assertFileExists($base . '.htm');
            $this->assertStringContainsString("'zz-scaffold-test' => '\$/numencode/bricks/bricks/zz-scaffold-test',", $output);
        } finally {
            File::delete([$base . '.yaml', $base . '.htm']);
        }

        [$code, $output] = $this->command('create:brick', ['code' => 'promo', '--plugin' => 'Acme.Nope']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('The plugin "Acme.Nope" doesn\'t exist.', $output);

        [$code, $output] = $this->command('create:brick', ['code' => 'image', '--plugin' => 'NumenCode.Bricks']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Use --force to create it anyway', $output);

        [$code, $output] = $this->command('create:brick', ['code' => 'promo', '--plugin' => 'NumenCode.Bricks', '--override' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('leave out --plugin', $output);
    }

    public function testTheScaffoldIsAValidDefinition(): void
    {
        $theme = $this->useScratchTheme();
        $this->command('create:brick', ['code' => 'promo-box', '--name' => 'true']);

        $type = BrickType::fromYaml('promo-box', $theme->getPath() . '/bricks/promo-box.yaml', $theme->getPath() . '/partials/bricks/promo-box.htm');

        $this->assertSame('true', $type->name);
    }
}
