<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use NumenCode\Bricks\Classes\SharedTranslations;
use NumenCode\Bricks\Tests\BricksTestCase;

class SharedTranslationsTest extends BricksTestCase
{
    /**
     * @var array<string, array<int, string>>
     */
    protected array $translatable = ['heading' => ['text'], 'quote' => ['quote', 'source'], 'spacer' => []];

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function structure(): array
    {
        return [
            ['_group' => 'heading', '_id' => 'h1', 'text' => 'Title', 'level' => 'h2'],
            ['_group' => 'quote', '_id' => 'q1', 'quote' => 'Words', 'author' => 'Ann', 'source' => ''],
            ['_group' => 'spacer', '_id' => 's1', 'size' => 'large'],
        ];
    }

    public function testTranslatableFieldsAreTheMarkedTopLevelFields(): void
    {
        $this->assertSame(['text'], SharedTranslations::translatableFields([
            'text'    => ['type' => 'text', 'translatable' => true],
            'level'   => ['type' => 'dropdown'],
            'images'  => ['type' => 'repeater', 'form' => ['fields' => ['alt' => ['translatable' => true]]]],
            '_anchor' => ['type' => 'text', 'translatable' => true],
            'broken'  => 'not a definition',
        ]));

        $this->assertSame(
            ['a' => ['x'], 'b' => []],
            SharedTranslations::translatableByGroup([
                'a' => ['fields' => ['x' => ['translatable' => true]]],
                'b' => ['fields' => []],
            ]),
        );
    }

    public function testEmptyValues(): void
    {
        foreach ([null, '', '   ', "\n", '<p><br></p>', '<p>&nbsp;</p>', []] as $value) {
            $this->assertTrue(SharedTranslations::isEmpty($value), var_export($value, true));
        }

        foreach (['x', '<p>x</p>', '<p><img src="/a.jpg"></p>', '0', 0, ['a'], false] as $value) {
            $this->assertFalse(SharedTranslations::isEmpty($value), var_export($value, true));
        }
    }

    public function testExtractTakesTheFilledTranslatableFieldsPerId(): void
    {
        $map = SharedTranslations::extract([
            ['_group' => 'heading', '_id' => 'h1', 'text' => 'Naslov', 'level' => 'h3'],
            ['_group' => 'quote', '_id' => 'q1', 'quote' => '', 'source' => 'Knjiga', 'author' => 'X'],
            ['_group' => 'heading', 'text' => 'No id'],
            ['_group' => 'unknown', '_id' => 'u1', 'text' => 'Unknown type'],
            'not a brick',
        ], $this->translatable);

        $this->assertSame(['h1' => ['text' => 'Naslov'], 'q1' => ['source' => 'Knjiga']], $map);
    }

    public function testCleanKeepsOnlyBricksOfTheStructureAndTheirTranslatableFields(): void
    {
        $cleaned = SharedTranslations::clean([
            'h1'   => ['text' => 'Naslov', 'level' => 'h3'],
            'q1'   => ['quote' => "\n", 'source' => 'Knjiga'],
            'gone' => ['text' => 'Deleted brick'],
            's1'   => 'broken',
        ], $this->structure(), $this->translatable);

        $this->assertSame(['h1' => ['text' => 'Naslov'], 'q1' => ['source' => 'Knjiga']], $cleaned);
        $this->assertSame([], SharedTranslations::clean('not a map', $this->structure(), $this->translatable));
    }

    public function testMergeFallsBackPerField(): void
    {
        $map = ['h1' => ['text' => 'Naslov'], 'q1' => ['source' => 'Knjiga']];
        $merged = SharedTranslations::merge($this->structure(), $map);

        $this->assertSame('Naslov', $merged[0]['text']);
        $this->assertSame('h2', $merged[0]['level']);
        $this->assertSame('Words', $merged[1]['quote'], 'untranslated: the default value');
        $this->assertSame('Knjiga', $merged[1]['source']);
        $this->assertSame($this->structure()[2], $merged[2]);
    }

    public function testMergeForTheEditorEmptiesUntranslatedFields(): void
    {
        $merged = SharedTranslations::merge($this->structure(), ['h1' => ['text' => 'Naslov']], $this->translatable, false);

        $this->assertSame('Naslov', $merged[0]['text']);
        $this->assertSame('', $merged[1]['quote']);
        $this->assertSame('Ann', $merged[1]['author'], 'not translatable: the default value');
    }

    public function testMergeNeverTouchesReservedKeys(): void
    {
        $merged = SharedTranslations::merge($this->structure(), ['h1' => ['_group' => 'html', '_id' => 'x', 'text' => 'T']]);

        $this->assertSame('heading', $merged[0]['_group']);
        $this->assertSame('h1', $merged[0]['_id']);
    }

