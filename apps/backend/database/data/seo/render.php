<?php

return static function (array $page, array $common, string $locale, array $copy): string {
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $list = static fn (array $items): string => implode('', array_map(static fn (string $item): string => '<li>'.$e($item).'</li>', $items));
    $cards = '';
    foreach ($page['causes'] as $index => [$title, $text]) {
        $cards .= '<article class="bw-card"><span class="bw-number">0'.($index + 1).'</span><h3>'.$e($title).'</h3><p>'.$e($text).'</p></article>';
    }
    $links = '';
    foreach ($page['related'] as $related) {
        $links .= '<li><a href="/'.$locale.'/'.$e($related).'">'.$e($copy[$related]['h1']).'</a></li>';
    }
    $sources = '';
    foreach ($page['sources'] as [$label, $url]) {
        $sources .= '<li><a href="'.$e($url).'">'.$e($label).'</a></li>';
    }
    $faqs = '';
    foreach ($page['faq'] as [$question, $answer]) {
        $faqs .= '<article><h3>'.$e($question).'</h3><p>'.$e($answer).'</p></article>';
    }
    $extra = '';
    $extraToc = '';
    foreach ($page['extra'] ?? [] as $block) {
        $extraToc .= '<a href="#'.$e($block['id']).'">'.$e($block['toc']).'</a>';
        $inner = '';
        if ($block['type'] === 'flow') {
            foreach ($block['steps'] as $index => [$check, $yes, $no]) {
                $inner .= '<li class="bw-flow-step"><span class="bw-number">'.($index + 1).'</span><div><h3>'.$e($check).'</h3><p><strong>'.$e($common['if_yes']).'</strong> '.$e($yes).'</p><p><strong>'.$e($common['if_no']).'</strong> '.$e($no).'</p></div></li>';
            }
            $inner = '<ol class="bw-flow">'.$inner.'</ol>';
        } elseif ($block['type'] === 'example') {
            $inner = '<p class="bw-code-title">'.$e($block['code_title']).'</p><pre class="bw-code"><code>'.$e(implode("\n", $block['lines'])).'</code></pre><p class="bw-inline-note">'.$e($common['example_note']).'</p><div class="bw-card bw-takeaway"><h3>'.$e($common['takeaway']).'</h3><p>'.$e($block['takeaway']).'</p></div>';
        } elseif ($block['type'] === 'table') {
            $head = implode('', array_map(static fn (string $cell): string => '<th scope="col">'.$e($cell).'</th>', $block['head']));
            $rows = implode('', array_map(static fn (array $row): string => '<tr>'.implode('', array_map(static fn (string $cell): string => '<td>'.$e($cell).'</td>', $row)).'</tr>', $block['rows']));
            $inner = '<div class="bw-table-wrap"><table class="bw-table"><thead><tr>'.$head.'</tr></thead><tbody>'.$rows.'</tbody></table></div>';
        } else {
            foreach ($block['items'] as [$title, $text]) {
                $inner .= '<article class="bw-card"><h3>'.$e($title).'</h3><p>'.$e($text).'</p></article>';
            }
            $inner = '<div class="bw-grid-2 bw-gap">'.$inner.'</div>';
        }
        $extra .= '<section class="bw-section" id="'.$e($block['id']).'"><h2>'.$e($block['title']).'</h2>'.(isset($block['intro']) ? '<p class="bw-copy">'.$e($block['intro']).'</p>' : '').$inner.'</section>';
    }
    $toc = '<a href="#causes">'.$e($common['causes']).'</a>'.$extraToc;
    foreach (['manual', 'fix', 'monitoring', 'connect'] as $id) {
        $toc .= '<a href="#'.$id.'">'.$e($common[$id]).'</a>';
    }

    return '<div class="bw-page" lang="'.$locale.'">'
        .'<section class="bw-hero"><div><p class="bw-eyebrow">'.$e($page['eyebrow']).'</p><h1>'.$e($page['h1']).'</h1><p class="bw-lead">'.$e($page['lead']).'</p><div class="bw-actions"><a class="bw-btn" href="#manual">'.$e($common['manual_cta']).'</a><a class="bw-btn bw-btn-outline" href="#monitoring">'.$e($common['monitor_cta']).'</a></div><div class="bw-meta"><span>'.$e($common['meta_read']).'</span><span>'.$e($common['meta_control']).'</span></div></div>'
        .'<aside class="bw-panel"><div class="bw-panel-top"><span class="bw-demo-title">'.$e($common['quick']).'</span><span class="bw-demo-label">'.$e($page['label']).'</span></div><div class="bw-panel-body"><h2>'.$e($page['answer_title']).'</h2><p class="bw-copy">'.$e($page['answer']).'</p><ul class="bw-list">'.$list($page['checks']).'</ul></div></aside></section>'
        .'<nav class="bw-toc" aria-label="'.$e($common['toc']).'">'.$toc.'</nav>'
        .'<section class="bw-section" id="causes"><div class="bw-section-head"><div><h2>'.$e($page['causes_title']).'</h2><p class="bw-copy">'.$e($page['causes_intro']).'</p></div></div><div class="bw-grid-3">'.$cards.'</div></section>'
        .$extra
        .'<section class="bw-section" id="manual"><h2>'.$e($page['manual_title']).'</h2><ol class="bw-list">'.$list($page['manual']).'</ol><p class="bw-inline-note">'.$e($common['source_intro']).'</p><ul class="bw-list">'.$sources.'</ul></section>'
        .'<section class="bw-section" id="fix"><h2>'.$e($page['fix_title']).'</h2><div class="bw-grid-2 bw-gap"><article class="bw-card"><h3>'.$e($common['fix_now']).'</h3><p>'.$e($page['fix_now']).'</p></article><article class="bw-card"><h3>'.$e($common['fix_verify']).'</h3><p>'.$e($page['fix_verify']).'</p></article></div></section>'
        .'<section class="bw-section" id="monitoring"><h2>'.$e($page['monitor_title']).'</h2><div class="bw-split"><div><p class="bw-copy">'.$e($page['monitor']).'</p><ul class="bw-yes">'.$list($common['monitor_facts']).'</ul></div><div class="bw-honest"><h3>'.$e($common['limits']).'</h3><p>'.$e($page['limit']).'</p></div></div></section>'
        .'<section class="bw-section" id="connect"><h2>'.$e($common['connect_title']).'</h2><ol class="bw-list">'.$list($page['connect']).'</ol><p class="bw-inline-note">'.$e($common['paypal_note']).' <a href="/'.$locale.'/security">'.$e($common['security']).'</a></p></section>'
        .'<section class="bw-section"><h2>'.$e($common['faq']).'</h2><div class="bw-faq">'.$faqs.'</div></section>'
        .'<section class="bw-section"><h2>'.$e($common['related']).'</h2><ul class="bw-list">'.$links.'</ul></section>'
        .'<section class="bw-section"><div class="bw-cta"><h2>'.$e($common['cta_title']).'</h2><p class="bw-copy">'.$e($common['cta_text']).'</p><div class="bw-actions"><a class="bw-btn" href="/'.$locale.'/signup">'.$e($common['signup']).'</a><a class="bw-btn bw-btn-outline" href="/'.$locale.'/pricing">'.$e($common['pricing']).'</a></div></div></section></div>';
};
