<?php

$cards = fn (string $class, array $items, Closure $card): string => '<div class="'.$class.'">'.implode('', array_map($card, $items)).'</div>';
$testimonials = fn (string $title, array $items): string => '<section><h2>'.$title.'</h2>'.$cards('grid-3', $items, fn (array $item): string => '<div class="card"><blockquote>'.$item[0].'</blockquote><p class="small muted">'.$item[1].'</p></div>').'</section>';
$logos = fn (string $eyebrow, string $logo, string $hint): string => '<section class="band center"><p class="eyebrow">'.$eyebrow.'</p>'.$cards('grid-3', [1, 2, 3], fn (): string => '<div class="card"><p class="center muted">'.$logo.'</p></div>').'<p class="small muted center">'.$hint.'</p></section>';
$comparison = fn (string $title, string $lead, array $head, array $rows): string => '<section><h2>'.$title.'</h2><p class="lead">'.$lead.'</p><table><tbody><tr>'.implode('', array_map(fn (string $cell): string => '<th>'.$cell.'</th>', $head)).'</tr>'.implode('', array_map(fn (array $row): string => '<tr>'.implode('', array_map(fn (string $cell): string => '<td>'.$cell.'</td>', $row)).'</tr>', $rows)).'</tbody></table></section>';
$stats = fn (string $title, array $items): string => '<section class="band"><h2>'.$title.'</h2>'.$cards('grid-3', $items, fn (array $item): string => '<div class="feature"><h3>'.$item[0].'</h3><p>'.$item[1].'</p></div>').'</section>';
$list = fn (string $title, string $lead, array $items): string => '<section><h2>'.$title.'</h2><p class="lead">'.$lead.'</p><ol>'.implode('', array_map(fn (array $item): string => '<li><strong>'.$item[0].'</strong> — '.$item[1].'</li>', $items)).'</ol></section>';
$team = fn (string $title, array $items): string => '<section><h2>'.$title.'</h2>'.$cards('grid-3', $items, fn (array $item): string => '<div class="card"><h3>'.$item[0].'</h3><p class="muted">'.$item[1].'</p><p>'.$item[2].'</p></div>').'</section>';
$gallery = fn (string $title, string $picture, string $caption, string $hint): string => '<section><h2>'.$title.'</h2>'.$cards('grid-3', [1, 2, 3], fn (int $index): string => '<div class="card"><p class="center muted">'.$picture.' '.$index.'</p><p class="small">'.$caption.'</p></div>').'<p class="small muted">'.$hint.'</p></section>';
$contact = fn (string $title, array $mail, array $form, string $href): string => '<section><h2>'.$title.'</h2><div class="grid-2"><div class="card"><h3>'.$mail[0].'</h3><p>'.$mail[1].'</p><p><a href="mailto:hello&#64;example.com">hello&#64;example.com</a></p></div><div class="card"><h3>'.$form[0].'</h3><p>'.$form[1].'</p><p class="actions"><a class="button" href="'.$href.'">'.$form[2].'</a></p></div></div></section>';
$faq = fn (string $title, array $items): string => '<section><h2>'.$title.'</h2>'.$cards('grid-2', $items, fn (array $item): string => '<div class="card"><h3>'.$item[0].'</h3><p>'.$item[1].'</p></div>').'</section>';
$banner = fn (string $title, string $text, string $button, string $href, string $note): string => '<section class="card center"><h2>'.$title.'</h2><p class="lead center">'.$text.'</p><p class="actions center"><a class="button" href="'.$href.'">'.$button.'</a></p><p class="small muted center">'.$note.'</p></section>';

