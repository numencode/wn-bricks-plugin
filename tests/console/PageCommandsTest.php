<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Console;

use Cms\Classes\Theme;
use NumenCode\Bricks\Classes\StaticPageFile;

/**
 * bricks:convert-pages and bricks:clear-markup.
 */
class PageCommandsTest extends CommandTestCase
{
    protected Theme $theme;

    public function setUp(): void
    {
        parent::setUp();

        $this->theme = $this->useScratchTheme();

        $this->themeFile('layouts/bricks.htm', "[staticPage]\n==\n{variable type=\"bricks\" name=\"bricks\"}{/variable}\n{{ renderBricks() }}");
        $this->themeFile('layouts/plain.htm', "[staticPage]\n==\n{% page %}");

        $this->page('about', '/about', 'bricks', '<h2>About us</h2><p>We make "things" – ž.</p>');
        $this->page('about', '/o-nas', 'bricks', '<h2>O nas</h2><p>Delamo.</p>', 'sl');
        $this->page('contact', '/contact', 'plain', '<p>Write to us.</p>');
        $this->page('empty', '/empty', 'bricks', '');
    }

    protected function page(string $name, string $url, string $layout, string $markup, string $locale = ''): void
    {
        $directory = StaticPageFile::DIRECTORY . ($locale === '' ? '' : '-' . $locale);

        $this->themeFile("{$directory}/{$name}.htm", "[viewBag]\ntitle = \"" . ucfirst($name) . "\"\nurl = \"{$url}\"\nlayout = \"{$layout}\"\n==\n{$markup}");
    }

    protected function file(string $name, string $locale = ''): StaticPageFile
    {
        $directory = StaticPageFile::DIRECTORY . ($locale === '' ? '' : '-' . $locale);

        return new StaticPageFile($this->theme, $name, $this->scratch . "/scratch/{$directory}/{$name}.htm", $locale);
    }

    public function testConvertWritesBricksInEveryLanguageAndKeepsTheContent(): void
    {
        [$code, $output] = $this->command('bricks:convert-pages', ['pages' => ['about']]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('about: 2 brick(s): 1 Heading, 1 Text', $output);
        $this->assertStringContainsString('about (sl): 2 brick(s)', $output);
        $this->assertStringContainsString('Converted 2 page file(s).', $output);

        $about = $this->file('about');
        $this->assertSame([
            ['text' => 'About us', 'level' => 'h2', '_group' => 'heading'],
            ['content' => '<p>We make "things" – ž.</p>', '_group' => 'text'],
        ], $about->bricks('bricks'));
        $this->assertSame('<h2>About us</h2><p>We make "things" – ž.</p>', $about->markup(), 'the old content is kept');
        $this->assertSame(['title' => 'About', 'url' => '/about', 'layout' => 'bricks'], array_diff_key($about->viewBag(), ['bricks' => 1]));

        $this->assertSame('O nas', $this->file('about', 'sl')->bricks('bricks')[0]['text']);
    }

    public function testConvertSkipsPagesWithBricksUnlessForced(): void
    {
        $this->command('bricks:convert-pages', ['pages' => ['about']]);
        $this->page('about', '/about', 'bricks', '<p>Changed</p>');

        // The SL file still has its bricks; the rewritten EN file has none
        [$code, $output] = $this->command('bricks:convert-pages', ['pages' => ['about']]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('about (sl): already has bricks, skipped (use --force to replace them)', $output);

        [, $output] = $this->command('bricks:convert-pages', ['pages' => ['about'], '--force' => true, '--locale' => ['sl']]);
        $this->assertStringContainsString('about (sl): 2 brick(s)', $output);
        $this->assertStringNotContainsString('about: ', $output);
    }

    public function testDryRunSavesNothingAndListsTheBricks(): void
    {
        $before = file_get_contents($this->file('about')->path);

        [$code, $output] = $this->command('bricks:convert-pages', ['--all' => true, '--dry-run' => true, '--no-split' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('1. Text: About us We make "things" – ž.', $output);
        $this->assertStringContainsString('empty: no content', $output);
        $this->assertStringContainsString('Would convert 3 page file(s).', $output);
        $this->assertSame($before, file_get_contents($this->file('about')->path));
    }

    public function testConvertWarnsAboutLayoutsWithoutTheField(): void
    {
        [$code, $output] = $this->command('bricks:convert-pages', ['pages' => ['/contact']]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('contact: the layout "plain" has no bricks field "bricks"', $output);
        $this->assertSame('text', $this->file('contact')->bricks('bricks')[0]['_group']);
    }

    public function testChoosingPages(): void
    {
        [$code, $output] = $this->command('bricks:convert-pages');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Name the pages', $output);

        [$code, $output] = $this->command('bricks:convert-pages', ['pages' => ['about'], '--all' => true]);
        $this->assertSame(1, $code);

        [$code, $output] = $this->command('bricks:convert-pages', ['pages' => ['nope']]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('There is no static page "nope"', $output);

        // By URL; "default" is the default language's file
        [$code, $output] = $this->command('bricks:convert-pages', ['pages' => ['/about'], '--locale' => ['default'], '--dry-run' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Would convert 1 page file(s).', $output);
    }

    public function testClearMarkupRemovesTheContentOfFilesWithBricks(): void
    {
        $this->command('bricks:convert-pages', ['--all' => true]);
        $this->page('about', '/o-nas', 'bricks', '<p>Only content</p>', 'sl');

        [$code, $output] = $this->command('bricks:clear-markup', ['--all' => true, '--dry-run' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('about (sl): no bricks of its own, content kept', $output);
        $this->assertStringContainsString('contact: the layout "plain" has no bricks field "bricks", content kept', $output);
        $this->assertStringContainsString('Would remove the content of 1 page file(s).', $output);
        $this->assertNotSame('', $this->file('about')->markup());

        // Asks first: without an answer (no interaction) nothing is removed
        [$code] = $this->command('bricks:clear-markup', ['--all' => true, '--no-interaction' => true]);
        $this->assertSame(1, $code);
        $this->assertNotSame('', $this->file('about')->markup());

        [$code, $output] = $this->command('bricks:clear-markup', ['--all' => true, '--force' => true]);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Removed the old content of 1 page file(s).', $output);

        $about = $this->file('about');
        $this->assertSame('', $about->markup());
        $this->assertCount(2, $about->bricks('bricks'), 'the bricks stay');
        $this->assertSame('<p>Only content</p>', $this->file('about', 'sl')->markup());
        $this->assertSame('<p>Write to us.</p>', $this->file('contact')->markup());
    }
}
