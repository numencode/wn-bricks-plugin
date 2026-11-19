<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Event;
use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\BrickType;
use NumenCode\Bricks\Classes\PageConverter;
use NumenCode\Bricks\Tests\BricksTestCase;
use NumenCode\Bricks\Tests\Fixtures\Components\Greeting;
use Winter\Pages\Classes\Snippet;
use Winter\Pages\Classes\SnippetManager;
use Winter\Storm\Exception\ApplicationException;

class PageConverterTest extends BricksTestCase
{
    /**
     * @var array<string, BrickType>
     */
    protected array $types;

    public function setUp(): void
    {
        parent::setUp();

        $this->registerGreeting();
        SnippetManager::forgetInstance();
        config(['app.url' => 'https://localhost', 'cms.storage.media.path' => '/storage/app/media']);

        // A snippet of the Greeting component: brick "snippet-greeter"
        Event::listen('pages.snippets.listSnippets', function (SnippetManager $manager) {
            $snippet = new Snippet();
            $snippet->initFromComponentInfo(Greeting::class, 'greeter');
            $manager->addSnippet($snippet);
        });

        // The fixture theme replaces "heading" with a text-only one; use the base set's here
        $this->types = BrickRegistry::instance()->all($this->useFixtureTheme('bricktest'));
        $this->types['heading'] = BrickType::fromYaml(
            'heading',
            plugins_path('numencode/bricks/bricks/heading.yaml'),
            plugins_path('numencode/bricks/bricks/heading.htm'),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function convert(string $html, bool $split = true, ?PageConverter &$converter = null): array
    {
        $converter = new PageConverter($this->types, $split);

        return $converter->convert($html);
    }

    public function testAPageBecomesBricks(): void
    {
        $html = <<<'HTML'
            <figure class="fr-draggable" data-component="NumenCode\Bricks\Tests\Fixtures\Components\Greeting" data-snippet="greeter" data-property-name="Ana" data-property-tone="warm">&nbsp;</figure>
            <p><br></p>
            <h2 id="team">Our  team</h2>
            <p>First <strong>paragraph</strong> – čšž.</p>
            <p>Second&nbsp;paragraph.</p>
            <hr>
            <p><img src="/storage/app/media/team/photo%201.jpg" alt="The team" class="fr-fic fr-dib"></p>
            <figure><img src="https://localhost/storage/app/media/b.png"><figcaption>A caption</figcaption></figure>
            <h3>Contact</h3>
            <table><tr><td>Cell</td></tr></table>
            <p>&nbsp;</p>
            HTML;

        $this->assertSame([
            ['name' => 'Ana', 'tone' => 'warm', '_group' => 'snippet-greeter'],
            ['text' => 'Our team', 'level' => 'h2', '_anchor' => 'team', '_group' => 'heading'],
            ['content' => "<p>First <strong>paragraph</strong> – čšž.</p>\n<p>Second\u{a0}paragraph.</p>", '_group' => 'text'],
            ['_group' => 'divider'],
            ['image' => '/team/photo 1.jpg', 'alt' => 'The team', '_group' => 'image'],
            ['image' => '/b.png', 'caption' => 'A caption', '_group' => 'image'],
            ['text' => 'Contact', 'level' => 'h3', '_group' => 'heading'],
            ['content' => '<table><tr><td>Cell</td></tr></table>', '_group' => 'text'],
        ], $this->convert($html));
    }

    public function testWithoutSplittingOnlyComponentAndTextBricks(): void
    {
        $bricks = $this->convert('<h2>Title</h2><p>Text</p><hr><figure data-snippet="greeter"></figure><p>More</p>', false);

        $this->assertSame([
            ['content' => '<h2>Title</h2><p>Text</p><hr>', '_group' => 'text'],
            ['_group' => 'snippet-greeter'],
            ['content' => '<p>More</p>', '_group' => 'text'],
        ], $bricks);
    }

    public function testHeadingsWithMoreThanPlainTextStayInTheText(): void
    {
        $this->assertSame([
            ['content' => '<h2><a href="/x">Link</a></h2><h3 style="color: red">Red</h3><h1>Big</h1><h5>Small</h5><h2> </h2>', '_group' => 'text'],
        ], $this->convert('<h2><a href="/x">Link</a></h2><h3 style="color: red">Red</h3><h1>Big</h1><h5>Small</h5><h2> </h2>'));
    }

    public function testOnlyMediaLibraryImagesOnTheirOwnBecomeImageBricks(): void
    {
        $html = '<p><img src="https://example.com/storage/app/media/a.jpg"></p>'
            . '<p><img src="/themes/demo/assets/b.jpg"></p>'
            . '<p>Text <img src="/storage/app/media/c.jpg"></p>'
            . '<p><a href="/x"><img src="/storage/app/media/d.jpg"></a></p>'
            . '<p><img src="/storage/app/media/e.jpg"><img src="/storage/app/media/f.jpg"></p>';

        $bricks = $this->convert($html);

        $this->assertCount(1, $bricks);
        $this->assertSame('text', $bricks[0]['_group']);
    }

    public function testATypeTheThemeChangedIsOnlyUsedWithItsFields(): void
    {
        // The fixture theme's heading has only a "text" field
        $this->types = BrickRegistry::instance()->all();

        $this->assertSame([['text' => 'Title', '_group' => 'heading']], $this->convert('<h4>Title</h4>'));

        unset($this->types['divider']);
        $this->assertSame([['content' => '<hr>', '_group' => 'text']], $this->convert('<hr>'));

        unset($this->types['text']);
        $this->expectException(ApplicationException::class);
        $this->convert('<p>Text</p>');
    }

    public function testSnippetProperties(): void
    {
        // HTML attribute names are lowercase; unknown properties are left out
        $bricks = $this->convert('<figure data-snippet="greeter" data-property-NAME="Bo" data-property-colour="red"></figure>', true, $converter);

        $this->assertSame([['name' => 'Bo', '_group' => 'snippet-greeter']], $bricks);
        $this->assertSame(['The property "colour" of the snippet "greeter" is not a field of the brick "snippet-greeter"; it was left out.'], $converter->warnings());

        // Without data-snippet: the component's brick type (the theme's explicit "greeting")
        $this->assertSame(
            [['name' => 'Cy', '_group' => 'greeting']],
            $this->convert('<figure data-component="\NumenCode\Bricks\Tests\Fixtures\Components\Greeting" data-property-name="Cy"></figure>'),
        );
    }

    public function testSnippetsWithoutABrickTypeStayInTheTextWithAWarning(): void
    {
        $bricks = $this->convert('<figure data-snippet="partial-thing">x</figure><table><tr><td><figure data-snippet="greeter"></figure></td></tr></table>', true, $converter);

        $this->assertSame('text', $bricks[0]['_group']);
        $this->assertCount(1, $bricks);
        $this->assertSame([
            'The snippet "partial-thing" has no brick type; it stays in a text brick, where it isn\'t rendered.',
            'A snippet inside other HTML (e.g. a table) stays in a text brick, where it isn\'t rendered.',
        ], $converter->warnings());
    }

    public function testEmptyContent(): void
    {
        $this->assertSame([], $this->convert(''));
        $this->assertSame([], $this->convert("<p><br></p>\n<p>&nbsp;</p><div> </div><br><!-- note -->"));
    }
}
