<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Backend\Classes\Controller;
use Backend\Classes\FormField;
use Cms\Classes\Theme;
use NumenCode\Bricks\Classes\BrickIds;
use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\FieldBuilder;
use NumenCode\Bricks\FormWidgets\SharedBricks;
use NumenCode\Bricks\Tests\BricksTestCase;
use Request;
use Winter\Pages\Classes\Page;
use Winter\Translate\Models\Locale;

/**
 * The editor of a shared structure: what it saves and how it shows another language. The language buttons and the
 * switch request were checked in the browser.
 */
class SharedBricksTest extends BricksTestCase
{
    protected Theme $theme;

    protected const ALIAS = 'shared';

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

    /**
     * Simulates a form POST (an AJAX request of the page editor).
     *
     * @param array<string, mixed> $data
     */
    protected function postForm(array $data): void
    {
        Request::instance()->setMethod('POST');
        Request::instance()->request->replace($data + [self::ALIAS . '_loaded' => 1]);
    }

    protected function widget(Page $page): SharedBricks
    {
        $field = new FormField('viewBag[bricks]', 'Bricks');
        $field->value = $page->viewBag['bricks'] ?? null;
        $offered = BrickRegistry::instance()->filter(['text', 'quote', 'spacer'], [], $this->theme);
        // A brick type that no longer exists, as PagesIntegration adds it for stored bricks
        $config = FieldBuilder::field(
            ['type' => 'bricks', 'translation' => 'fields'],
            $offered,
            $offered + ['gone' => \NumenCode\Bricks\Classes\UnknownBricks::type('gone', ['title'])],
        );

        // The repeater asks its parent form for the controller (AJAX handler)
        $controller = new Controller();
        /** @var \Backend\Widgets\Form $form */
        $form = (new \ReflectionClass(\Backend\Widgets\Form::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\Backend\Classes\WidgetBase::class, 'controller'))->setValue($form, $controller);

        return new SharedBricks($controller, $field, $config + ['model' => $page, 'alias' => self::ALIAS, 'parentForm' => $form]);
    }

    protected function page(): Page
    {
        $page = new Page();
        $page->translatable[] = 'viewBag[bricks_translations]';

        return $page;
    }

    // The fixture theme's "quote": "quote" is translatable, "author" isn't

    public function testSavingInTheDefaultLanguageKeepsTheOtherLanguagesTranslations(): void
    {
        $this->postForm([
            'viewBag'                     => ['bricks' => [
                ['_group' => 'quote', '_id' => 'q1', 'quote' => 'Words', 'author' => 'Ann'],
                ['_group' => 'text', 'content' => '<p>New</p>'],
            ]],
            self::ALIAS . '_locale'       => 'en',
            self::ALIAS . '_translations' => ['sl' => '{"q1":{"quote":"Besede","author":"X"},"gone":{"quote":"Deleted"}}'],
        ]);
        $page = $this->page();

        $structure = $this->widget($page)->getSaveValue(post('viewBag.bricks'));

        $this->assertSame('Words', $structure[0]['quote']);
        $this->assertSame('q1', $structure[0]['_id']);
        $this->assertTrue(BrickIds::isValid($structure[1]['_id'] ?? null), 'a new brick gets its id now');
        $this->assertSame(
            ['q1' => ['quote' => 'Besede']],
            $page->getTranslateAttributes('sl')['viewBag']['bricks_translations'],
            'only translatable fields of bricks that still exist',
        );
    }

