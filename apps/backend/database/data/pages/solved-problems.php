<?php

$copy = [
    'de' => ['label' => 'Gelöste Probleme', 'title' => 'Gelöste Probleme: echte Fehler in Online-Shops und ihre Lösung', 'description' => 'Fälle aus Shops unserer Kunden: was beim Einkauf kaputt war, wie wir die Ursache gefunden haben und wie sie behoben wurde.', 'eyebrow' => 'Gelöste Probleme', 'h1' => 'Echte Fehler in Online-Shops – und wie sie behoben wurden', 'lead' => 'Hier zeigen wir einzigartige Probleme aus Shops unserer Kunden: was Kunden am Einkaufen gehindert hat, wie wir die Ursache gefunden haben und was wir zur Behebung empfohlen haben. Ohne Namen der Shops.', 'read' => 'Fall lesen', 'cta' => 'Shop kostenlos prüfen'],
    'en' => ['label' => 'Solved problems', 'title' => 'Solved problems: real errors in online stores and their fixes', 'description' => 'Cases from our clients\' stores: what broke on the way to purchase, how we found the cause and how it was fixed.', 'eyebrow' => 'Solved problems', 'h1' => 'Real errors in online stores — and how they were fixed', 'lead' => 'Here we show unique problems from our clients\' stores: what kept customers from buying, how we found the cause and what we recommended to fix it. Without store names.', 'read' => 'Read the case', 'cta' => 'Check your store for free'],
    'ru' => ['label' => 'Решённые проблемы', 'title' => 'Решённые проблемы: реальные ошибки интернет-магазинов и их решение', 'description' => 'Случаи из магазинов наших клиентов: что ломалось на пути к покупке, как мы нашли причину и как её устранили.', 'eyebrow' => 'Решённые проблемы', 'h1' => 'Реальные ошибки интернет-магазинов — и как их исправили', 'lead' => 'Здесь мы показываем уникальные проблемы из магазинов наших клиентов: что мешало покупателям купить, как мы нашли причину и что предложили для её устранения. Без названий магазинов.', 'read' => 'Читать разбор', 'cta' => 'Проверить магазин бесплатно'],
];
$cases = array_map(static fn (string $slug): array => ['slug' => $slug, 'content' => require __DIR__."/cases/{$slug}.php"], require __DIR__.'/cases/index.php');
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$locales = [];
foreach ($copy as $locale => $c) {
    $cards = '';
    foreach ($cases as $case) {
        $page = $case['content'][$locale];
        $cards .= '<article class="bw-card"><p class="bw-eyebrow">'.$e($page['c']['eyebrow']).'</p><h3><a href="/'.$locale.'/'.$case['slug'].'">'.$e($page['c']['h1']).'</a></h3><p>'.$e($page['description']).'</p><a class="bw-textlink" href="/'.$locale.'/'.$case['slug'].'">'.$e($c['read']).'</a></article>';
    }
    $locales[$locale] = [
        'title' => $c['title'],
        'description' => $c['description'],
        'body' => '<div class="bw-page" lang="'.$locale.'"><section class="bw-hero"><div><p class="bw-eyebrow">'.$e($c['eyebrow']).'</p><h1>'.$e($c['h1']).'</h1><p class="bw-lead">'.$e($c['lead']).'</p><div class="bw-actions"><a class="bw-btn" href="/'.$locale.'/check-store">'.$e($c['cta']).'</a></div></div></section>'
            .'<section class="bw-section"><div class="bw-grid-2 bw-gap">'.$cards.'</div></section></div>',
    ];
}

return [
    'menu' => 'footer',
    'sort' => 15,
    'menu_labels' => array_map(static fn (array $c): string => $c['label'], $copy),
    'locales' => $locales,
];
