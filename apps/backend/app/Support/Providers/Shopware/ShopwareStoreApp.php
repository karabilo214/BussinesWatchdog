<?php

namespace App\Support\Providers\Shopware;

use ZipArchive;

class ShopwareStoreApp
{
    public const COMPATIBILITY = '~6.6.0 || ~6.7.0';

    public const ICON = 'Resources/config/plugin.png';

    public function __construct(private readonly ShopwareApp $app) {}

    public static function name(): ?string
    {
        $name = (string) config('watchdog.shopware.store_app_name');

        return preg_match('/^[A-Z][A-Za-z0-9]{2,63}$/', $name) === 1 && $name !== ShopwareApp::APP_NAME ? $name : null;
    }

    /**
     * @return array{archive: string, filename: string, name: string}
     */
    public function package(): array
    {
        $name = self::name() ?? throw new \RuntimeException('shopware_store_app_name_missing');
        $icon = file_get_contents(resource_path('shopware-store/plugin.png'));

        if ($icon === false) {
            throw new \RuntimeException('shopware_store_app_icon_missing');
        }

        $files = ['manifest.xml' => $this->manifest($name), self::ICON => $icon, ...$this->app->sourceFiles()];
        $path = tempnam(sys_get_temp_dir(), 'bw-shopware-store-app');

        if ($path === false) {
            throw new \RuntimeException('shopware_app_archive_temp_failed');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('shopware_app_archive_open_failed');
            }

            try {
                foreach ($files as $relative => $contents) {
                    if (! $zip->addFromString($name.'/'.$relative, $contents)) {
                        throw new \RuntimeException('shopware_app_source_write_failed');
                    }
                }
            } finally {
                if (! $zip->close()) {
                    throw new \RuntimeException('shopware_app_archive_close_failed');
                }
            }

            $bytes = file_get_contents($path);

            if ($bytes === false || $bytes === '') {
                throw new \RuntimeException('shopware_app_archive_read_failed');
            }

            return ['archive' => $bytes, 'filename' => $name.'-'.ShopwareApp::APP_VERSION.'.zip', 'name' => $name];
        } finally {
            @unlink($path);
        }
    }

    private function manifest(string $name): string
    {
        $events = ShopwareApp::events();
        $webhookUrl = htmlspecialchars(url('/api/v1/shopware/app/webhook'), ENT_XML1);
        $webhooks = implode("\n", array_map(fn (string $hook, string $event): string => sprintf('        <webhook name="%s" url="%s" event="%s"/>', $hook, $webhookUrl, $event), array_keys($events), $events));
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/shopware/trunk/src/Core/Framework/App/Manifest/Schema/manifest-2.0.xsd">
    <meta>
        <name>%s</name>
        <label>Business Watchdog</label>
        <label lang="de-DE">Business Watchdog</label>
        <description>Finds orders whose payment does not match: marked as paid without a capture, refunds missing on one side, failing payments. Read-only: it never changes orders.</description>
        <description lang="de-DE">Findet Bestellungen, deren Zahlung nicht stimmt: als bezahlt markiert ohne Zahlungseingang, fehlende Erstattungen, fehlschlagende Zahlungen. Nur lesend.</description>
        <author>Business Watchdog</author>
        <copyright>(c) Business Watchdog</copyright>
        <version>%s</version>
        <icon>%s</icon>
        <license>proprietary</license>
        <compatibility>%s</compatibility>
        <privacy>%s</privacy>
    </meta>
    <setup>
        <registrationUrl>%s</registrationUrl>
    </setup>
    <permissions>
%s
    </permissions>
    <webhooks>
%s
    </webhooks>
    <admin>
        <main-module source="%s"/>
    </admin>
</manifest>

XML;

        return sprintf(
            $manifest,
            $name,
            ShopwareApp::APP_VERSION,
            self::ICON,
            htmlspecialchars(self::COMPATIBILITY, ENT_XML1),
            htmlspecialchars(rtrim((string) config('app.url'), '/').'/en/privacy', ENT_XML1),
            htmlspecialchars(url('/api/v1/shopware/app/register'), ENT_XML1),
            ShopwareApp::permissionsXml(),
            $webhooks,
            htmlspecialchars(url('/api/v1/shopware/app/connect'), ENT_XML1),
        );
    }
}
