<?php

$features = fn (string $title, array $items): string => '<section class="band"><h2>'.$title.'</h2><div class="grid-3">'.implode('', array_map(fn (array $item): string => '<div class="feature"><h3>'.$item[0].'</h3><p>'.$item[1].'</p></div>', $items)).'</div></section>';
$faq = fn (string $title, array $items): string => '<section><h2>'.$title.'</h2>'.implode('', array_map(fn (array $item): string => '<h3>'.$item[0].'</h3><p>'.$item[1].'</p>', $items)).'</section>';
$plans = fn (string $title, string $lead, array $plans, string $button, string $href, string $note): string => '<section><h2>'.$title.'</h2><p class="lead">'.$lead.'</p><div class="grid-3">'.implode('', array_map(fn (array $plan): string => '<div class="card"><h3>'.$plan[0].'</h3><p class="muted">'.$plan[1].'</p><ul>'.implode('', array_map(fn (string $line): string => '<li>'.$line.'</li>', $plan[2])).'</ul></div>', $plans)).'</div><p class="actions"><a class="button" href="'.$href.'">'.$button.'</a></p><p class="small muted">'.$note.'</p></section>';
$steps = fn (string $title, array $items): string => '<section><h2>'.$title.'</h2><div class="grid-3">'.implode('', array_map(fn (array $item, int $index): string => '<div class="card"><p class="eyebrow">'.($index + 1).'</p><h3>'.$item[0].'</h3><p>'.$item[1].'</p></div>', $items, array_keys($items))).'</div></section>';

