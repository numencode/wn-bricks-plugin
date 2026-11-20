<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Config;
use NumenCode\Bricks\Classes\BrickIds;
use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\BrickTree;
use NumenCode\Bricks\Classes\BrickType;
use NumenCode\Bricks\Classes\FieldBuilder;
use NumenCode\Bricks\Classes\UnknownBricks;
use NumenCode\Bricks\Tests\BricksTestCase;

/**
 * Bricks inside bricks: a brick type's `type: bricks` fields (stage 9c).
 */
class NestedBricksTest extends BricksTestCase
{
    public function testAContainersBricksFieldBecomesARepeaterOfTheNextLevel(): void
    {
        $types = $this->types();

        $field = FieldBuilder::field(['type' => 'bricks'], $types);
        $inner = $field['groups']['section']['fields']['inner'];

        $this->assertSame('repeater', $inner['type']);
        $this->assertSame('Inner', $inner['label'], 'the nested field keeps its own options');
        $this->assertArrayNotHasKey('allow', $inner);
        $this->assertEqualsCanonicalizing(array_keys($field['groups']), array_keys($inner['groups']), 'every type has a group, so stored bricks keep their values');
        $this->assertSame(2, $inner['containerAttributes']['data-bricks-depth']);
        $this->assertArrayNotHasKey('data-bricks-depth', $field['containerAttributes']);

        // Offered: allow (quote, text, section) minus the container at the last level
        $hidden = explode(',', $inner['containerAttributes']['data-bricks-hidden']);
        $this->assertNotContains('quote', $hidden);
        $this->assertNotContains('text', $hidden);
        $this->assertContains('section', $hidden, 'no container inside a container at the default depth 2');
        $this->assertContains('heading', $hidden, 'not allowed');

        // Its own groups at the last level: the container's field only keeps its value
        $this->assertSame('partial', $inner['groups']['section']['fields']['inner']['type']);
        $this->assertStringEndsWith('unknownbricks/_value.php', $inner['groups']['section']['fields']['inner']['path']);
    }

    public function testMaxDepthLimitsTheNesting(): void
    {
        $types = $this->types();

        Config::set('numencode.bricks::max_depth', 1);
        $field = FieldBuilder::field(['type' => 'bricks'], $types);

        $this->assertContains('section', explode(',', $field['containerAttributes']['data-bricks-hidden'] ?? ''), 'containers are not offered without nesting');
        $this->assertSame('partial', $field['groups']['section']['fields']['inner']['type']);

        Config::set('numencode.bricks::max_depth', 3);
        $field = FieldBuilder::field(['type' => 'bricks'], $types);
        $inner = $field['groups']['section']['fields']['inner'];
        $innermost = $inner['groups']['section']['fields']['inner'];

        $this->assertNotContains('section', explode(',', $inner['containerAttributes']['data-bricks-hidden']));
        $this->assertSame('repeater', $innermost['type']);
        $this->assertSame(3, $innermost['containerAttributes']['data-bricks-depth']);
        $this->assertContains('section', explode(',', $innermost['containerAttributes']['data-bricks-hidden']));
        $this->assertSame('partial', $innermost['groups']['section']['fields']['inner']['type']);
    }

    public function testDeniedAndMissingTypesAreNotOfferedInside(): void
    {
        $types = $this->types();
        $types['gone'] = UnknownBricks::type('gone', ['title']);

        $field = FieldBuilder::field(['type' => 'bricks'], $types, null, ['quote']);
        $hidden = explode(',', $field['groups']['section']['fields']['inner']['containerAttributes']['data-bricks-hidden']);

        $this->assertContains('quote', $hidden, 'denied');
        $this->assertContains('gone', $hidden, 'missing type');
        $this->assertNotContains('text', $hidden);
    }

