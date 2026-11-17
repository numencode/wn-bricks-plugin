<?php

declare(strict_types=1);

namespace NumenCode\Bricks;

use NumenCode\Bricks\Classes\BrickRenderer;
use NumenCode\Bricks\Classes\PagesIntegration;
use NumenCode\Bricks\Classes\VideoUrl;
use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    /**
     * The base set: brick codes, in the order of the "Add brick" list.
     */
    public const BASE_SET = ['heading', 'text', 'image', 'gallery', 'columns', 'button', 'quote', 'video', 'divider', 'spacer', 'html'];

    public $require = ['NumenCode.Fundamentals'];

    public function pluginDetails(): array
    {
        return [
            'name'        => 'numencode.bricks::lang.plugin.name',
            'description' => 'numencode.bricks::lang.plugin.description',
            'author'      => 'Blaz Orazem',
            'icon'        => 'icon-cubes',
            'homepage'    => 'https://www.numencode.com',
        ];
    }

    public function boot(): void
    {
        PagesIntegration::register();
    }

    public function registerPermissions(): array
    {
        return [
            'numencode.bricks.use_html' => [
                'tab'   => 'numencode.bricks::lang.plugin.name',
                'label' => 'numencode.bricks::lang.permissions.use_html',
            ],
        ];
    }

    public function registerMarkupTags(): array
    {
        return [
            'functions' => [
                'renderBricks' => [BrickRenderer::class, 'renderBricksFunction'],
                'renderBrick'  => [BrickRenderer::class, 'renderBrickFunction'],
            ],
            'filters' => [
                'bricksVideoUrl' => [VideoUrl::class, 'embed'],
            ],
        ];
    }

    /**
     * Brick types of this plugin: code => path of the definition without extension (<path>.yaml + <path>.htm).
     *
     * @return array<string, string>
     */
    public function registerBricks(): array
    {
        $bricks = [];

        foreach (self::BASE_SET as $code) {
            $bricks[$code] = '$/numencode/bricks/bricks/' . $code;
        }

        return $bricks;
    }
}