    /**
     * A box (title translatable) holding a heading, and a gallery-like repeater whose items have no id.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function nestedStructure(): array
    {
        return [
            ['_group' => 'box', '_id' => 'b1', 'title' => 'Box', 'inner' => [
                ['_group' => 'heading', '_id' => 'h2', 'text' => 'Inside', 'level' => 'h3'],
            ], 'images' => [['_group' => 'heading', 'text' => 'No id']]],
        ];
    }

    public function testNestedBricksAreInTheSameMap(): void
    {
        $translatable = $this->translatable + ['box' => ['title']];

        $this->assertSame(
            ['b1' => ['title' => 'Škatla'], 'h2' => ['text' => 'Notri']],
            SharedTranslations::extract([
                ['_group' => 'box', '_id' => 'b1', 'title' => 'Škatla', 'inner' => [
                    '3' => ['_group' => 'heading', '_id' => 'h2', 'text' => 'Notri', 'level' => 'h1'],
                ]],
            ], $translatable),
            'posted nested items (repeater indexes as keys)',
        );

        $this->assertSame(
            ['h2' => ['text' => 'Notri']],
            SharedTranslations::clean(['h2' => ['text' => 'Notri', 'level' => 'h1'], 'gone' => ['text' => 'X']], $this->nestedStructure(), $translatable),
        );

        $merged = SharedTranslations::merge($this->nestedStructure(), ['b1' => ['title' => 'Škatla'], 'h2' => ['text' => 'Notri']]);

        $this->assertSame('Škatla', $merged[0]['title']);
        $this->assertSame('Notri', $merged[0]['inner'][0]['text']);
        $this->assertSame('h3', $merged[0]['inner'][0]['level']);
        $this->assertSame('No id', $merged[0]['images'][0]['text'], 'items without an id are left as they are');

        $editor = SharedTranslations::merge($this->nestedStructure(), [], $translatable, false);
        $this->assertSame('', $editor[0]['inner'][0]['text'], 'untranslated nested fields are emptied in the editor too');
    }

    public function testRepeaterItemsWithIdsAreTranslatedPerItem(): void
    {
        $fields = [
            'images'  => ['type' => 'repeater', 'form' => ['fields' => [
                'image'   => ['type' => 'mediafinder'],
                'caption' => ['type' => 'text', 'translatable' => true],
            ]]],
            'columns' => ['type' => 'dropdown'],
            'tabs'    => ['type' => 'repeater', 'groups' => []],
        ];

        $translatable = SharedTranslations::translatableOf('gallery', $fields);
        $this->assertSame(['gallery' => [], 'gallery.images' => ['caption']], $translatable);

        $structure = [['_group' => 'gallery', '_id' => 'g1', 'images' => [
            ['image' => '/a.jpg', 'caption' => 'A', '_id' => 'i1'],
            ['image' => '/b.jpg', 'caption' => 'B', '_id' => 'i2'],
        ]]];

        $this->assertSame(
            ['i2' => ['caption' => 'Bé']],
            SharedTranslations::extract([['_group' => 'gallery', '_id' => 'g1', 'images' => [
                ['image' => '/x.jpg', 'caption' => '', '_id' => 'i1'],
                ['image' => '/x.jpg', 'caption' => 'Bé', '_id' => 'i2'],
            ]]], $translatable),
        );
        $this->assertSame(['i1' => ['caption' => 'Á']], SharedTranslations::clean(['i1' => ['caption' => 'Á', 'image' => '/x.jpg'], 'i9' => ['caption' => 'X']], $structure, $translatable));
        $this->assertSame(['i1' => ['caption' => 'Á']], SharedTranslations::clean(['i1' => ['caption' => 'Á']], [['_group' => 'gone', '_id' => 'g1', 'images' => [['_id' => 'i1']]]], [], ['gone']), 'a missing type keeps its items\' translations');

        // Reordered in the default language: each caption stays with its image
        $merged = SharedTranslations::merge(array_map(fn ($b) => ['images' => array_reverse($b['images'])] + $b, $structure), ['i1' => ['caption' => 'Á']]);
        $this->assertSame(['B', 'Á'], array_column($merged[0]['images'], 'caption'));
        $this->assertSame('/a.jpg', $merged[0]['images'][1]['image']);

        $editor = SharedTranslations::merge($structure, [], $translatable, false);
        $this->assertSame(['', ''], array_column($editor[0]['images'], 'caption'));
        $this->assertSame(['/a.jpg', '/b.jpg'], array_column($editor[0]['images'], 'image'));

        $this->assertSame([], SharedTranslations::itemLists(['_group' => 'gallery', 'images' => [['caption' => 'no ids']]]));
    }

    public function testNestedListsAreValuesHoldingBricks(): void
    {
        $this->assertSame(['inner', 'images'], array_keys(SharedTranslations::nestedLists($this->nestedStructure()[0])));
        $this->assertSame([], SharedTranslations::nestedLists(['_group' => 'x', 'tags' => ['a', 'b'], 'empty' => [], 'mixed' => [['_group' => 'y'], 'z']]));
        $this->assertSame(['b1', 'h2', null], array_map(fn ($brick) => $brick['_id'] ?? null, SharedTranslations::flatten($this->nestedStructure())));
    }
}
