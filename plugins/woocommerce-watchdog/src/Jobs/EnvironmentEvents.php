<?php

namespace BusinessWatchdog\WooCommerce\Jobs;

use BusinessWatchdog\WooCommerce\Capture\EventFactory;
use BusinessWatchdog\WooCommerce\Compat\Environment;
use BusinessWatchdog\WooCommerce\Connection\Connection;
use BusinessWatchdog\WooCommerce\Storage\Outbox;
use BusinessWatchdog\WooCommerce\Storage\Revisions;
use BusinessWatchdog\WooCommerce\Storage\State;

final class EnvironmentEvents
{
    public const STATE_VERSIONS = 'observed_versions';

    private const CHECKOUT_MODES = [
        Environment::CHECKOUT_CLASSIC => 'classic',
        Environment::CHECKOUT_BLOCKS => 'blocks',
        Environment::CHECKOUT_MIXED => 'custom',
        Environment::CHECKOUT_UNKNOWN => 'unknown',
    ];

    public static function capabilities(): int
    {
        if (Connection::current() === null) {
            return 0;
        }

        $data = [
            'hpos' => Environment::orderStorage() === Environment::ORDER_STORAGE_HPOS,
            'checkout_mode' => self::CHECKOUT_MODES[Environment::checkoutMode()] ?? 'unknown',
            'order_snapshots' => true,
            'refund_snapshots' => true,
            'payment_form_check' => false,
            'funnel_telemetry' => false,
        ];

        return self::record('integration.capabilities_changed', 'integration', State::installId(), 'integration:capabilities', $data);
    }

    public static function deployments(string $method = 'version_scan'): int
    {
        $current = self::currentVersions();
        $previous = State::get(self::STATE_VERSIONS);
        State::set(self::STATE_VERSIONS, $current);

        if (! is_array($previous) || Connection::current() === null) {
            return 0;
        }

        $recorded = 0;

        foreach ($current as $key => $component) {
            $old = $previous[$key]['version'] ?? null;

            if ($old === $component['version'] || $component['version'] === '') {
                continue;
            }

            $recorded += self::record('deployment.observed', 'deployment', substr($key, 0, 255), 'deployment:' . $key . ':' . $component['version'], [
                'component' => $component['component'],
                'component_id' => substr($component['id'], 0, 255),
                'old_version' => $old,
                'new_version' => substr($component['version'], 0, 255),
                'observation_method' => $method,
            ]);
        }

        return $recorded;
    }

    public static function currentVersions(): array
    {
        global $wp_version;

        $versions = [
            'wordpress:core' => ['component' => 'wordpress', 'id' => 'core', 'version' => (string) $wp_version],
            'woocommerce:woocommerce' => ['component' => 'woocommerce', 'id' => 'woocommerce', 'version' => (string) Environment::wooCommerceVersion()],
        ];

        $theme = wp_get_theme();
        $versions['theme:' . $theme->get_stylesheet()] = ['component' => 'theme', 'id' => $theme->get_stylesheet(), 'version' => (string) $theme->get('Version')];

        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();

        foreach ((array) get_option('active_plugins', []) as $file) {
            if (isset($plugins[$file]) && $file !== plugin_basename(BW_PLUGIN_FILE)) {
                $versions['plugin:' . $file] = ['component' => 'plugin', 'id' => (string) $file, 'version' => (string) $plugins[$file]['Version']];
            }
        }

        return $versions;
    }

    private static function record(string $type, string $aggregateType, string $aggregateId, string $key, array $data): int
    {
        $revision = Revisions::observe($key, hash('sha256', (string) wp_json_encode($data)), null, $data);

        if ($revision === null) {
            return 0;
        }

        $envelope = EventFactory::envelope($type, $aggregateType, $aggregateId, $revision, null, $data);
        Outbox::enqueue($envelope, $key);

        return 1;
    }
}
