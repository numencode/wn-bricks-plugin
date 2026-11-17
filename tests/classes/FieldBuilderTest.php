<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\FieldBuilder;
use NumenCode\Bricks\Tests\BricksTestCase;

class FieldBuilderTest extends BricksTestCase
{
    public function testGroupsKeepLangKeysIconsAndFields(): void
    {
        $this->useFixtureTheme();
        $groups = FieldBuilder::groups(BrickRegistry::instance()->filter(['text', 'quote']));

        $this->assertSame(['text', 'quote'], array_keys($groups));
        $this->assertSame('numencode.bricks::lang.bricks.text.name', $groups['text']['name']);
        $this->assertSame('numencode.bricks::lang.bricks.text.description', $groups['text']['description']);
        $this->assertSame('icon-paragraph', $groups['text']['icon']);
        $this->assertSame('richeditor', $groups['text']['fields']['content']['type']);
        $this->assertSame('icon-quote-left', $groups['quote']['icon']);
        $this->assertSame(['quote', 'author'], array_keys($groups['quote']['fields']));
    }

    public function testFieldBecomesARepeaterWithDefaults(): void
    {
        $this->useFixtureTheme();
        $types = BrickRegistry::instance()->filter(['text']);

        $field = FieldBuilder::field(['type' => 'bricks', 'tab' => 'Content', 'allow' => 'text', 'ignore' => '', 'label' => ''], $types);

        $this->assertSame('repeater', $field['type']);
        $this->assertSame(['text'], array_keys($field['groups']));
        $this->assertSame('Content', $field['tab']);
        $this->assertSame(FieldBuilder::DEFAULT_LABEL, $field['label']);
        $this->assertSame(FieldBuilder::DEFAULT_PROMPT, $field['prompt']);
        $this->assertSame('collapsed', $field['style']);
        $this->assertArrayNotHasKey('allow', $field);
        $this->assertArrayNotHasKey('ignore', $field);
    }

    public function testOptionsFromTheDefinitionWinExceptTypeAndGroups(): void
    {
        $this->useFixtureTheme();
        $types = BrickRegistry::instance()->filter(['text']);

        $field = FieldBuilder::field([
            'type'   => 'bricks',
            'label'  => 'Page content',
            'prompt' => 'Add a block',
            'style'  => 'accordion',
            'groups' => ['x' => []],
        ], $types);

        $this->assertSame('repeater', $field['type']);
        $this->assertSame(['text'], array_keys($field['groups']));
        $this->assertSame('Page content', $field['label']);
        $this->assertSame('Add a block', $field['prompt']);
        $this->assertSame('accordion', $field['style']);
    }

    public function testCodesAcceptStringsAndLists(): void
    {
        $this->assertSame(['text', 'image'], FieldBuilder::codes(' text, image ,'));
        $this->assertSame(['text', 'image'], FieldBuilder::codes(['text', ' image', '']));
        $this->assertSame([], FieldBuilder::codes(''));
        $this->assertSame([], FieldBuilder::codes(null));
    }
}
