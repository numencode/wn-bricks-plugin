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
];
