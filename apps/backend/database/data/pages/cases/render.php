<?php

return static function (array $c, string $locale): string {
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $list = static fn (array $items, string $class = 'bw-list'): string => '<ul class="'.$class.'">'.implode('', array_map(static fn (string $item): string => '<li>'.$e($item).'</li>', $items)).'</ul>';
    $steps = '';
    foreach ($c['steps'] as $index => [$title, $text]) {
        $steps .= '<li class="bw-flow-step"><span class="bw-number">'.($index + 1).'</span><div><h3>'.$e($title).'</h3><p>'.$e($text).'</p></div></li>';
    }
    $head = implode('', array_map(static fn (string $cell): string => '<th scope="col">'.$e($cell).'</th>', $c['table']['head']));
    $rows = implode('', array_map(static fn (array $row): string => '<tr>'.implode('', array_map(static fn (string $cell): string => '<td>'.$e($cell).'</td>', $row)).'</tr>', $c['table']['rows']));
    $fixes = '';
    foreach ($c['fixes'] as [$title, $text]) {
        $fixes .= '<article class="bw-card"><h3>'.$e($title).'</h3><p>'.$e($text).'</p></article>';
    }
    $faqs = '';
    foreach ($c['faq'] as [$question, $answer]) {
        $faqs .= '<article><h3>'.$e($question).'</h3><p>'.$e($answer).'</p></article>';
    }

    return '<div class="bw-page" lang="'.$locale.'">'
        .'<section class="bw-hero"><div><p class="bw-eyebrow">'.$e($c['eyebrow']).'</p><h1>'.$e($c['h1']).'</h1><p class="bw-lead">'.$e($c['lead']).'</p><div class="bw-actions"><a class="bw-btn" href="#fix">'.$e($c['cta_fix']).'</a><a class="bw-btn bw-btn-outline" href="/'.$locale.'/solved-problems">'.$e($c['cta_all']).'</a></div><div class="bw-meta">'.implode('', array_map(static fn (string $item): string => '<span>'.$e($item).'</span>', $c['meta'])).'</div></div>'
        .'<aside class="bw-panel"><div class="bw-panel-top"><span class="bw-demo-title">'.$e($c['summary_title']).'</span><span class="bw-demo-label">Shopware 6</span></div><div class="bw-panel-body">'.$list($c['summary']).'</div></aside></section>'
        .'<section class="bw-section" id="symptoms"><h2>'.$e($c['symptoms_title']).'</h2><p class="bw-copy">'.$e($c['symptoms_intro']).'</p>'.$list($c['symptoms']).'</section>'
        .'<section class="bw-section" id="search"><h2>'.$e($c['search_title']).'</h2><p class="bw-copy">'.$e($c['search_intro']).'</p><ol class="bw-flow">'.$steps.'</ol></section>'
        .'<section class="bw-section" id="cause"><h2>'.$e($c['cause_title']).'</h2><p class="bw-copy">'.$e($c['cause']).'</p><div class="bw-table-wrap"><table class="bw-table"><thead><tr>'.$head.'</tr></thead><tbody>'.$rows.'</tbody></table></div><p class="bw-inline-note">'.$e($c['table_note']).'</p></section>'
        .'<section class="bw-section" id="affected"><h2>'.$e($c['affected_title']).'</h2><p class="bw-copy">'.$e($c['affected']).'</p></section>'
        .'<section class="bw-section" id="fix"><h2>'.$e($c['fix_title']).'</h2><div class="bw-grid-3">'.$fixes.'</div></section>'
        .'<section class="bw-section" id="check"><h2>'.$e($c['check_title']).'</h2><ol class="bw-list">'.implode('', array_map(static fn (string $item): string => '<li>'.$e($item).'</li>', $c['check'])).'</ol><pre class="bw-code"><code>'.$e($c['code']).'</code></pre></section>'
        .'<section class="bw-section" id="lesson"><div class="bw-split"><div><h2>'.$e($c['lesson_title']).'</h2><p class="bw-copy">'.$e($c['lesson']).'</p></div><div class="bw-honest"><h3>'.$e($c['honest_title']).'</h3><p>'.$e($c['honest']).'</p></div></div></section>'
        .'<section class="bw-section"><h2>'.$e($c['faq_title']).'</h2><div class="bw-faq">'.$faqs.'</div></section>'
        .'<section class="bw-section"><div class="bw-cta"><h2>'.$e($c['final_title']).'</h2><p class="bw-copy">'.$e($c['final']).'</p><div class="bw-actions"><a class="bw-btn" href="/'.$locale.'/check-store">'.$e($c['final_check']).'</a><a class="bw-btn bw-btn-outline" href="/'.$locale.'/signup">'.$e($c['final_signup']).'</a></div></div></section></div>';
};
