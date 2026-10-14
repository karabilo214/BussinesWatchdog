<?php

namespace BusinessWatchdog\WooCommerce\Observation;

use BusinessWatchdog\WooCommerce\Synthetic\SyntheticRequest;

/**
 * Checkout observation, layer 2 (spec §17.1, ADR 0059): while the service wants it, the checkout and order-received
 * pages get a small script that counts checkout stages; the plugin counts the pages it renders itself and prints a
 * noscript beacon. Nothing is stored on the device and nothing about the visitor is kept.
 */
final class CheckoutObservation
{
    public const HANDLE = 'bw-checkout-observer';

    public const PAGES = ['checkout', 'finish'];

    public const ALTERED_OPTION = 'bw_checkout_script_altered_at';

    /** Optimiser exclusion patterns: the script file and its inline configuration. */
    private const PATTERNS = ['business-watchdog/assets/checkout-observer', 'BW_CHECKOUT_OBSERVER'];

    private static $page = false;

    public static function register(): void
    {
        if (! ObservationSettings::enabled()) {
            return;
        }

        add_action('wp_enqueue_scripts', [self::class, 'enqueue'], 1);
        add_action('wp_footer', [self::class, 'rendered'], 1);
        add_filter('script_loader_tag', [self::class, 'tag'], 10, 2);

        foreach (['rocket_exclude_js', 'rocket_exclude_defer_js', 'rocket_delay_js_exclusions', 'rocket_exclude_inline_js', 'litespeed_optimize_js_excludes', 'litespeed_optm_js_defer_exc', 'litespeed_optm_gm_js_exc'] as $filter) {
            add_filter($filter, [self::class, 'excludePatterns']);
        }

        foreach (['sgo_javascript_combine_exclude', 'sgo_js_minify_exclude', 'sgo_js_async_exclude'] as $filter) {
            add_filter($filter, [self::class, 'excludeHandle']);
        }

        add_filter('autoptimize_filter_js_exclude', [self::class, 'excludeAutoptimize']);
    }

    /**
     * @return string|null 'checkout' or 'finish' on an observed page
     */
    public static function page(): ?string
    {
        if (self::$page !== false) {
            return self::$page;
        }

        $page = null;

        if (function_exists('is_order_received_page') && is_order_received_page()) {
            $page = 'finish';
        } elseif (function_exists('is_checkout') && is_checkout() && ! (function_exists('is_checkout_pay_page') && is_checkout_pay_page())) {
            $page = 'checkout';
        }

        return self::$page = $page;
    }

    public static function enqueue(): void
    {
        $page = self::page();
        $token = $page === null ? null : ObservationToken::issue($page);

        if ($token === null) {
            return;
        }

        wp_enqueue_script(self::HANDLE, plugins_url('assets/checkout-observer.js', BW_PLUGIN_FILE), [], BW_PLUGIN_VERSION, false);
        wp_add_inline_script(self::HANDLE, 'window.BW_CHECKOUT_OBSERVER = ' . wp_json_encode([
            'endpoint' => rest_url('business-watchdog/v1/observe'),
            'token' => $token,
            'page' => $page,
            'synthetic' => SyntheticRequest::current() !== null,
        ]) . ';', 'before');
    }

    /**
     * Counts a page actually rendered (redirects such as an empty cart never reach the footer) and prints the beacon
     * that counts browsers without JavaScript. Pages rendered for a verified browser check are not counted.
     */
    public static function rendered(): void
    {
        $page = self::page();

        if ($page === null || SyntheticRequest::current() !== null || (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET')) {
            return;
        }

        ObservationLog::increment([$page . '_rendered' => 1]);
        $token = $page === 'checkout' ? ObservationToken::issue($page) : null;

        if ($token !== null) {
            echo '<noscript><img src="' . esc_url(add_query_arg('t', $token, rest_url('business-watchdog/v1/observe/noscript'))) . '" alt="" width="1" height="1" style="position:absolute;width:1px;height:1px" /></noscript>';
        }
    }

    public static function tag($tag, $handle)
    {
        if ($handle !== self::HANDLE || ! is_string($tag)) {
            return $tag;
        }

        return str_replace('<script ', '<script data-no-optimize="1" data-no-minify="1" data-no-defer="1" data-cfasync="false" data-pagespeed-no-defer ', $tag);
    }

    public static function excludePatterns($patterns)
    {
        return array_values(array_unique(array_merge(is_array($patterns) ? $patterns : [], self::PATTERNS)));
    }

    public static function excludeHandle($handles)
    {
        return array_values(array_unique(array_merge(is_array($handles) ? $handles : [], [self::HANDLE])));
    }

    public static function excludeAutoptimize($exclude)
    {
        return trim((string) $exclude . ', ' . implode(', ', self::PATTERNS), ', ');
    }

    /**
     * A report from the script (or the noscript beacon) of an observed page.
     */
    public static function report(?string $tokenPage, string $page, array $stages, int $jsErrors): bool
    {
        if (! ObservationSettings::enabled() || $tokenPage === null || $tokenPage !== $page || SyntheticRequest::current() !== null) {
            return false;
        }

        $counts = [];

        foreach (array_unique(array_filter($stages, 'is_string')) as $stage) {
            if ($stage === 'script_altered') {
                update_option(self::ALTERED_OPTION, gmdate('c'), false);
            } elseif (in_array($stage, ['script_loaded', 'form_ready', 'returned_with_error', 'submit_clicked', 'submit_stalled', 'finish_reached'], true)) {
                $counts[$stage] = 1;
            }
        }

        if ($jsErrors > 0) {
            $counts['js_errors'] = min($jsErrors, 20);
        }

        ObservationLog::increment($counts);

        return true;
    }

    public static function reset(): void
    {
        self::$page = false;
    }
}
