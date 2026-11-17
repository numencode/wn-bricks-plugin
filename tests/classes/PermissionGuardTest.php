<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Backend\Facades\BackendAuth;
use Backend\Models\User;
use Cms\Classes\Theme;
use NumenCode\Bricks\Classes\PermissionGuard;
use NumenCode\Bricks\Tests\BricksTestCase;
use Winter\Pages\Classes\Page;
use Winter\Storm\Exception\ValidationException;
use Winter\Translate\Models\Locale;

class PermissionGuardTest extends BricksTestCase
{
    protected Theme $theme;

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
     * The fixture page "guarded" (EN: text, two html bricks; SL: one html brick), as the backend form loads it.
     */
    protected function page(): Page
    {
        $page = Page::load($this->theme, 'guarded');
        $page->translatable[] = 'viewBag[bricks]';

        return $page;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function bricks(Page $page): array
    {
        return $page->attributes['viewBag']['bricks'];
    }

    /**
     * @param array<int, array<string, mixed>> $bricks
     */
    protected function setBricks(Page $page, array $bricks, ?string $locale = null): void
    {
        if ($locale === null) {
            $page->attributes['viewBag']['bricks'] = $bricks;
        } else {
            $page->setAttributeTranslated('viewBag[bricks]', $bricks, $locale);
        }
    }

    protected function editor(bool $html = false): User
    {
        $user = new User();
        $user->permissions = $html ? ['numencode.bricks.use_html' => 1] : [];

        return $user;
    }

    protected function assertDenied(Page $page, ?User $user = null): void
    {
        try {
            PermissionGuard::check($page, $user ?? $this->editor(), $this->theme);
            $this->fail('The save was not rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('viewBag[bricks]', $e->getErrors()->toArray());
            $this->assertStringContainsString('HTML', $e->getMessage());
        }
    }

    protected function assertAllowed(Page $page, ?User $user = null): void
    {
        PermissionGuard::check($page, $user ?? $this->editor(), $this->theme);
        $this->addToAssertionCount(1);
    }

    public function testAnUnchangedPageCanBeSaved(): void
    {
        $page = $this->page();
        $bricks = $this->bricks($page);

        // As sent by the browser: CRLF line endings, values the form adds empty
        $bricks[1]['html'] = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $bricks[1]['html'])) . "\r\n";
        $bricks[2]['unused'] = '';
        $this->setBricks($page, $bricks);

        $this->assertAllowed($page);
    }

    public function testOtherBricksCanBeChangedAddedAndRemoved(): void
    {
        $page = $this->page();
        $bricks = $this->bricks($page);
        $bricks[0]['content'] = '<p>Changed</p>';
        $bricks[] = ['_group' => 'heading', 'text' => 'New'];
        $this->setBricks($page, $bricks);

        $this->assertAllowed($page);
    }

    public function testHtmlBricksCanBeMovedRemovedAndCopied(): void
    {
        $page = $this->page();
        [$text, $first, $second] = $this->bricks($page);
        $this->setBricks($page, [$second, $text, $second]);

        $this->assertAllowed($page);
    }

    public function testAddingAnHtmlBrickIsRejected(): void
    {
        $page = $this->page();
        $bricks = $this->bricks($page);
        $bricks[] = ['_group' => 'html', 'html' => '<script>alert(1)</script>'];
        $this->setBricks($page, $bricks);

        $this->assertDenied($page);
    }

    public function testChangingAnHtmlBrickIsRejected(): void
    {
        $page = $this->page();
        $bricks = $this->bricks($page);
        $bricks[2]['html'] .= '<script>alert(1)</script>';
        $this->setBricks($page, $bricks);

        $this->assertDenied($page);
    }

    public function testChangingTheTypeOfABrickToHtmlIsRejected(): void
    {
        $page = $this->page();
        $bricks = $this->bricks($page);
        $bricks[0]['_group'] = 'html';
        $this->setBricks($page, $bricks);

        $this->assertDenied($page);
    }

    public function testTranslationsAreChecked(): void
    {
        $page = $this->page();
        $this->setBricks($page, [['_group' => 'html', 'html' => '<script>alert(1)</script>']], 'sl');

        $this->assertDenied($page);
    }

    public function testStoredHtmlBricksCanBeCopiedBetweenLanguages(): void
    {
        $page = $this->page();
        $english = $this->bricks($page);
        $this->setBricks($page, [$english[1], ['_group' => 'html', 'html' => '<div>Slovenski HTML</div>']], 'sl');
        $this->setBricks($page, [...$english, ['_group' => 'html', 'html' => '<div>Slovenski HTML</div>']]);

        $this->assertAllowed($page);
    }

    public function testNewPagesAreChecked(): void
    {
        $page = new Page();
        $page->translatable[] = 'viewBag[bricks]';
        $page->attributes['viewBag'] = ['layout' => 'bricks', 'bricks' => [['_group' => 'html', 'html' => '<b>x</b>']]];

        $this->assertDenied($page);
    }

    public function testUsersWithThePermissionAndSavesWithoutABackendUserAreNotChecked(): void
    {
        $page = $this->page();
        $bricks = $this->bricks($page);
        $bricks[] = ['_group' => 'html', 'html' => '<b>New</b>'];
        $this->setBricks($page, $bricks);

        $this->assertAllowed($page, $this->editor(html: true));

        PermissionGuard::check($page, null, $this->theme);
        $this->addToAssertionCount(1);
    }

    public function testSavingAPageRunsTheCheckBeforeTranslateWritesTheTranslations(): void
    {
        BackendAuth::setUser($this->editor());

        $page = $this->page();
        $this->setBricks($page, [['_group' => 'html', 'html' => '<script>alert(1)</script>']], 'sl');

        $file = $this->theme->getPath() . '/content/static-pages-sl/guarded.htm';
        $before = file_get_contents($file);

        try {
            $page->save();
            $this->fail('The save was not rejected');
        } catch (ValidationException) {
            $this->assertSame($before, file_get_contents($file));
        }
    }
}
