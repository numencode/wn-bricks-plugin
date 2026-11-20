<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Snippets as component bricks
    |--------------------------------------------------------------------------
    |
    | Components that plugins register as Winter.Pages snippets (registerPageSnippets(), or added with the
    | pages.snippets.listSnippets event) are offered as component bricks, with fields generated from their
    | properties. Their brick codes are "snippet-" + the snippet code (e.g. snippet-gallery), so they never
    | collide with explicitly defined brick types.
    |
    | true: all snippet components, false: none, or a list of component classes.
    |
    */
    'snippets' => true,

    /*
    |--------------------------------------------------------------------------
    | Bricks inside bricks
    |--------------------------------------------------------------------------
    |
    | How deep bricks may nest. A brick type holds other bricks with a field "type: bricks" (e.g. the
    | columns of Columns). 2: a page's bricks and the bricks inside them, but no brick holding bricks
    | inside another one; 1: no nesting (such types aren't offered). Bricks stored deeper keep their
    | content, but it can't be edited.
    |
    */
    'max_depth' => 2,
];
