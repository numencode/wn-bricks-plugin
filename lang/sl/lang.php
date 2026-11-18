<?php

return [
    'plugin' => [
        'name'        => 'Gradniki',
        'description' => 'Prevedljiv urejevalnik vsebine statičnih strani iz gradnikov.',
    ],
    'edit_link'   => 'Uredi: :type',
    'actions'     => [
        'hide'   => 'Skrij na spletni strani',
        'show'   => 'Prikaži na spletni strani',
        'hidden' => 'Skrito',
    ],
    'unknown'     => [
        'name'        => 'Neznan gradnik (:code)',
        'description' => 'Vrsta tega gradnika ne obstaja več.',
        'notice'      => 'Vrsta tega gradnika ne obstaja več (npr. njena definicija je bila odstranjena). Ob shranjevanju strani se vsebina ohrani nespremenjena, na spletni strani pa se ne prikaže. Gradnik lahko premaknete ali izbrišete.',
    ],
    'permissions' => [
        'use_html' => 'Dodajanje in urejanje gradnikov HTML',
        'denied'   => 'Nimate dovoljenja za dodajanje ali spreminjanje gradnikov »:type«.',
    ],
    'bricks' => [
        'heading' => [
            'name'        => 'Naslov',
            'description' => 'Naslov razdelka.',
        ],
        'text' => [
            'name'        => 'Besedilo',
            'description' => 'Oblikovano besedilo z odstavki, seznami in povezavami.',
        ],
        'image' => [
            'name'        => 'Slika',
            'description' => 'Ena slika z napisom.',
        ],
        'gallery' => [
            'name'        => 'Galerija',
            'description' => 'Mreža slik.',
        ],
        'columns' => [
            'name'        => 'Stolpci',
            'description' => 'Dva ali trije stolpci oblikovanega besedila.',
        ],
        'button' => [
            'name'        => 'Gumb',
            'description' => 'Povezava v obliki gumba.',
        ],
        'quote' => [
            'name'        => 'Citat',
            'description' => 'Navedek z avtorjem.',
        ],
        'video' => [
            'name'        => 'Video',
            'description' => 'Video z YouTuba ali Vimea.',
        ],
        'divider' => [
            'name'        => 'Ločilna črta',
            'description' => 'Vodoravna črta med razdelki.',
        ],
        'spacer' => [
            'name'        => 'Razmik',
            'description' => 'Prazen prostor med razdelki.',
        ],
        'html' => [
            'name'        => 'HTML',
            'description' => 'Poljubna koda HTML (za skrbnike).',
        ],
    ],
    'field' => [
        'label'  => 'Gradniki',
        'prompt' => 'Dodaj gradnik',
    ],
    'fields' => [
        'level'               => 'Raven',
        'image'               => 'Slika',
        'images'              => 'Slike',
        'images_prompt'       => 'Dodaj sliko',
        'alt'                 => 'Nadomestno besedilo',
        'alt_comment'         => 'Opiše sliko za bralnike zaslona in iskalnike.',
        'caption'             => 'Napis',
        'width'               => 'Širina',
        'columns'             => 'Stolpci',
        'column_1'            => '1. stolpec',
        'column_2'            => '2. stolpec',
        'column_3'            => '3. stolpec',
        'button_text'         => 'Besedilo gumba',
        'url'                 => 'Povezava',
        'url_comment'         => 'Naslov strani (npr. /kontakt) ali celoten URL (https://...).',
        'style'               => 'Slog',
        'new_window'          => 'Odpri v novem oknu',
        'quote'               => 'Citat',
        'author'              => 'Avtor',
        'source'              => 'Vir',
        'source_comment'      => 'Neobvezno, npr. knjiga, članek ali vloga avtorja.',
        'video_url'           => 'Povezava do videa',
        'video_url_comment'   => 'Naslov videa na YouTubu ali Vimeu, npr. https://www.youtube.com/watch?v=...',
        'video_title'         => 'Naslov videa',
        'video_title_comment' => 'Opiše video za bralnike zaslona.',
        'size'                => 'Velikost',
        'html'                => 'Koda HTML',
        'html_comment'        => 'Vstavi se v stran nespremenjena.',
    ],
    'options' => [
        'width' => [
            'content' => 'Širina vsebine',
            'wide'    => 'Široko',
            'full'    => 'Celotna širina',
        ],
        'style' => [
            'primary'   => 'Primarni',
            'secondary' => 'Sekundarni',
            'link'      => 'Povezava',
        ],
        'size' => [
            'small'  => 'Majhen',
            'medium' => 'Srednji',
            'large'  => 'Velik',
        ],
    ],
];