return [
    [
        'category' => 'hero',
        'name' => 'Первый экран',
        'bodies' => [
            'ru' => '<section class="hero"><p class="eyebrow">Для интернет-магазинов</p><h1>Главное обещание в одной строке</h1><p class="lead">Два-три предложения о том, какую задачу вы решаете и для кого.</p><p class="actions"><a class="button" href="/ru/signup">Попробовать бесплатно</a> <a class="button button-secondary" href="/ru/pricing">Посмотреть тарифы</a></p></section>',
            'en' => '<section class="hero"><p class="eyebrow">For online shops</p><h1>Your main promise in one line</h1><p class="lead">Two or three sentences about the problem you solve and for whom.</p><p class="actions"><a class="button" href="/en/signup">Try it free</a> <a class="button button-secondary" href="/en/pricing">See plans</a></p></section>',
            'de' => '<section class="hero"><p class="eyebrow">Für Onlineshops</p><h1>Ihr wichtigstes Versprechen in einer Zeile</h1><p class="lead">Zwei oder drei Sätze dazu, welches Problem Sie für wen lösen.</p><p class="actions"><a class="button" href="/de/signup">Kostenlos testen</a> <a class="button button-secondary" href="/de/pricing">Tarife ansehen</a></p></section>',
        ],
    ],
    [
        'category' => 'features',
        'name' => 'Преимущества в три колонки',
        'bodies' => [
            'ru' => $features('Почему это удобно', [['Первое преимущество', 'Коротко, чем это полезно клиенту.'], ['Второе преимущество', 'Одно-два предложения без лишних слов.'], ['Третье преимущество', 'Конкретный результат, а не общие слова.']]),
            'en' => $features('Why it helps', [['First benefit', 'Briefly, how it helps the customer.'], ['Second benefit', 'One or two sentences, no filler.'], ['Third benefit', 'A concrete result rather than general words.']]),
            'de' => $features('Warum es hilft', [['Erster Vorteil', 'Kurz, wie es dem Kunden nützt.'], ['Zweiter Vorteil', 'Ein oder zwei Sätze ohne Füllwörter.'], ['Dritter Vorteil', 'Ein konkretes Ergebnis statt allgemeiner Worte.']]),
        ],
    ],
    [
        'category' => 'steps',
        'name' => 'Как это работает (три шага)',
        'bodies' => [
            'ru' => $steps('Как это работает', [['Подключите магазин', 'Что нужно сделать на первом шаге.'], ['Настройте проверки', 'Что происходит на втором шаге.'], ['Получайте уведомления', 'Что клиент получает в итоге.']]),
            'en' => $steps('How it works', [['Connect your shop', 'What to do in the first step.'], ['Set up checks', 'What happens in the second step.'], ['Get notified', 'What the customer gets in the end.']]),
            'de' => $steps('So funktioniert es', [['Shop verbinden', 'Was im ersten Schritt zu tun ist.'], ['Prüfungen einrichten', 'Was im zweiten Schritt passiert.'], ['Benachrichtigt werden', 'Was der Kunde am Ende erhält.']]),
        ],
    ],
    [
        'category' => 'pricing',
        'name' => 'Сравнение тарифов (без цен)',
        'bodies' => [
            'ru' => $plans('Тарифы', 'Коротко, чем тарифы отличаются друг от друга.', [['Первый тариф', 'Для кого он подходит.', ['Что входит', 'Ещё одна возможность']], ['Второй тариф', 'Для кого он подходит.', ['Всё из первого тарифа', 'Дополнительная возможность']], ['Третий тариф', 'Для кого он подходит.', ['Всё из второго тарифа', 'Индивидуальные условия']]], 'Посмотреть цены', '/ru/pricing', 'Актуальные цены — на странице тарифов.'),
            'en' => $plans('Plans', 'Briefly, how the plans differ.', [['First plan', 'Who it is for.', ['What is included', 'One more feature']], ['Second plan', 'Who it is for.', ['Everything in the first plan', 'An extra feature']], ['Third plan', 'Who it is for.', ['Everything in the second plan', 'Individual terms']]], 'See prices', '/en/pricing', 'Current prices are on the pricing page.'),
            'de' => $plans('Tarife', 'Kurz, worin sich die Tarife unterscheiden.', [['Erster Tarif', 'Für wen er passt.', ['Was enthalten ist', 'Eine weitere Funktion']], ['Zweiter Tarif', 'Für wen er passt.', ['Alles aus dem ersten Tarif', 'Eine zusätzliche Funktion']], ['Dritter Tarif', 'Für wen er passt.', ['Alles aus dem zweiten Tarif', 'Individuelle Bedingungen']]], 'Preise ansehen', '/de/pricing', 'Aktuelle Preise stehen auf der Tarifseite.'),
        ],
    ],
    [
        'category' => 'faq',
        'name' => 'Вопросы и ответы',
        'bodies' => [
            'ru' => $faq('Частые вопросы', [['Первый вопрос?', 'Короткий и честный ответ.'], ['Второй вопрос?', 'Ответ в одном-двух предложениях.'], ['Третий вопрос?', 'Если ответ длинный — дайте ссылку на подробную страницу.']]),
            'en' => $faq('Frequently asked questions', [['First question?', 'A short and honest answer.'], ['Second question?', 'An answer in one or two sentences.'], ['Third question?', 'If the answer is long, link to a detailed page.']]),
            'de' => $faq('Häufige Fragen', [['Erste Frage?', 'Eine kurze, ehrliche Antwort.'], ['Zweite Frage?', 'Eine Antwort in ein oder zwei Sätzen.'], ['Dritte Frage?', 'Ist die Antwort lang, verlinken Sie eine ausführliche Seite.']]),
        ],
    ],
    [
        'category' => 'cta',
        'name' => 'Призыв к действию',
        'bodies' => [
            'ru' => '<section class="band center"><h2>Готовы начать?</h2><p class="lead center">Одно предложение о том, что клиент получит сразу.</p><p class="actions center"><a class="button" href="/ru/signup">Зарегистрироваться</a> <a class="button button-secondary" href="/ru/contact">Задать вопрос</a></p></section>',
            'en' => '<section class="band center"><h2>Ready to start?</h2><p class="lead center">One sentence about what the customer gets right away.</p><p class="actions center"><a class="button" href="/en/signup">Sign up</a> <a class="button button-secondary" href="/en/contact">Ask a question</a></p></section>',
            'de' => '<section class="band center"><h2>Bereit loszulegen?</h2><p class="lead center">Ein Satz dazu, was der Kunde sofort bekommt.</p><p class="actions center"><a class="button" href="/de/signup">Registrieren</a> <a class="button button-secondary" href="/de/contact">Frage stellen</a></p></section>',
        ],
    ],
    [
        'category' => 'text',
        'name' => 'Две карточки с текстом',
        'bodies' => [
            'ru' => '<div class="grid-2"><div class="card"><h3>Заголовок карточки</h3><p>Текст первой карточки.</p></div><div class="card"><h3>Заголовок карточки</h3><p>Текст второй карточки.</p></div></div>',
            'en' => '<div class="grid-2"><div class="card"><h3>Card heading</h3><p>Text of the first card.</p></div><div class="card"><h3>Card heading</h3><p>Text of the second card.</p></div></div>',
            'de' => '<div class="grid-2"><div class="card"><h3>Kartenüberschrift</h3><p>Text der ersten Karte.</p></div><div class="card"><h3>Kartenüberschrift</h3><p>Text der zweiten Karte.</p></div></div>',
        ],
    ],
    [
        'category' => 'text',
        'name' => 'Важное примечание',
        'bodies' => [
            'ru' => '<p class="note">Важное уточнение, которое клиент не должен пропустить.</p>',
            'en' => '<p class="note">An important detail the customer must not miss.</p>',
            'de' => '<p class="note">Ein wichtiger Hinweis, den der Kunde nicht übersehen darf.</p>',
        ],
    ],
];