    public function testSavingInAnotherLanguageKeepsTheStructure(): void
    {
        $structure = [
            ['_group' => 'quote', '_id' => 'q1', 'quote' => 'Words', 'author' => 'Ann'],
            ['_group' => 'spacer', '_id' => 's1', 'size' => 'large'],
        ];

        $this->postForm([
            'viewBag'                     => ['bricks' => [
                ['_group' => 'quote', '_id' => 'q1', 'quote' => 'Besede', 'author' => 'Changed'],
                ['_group' => 'spacer', '_id' => 's1', 'size' => 'small'],
            ]],
            self::ALIAS . '_locale'       => 'sl',
            self::ALIAS . '_structure'    => json_encode($structure),
            self::ALIAS . '_translations' => ['sl' => '{"q1":{"quote":"Old"}}'],
        ]);
        $page = $this->page();

        $saved = $this->widget($page)->getSaveValue(post('viewBag.bricks'));

        $this->assertSame($structure, $saved, 'the structure comes from the default language, not the posted items');
        $this->assertSame(['q1' => ['quote' => 'Besede']], $page->getTranslateAttributes('sl')['viewBag']['bricks_translations']);
    }

    public function testAnotherLanguageShowsOnlyTheTranslatableFieldsEditable(): void
    {
        $structure = [['_group' => 'quote', '_id' => 'q1', 'quote' => 'Words', 'author' => 'Ann', '_anchor' => 'top']];

        $this->postForm([
            'viewBag'                     => ['bricks' => [['_group' => 'quote', '_id' => 'q1', 'quote' => 'Besede']]],
            self::ALIAS . '_locale'       => 'sl',
            self::ALIAS . '_structure'    => json_encode($structure),
            self::ALIAS . '_translations' => ['sl' => '{}'],
        ]);

        $widget = $this->widget($this->page());
        $this->assertTrue($widget->isTranslating());

        $form = (new \ReflectionProperty(\Backend\FormWidgets\Repeater::class, 'formWidgets'))->getValue($widget)[0];
        $fields = $form->fields;

        $this->assertSame('Words', $fields['quote']['placeholder']);
        $this->assertTrue($fields['author']['readOnly']);
        $this->assertArrayHasKey(FieldBuilder::ID_FIELD, $fields);
        $this->assertArrayNotHasKey(FieldBuilder::ANCHOR_FIELD, $fields);
        $this->assertArrayNotHasKey(FieldBuilder::HIDDEN_FIELD, $fields);
        $this->assertSame('Besede', $form->data->quote);
        $this->assertSame('Ann', $form->data->author);
    }

    public function testWithOneLanguageItIsAPlainRepeater(): void
    {
        Locale::where('code', 'sl')->delete();
        Locale::clearCache();

        $this->postForm(['viewBag' => ['bricks' => [['_group' => 'quote', 'quote' => 'Words', 'author' => 'Ann']]]]);
        $page = $this->page();

        $saved = $this->widget($page)->getSaveValue(post('viewBag.bricks'));

        $this->assertSame('Words', $saved[0]['quote']);
        $this->assertFalse($this->widget($page)->isTranslating());
    }

    public function testTranslationsOfBricksOfMissingTypesStayWhileTranslating(): void
    {
        $structure = [
            ['_group' => 'gone', '_id' => 'g1', 'title' => 'Old'],
            ['_group' => 'quote', '_id' => 'q1', 'quote' => 'Words', 'author' => 'Ann'],
        ];

        $this->postForm([
            'viewBag'                     => ['bricks' => [
                ['_group' => 'gone', '_id' => 'g1', 'title' => 'Old'],
                ['_group' => 'quote', '_id' => 'q1', 'quote' => 'Besede'],
            ]],
            self::ALIAS . '_locale'       => 'sl',
            self::ALIAS . '_structure'    => json_encode($structure),
            self::ALIAS . '_translations' => ['sl' => '{"g1":{"title":"Star naslov"}}'],
        ]);
        $page = $this->page();

        $this->widget($page)->getSaveValue(post('viewBag.bricks'));

        $this->assertSame(
            ['g1' => ['title' => 'Star naslov'], 'q1' => ['quote' => 'Besede']],
            $page->getTranslateAttributes('sl')['viewBag']['bricks_translations'],
        );
    }
}
