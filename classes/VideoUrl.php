<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

/**
 * Turns a YouTube or Vimeo page URL into its embed URL (Twig filter |bricksVideoUrl).
 */
class VideoUrl
{
    /**
     * @return string|null The embed URL, or null for an unsupported or invalid URL
     */
    public static function embed(mixed $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || !$parts = parse_url(str_contains($url, '://') ? $url : 'https://' . $url)) {
            return null;
        }

        $host = strtolower(preg_replace('/^(www\.|m\.)/', '', $parts['host'] ?? ''));
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        if (in_array($host, ['youtube.com', 'youtube-nocookie.com', 'youtu.be'], true)) {
            $id = match (true) {
                $host === 'youtu.be'                              => trim($path, '/'),
                isset($query['v'])                                => (string) $query['v'],
                (bool) preg_match('#^/(embed|shorts|live)/([^/]+)#', $path, $match) => $match[2],
                default                                           => '',
            };

            return preg_match('/^[\w-]{6,20}$/', $id) ? 'https://www.youtube-nocookie.com/embed/' . $id : null;
        }

        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true) && preg_match('#/(?:video/)?(\d+)#', $path, $match)) {
            return 'https://player.vimeo.com/video/' . $match[1];
        }

        return null;
    }
}
