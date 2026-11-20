<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Backend\Facades\BackendAuth;
use Backend\Models\User;
use Backend\Widgets\Form;
use Cms\Classes\Controller;
use Cms\Classes\Theme;
use Event;
use Illuminate\Database\Schema\Blueprint;
use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\BrickRenderer;
use NumenCode\Bricks\Classes\ModelHost;
use NumenCode\Bricks\Classes\ModelIntegration;
use NumenCode\Bricks\Classes\PermissionGuard;
use NumenCode\Bricks\Classes\UnknownBricks;
use NumenCode\Bricks\Tests\BricksTestCase;
use NumenCode\Bricks\Tests\Fixtures\Components\Greeting;
use NumenCode\Bricks\Tests\Fixtures\Models\Record;
use ReflectionClass;
use Schema;
use Winter\Storm\Exception\ValidationException;
use Winter\Translate\Models\Locale;

class ModelIntegrationTest extends BricksTestCase
{
    protected Theme $theme;

    public function setUp(): void
    {
        parent::setUp();

        if (!class_exists(Locale::class)) {
            $this->markTestSkipped('Winter.Translate is needed.');
        }

        require_once $this->fixturePath('models/Record.php');

        Schema::create(Record::TABLE, function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->mediumText('bricks')->nullable();
            $table->mediumText('aside')->nullable();
            $table->timestamps();
        });

        Locale::create(['code' => 'sl', 'name' => 'Slovenščina', 'is_enabled' => true]);
        Locale::clearCache();

