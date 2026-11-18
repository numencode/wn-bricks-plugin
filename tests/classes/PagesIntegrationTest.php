<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Backend\Widgets\Form;
use Event;
use NumenCode\Bricks\Classes\PagesIntegration;
use NumenCode\Bricks\Tests\BricksTestCase;
use ReflectionClass;
use Winter\Pages\Classes\Page;
use Winter\Storm\Exception\SystemException;

class PagesIntegrationTest extends BricksTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        if (!class_exists(Page::class)) {
            $this->markTestSkipped('Winter.Pages is not installed.');
        }

        $this->useFixtureTheme();
    }

    /**
     * A form widget with the given fields, without running its init() (no controller or rendering needed).
     *
     * @param array<string, array<string, mixed>> $secondaryFields
     */
    protected function makeForm(object $model, array $secondaryFields, bool $nested = false): Form
    {
        /** @var Form $form */
        $form = (new ReflectionClass(Form::class))->newInstanceWithoutConstructor();
        $form->model = $model;
        $form->isNested = $nested;
        $form->fields = ['viewBag[title]' => ['type' => 'text']];
        $form->tabs = ['fields' => ['viewBag[bricks]' => ['type' => 'bricks', 'tab' => 'Content', 'allow' => 'text, quote']]];
        $form->secondaryTabs = ['fields' => $secondaryFields];

        return $form;
    }

    public function testBricksFieldsBecomeRepeatersInEveryContainer(): void
    {
        $page = new Page();
        $form = $this->makeForm($page, ['viewBag[aside]' => ['type' => 'bricks', 'ignore' => 'heading']]);

        PagesIntegration::extendForm($form);

        $bricks = $form->tabs['fields']['viewBag[bricks]'];
        $aside = $form->secondaryTabs['fields']['viewBag[aside]'];

        $this->assertSame('repeater', $bricks['type']);
        $this->assertSame('Content', $bricks['tab']);
        $this->assertSame('repeater', $aside['type']);
        $this->assertSame(['type' => 'text'], $form->fields['viewBag[title]']);

        // The offered types first (allow order), then all other types for existing bricks, hidden in "Add brick"
        $this->assertSame(['text', 'quote'], array_slice(array_keys($bricks['groups']), 0, 2));
        $this->assertArrayHasKey('heading', $bricks['groups']);
        $hidden = explode(',', $bricks['containerAttributes']['data-bricks-hidden']);
        $this->assertContains('heading', $hidden);
        $this->assertNotContains('text', $hidden);
        $this->assertNotContains('quote', $hidden);

        $hidden = explode(',', $aside['containerAttributes']['data-bricks-hidden']);
        $this->assertContains('heading', $hidden);
        $this->assertNotContains('quote', $hidden);
    }

    public function testTypesWithoutPermissionAreHiddenAndReadOnly(): void
    {
        $page = new Page();
        $fields = ['viewBag[bricks]' => ['type' => 'bricks']];

        // No user (or one without numencode.bricks.use_html): the html type is kept but hidden and read-only
        $field = PagesIntegration::replaceFields($fields, $page, null, false)['viewBag[bricks]'];

        $this->assertContains('html', explode(',', $field['containerAttributes']['data-bricks-hidden']));
        $this->assertTrue($field['groups']['html']['fields']['html']['readOnly']);
        $this->assertArrayNotHasKey('readOnly', $field['groups']['text']['fields']['content']);

        // With the permission: offered and editable
        $user = new \Backend\Models\User();
        $user->permissions = ['numencode.bricks.use_html' => 1];
        $field = PagesIntegration::replaceFields($fields, $page, null, false, $user)['viewBag[bricks]'];

        $this->assertArrayNotHasKey('data-bricks-hidden', $field['containerAttributes']);
        $this->assertArrayNotHasKey('readOnly', $field['groups']['html']['fields']['html']);
    }

    public function testBricksFieldsAreRegisteredAsTranslatableOnce(): void
    {
        $page = new Page();

        PagesIntegration::replaceFields(['viewBag[bricks]' => ['type' => 'bricks']], $page, null, true);
        PagesIntegration::replaceFields(['viewBag[bricks]' => ['type' => 'bricks']], $page, null, true);

        $this->assertSame(1, count(array_keys($page->translatable, 'viewBag[bricks]', true)));
    }

    public function testWithoutTranslateNothingIsRegistered(): void
    {
        $page = new Page();
        $before = $page->translatable;

        $fields = PagesIntegration::replaceFields(['viewBag[bricks]' => ['type' => 'bricks']], $page, null, false);

        $this->assertSame('repeater', $fields['viewBag[bricks]']['type']);
        $this->assertSame($before, $page->translatable);
        $this->assertArrayNotHasKey('locales', json_decode($fields['viewBag[bricks]']['containerAttributes']['data-bricks'], true));
    }

    public function testTranslatableFieldsGetTheLanguagesForTheFallbackHint(): void
    {
        if (!class_exists(\Winter\Translate\Classes\Translator::class)) {
            $this->markTestSkipped('Winter.Translate is not installed.');
        }

        $fields = PagesIntegration::replaceFields(['viewBag[bricks]' => ['type' => 'bricks']], new Page(), null, true);
        $locales = json_decode($fields['viewBag[bricks]']['containerAttributes']['data-bricks'], true)['locales'];

        $this->assertSame(\Winter\Translate\Classes\Translator::instance()->getDefaultLocale(), $locales['default']);
        $this->assertArrayHasKey($locales['default'], $locales['names']);
        $this->assertStringContainsString(':locale', $locales['notice']);
        $this->assertStringContainsString(':default', $locales['copy']);
    }

    public function testTranslateTurnsTheFieldIntoAMultilingualRepeater(): void
    {
        if (!class_exists(\Winter\Translate\Plugin::class)) {
            $this->markTestSkipped('Winter.Translate is not installed.');
        }

        $form = $this->makeForm(new Page(), []);

        // The real event: this plugin's listener first, then Translate's (priority -1)
        Event::fire('backend.form.extendFieldsBefore', [$form]);

        $this->assertSame('mlrepeater', $form->tabs['fields']['viewBag[bricks]']['type']);
        $this->assertContains('viewBag[bricks]', $form->model->translatable);
    }

    /**
     * @return array<string, mixed> Winter.Pages' Preview field
     */
    protected function previewField(): array
    {
        return ['tab' => 'winter.pages::lang.editor.preview', 'type' => 'partial', 'permissions' => ['winter.pages.access_preview']];
    }

    public function testThePreviewMovesNextToABricksFieldInTheUpperTabs(): void
    {
        $form = $this->makeForm(new Page(), ['markup' => ['type' => 'richeditor'], '_preview' => $this->previewField()]);
        $form->tabs['fields'] = [
            'viewBag[layout]'     => ['type' => 'dropdown', 'tab' => PagesIntegration::SETTINGS_TAB],
            'viewBag[meta_title]' => ['type' => 'text', 'tab' => 'cms::lang.editor.meta'],
        ] + $form->tabs['fields'];
        PagesIntegration::extendForm($form);
        PagesIntegration::movePreview($form);

        $this->assertSame(['markup'], array_keys($form->secondaryTabs['fields']));
        // Content, Preview, Meta, Settings
        $this->assertSame(['viewBag[bricks]', '_preview', 'viewBag[meta_title]', 'viewBag[layout]'], array_keys($form->tabs['fields']));
        $this->assertSame('bricks-preview', $form->tabs['fields']['_preview']['cssClass']);
        $this->assertSame(['winter.pages.access_preview'], $form->tabs['fields']['_preview']['permissions']);
        $this->assertSame('icon-crosshairs', $form->tabs['icons']['winter.pages::lang.editor.preview']);
    }

    public function testTabsAreSortedForEditingBricks(): void
    {
        $bricks = ['type' => 'repeater', 'containerAttributes' => ['data-bricks' => '{}']];
        $fields = [
            'layout'  => ['tab' => PagesIntegration::SETTINGS_TAB],
            'hidden'  => ['tab' => PagesIntegration::SETTINGS_TAB],
            'meta'    => ['tab' => 'Meta'],
            'seo'     => ['tab' => 'SEO'],
            'intro'   => ['tab' => 'Content', 'type' => 'text'],
            'bricks'  => ['tab' => 'Content'] + $bricks,
            'aside'   => ['tab' => 'Aside'] + $bricks,
            'preview' => ['tab' => 'Preview'],
        ];

        $this->assertSame(
            ['intro', 'bricks', 'aside', 'preview', 'meta', 'seo', 'layout', 'hidden'],
            array_keys(PagesIntegration::sortTabs($fields, 'Preview')),
        );
        $this->assertSame([], PagesIntegration::sortTabs([], 'Preview'));
    }

    public function testThePreviewStaysWhenTheBricksAreInTheLowerTabs(): void
    {
        // Without bricks in the upper tabs (a lower bricks field gets its own tab next to the Preview anyway)
        $form = $this->makeForm(new Page(), ['viewBag[aside]' => ['type' => 'bricks', 'tab' => 'Aside'], '_preview' => $this->previewField()]);
        $form->tabs = ['fields' => ['viewBag[layout]' => ['type' => 'dropdown']]];
        PagesIntegration::extendForm($form);
        PagesIntegration::movePreview($form);

        $this->assertArrayHasKey('_preview', $form->secondaryTabs['fields']);
        $this->assertArrayNotHasKey('_preview', $form->tabs['fields']);

        // Nested forms and forms without a Preview are left alone
        $nested = $this->makeForm(new Page(), ['_preview' => $this->previewField()], nested: true);
        PagesIntegration::extendForm($nested);
        PagesIntegration::movePreview($nested);
        $this->assertArrayHasKey('_preview', $nested->secondaryTabs['fields']);

        $noPreview = $this->makeForm(new Page(), []);
        PagesIntegration::extendForm($noPreview);
        PagesIntegration::movePreview($noPreview);
        $this->assertArrayNotHasKey('_preview', $noPreview->tabs['fields']);
    }

    public function testOtherFormsAreNotTouched(): void
    {
        $model = new \stdClass();
        $form = $this->makeForm(new Page(), [], nested: true);
        $other = (new ReflectionClass(Form::class))->newInstanceWithoutConstructor();
        $other->model = $model;
        $other->fields = ['content' => ['type' => 'bricks']];

        PagesIntegration::extendForm($form);
        PagesIntegration::extendForm($other);

        $this->assertSame('bricks', $form->tabs['fields']['viewBag[bricks]']['type']);
        $this->assertSame('bricks', $other->fields['content']['type']);
    }

    public function testAFieldWithoutBrickTypesIsReported(): void
    {
        $this->expectException(SystemException::class);
        $this->expectExceptionMessage('The bricks field "viewBag[bricks]" offers no brick types');

        PagesIntegration::replaceFields(['viewBag[bricks]' => ['type' => 'bricks', 'allow' => 'nope']], new Page(), null, false);
    }
}
