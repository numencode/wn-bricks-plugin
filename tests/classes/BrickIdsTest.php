<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Cms\Classes\Theme;
use NumenCode\Bricks\Classes\BrickIds;
use NumenCode\Bricks\Classes\FieldBuilder;
use NumenCode\Bricks\Classes\PermissionGuard;
use NumenCode\Bricks\Tests\BricksTestCase;
use Winter\Pages\Classes\Page;
use Winter\Translate\Models\Locale;

class BrickIdsTest extends BricksTestCase
{
    public function testGeneratedIdsAreValidAndDiffer(): void
    {
        $ids = array_map(fn () => BrickIds::generate(), range(1, 50));

        $this->assertCount(50, array_unique($ids));

        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]{' . BrickIds::LENGTH . '}$/', $id);
        }
    }

    public function testBricksWithoutAValidIdGetOneAndExistingIdsAreKept(): void
    {
        $bricks = BrickIds::bricks([
            ['_group' => 'text', '_id' => 'keep-me_1'],
            ['_group' => 'text'],
            ['_group' => 'text', '_id' => 'not valid!'],
            ['_group' => 'text', '_id' => ['an', 'array']],
            'not a brick',
        ]);

        $this->assertSame('keep-me_1', $bricks[0]['_id']);
        $this->assertTrue(BrickIds::isValid($bricks[1]['_id']));
        $this->assertTrue(BrickIds::isValid($bricks[2]['_id']));
        $this->assertTrue(BrickIds::isValid($bricks[3]['_id']));
        $this->assertSame('not a brick', $bricks[4]);
        $this->assertCount(4, array_unique(array_column(array_slice($bricks, 0, 4), '_id')));
    }

    public function testARepeatedIdIsReplacedInAllButTheFirstBrick(): void
    {
        $bricks = BrickIds::bricks([
            ['_group' => 'text', '_id' => 'same'],
            ['_group' => 'heading', '_id' => 'same'],
        ]);

        $this->assertSame('same', $bricks[0]['_id']);
        $this->assertNotSame('same', $bricks[1]['_id']);
        $this->assertTrue(BrickIds::isValid($bricks[1]['_id']));
    }

    public function testAListWithIdsIsUnchanged(): void
    {
        $bricks = [['_group' => 'text', '_id' => 'a'], ['_group' => 'text', '_id' => 'b']];

        $this->assertSame($bricks, BrickIds::bricks($bricks));
    }

    public function testEveryBrickFormHasTheIdField(): void
    {
        $theme = $this->useFixtureTheme();
        $groups = FieldBuilder::groups(\NumenCode\Bricks\Classes\BrickRegistry::instance()->all($theme));

        foreach ($groups as $group) {
            $this->assertSame('partial', $group['fields'][FieldBuilder::ID_FIELD]['type']);
        }
    }

    public function testAnIdIsNotAChangeOfTheBricksContent(): void
    {
        $this->assertSame(
            PermissionGuard::fingerprint(['_group' => 'html', 'html' => '<b>x</b>']),
            PermissionGuard::fingerprint(['_group' => 'html', 'html' => '<b>x</b>', '_id' => 'abc']),
        );
    }

    public function testEveryLanguageOfAPageGetsIds(): void
    {
        if (!class_exists(Page::class) || !class_exists(Locale::class)) {
            $this->markTestSkipped('Winter.Pages and Winter.Translate are needed.');
        }

        $theme = $this->useFixtureTheme();
        Locale::create(['code' => 'sl', 'name' => 'Slovenščina', 'is_enabled' => true]);
        Locale::clearCache();

        $page = Page::load($theme, 'guarded');
        $page->translatable[] = 'viewBag[bricks]';
        $page->setAttributeTranslated('viewBag[bricks]', [['_group' => 'text', 'content' => 'SL']], 'sl');

        BrickIds::assign($page);

        foreach ($page->attributes['viewBag']['bricks'] as $brick) {
            $this->assertTrue(BrickIds::isValid($brick['_id'] ?? null));
        }

        $this->assertTrue(BrickIds::isValid($page->getTranslateAttributes('sl')['viewBag']['bricks'][0]['_id'] ?? null));
    }
}
