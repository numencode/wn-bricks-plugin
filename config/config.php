<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Snippets as component bricks
    |--------------------------------------------------------------------------
    |
    | Components that plugins register as Winter.Pages snippets (registerPageSnippets(), or added with the
    | pages.snippets.listSnippets event) are offered as component bricks, with fields generated from their
    | properties. Brick types registered explicitly with the same code replace them.
    |
    | true: all snippet components, false: none, or a list of component classes.
    |
    */
    'snippets' => true,
];
