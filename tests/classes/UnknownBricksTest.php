<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Backend\Widgets\Form;
use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\FieldBuilder;
use NumenCode\Bricks\Classes\PagesIntegration;
use NumenCode\Bricks\Classes\UnknownBricks;
use NumenCode\Bricks\Tests\BricksTestCase;
use ReflectionClass;
use Winter\Pages\Classes\Page;

class UnknownBricksTest extends BricksTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        if (!class_exists(Page::class)) {
            $this->markTestSkipped('Winter.Pages is not installed.');
        }

        $this->useFixtureTheme();
    }

    protected function pageWithBricks(array $bricks): Page
    {
        // Like a loaded page (the viewBag attribute; the public $viewBag property is only filled on load)
        $page = new Page();
        $page->attributes['viewBag'] = ['bricks' => $bricks];

        return $page;
    }

    public function testStoredKeysPerTypeWithoutReservedFields(): void
    {
        $page = $this->pageWithBricks([
            ['_group' => 'gone', 'title' => 'A', 'images' => [['image' => '/a.jpg']]],
            ['_group' => 'gone', 'size' => 'l', 'brick' => 'x'],
            ['_group' => 'text', 'content' => '<p>Hi</p>'],
            ['_group' => 'empty'],
            'not a brick',
        ]);

        $this->assertSame([
            'gone'  => ['title', 'images', 'size'],
            'text'  => ['content'],
            'empty' => [],
        ], UnknownBricks::storedKeys($page, 'viewBag[bricks]'));
        $this->assertSame([], UnknownBricks::storedKeys($page, 'markup'));
    }

    public function testMissingTypesGetAGroupThatIsNotOffered(): void
    {
        $page = $this->pageWithBricks([
            ['_group' => 'gone', 'title' => 'A', 'images' => [['image' => '/a.jpg']]],
            ['_group' => 'text', 'content' => '<p>Hi</p>'],
        ]);

        /** @var Form $form */
        $form = (new ReflectionClass(Form::class))->newInstanceWithoutConstructor();
        $form->model = $page;
        $form->isNested = false;
        $form->fields = [];
        $form->tabs = ['fields' => ['viewBag[bricks]' => ['type' => 'bricks', 'tab' => 'Content', 'allow' => 'text']]];
        $form->secondaryTabs = ['fields' => []];

        PagesIntegration::extendForm($form);
        $field = $form->tabs['fields']['viewBag[bricks]'];
        $group = $field['groups']['gone'];

        $this->assertSame(trans('numencode.bricks::lang.unknown.name', ['code' => 'gone']), $group['name']);
        $this->assertSame(UnknownBricks::ICON, $group['icon']);
        $this->assertSame([UnknownBricks::NOTICE_FIELD, 'title', 'images', '_advanced', FieldBuilder::ANCHOR_FIELD, FieldBuilder::CLASS_FIELD, FieldBuilder::HIDDEN_FIELD, FieldBuilder::ID_FIELD], array_keys($group['fields']));
        $this->assertSame('partial', $group['fields']['images']['type']);
        $this->assertContains('gone', explode(',', $field['containerAttributes']['data-bricks-hidden']));
        $this->assertFalse(BrickRegistry::instance()->has('gone'), 'not a registered type, so never rendered');
    }

    public function testTheValuePartialPostsTheStoredValueBack(): void
    {
        $formField = new class {
            public function getName(): string
            {
                return 'viewBag[bricks][0][images]';
            }
        };
        $formValue = [['image' => '/a "b".jpg', 'alt' => null, 'wide' => true], ['image' => '/c.jpg']];

        ob_start();
        include plugins_path('numencode/bricks/classes/unknownbricks/_value.php');
        $html = ob_get_clean();

        $this->assertSame(
            '<input type="hidden" name="viewBag[bricks][0][images][0][image]" value="/a &quot;b&quot;.jpg">'
            . '<input type="hidden" name="viewBag[bricks][0][images][0][wide]" value="1">'
            . '<input type="hidden" name="viewBag[bricks][0][images][1][image]" value="/c.jpg">',
            $html,
        );
    }
}
