<?php

return [
    'asset_base_url' => env('STOREFRONT_DENIM_ASSET_BASE_URL', 'https://stj-assets.sfo3.cdn.digitaloceanspaces.com/marcas/denim'),
    'banner' => ['href' => '/catalogo?category=Denim', 'categoryId' => 18],
    'hero_slider' => [
        ['desktopImage' => 'desktop/01%20landing/slider-desk.jpg', 'mobileImage' => 'movil/01%20slider%20movil/slider%20movil.jpg', 'alt' => 'Temporada Denim, elige tu fit', 'href' => '/catalogo?category=Denim', 'categoryId' => 18],
    ],
    'featured_fits' => [
        'title' => 'Fits destacados',
        'items' => [
            ['desktopImage' => 'desktop/02%20fits%20destacados/01-nin%CC%83a-wide-leg.jpg', 'mobileImage' => 'movil/02%20fits%20destacados/01-nin%CC%83a-wide-leg.jpg', 'alt' => 'Wide leg pull on', 'href' => '/catalogo?category=DenimNinas&fit=WIDE%20LEG', 'categoryId' => 19],
            ['desktopImage' => 'desktop/02%20fits%20destacados/02-nin%CC%83o-relaxed-cargo-denim.jpg', 'mobileImage' => 'movil/02%20fits%20destacados/02-nin%CC%83o-relaxed-cargo-denim.jpg', 'alt' => 'Relaxed cargo denim', 'href' => '/catalogo?category=DenimNinos&fit=Cargo', 'categoryId' => 20],
            ['desktopImage' => 'desktop/02%20fits%20destacados/03-nin%CC%83a-romper-short.jpg', 'mobileImage' => 'movil/02%20fits%20destacados/03-nin%CC%83a-romper-short.jpg', 'alt' => 'Romper short', 'href' => '/catalogo?category=DenimNinas&fit=Romper%20Short', 'categoryId' => 19],
            ['desktopImage' => 'desktop/02%20fits%20destacados/04-nin%CC%83o-jogger-cargo.jpg', 'mobileImage' => 'movil/02%20fits%20destacados/04-nin%CC%83o-jogger-cargo.jpg', 'alt' => 'Jogger cargo', 'href' => '/catalogo?category=DenimNinos&fit=Jogger', 'categoryId' => 20],
            ['desktopImage' => 'desktop/02%20fits%20destacados/16995%20-%2005-nin_a-falda.jpg', 'mobileImage' => 'movil/02%20fits%20destacados/16995%20-%2005-nin_a-falda.jpg', 'alt' => 'Falda Denim', 'href' => '/catalogo?category=DenimNinas&fit=Wideleg', 'categoryId' => 19],
            ['desktopImage' => 'desktop/02%20fits%20destacados/16995%20-%2006-nin_o-cargo-kaki.jpg', 'mobileImage' => 'movil/02%20fits%20destacados/16995%20-%2006-nin_o-cargo-kaki.jpg', 'alt' => 'Cargo kaki', 'href' => '/catalogo?category=DenimNinos&fit=Cargo', 'categoryId' => 20],
            ['desktopImage' => 'desktop/02%20fits%20destacados/16995%20-%2007%20nin_a-flare-denim.jpg', 'mobileImage' => 'movil/02%20fits%20destacados/16995%20-%2007%20nin_a-flare-denim.jpg', 'alt' => 'Flare Denim', 'href' => '/catalogo?category=DenimNinas&fit=FLARE', 'categoryId' => 19],
            ['desktopImage' => 'desktop/02%20fits%20destacados/16995%20-%2008-nin_o-short.jpg', 'mobileImage' => 'movil/02%20fits%20destacados/16995%20-%2008-nin_o-short.jpg', 'alt' => 'Short Denim', 'href' => '/catalogo?category=DenimNinos&fit=Jogger', 'categoryId' => 20],
        ],
    ],
    'details' => [
        'title' => 'Detalles',
        'columns' => [
            [['desktopImage' => 'desktop/03%20detalles/01-overall-denim.jpg', 'mobileImage' => 'movil/03%20detalles/01-detalle-overall.jpg', 'alt' => 'Detalles overall denim', 'href' => '/catalogo?category=DenimNinas&fit=Romper%20Short']],
            [['desktopImage' => 'desktop/03%20detalles/02-cargo-kaki.jpg', 'mobileImage' => 'movil/03%20detalles/02-cargo-kaki.jpg', 'alt' => 'Detalles cargo kaki', 'href' => '/catalogo?category=DenimNinos&fit=Cargo']],
        ],
    ],
    'video' => [
        'srcDesktop' => 'desktop/videos/video_home_paises_denim_desk1600x768_FULLHD.mp4',
        'srcMobile' => 'movil/videos/video_home_paises_denim_movil-420x650-FULLHD.mp4',
        'posterDesktop' => 'desktop/01%20landing/slider-desk.jpg',
        'posterMobile' => 'movil/01%20slider%20movil/slider%20movil.jpg',
        'alt' => 'Video de la temporada Denim',
        'href' => '/catalogo?category=Denim',
    ],
    'audience_links' => [
        ['label' => 'niñas', 'href' => '/catalogo?category=DenimNinas', 'categoryId' => 19],
        ['label' => 'niños', 'href' => '/catalogo?category=DenimNinos', 'categoryId' => 20],
    ],
];
