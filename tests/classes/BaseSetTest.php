<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Cms\Classes\Controller;
use Cms\Classes\Theme;
use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Plugin;
use NumenCode\Bricks\Tests\BricksTestCase;
use Winter\Pages\Classes\Page;

/**
 * The base set's markup, rendered through the CMS (fixture page /base).
 */
class BaseSetTest extends BricksTestCase
{
    protected string $html = '';

    public function setUp(): void
    {
        parent::setUp();

        if (!class_exists(Page::class)) {
            $this->markTestSkipped('Winter.Pages is not installed.');
        }

        $this->useFixtureTheme();

        $html = (string) (new Controller(Theme::getActiveTheme()))->run('/base')->getContent();
        preg_match('#<main>(.*?)</main>#s', $html, $match);
        $this->html = static::normalize($match[1] ?? '');
    }

    /**
     * Collapses whitespace and removes it between tags, so markup can be compared regardless of indentation.
     */
    protected static function normalize(string $html): string
    {
        return trim(preg_replace(['/\s+/', '/>\s+</'], [' ', '><'], $html));
    }

    protected function assertHtmlContains(string $expected): void
    {
        $this->assertStringContainsString(static::normalize($expected), $this->html);
    }

    public function testTheBaseSetIsRegistered(): void
    {
        $types = BrickRegistry::instance()->all();

        foreach (Plugin::BASE_SET as $code) {
            $this->assertArrayHasKey($code, $types);
        }

        $this->assertSame(['numencode.bricks.use_html'], $types['html']->permissions);
        $this->assertSame([], $types['divider']->fields);
    }

    public function testImage(): void
    {
        $this->assertMatchesRegularExpression(
            '#<figure class="brick brick--image brick--image-wide"><img class="brick__image" src="[^"]*/photo\.jpg" alt="A photo" loading="lazy"><figcaption class="brick__caption">Caption</figcaption></figure>#',
            $this->html,
        );
    }

    public function testGallerySkipsEmptyImages(): void
    {
        $this->assertHtmlContains('<div class="brick brick--gallery brick--gallery-4">');
        $this->assertSame(2, substr_count($this->html, '<figure class="brick__item">'));
        $this->assertMatchesRegularExpression('#href="[^"]*/one\.jpg" data-gallery="bricks-1"#', $this->html);
        $this->assertHtmlContains('<figcaption class="brick__caption">Two</figcaption>');
    }

    public function testColumns(): void
    {
        $this->assertHtmlContains(
            '<div class="brick brick--columns brick--columns-3"> <div class="brick__column"><p>First</p></div> <div class="brick__column"><p>Second</p></div> <div class="brick__column"><p>Third</p></div> </div>',
        );

        // An invalid count falls back to 2 columns
        $this->assertHtmlContains('<div class="brick brick--columns brick--columns-2"> <div class="brick__column"><p>A</p></div> <div class="brick__column"><p>B</p></div> </div>');
        $this->assertStringNotContainsString('Hidden', $this->html);
    }

    public function testButton(): void
    {
        $this->assertHtmlContains(
            '<a class="brick__button brick__button--secondary" href="/contact" target="_blank" rel="noopener">Contact us</a>',
        );

        // Without a URL nothing is rendered – and no value of an earlier brick (the video URL) leaks into it
        $this->assertStringNotContainsString('No link', $this->html);
    }

    public function testVideo(): void
    {
        $this->assertHtmlContains('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ" title="A video"');
        $this->assertSame(1, substr_count($this->html, 'brick--video'), 'an unsupported URL renders nothing');
    }

    public function testDividerSpacerAndHtml(): void
    {
        $this->assertHtmlContains('<hr class="brick brick--divider">');
        $this->assertHtmlContains('<div class="brick brick--spacer brick--spacer-large" aria-hidden="true"></div>');
        $this->assertHtmlContains('<div class="brick brick--html"><div id="custom">Custom</div></div>');
    }
}
