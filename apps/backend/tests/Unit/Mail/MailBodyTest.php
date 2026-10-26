<?php

namespace Tests\Unit\Mail;

use App\Support\Mail\MailBody;
use PHPUnit\Framework\TestCase;

class MailBodyTest extends TestCase
{
    private const HOSTS = ['watchdog.test'];

    public function test_a_link_on_its_own_line_becomes_a_button_with_the_action_or_its_own_label(): void
    {
        $blocks = MailBody::blocks("Reset your password:\nhttps://watchdog.test/app/reset?token=a\n\nOpen incident: https://watchdog.test/app/incidents/1", 'Set a new password', 'Open', self::HOSTS);

        $this->assertSame([
            ['type' => 'paragraph', 'lines' => [[['text' => 'Reset your password:']]]],
            ['type' => 'button', 'label' => 'Set a new password', 'url' => 'https://watchdog.test/app/reset?token=a'],
            ['type' => 'button', 'label' => 'Open incident', 'url' => 'https://watchdog.test/app/incidents/1'],
        ], $blocks);
    }

    public function test_an_indented_link_stays_a_small_link_inside_its_paragraph(): void
    {
        $blocks = MailBody::blocks("• Incident one\n  https://watchdog.test/app/incidents/1", 'Open', 'View', self::HOSTS);

        $this->assertSame([['type' => 'paragraph', 'lines' => [
            [['text' => '• Incident one']],
            [['text' => 'View', 'url' => 'https://watchdog.test/app/incidents/1']],
        ]]], $blocks);
    }

    public function test_only_the_services_own_links_become_clickable(): void
    {
        $blocks = MailBody::blocks("Store https://evil.example/login, see https://watchdog.test/app/.\nhttps://evil.example/app\nhttps://user@watchdog.test/app", 'Open', 'Open', self::HOSTS);

        $this->assertSame([['type' => 'paragraph', 'lines' => [
            [['text' => 'Store https://evil.example/login, see '], ['text' => 'https://watchdog.test/app/', 'url' => 'https://watchdog.test/app/'], ['text' => '.']],
            [['text' => 'https://evil.example/app']],
            [['text' => 'https://user@watchdog.test/app']],
        ]]], $blocks);
        $this->assertFalse(MailBody::trusted('javascript://watchdog.test/x', self::HOSTS));
        $this->assertFalse(MailBody::trusted('https://watchdog.test.evil.example/', self::HOSTS));
    }
}
