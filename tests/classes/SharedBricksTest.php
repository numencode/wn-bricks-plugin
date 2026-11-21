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
        // A container (title translatable, "inner" holds bricks)
        BrickRegistry::instance()->registerCallback(
            fn (BrickRegistry $registry) => $registry->registerBricks(['box' => $this->fixturePath('bricks/box')], 'Test'),
        );
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
        $offered = BrickRegistry::instance()->filter(['text', 'quote', 'spacer', 'box', 'gallery', 'button'], [], $this->theme);
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
        $this->assertSame('Words', $fields['quote']['containerAttributes']['data-bricks-default'], 'for the collapsed title');
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

    /**
     * A page shown in Slovenian with two quotes: q1 already translated, q2 not.
     */
    protected function translatingForm(array $extra = []): void
    {
        require_once $this->fixturePath('providers/FakeProvider.php');
        \NumenCode\Bricks\Tests\Fixtures\Providers\FakeProvider::$calls = [];
        config(['winter.translate::providers' => [
            'fake'   => ['class' => \NumenCode\Bricks\Tests\Fixtures\Providers\FakeProvider::class, 'key' => 'test'],
            'nokey'  => ['class' => \NumenCode\Bricks\Tests\Fixtures\Providers\FakeProvider::class, 'key' => ''],
        ]]);

        $structure = [
            ['_group' => 'quote', '_id' => 'q1', 'quote' => 'First', 'author' => 'Ann'],
            ['_group' => 'quote', '_id' => 'q2', 'quote' => 'Second', 'author' => 'Bob'],
        ];

        $this->postForm($extra + [
            'viewBag'                     => ['bricks' => [
                ['_group' => 'quote', '_id' => 'q1', 'quote' => 'Prvi'],
                ['_group' => 'quote', '_id' => 'q2', 'quote' => ''],
            ]],
            self::ALIAS . '_locale'       => 'sl',
            self::ALIAS . '_structure'    => json_encode($structure),
            self::ALIAS . '_translations' => ['sl' => '{}'],
        ]);
    }

    /**
     * @return array<string, array<string, mixed>> The language's translations in the re-rendered lockers
     */
    protected function renderedTranslations(array $response): array
    {
        preg_match('/name="' . self::ALIAS . '_translations\[sl\]" value="([^"]*)"/', reset($response), $match);

        return json_decode(html_entity_decode($match[1] ?? '{}', ENT_QUOTES), true);
    }

    public function testOnlyProvidersWithAKeyAreOffered(): void
    {
        $this->translatingForm();

        $this->assertSame(['fake' => 'Fake'], SharedBricks::providers());
    }

    public function testTranslatingAllFillsOnlyTheEmptyFields(): void
    {
        $this->translatingForm(['_bricks_translate' => SharedBricks::TRANSLATE_ALL, '_provider' => 'fake']);

        $translations = $this->renderedTranslations($this->widget($this->page())->onTranslateBricks());

        $this->assertSame(['q1' => ['quote' => 'Prvi'], 'q2' => ['quote' => '[sl from en] Second']], $translations);
        $this->assertSame([['Second']], \NumenCode\Bricks\Tests\Fixtures\Providers\FakeProvider::$calls, 'one request, untranslatable fields left out');
    }

    public function testTranslatingOneBrickReplacesItsTranslation(): void
    {
        $this->translatingForm(['_bricks_translate' => 'q1', '_provider' => 'fake']);

        $translations = $this->renderedTranslations($this->widget($this->page())->onTranslateBricks());

        $this->assertSame(['q1' => ['quote' => '[sl from en] First']], $translations);
    }

    /**
     * @return array<int, array<string, mixed>> A box (b1) with a quote (q1) and a spacer (s1) inside
     */
    protected function nestedStructure(): array
    {
        return [
            ['_group' => 'box', '_id' => 'b1', 'title' => 'Box', 'inner' => [
                ['_group' => 'quote', '_id' => 'q1', 'quote' => 'Words', 'author' => 'Ann'],
                ['_group' => 'spacer', '_id' => 's1', 'size' => 'large'],
            ]],
        ];
    }

    public function testNestedBricksAreTranslatedInTheirLockedList(): void
    {
        $this->postForm([
            'viewBag'                     => ['bricks' => [
                ['_group' => 'box', '_id' => 'b1', 'title' => 'Škatla', 'inner' => [
                    ['_group' => 'quote', '_id' => 'q1', 'quote' => 'Besede', 'author' => 'Changed'],
                    ['_group' => 'spacer', '_id' => 's1', 'size' => 'small'],
                ]],
            ]],
            self::ALIAS . '_locale'       => 'sl',
            self::ALIAS . '_structure'    => json_encode($this->nestedStructure()),
            self::ALIAS . '_translations' => ['sl' => '{}'],
        ]);
        $page = $this->page();
        $widget = $this->widget($page);

        // The box's form: its bricks field is a nested SharedBricks with the default language's bricks
        $box = (new \ReflectionProperty(\Backend\FormWidgets\Repeater::class, 'formWidgets'))->getValue($widget)[0];
        $inner = $box->fields['inner'];

        $this->assertSame(FieldBuilder::SHARED_WIDGET, $inner['type']);
        $this->assertSame('Words', $inner[SharedBricks::NESTED_OPTION]['structure'][0]['quote']);
        $this->assertArrayNotHasKey('_bricks_nothing', $box->fields, 'a container has something to translate inside');

        $field = new FormField('viewBag[bricks][0][inner]', 'Inner');
        $field->value = post('viewBag.bricks.0.inner');
        $nested = new SharedBricks($widget->getController(), $field, $inner + [
            'model'      => $page,
            'alias'      => 'nested',
            'parentForm' => $box,
        ]);
        $this->assertTrue($nested->isTranslating());

        $quote = (new \ReflectionMethod(SharedBricks::class, 'makeItemFormWidget'))->invoke($nested, 0, 'quote');
        $this->assertSame('Words', $quote->fields['quote']['placeholder']);
        $this->assertTrue($quote->fields['author']['readOnly']);
        $this->assertArrayNotHasKey(FieldBuilder::HIDDEN_FIELD, $quote->fields);

        $saved = $widget->getSaveValue(post('viewBag.bricks'));

        $this->assertSame($this->nestedStructure(), $saved, 'the structure, nested bricks included, comes from the default language');
        $this->assertSame(
            ['b1' => ['title' => 'Škatla'], 'q1' => ['quote' => 'Besede']],
            $page->getTranslateAttributes('sl')['viewBag']['bricks_translations'],
        );
    }

    public function testSavingInTheDefaultLanguageGivesNestedBricksIdsAndKeepsTheirTranslations(): void
    {
        $this->postForm([
            'viewBag'                     => ['bricks' => [
                ['_group' => 'box', '_id' => 'b1', 'title' => 'Box', 'inner' => [
                    ['_group' => 'quote', '_id' => 'q1', 'quote' => 'Words', 'author' => 'Ann'],
                    ['_group' => 'text', 'content' => '<p>New</p>'],
                ]],
            ]],
            self::ALIAS . '_locale'       => 'en',
            self::ALIAS . '_translations' => ['sl' => '{"q1":{"quote":"Besede"},"b1":{"title":"Škatla"}}'],
        ]);
        $page = $this->page();

        $structure = $this->widget($page)->getSaveValue(post('viewBag.bricks'));

        $this->assertTrue(BrickIds::isValid($structure[0]['inner'][1]['_id'] ?? null), 'a new nested brick gets its id now');
        $this->assertSame(
            ['b1' => ['title' => 'Škatla'], 'q1' => ['quote' => 'Besede']],
            $page->getTranslateAttributes('sl')['viewBag']['bricks_translations'],
        );
    }

    public function testTranslatingAContainerTranslatesTheBricksInside(): void
    {
        require_once $this->fixturePath('providers/FakeProvider.php');
        config(['winter.translate::providers' => [
            'fake' => ['class' => \NumenCode\Bricks\Tests\Fixtures\Providers\FakeProvider::class, 'key' => 'test'],
        ]]);

        $this->postForm([
            'viewBag'                     => ['bricks' => [
                ['_group' => 'box', '_id' => 'b1', 'title' => '', 'inner' => [
                    ['_group' => 'quote', '_id' => 'q1', 'quote' => 'Prej'],
                    ['_group' => 'spacer', '_id' => 's1'],
                ]],
            ]],
            self::ALIAS . '_locale'       => 'sl',
            self::ALIAS . '_structure'    => json_encode($this->nestedStructure()),
            self::ALIAS . '_translations' => ['sl' => '{"q1":{"quote":"Prej"}}'],
            '_bricks_translate'           => 'b1',
            '_provider'                   => 'fake',
        ]);

        $translations = $this->renderedTranslations($this->widget($this->page())->onTranslateBricks());

        $this->assertSame(['b1' => ['title' => '[sl from en] Box'], 'q1' => ['quote' => '[sl from en] Words']], $translations);
    }

    public function testGalleryCaptionsAreTranslatedPerImage(): void
    {
        $structure = [['_group' => 'gallery', '_id' => 'g1', 'columns' => '3', 'images' => [
            ['image' => '/a.jpg', 'alt' => 'A', 'caption' => 'First', '_id' => 'i1'],
            ['image' => '/b.jpg', 'alt' => 'B', 'caption' => 'Second', '_id' => 'i2'],
        ]]];

        $this->postForm([
            'viewBag'                     => ['bricks' => [['_group' => 'gallery', '_id' => 'g1', 'images' => [
                ['image' => '/changed.jpg', 'alt' => '', 'caption' => 'Prvi', '_id' => 'i1'],
                ['image' => '/b.jpg', 'alt' => '', 'caption' => '', '_id' => 'i2'],
            ]]]],
            self::ALIAS . '_locale'       => 'sl',
            self::ALIAS . '_structure'    => json_encode($structure),
            self::ALIAS . '_translations' => ['sl' => '{}'],
        ]);
        $page = $this->page();
        $widget = $this->widget($page);

        $gallery = (new \ReflectionProperty(\Backend\FormWidgets\Repeater::class, 'formWidgets'))->getValue($widget)[0];
        $images = $gallery->fields['images'];

        $this->assertSame(FieldBuilder::SHARED_WIDGET, $images['type']);
        $this->assertSame('gallery.images', $images[SharedBricks::NESTED_OPTION]['key']);
        $this->assertArrayNotHasKey('_bricks_nothing', $gallery->fields);

        $field = new FormField('viewBag[bricks][0][images]', 'Images');
        $field->value = post('viewBag.bricks.0.images');
        $nested = new SharedBricks($widget->getController(), $field, $images + ['model' => $page, 'alias' => 'images', 'parentForm' => $gallery]);
        $item = (new \ReflectionMethod(SharedBricks::class, 'makeItemFormWidget'))->invoke($nested, 1);

        $this->assertSame('Second', $item->fields['caption']['placeholder'], 'the default caption of this image');
        $this->assertTrue($item->fields['image']['readOnly']);
        $this->assertArrayHasKey(FieldBuilder::ID_FIELD, $item->fields);

        $saved = $widget->getSaveValue(post('viewBag.bricks'));

        $this->assertSame($structure, $saved);
        $this->assertSame(['i1' => ['caption' => 'Prvi']], $page->getTranslateAttributes('sl')['viewBag']['bricks_translations']);
    }

    public function testTheButtonsLinkIsTranslatedByHandOnly(): void
    {
        require_once $this->fixturePath('providers/FakeProvider.php');
        \NumenCode\Bricks\Tests\Fixtures\Providers\FakeProvider::$calls = [];
        config(['winter.translate::providers' => [
            'fake' => ['class' => \NumenCode\Bricks\Tests\Fixtures\Providers\FakeProvider::class, 'key' => 'test'],
        ]]);

        $structure = [['_group' => 'button', '_id' => 'b1', 'text' => 'About us', 'url' => '/about', 'style' => 'primary']];
        $this->postForm([
            'viewBag'                     => ['bricks' => [['_group' => 'button', '_id' => 'b1', 'text' => '', 'url' => '/sl/o-nas', 'style' => 'primary']]],
            self::ALIAS . '_locale'       => 'sl',
            self::ALIAS . '_structure'    => json_encode($structure),
            self::ALIAS . '_translations' => ['sl' => '{}'],
            '_bricks_translate'           => 'b1',
            '_provider'                   => 'fake',
        ]);

        $widget = $this->widget($this->page());
        $fields = (new \ReflectionProperty(\Backend\FormWidgets\Repeater::class, 'formWidgets'))->getValue($widget)[0]->fields;

        $this->assertSame('/about', $fields['url']['placeholder'], 'editable in another language');
        $this->assertArrayNotHasKey('readOnly', $fields['url']);

        $translations = $this->renderedTranslations($widget->onTranslateBricks());

        $this->assertSame([['About us']], \NumenCode\Bricks\Tests\Fixtures\Providers\FakeProvider::$calls, 'the link is not sent to the provider');
        $this->assertSame(['b1' => ['text' => '[sl from en] About us', 'url' => '/sl/o-nas']], $translations);
    }

    public function testWithoutAProviderNothingIsTranslated(): void
    {
        $this->translatingForm(['_bricks_translate' => SharedBricks::TRANSLATE_ALL]);
        config(['winter.translate::providers' => []]);

        $this->expectException(\Winter\Storm\Exception\ApplicationException::class);

        $this->widget($this->page())->onTranslateBricks();
    }
}
