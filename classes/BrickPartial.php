<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Cms\Contracts\CmsObject;

/**
 * A brick type's own markup file (outside the theme), loadable by the CMS Twig loader like a component partial,
 * so CMS filters and functions (|media, |page, |_ ...) work in it.
 */
class BrickPartial implements CmsObject
{
    public bool $exists;

    public int $mtime;

    protected string $content;

    public function __construct(protected string $path)
    {
        $this->exists = is_file($path);
        $this->mtime = $this->exists ? (int) filemtime($path) : 0;
        $this->content = $this->exists ? (string) file_get_contents($path) : '';
    }

    /**
     * Loads a markup file; $source is unused (the contract's first argument, e.g. a theme).
     */
    public static function load($source, $fileName)
    {
        $partial = new static((string) $fileName);

        return $partial->exists ? $partial : null;
    }

    public static function loadCached($source, $fileName)
    {
        return static::load($source, $fileName);
    }

    public function getFilePath($fileName = null)
    {
        return $this->path;
    }

    public function getFileName()
    {
        return basename($this->path);
    }

    public function getBaseFileName()
    {
        return pathinfo($this->path, PATHINFO_FILENAME);
    }

    public function getContent()
    {
        return $this->content;
    }

    public function getTwigContent()
    {
        return $this->content;
    }

    public function getTwigCacheKey()
    {
        return $this->path;
    }
}