        $this->registerGreeting();
        $this->theme = $this->useFixtureTheme();
    }

    public function tearDown(): void
    {
        Schema::dropIfExists(Record::TABLE);

        parent::tearDown();
    }

    /**
     * A form widget of a model with a translatable "bricks" field (tabs) and a plain "aside" (secondary tabs).
     */
    protected function makeForm(object $model, bool $nested = false): Form
    {
        /** @var Form $form */
        $form = (new ReflectionClass(Form::class))->newInstanceWithoutConstructor();
        $form->model = $model;
        $form->isNested = $nested;
        $form->fields = ['title' => ['type' => 'text']];
        $form->tabs = ['fields' => ['bricks' => ['type' => 'bricks', 'tab' => 'Content', 'allow' => 'text, quote']]];
        $form->secondaryTabs = ['fields' => ['aside@update' => ['type' => 'bricks', 'allow' => 'text']]];

        return $form;
    }

    protected function editor(bool $html = false): User
    {
        $user = new User();
        $user->permissions = $html ? ['numencode.bricks.use_html' => 1] : [];

        return $user;
    }

    protected function record(): Record
    {
        $record = Record::create([
            'title'  => 'Post',
            'bricks' => [
                ['content' => '<p>Text</p>', '_group' => 'text'],
                ['html' => '<b>Admin</b>', '_group' => 'html'],
            ],
        ]);

        $record->setAttributeTranslated('bricks', [['html' => '<i>SL</i>', '_group' => 'html']], 'sl');
        $record->save();

        return Record::find($record->id);
    }

    public function testBricksFieldsOfAModelBecomeRepeaters(): void
    {
        $form = $this->makeForm(new Record());
        ModelIntegration::extendForm($form);

        $bricks = $form->tabs['fields']['bricks'];
        $aside = $form->secondaryTabs['fields']['aside@update'];

        $this->assertSame('repeater', $bricks['type']);
        $this->assertSame('Content', $bricks['tab']);
        $this->assertSame('repeater', $aside['type']);
        $this->assertSame(['type' => 'text'], $form->fields['title']);
        $this->assertSame(['text', 'quote'], array_values(array_diff(
            array_keys($bricks['groups']),
            explode(',', $bricks['containerAttributes']['data-bricks-hidden']),
        )));

        // Only the translatable attribute gets the language settings for the editor
        $this->assertArrayHasKey('locales', json_decode($bricks['containerAttributes']['data-bricks'], true));
        $this->assertArrayNotHasKey('locales', json_decode($aside['containerAttributes']['data-bricks'], true));
        $this->assertSame(['bricks', 'aside'], ModelIntegration::fieldKeys($this->makeForm(new Record())));
    }

    public function testTranslateMakesOnlyTheTranslatableAttributeMultilingual(): void
    {
        $form = $this->makeForm(new Record());

        Event::fire('backend.form.extendFieldsBefore', [$form]);

        $this->assertSame('mlrepeater', $form->tabs['fields']['bricks']['type']);
        $this->assertSame('repeater', $form->secondaryTabs['fields']['aside@update']['type']);
    }

    public function testOtherFormsAreUntouched(): void
    {
        $nested = $this->makeForm(new Record(), true);
        ModelIntegration::extendForm($nested);
        $this->assertSame('bricks', $nested->tabs['fields']['bricks']['type']);

        $other = $this->makeForm(new \stdClass());
        ModelIntegration::extendForm($other);
        $this->assertSame('bricks', $other->tabs['fields']['bricks']['type']);
    }

    public function testSavingDropsEmptyOptionsInEveryLanguage(): void
    {
        $record = $this->record();
        ModelIntegration::extendForm($this->makeForm($record));

        $record->bricks = [['content' => '<p>A</p>', '_anchor' => '', '_class' => 'wide', '_group' => 'text']];
        $record->setAttributeTranslated('bricks', [['content' => '<p>B</p>', '_class' => ' ', '_group' => 'text']], 'sl');
        $record->save();

        $record = Record::find($record->id);
        $this->assertSame([['content' => '<p>A</p>', '_class' => 'wide', '_group' => 'text']], $this->withoutIds($record->bricks));
        $this->assertSame([['content' => '<p>B</p>', '_group' => 'text']], $this->withoutIds($record->getAttributeTranslated('bricks', 'sl')));
    }

    public function testPermissionsAreCheckedInEveryLanguage(): void
    {
        $record = $this->record();
        $host = new ModelHost($record, ['bricks']);

        // Moving and copying stored bricks is fine
        $record->bricks = array_reverse($record->bricks);
        $record->setAttributeTranslated('bricks', [['html' => '<b>Admin</b>', '_group' => 'html']], 'sl');
        PermissionGuard::check($host, $this->editor(), $this->theme);

        // A new HTML brick in another language isn't
        $record->setAttributeTranslated('bricks', [['html' => '<script>x</script>', '_group' => 'html']], 'sl');

        try {
            PermissionGuard::check($host, $this->editor(), $this->theme);
            $this->fail('The changed HTML brick was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bricks', $exception->getErrors()->toArray());
        }

        PermissionGuard::check($host, $this->editor(true), $this->theme);
    }

    public function testTheChecksRunWhenTheEditedModelIsSaved(): void
    {
        $record = $this->record();
        ModelIntegration::extendForm($this->makeForm($record));
        BackendAuth::setUser($this->editor());

        $record->bricks = [['html' => '<b>Changed</b>', '_group' => 'html']];

        $this->expectException(ValidationException::class);
        $record->save();
    }

    public function testUnknownTypesOfAModelKeepTheirValues(): void
    {
        $record = Record::create(['bricks' => [['old' => 'x', '_group' => 'gone']]]);
        $all = BrickRegistry::instance()->all($this->theme);

        $types = UnknownBricks::types(new ModelHost($record, ['bricks']), 'bricks', $all);

        $this->assertSame(['gone'], array_keys($types));
        $this->assertArrayHasKey('old', $types['gone']->fields);
    }

    public function testComponentBricksOfAListAreCreatedWhileRendering(): void
    {
        Greeting::$runs = [];

        $html = (string) (new Controller($this->theme))->run('/listed')->getContent();

        // Each rendering of the list gets its own component, which runs before it is rendered
        $this->assertStringContainsString('<one><p class="greeting">Hello Ana (brick_post_0)</p><div class="brick brick--text">', str_replace(["\n", '    '], '', $html));
        $this->assertStringContainsString('<two><p class="greeting">Hello Ana (brick_post_0_2)</p>', $html);
        $this->assertSame(['Ana', 'Ana'], Greeting::$runs);
    }

    public function testAModelsSharedStructureIsRenderedInItsLanguage(): void
    {
        $record = Record::create([
            'title' => 'Post',
            'aside' => [
                ['content' => '<p>English</p>', '_group' => 'text', '_id' => 't1'],
                ['quote' => 'Words', 'author' => 'Ann', '_group' => 'quote', '_id' => 'q1'],
            ],
        ]);
        $record->setAttributeTranslated('aside_translations', ['t1' => ['content' => '<p>Slovensko</p>'], 'q1' => ['author' => 'X']], 'sl');
        $record->save();

        $record = Record::find($record->id);
        $this->assertSame('<p>English</p>', BrickRenderer::modelBricks($record, 'aside', $this->theme)[0]['content']);

        $record->translateContext('sl');
        $bricks = BrickRenderer::modelBricks($record, 'aside', $this->theme);

        $this->assertSame('<p>Slovensko</p>', $bricks[0]['content']);
        $this->assertSame('Words', $bricks[1]['quote'], 'untranslated: the default value');
        $this->assertSame('Ann', $bricks[1]['author'], 'not translatable: ignored');
    }

    public function testAModelsTranslatedListIsRenderedAsItIs(): void
    {
        $record = $this->record();
        $record->translateContext('sl');

        $this->assertSame([['html' => '<i>SL</i>', '_group' => 'html']], BrickRenderer::modelBricks($record, 'bricks', $this->theme));
    }
}