    public function testAReadOnlyContainerKeepsItsNestedBricks(): void
    {
        $field = FieldBuilder::field(['type' => 'bricks'], $this->types(), null, ['section']);

        $this->assertSame('partial', $field['groups']['section']['fields']['inner']['type']);
        $this->assertTrue($field['groups']['section']['fields']['title']['readOnly']);
    }

    public function testASharedFieldsNestedListsArePlainRepeaters(): void
    {
        $field = FieldBuilder::field(['type' => 'bricks', 'translation' => 'fields'], $this->types());

        $this->assertSame(FieldBuilder::SHARED_WIDGET, $field['type']);
        $this->assertSame('repeater', $field['groups']['section']['fields']['inner']['type']);
    }

    public function testFilterKeepsTheAllowedOrder(): void
    {
        $types = $this->types();

        $this->assertSame(['text', 'quote'], array_keys(FieldBuilder::filter($types, ['text', 'missing', 'quote'])));
        $this->assertSame(['heading', 'quote', 'section'], array_keys(FieldBuilder::filter($types, [], ['text'])));
    }

    public function testContainersAndTheirBricksFields(): void
    {
        $types = $this->types();

        $this->assertTrue($types['section']->isContainer());
        $this->assertSame(['inner'], array_keys($types['section']->bricksFields()));
        $this->assertFalse($types['text']->isContainer());
    }

    public function testTreeWalksNestedListsOfKnownContainers(): void
    {
        $types = $this->types();
        $bricks = [
            ['_group' => 'text'],
            ['_group' => 'section', 'inner' => [['_group' => 'quote'], ['_group' => 'text']]],
            ['_group' => 'gone', 'inner' => [['_group' => 'text']]],
        ];

        $this->assertSame(['0', '1', '1.inner.0', '1.inner.1', '2'], array_map('strval', array_keys(iterator_to_array(BrickTree::flatten($bricks, $types)))));

        $mapped = BrickTree::map($bricks, fn (array $brick) => $brick + ['seen' => true], $types);

        $this->assertTrue($mapped[1]['inner'][1]['seen']);
        $this->assertArrayNotHasKey('seen', $mapped[2]['inner'][0], 'a missing type\'s values stay as they are');
    }

    public function testIdsAreUniqueInTheWholeTree(): void
    {
        $types = $this->types();
        $bricks = BrickIds::bricks([
            ['_group' => 'section', '_id' => 'outer', 'inner' => [
                ['_group' => 'text', '_id' => 'outer'],
                ['_group' => 'text'],
                ['_group' => 'text', '_id' => 'kept'],
            ]],
            ['_group' => 'text', '_id' => 'kept'],
        ], $types);

        $this->assertSame('outer', $bricks[0]['_id']);
        $this->assertNotSame('outer', $bricks[0]['inner'][0]['_id'], 'a repeated id is replaced, also in a nested list');
        $this->assertTrue(BrickIds::isValid($bricks[0]['inner'][1]['_id']));
        $this->assertSame('kept', $bricks[0]['inner'][2]['_id']);
        $this->assertNotSame('kept', $bricks[1]['_id']);

        $topOnly = BrickIds::bricks([['_group' => 'section', 'inner' => [['_group' => 'text']]]]);
        $this->assertArrayNotHasKey('_id', $topOnly[0]['inner'][0], 'without the types only the list itself');
    }

    /**
     * The fixture theme's text, quote and heading, plus a container "section" with a title and a bricks field.
     *
     * @return array<string, BrickType>
     */
    protected function types(): array
    {
        $this->useFixtureTheme();

        return BrickRegistry::instance()->filter(['heading', 'text', 'quote']) + [
            'section' => BrickType::fromArray('section', [
                'name'   => 'Section',
                'fields' => [
                    'title' => ['label' => 'Title', 'type' => 'text'],
                    'inner' => ['label' => 'Inner', 'type' => 'bricks', 'allow' => ['quote', 'text', 'section']],
                ],
            ], __FILE__),
        ];
    }
}
