<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use NumenCode\Bricks\Classes\VideoUrl;
use NumenCode\Bricks\Tests\BricksTestCase;

class VideoUrlTest extends BricksTestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function urls(): array
    {
        $youtube = 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ';

        return [
            'youtube watch'        => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s', $youtube],
            'youtube mobile'       => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', $youtube],
            'youtube without www'  => ['youtube.com/watch?v=dQw4w9WgXcQ', $youtube],
            'youtu.be'             => ['https://youtu.be/dQw4w9WgXcQ?si=abc', $youtube],
            'youtube shorts'       => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', $youtube],
            'youtube embed'        => ['https://www.youtube.com/embed/dQw4w9WgXcQ', $youtube],
            'vimeo'                => ['https://vimeo.com/76979871', 'https://player.vimeo.com/video/76979871'],
            'vimeo player'         => ['https://player.vimeo.com/video/76979871?h=abc', 'https://player.vimeo.com/video/76979871'],
            'youtube without id'   => ['https://www.youtube.com/', null],
            'youtube bad id'       => ['https://www.youtube.com/watch?v=<script>', null],
            'vimeo without number' => ['https://vimeo.com/channels', null],
            'other site'           => ['https://example.com/watch?v=dQw4w9WgXcQ', null],
            'javascript'           => ['javascript:alert(1)', null],
            'empty'                => ['', null],
            'null'                 => [null, null],
        ];
    }

    /**
     * @dataProvider urls
     */
    public function testEmbedUrls(mixed $url, ?string $expected): void
    {
        $this->assertSame($expected, VideoUrl::embed($url));
    }
}
