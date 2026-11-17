<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Cms\Classes\Controller;
use Cms\Classes\Theme;
use NumenCode\Bricks\Tests\BricksTestCase;
use NumenCode\Bricks\Tests\Fixtures\Components\Greeting;
use Winter\Pages\Classes\Page;

/**
 * Renders the fixture theme's static pages through the CMS (layout bricks.htm: main = renderBricks(),
 * aside = renderBricks('aside'), loop = renderBrick() in a for loop).
 */
class BrickRendererTest extends BricksTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        if (!class_exists(Page::class)) {
            $this->markTestSkipped('Winter.Pages is not installed.');
        }

        $this->registerGreeting();
        Greeting::$runs = [];

        $this->useFixtureTheme();
    }

    protected function render(string $url): string
    {
        return (string) (new Controller(Theme::getActiveTheme()))->run($url)->getContent();
    }

    protected function section(string $html, string $tag): string
    {
        preg_match("#<{$tag}>(.*?)</{$tag}>#s", $html, $match);

        return $match[1] ?? '';
    }

    public function testBricksRenderWithPluginMarkupAndThemePartials(): void
    {
        $main = $this->section($this->render('/home'), 'main');

        // Plugin markup (heading: theme definition without a partial → plugin heading.htm), escaped values
        $this->assertStringContainsString('<h2 class="brick brick--heading">Theme &lt;heading&gt;</h2>', $main);
        $this->assertStringContainsString('<div class="brick brick--text"><p>Plugin <b>text</b></p></div>', $main);

        // Theme partial with the brick variable
        $this->assertStringContainsString('<blockquote class="theme-quote" data-index="2" data-field="bricks">To be<cite>Someone</cite></blockquote>', $main);

        // Unknown types are skipped; the order is kept
        $this->assertStringNotContainsString('Unknown', $main);
        $this->assertLessThan(strpos($main, 'theme-quote'), strpos($main, 'brick--text'));
    }

    public function testComponentBricksRunAndRender(): void
    {
        $html = $this->render('/home');

        $this->assertStringContainsString('<p class="greeting">Hello Bricks! (brick_bricks_4)</p>', $this->section($html, 'main'));
        $this->assertStringContainsString('<p class="greeting">Hello Aside (brick_aside_1)</p>', $this->section($html, 'aside'));

        // onRun() ran once per component brick (both fields)
        $this->assertSame(['Bricks', 'Aside'], Greeting::$runs);
    }

    public function testOtherFieldsAndRenderBrickInALoop(): void
    {
        $html = $this->render('/home');

        $this->assertStringContainsString('<p>Aside text</p>', $this->section($html, 'aside'));
        $this->assertStringNotContainsString('Plugin <b>text</b>', $this->section($html, 'aside'));

        $loop = $this->section($html, 'loop');
        $this->assertStringContainsString('data-index="2"', $loop);
        $this->assertStringContainsString('Hello Bricks! (brick_bricks_4)', $loop);
    }

    public function testAPageWithoutBricksRendersNothing(): void
    {
        $html = $this->render('/empty');

        $this->assertSame('', trim($this->section($html, 'main')));
    }

    /**
     * In its own process: a static page rendered by an earlier test stays cached in a static (not found yet), so
     * the SL request would get the EN viewBag. Passes alone and in a separate process.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLocalesUseTheirOwnListOrTheDefaultList(): void
    {
        if (!class_exists(\Winter\Translate\Classes\Translator::class)) {
            $this->markTestSkipped('Winter.Translate is not installed.');
        }

        \Winter\Translate\Models\Locale::create(['code' => 'sl', 'name' => 'Slovenščina', 'is_enabled' => true]);

        // Translate's route middleware (URL prefix / session) doesn't run for Controller::run(): set the locale here
        \Winter\Translate\Classes\Translator::instance()->setLocale('sl', false);

        // Own SL list (incl. a component brick with SL properties)
        $main = $this->section($this->render('/home'), 'main');
        $this->assertStringContainsString('<p>Slovensko besedilo</p>', $main);
        $this->assertStringContainsString('Hello Opeke', $main);
        $this->assertStringNotContainsString('Plugin <b>text</b>', $main);

        // No SL file → the default list
        $this->assertStringContainsString('<p>About text</p>', $this->section($this->render('/about'), 'main'));
    }
}
