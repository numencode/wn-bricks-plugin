<?php

return [
    'plugin' => [
        'name'        => 'Bricks',
        'description' => 'Translatable block-based content builder for static pages.',
    ],
    'edit_link'   => 'Edit :type',
    'actions'     => [
        'hide'   => 'Hide on the website',
        'show'   => 'Show on the website',
        'hidden' => 'Hidden',
    ],
    'unknown'     => [
        'name'        => 'Unknown brick (:code)',
        'description' => 'The type of this brick no longer exists.',
        'notice'      => 'The type of this brick no longer exists (e.g. its definition was removed). Its content is kept unchanged when the page is saved, but it is not shown on the website. You can move or delete it.',
    ],
    'permissions' => [
        'use_html' => 'Add and edit HTML bricks',
        'denied'   => "You don't have permission to add or change \":type\" bricks.",
    ],
    'bricks' => [
        'heading' => [
            'name'        => 'Heading',
            'description' => 'A section heading.',
        ],
        'text' => [
            'name'        => 'Text',
            'description' => 'Formatted text with paragraphs, lists and links.',
        ],
        'image' => [
            'name'        => 'Image',
            'description' => 'One image with a caption.',
        ],
        'gallery' => [
            'name'        => 'Gallery',
            'description' => 'A grid of images.',
        ],
        'columns' => [
            'name'        => 'Columns',
            'description' => 'Two or three columns of formatted text.',
        ],
        'button' => [
            'name'        => 'Button',
            'description' => 'A link shown as a button.',
        ],
        'quote' => [
            'name'        => 'Quote',
            'description' => 'A quotation with its author.',
        ],
        'video' => [
            'name'        => 'Video',
            'description' => 'A YouTube or Vimeo video.',
        ],
        'divider' => [
            'name'        => 'Divider',
            'description' => 'A horizontal line between sections.',
        ],
        'spacer' => [
            'name'        => 'Spacer',
            'description' => 'Empty space between sections.',
        ],
        'html' => [
            'name'        => 'HTML',
            'description' => 'Custom HTML code (for administrators).',
        ],
    ],
    'field' => [
        'label'  => 'Bricks',
        'prompt' => 'Add brick',
    ],
    'fields' => [
        'level'               => 'Level',
        'image'               => 'Image',
        'images'              => 'Images',
        'images_prompt'       => 'Add image',
        'alt'                 => 'Alternative text',
        'alt_comment'         => 'Describes the image for screen readers and search engines.',
        'caption'             => 'Caption',
        'width'               => 'Width',
        'columns'             => 'Columns',
        'column_1'            => 'Column 1',
        'column_2'            => 'Column 2',
        'column_3'            => 'Column 3',
        'button_text'         => 'Button text',
        'url'                 => 'Link',
        'url_comment'         => 'A page address (e.g. /contact) or a full URL (https://...).',
        'style'               => 'Style',
        'new_window'          => 'Open in a new window',
        'quote'               => 'Quote',
        'author'              => 'Author',
        'source'              => 'Source',
        'source_comment'      => 'Optional, e.g. a book, an article or the author\'s role.',
        'video_url'           => 'Video address',
        'video_url_comment'   => 'The address of a YouTube or Vimeo video, e.g. https://www.youtube.com/watch?v=...',
        'video_title'         => 'Video title',
        'video_title_comment' => 'Describes the video for screen readers.',
        'size'                => 'Size',
        'html'                => 'HTML code',
        'html_comment'        => 'Inserted into the page unchanged.',
    ],
    'options' => [
        'width' => [
            'content' => 'Content width',
            'wide'    => 'Wide',
            'full'    => 'Full width',
        ],
        'style' => [
            'primary'   => 'Primary',
            'secondary' => 'Secondary',
            'link'      => 'Link',
        ],
        'size' => [
            'small'  => 'Small',
            'medium' => 'Medium',
            'large'  => 'Large',
        ],
    ],
];
