<?php

declare(strict_types=1);

namespace NumenCode\Bricks;

use System\Classes\PluginBase;

class Plugin extends PluginBase
{
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

    /**
     * Brick types of this plugin: code => path of the definition without extension (<path>.yaml + <path>.htm).
     *
     * @return array<string, string>
     */
    public function registerBricks(): array
    {
        return [
            'heading' => '$/numencode/bricks/bricks/heading',
            'text'    => '$/numencode/bricks/bricks/text',
        ];
    }
}
