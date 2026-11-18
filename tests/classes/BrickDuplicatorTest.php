<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Backend\Classes\FormField;
use Backend\FormWidgets\Repeater;
use NumenCode\Bricks\Classes\BrickDuplicator;
use NumenCode\Bricks\Tests\BricksTestCase;
use ReflectionClass;
use Request;
use Winter\Storm\Exception\ApplicationException;

/**
 * The handler's checks; duplicating itself (rendering the copy) needs a backend controller and was checked in the
 * browser (rich editor, component and nested bricks, with Winter.Translate's MLRepeater).
 */
class BrickDuplicatorTest extends BricksTestCase
{
    protected function repeater(bool $bricks = true, int $maxItems = 0): Repeater
    {
        /** @var Repeater $repeater */
        $repeater = (new ReflectionClass(Repeater::class))->newInstanceWithoutConstructor();
        $field = new FormField('viewBag[bricks]', 'Bricks');
        $field->config = $bricks ? ['containerAttributes' => ['data-bricks' => '{}']] : [];
        (new \ReflectionProperty(Repeater::class, 'formField'))->setValue($repeater, $field);
        $repeater->previewMode = false;
        $repeater->maxItems = $maxItems;

        return $repeater;
    }

    protected function assertRefused(Repeater $repeater, string $message): void
    {
        try {
            BrickDuplicator::duplicate($repeater);
            $this->fail('The duplicate was not refused');
        } catch (ApplicationException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testRepeatersGetTheHandler(): void
    {
        BrickDuplicator::register();
        $repeater = $this->repeater();
        $repeater->extendableConstruct();

        $this->assertTrue($repeater->methodExists(BrickDuplicator::HANDLER));
    }

    public function testOnlyExistingBricksOfABricksFieldCanBeDuplicated(): void
    {
        // post() reads the POST data of a POST request (an AJAX request)
        Request::instance()->setMethod('POST');
        Request::instance()->request->replace(['viewBag' => ['bricks' => [3 => ['_group' => 'text', 'content' => 'A']]], '_bricks_source' => '3']);

        $this->assertRefused($this->repeater(bricks: false), 'Only bricks');

        Request::instance()->request->set('_bricks_source', '7');
        $this->assertRefused($this->repeater(), trans('numencode.bricks::lang.actions.duplicate_failed'));

        Request::instance()->request->set('_bricks_source', '3');
        $this->assertRefused($this->repeater(maxItems: 1), trans('numencode.bricks::lang.actions.duplicate_max'));
    }

    public function testPastedValuesMustBeABrick(): void
    {
        $this->assertSame(
            ['_group' => 'text', 'content' => '<p>A</p>', 'images' => [['image' => '/a.jpg']]],
            BrickDuplicator::pastedValues('{"_group":"text","content":"<p>A</p>","images":[{"image":"/a.jpg"}],"brick":"x"}'),
        );

        foreach (['', 'not json', '[]', '{"content":"A"}', '{"_group":""}', '{"_group":["text"]}'] as $json) {
            $this->assertNull(BrickDuplicator::pastedValues($json), $json);
        }
        $this->assertNull(BrickDuplicator::pastedValues(['_group' => 'text']));
    }

    public function testOnlyTypesTheFieldOffersCanBePasted(): void
    {
        Request::instance()->setMethod('POST');
        $repeater = $this->repeater();
        (new \ReflectionProperty(Repeater::class, 'groupDefinitions'))->setValue($repeater, ['text' => [], 'html' => []]);
        $field = (new \ReflectionProperty(Repeater::class, 'formField'))->getValue($repeater);
        $field->config['containerAttributes']['data-bricks-hidden'] = 'html';
        $unavailable = trans('numencode.bricks::lang.actions.paste_unavailable');

        foreach (['{"_group":"gallery"}', '{"_group":"html","html":"<b>x</b>"}', 'nonsense'] as $paste) {
            Request::instance()->request->replace(['_bricks_paste' => $paste]);
            $this->assertRefused($repeater, $unavailable);
        }
    }
}