return [
    [
        'category' => 'testimonials',
        'name' => 'Отзывы клиентов',
        'bodies' => [
            'ru' => $testimonials('Что говорят клиенты', [['Короткая цитата клиента о результате, который он получил.', 'Имя, компания'], ['Ещё один отзыв — лучше конкретный, с цифрой или примером.', 'Имя, компания'], ['Третий отзыв: что изменилось после подключения.', 'Имя, компания']]),
            'en' => $testimonials('What customers say', [['A short customer quote about the result they got.', 'Name, company'], ['Another review — better a concrete one, with a number or an example.', 'Name, company'], ['A third review: what changed after connecting.', 'Name, company']]),
            'de' => $testimonials('Was Kunden sagen', [['Ein kurzes Kundenzitat zum erreichten Ergebnis.', 'Name, Firma'], ['Eine weitere Bewertung — am besten konkret, mit Zahl oder Beispiel.', 'Name, Firma'], ['Eine dritte Bewertung: was sich nach dem Verbinden geändert hat.', 'Name, Firma']]),
        ],
    ],
    [
        'category' => 'logos',
        'name' => 'Логотипы клиентов',
        'bodies' => [
            'ru' => $logos('Нам доверяют', 'Логотип компании', 'Замените подписи логотипами: кнопка «Картинка» → «Загрузить с компьютера».'),
            'en' => $logos('Trusted by', 'Company logo', 'Replace the labels with logos: “Image” → “Upload from computer”.'),
            'de' => $logos('Uns vertrauen', 'Firmenlogo', 'Ersetzen Sie die Beschriftungen durch Logos: „Bild“ → „Vom Computer hochladen“.'),
        ],
    ],
    [
        'category' => 'comparison',
        'name' => 'Сравнение: с нами и без нас',
        'bodies' => [
            'ru' => $comparison('Чем это лучше', 'Сравните по пунктам, которые важны вашим клиентам.', ['Что важно', 'С нами', 'Как обычно'], [['Первый пункт', 'Как это решаем мы', 'Как это делают без нас'], ['Второй пункт', 'Наш вариант', 'Обычный вариант'], ['Третий пункт', 'Наш вариант', 'Обычный вариант']]),
            'en' => $comparison('Why it is better', 'Compare on the points that matter to your customers.', ['What matters', 'With us', 'The usual way'], [['First point', 'How we solve it', 'How it is done without us'], ['Second point', 'Our way', 'The usual way'], ['Third point', 'Our way', 'The usual way']]),
            'de' => $comparison('Warum es besser ist', 'Vergleichen Sie nach den Punkten, die Ihren Kunden wichtig sind.', ['Was zählt', 'Mit uns', 'Wie üblich'], [['Erster Punkt', 'Wie wir es lösen', 'Wie es ohne uns gemacht wird'], ['Zweiter Punkt', 'Unser Weg', 'Der übliche Weg'], ['Dritter Punkt', 'Unser Weg', 'Der übliche Weg']]),
        ],
    ],
    [
        'category' => 'stats',
        'name' => 'Цифры и показатели',
        'bodies' => [
            'ru' => $stats('В цифрах', [['123', 'Что означает эта цифра для клиента.'], ['45 %', 'Ещё один измеримый показатель.'], ['6', 'Третья цифра с коротким пояснением.']]),
            'en' => $stats('In numbers', [['123', 'What this number means for the customer.'], ['45 %', 'Another measurable figure.'], ['6', 'A third number with a short explanation.']]),
            'de' => $stats('In Zahlen', [['123', 'Was diese Zahl für den Kunden bedeutet.'], ['45 %', 'Eine weitere messbare Kennzahl.'], ['6', 'Eine dritte Zahl mit kurzer Erklärung.']]),
        ],
    ],
    [
        'category' => 'steps',
        'name' => 'Как это работает (четыре шага списком)',
        'bodies' => [
            'ru' => $list('Как это работает', 'Весь путь клиента — от регистрации до первого результата.', [['Регистрация', 'что нужно сделать в самом начале'], ['Подключение', 'что клиент подключает и сколько это занимает'], ['Настройка', 'что можно настроить под себя'], ['Результат', 'что клиент видит и получает']]),
            'en' => $list('How it works', 'The whole path — from sign-up to the first result.', [['Sign up', 'what to do at the very start'], ['Connect', 'what the customer connects and how long it takes'], ['Set up', 'what can be adjusted'], ['Result', 'what the customer sees and gets']]),
            'de' => $list('So funktioniert es', 'Der ganze Weg — von der Registrierung bis zum ersten Ergebnis.', [['Registrierung', 'was ganz am Anfang zu tun ist'], ['Verbinden', 'was der Kunde verbindet und wie lange es dauert'], ['Einrichten', 'was sich anpassen lässt'], ['Ergebnis', 'was der Kunde sieht und erhält']]),
        ],
    ],
    [
        'category' => 'team',
        'name' => 'Команда',
        'bodies' => [
            'ru' => $team('Команда', [['Имя Фамилия', 'Роль', 'Одно предложение о том, чем человек занимается.'], ['Имя Фамилия', 'Роль', 'Одно предложение о том, чем человек занимается.'], ['Имя Фамилия', 'Роль', 'Одно предложение о том, чем человек занимается.']]),
            'en' => $team('Team', [['First Last', 'Role', 'One sentence about what this person does.'], ['First Last', 'Role', 'One sentence about what this person does.'], ['First Last', 'Role', 'One sentence about what this person does.']]),
            'de' => $team('Team', [['Vorname Nachname', 'Rolle', 'Ein Satz dazu, was diese Person macht.'], ['Vorname Nachname', 'Rolle', 'Ein Satz dazu, was diese Person macht.'], ['Vorname Nachname', 'Rolle', 'Ein Satz dazu, was diese Person macht.']]),
        ],
    ],
    [
        'category' => 'gallery',
        'name' => 'Галерея (три картинки)',
        'bodies' => [
            'ru' => $gallery('Как это выглядит', 'Картинка', 'Короткая подпись к картинке.', 'Замените подписи «Картинка» картинками: кнопка «Картинка» → «Загрузить с компьютера».'),
            'en' => $gallery('What it looks like', 'Image', 'A short caption for the image.', 'Replace the “Image” labels with pictures: “Image” → “Upload from computer”.'),
            'de' => $gallery('So sieht es aus', 'Bild', 'Eine kurze Bildunterschrift.', 'Ersetzen Sie die Beschriftungen „Bild“ durch Bilder: „Bild“ → „Vom Computer hochladen“.'),
        ],
    ],
    [
        'category' => 'contact',
        'name' => 'Контакты',
        'bodies' => [
            'ru' => $contact('Свяжитесь с нами', ['Напишите нам', 'Когда и как вы отвечаете. Замените адрес на свой.'], ['Форма обратной связи', 'Удобно, если вопрос длинный или нужно приложить подробности.', 'Написать нам'], '/ru/contact'),
            'en' => $contact('Get in touch', ['Write to us', 'When and how you answer. Replace the address with yours.'], ['Contact form', 'Handy for a long question or when you need to add details.', 'Contact us'], '/en/contact'),
            'de' => $contact('Kontakt', ['Schreiben Sie uns', 'Wann und wie Sie antworten. Ersetzen Sie die Adresse durch Ihre.'], ['Kontaktformular', 'Praktisch bei längeren Fragen oder wenn Details nötig sind.', 'Nachricht senden'], '/de/contact'),
        ],
    ],
    [
        'category' => 'faq',
        'name' => 'Вопросы и ответы в две колонки',
        'bodies' => [
            'ru' => $faq('Частые вопросы', [['Первый вопрос?', 'Короткий и честный ответ.'], ['Второй вопрос?', 'Ответ в одном-двух предложениях.'], ['Третий вопрос?', 'Конкретный ответ без общих слов.'], ['Четвёртый вопрос?', 'Если ответ длинный — дайте ссылку на подробную страницу.']]),
            'en' => $faq('Frequently asked questions', [['First question?', 'A short and honest answer.'], ['Second question?', 'An answer in one or two sentences.'], ['Third question?', 'A concrete answer, no general words.'], ['Fourth question?', 'If the answer is long, link to a detailed page.']]),
            'de' => $faq('Häufige Fragen', [['Erste Frage?', 'Eine kurze, ehrliche Antwort.'], ['Zweite Frage?', 'Eine Antwort in ein oder zwei Sätzen.'], ['Dritte Frage?', 'Eine konkrete Antwort ohne allgemeine Worte.'], ['Vierte Frage?', 'Ist die Antwort lang, verlinken Sie eine ausführliche Seite.']]),
        ],
    ],
    [
        'category' => 'cta',
        'name' => 'Баннер с одной кнопкой',
        'bodies' => [
            'ru' => $banner('Заголовок призыва', 'Одно предложение о том, почему стоит начать сейчас.', 'Начать', '/ru/signup', 'Короткое примечание: условия или что важно знать заранее.'),
            'en' => $banner('Call to action headline', 'One sentence about why it is worth starting now.', 'Get started', '/en/signup', 'A short note: terms or what to know beforehand.'),
            'de' => $banner('Überschrift des Aufrufs', 'Ein Satz dazu, warum sich der Start jetzt lohnt.', 'Loslegen', '/de/signup', 'Ein kurzer Hinweis: Bedingungen oder was man vorher wissen sollte.'),
        ],
    ],
];
