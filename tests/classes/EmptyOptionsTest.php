<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Cms\Classes\Theme;
use NumenCode\Bricks\Classes\EmptyOptions;
use NumenCode\Bricks\Tests\BricksTestCase;
use Winter\Pages\Classes\Page;
use Winter\Translate\Models\Locale;

class EmptyOptionsTest extends BricksTestCase
{
    protected Theme $theme;

    public function setUp(): void
    {
        parent::setUp();

        if (!class_exists(Page::class) || !class_exists(Locale::class)) {
            $this->markTestSkipped('Winter.Pages and Winter.Translate are needed.');
        }

        $this->theme = $this->useFixtureTheme();
        Locale::create(['code' => 'sl', 'name' => 'Slovenščina', 'is_enabled' => true]);
        Locale::clearCache();
    }

    public function testEmptyOptionsAreDroppedAndOthersKept(): void
    {
        $this->assertSame(
            [
                ['_group' => 'text', 'content' => ''],
                ['_group' => 'text', '_anchor' => 'intro', '_hidden' => '1'],
                'not a brick',
            ],
            EmptyOptions::bricks([
                ['_group' => 'text', 'content' => '', '_anchor' => '', '_class' => '  ', '_hidden' => null],
                ['_group' => 'text', '_anchor' => 'intro', '_class' => '', '_hidden' => '1'],
                'not a brick',
            ]),
        );
    }

    public function testEveryLanguageOfAPageIsCleaned(): void
    {
        $page = Page::load($this->theme, 'guarded');
        $page->translatable[] = 'viewBag[bricks]';

        $page->attributes['viewBag']['bricks'][0] += ['_anchor' => '', '_class' => 'wide'];
        $sl = $page->getTranslateAttributes('sl')['viewBag']['bricks'];
        $sl[0] += ['_anchor' => '', '_class' => ''];
        $page->setAttributeTranslated('viewBag[bricks]', $sl, 'sl');

        EmptyOptions::clean($page);

        $this->assertArrayNotHasKey('_anchor', $page->attributes['viewBag']['bricks'][0]);
        $this->assertSame('wide', $page->attributes['viewBag']['bricks'][0]['_class']);

        $sl = $page->getTranslateAttributes('sl')['viewBag']['bricks'][0];
        $this->assertArrayNotHasKey('_anchor', $sl);
        $this->assertArrayNotHasKey('_class', $sl);
    }
}
