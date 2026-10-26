<?php

namespace App\Console\Commands;

use App\Mail\NotificationMessageMail;
use App\Support\Mail\MailPreviews;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

class PreviewMail extends Command
{
    protected $signature = 'mail:preview
        {--out= : Directory for the HTML and text version of every letter and an index.html}
        {--to= : Send every letter with sample data to this address through the configured mailer (Mailpit locally)}
        {--locale=* : Only these languages (ru, en, de)}
        {--only= : Only letters whose name contains this text}';

    protected $description = 'Render every service email with sample data for review';

    public function handle(MailPreviews $previews): int
    {
        $out = trim((string) $this->option('out'));
        $to = trim((string) $this->option('to'));
        $locales = array_values(array_intersect(NotificationMessageMail::LOCALES, $this->option('locale') ?: NotificationMessageMail::LOCALES));

        if (($out === '') === ($to === '')) {
            $this->error('Pass exactly one of --out or --to.');

            return self::FAILURE;
        }

        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('--to is not an email address.');

            return self::FAILURE;
        }

        $only = trim((string) $this->option('only'));
        $letters = array_filter($previews->all($locales), fn (string $name): bool => $only === '' || str_contains($name, $only), ARRAY_FILTER_USE_KEY);

        if ($out !== '') {
            File::ensureDirectoryExists($out);
            $index = [];

            foreach ($letters as $name => $letter) {
                File::put("{$out}/{$name}.html", $letter->render());
                File::put("{$out}/{$name}.txt", view('mail.layout-text', $letter->layout())->render());
                $index[] = '<li><a href="'.e($name).'.html">'.e($name).'</a> · <a href="'.e($name).'.txt">text</a> — '.e($letter->subjectLine).'</li>';
            }

            File::put("{$out}/index.html", '<!DOCTYPE html><meta charset="utf-8"><title>Business Watchdog emails</title><ul>'.implode("\n", $index).'</ul>');
            $this->line(sprintf('letters=%d out=%s', count($letters), $out));

            return self::SUCCESS;
        }

        foreach ($letters as $letter) {
            Mail::to($to)->send($letter);
        }

        $this->line(sprintf('letters=%d sent_to=%s', count($letters), $to));

        return self::SUCCESS;
    }
}
