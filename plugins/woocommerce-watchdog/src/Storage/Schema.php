<?php

namespace BusinessWatchdog\WooCommerce\Storage;

final class Schema
{
    public const VERSION = 4;

    public const OPTION = 'business_watchdog_schema_version';

    public static function outboxTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'business_watchdog_outbox';
    }

    public static function revisionsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'business_watchdog_revisions';
    }

    public static function stateTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'business_watchdog_state';
    }

    public static function attemptsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'business_watchdog_payment_attempts';
    }

    public static function observationTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'business_watchdog_checkout_observation';
    }

    public static function isCurrent(): bool
    {
        return (int) get_option(self::OPTION, 0) >= self::VERSION;
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $outbox = self::outboxTable();
        $revisions = self::revisionsTable();
        $state = self::stateTable();
        $attempts = self::attemptsTable();
        $observation = self::observationTable();

        dbDelta("CREATE TABLE {$outbox} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id char(36) NOT NULL,
  aggregate_key varchar(191) NOT NULL,
  revision bigint(20) unsigned NOT NULL DEFAULT 0,
  event_type varchar(64) NOT NULL,
  payload longtext NOT NULL,
  payload_hash char(64) NOT NULL,
  state varchar(20) NOT NULL DEFAULT 'pending',
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  next_attempt_at datetime NOT NULL,
  last_error varchar(191) NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_id (event_id),
  KEY state_due (state,next_attempt_at),
  KEY aggregate_key (aggregate_key)
) ENGINE=InnoDB {$charsetCollate};");

        dbDelta("CREATE TABLE {$revisions} (
  aggregate_key varchar(191) NOT NULL,
  parent_key varchar(191) NULL,
  revision bigint(20) unsigned NOT NULL DEFAULT 0,
  snapshot_hash char(64) NOT NULL,
  last_data longtext NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (aggregate_key),
  KEY parent_key (parent_key)
) ENGINE=InnoDB {$charsetCollate};");

        dbDelta("CREATE TABLE {$state} (
  name varchar(100) NOT NULL,
  value longtext NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (name)
) ENGINE=InnoDB {$charsetCollate};");

        dbDelta("CREATE TABLE {$attempts} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NULL,
  payment_method varchar(100) NOT NULL,
  outcome varchar(32) NULL,
  failure_class varchar(40) NULL,
  attempted_at datetime NOT NULL,
  resolved_at datetime NULL,
  reported tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY order_outcome (order_id,outcome),
  KEY open_attempts (outcome,attempted_at),
  KEY unreported (reported,resolved_at)
) ENGINE=InnoDB {$charsetCollate};");

        dbDelta("CREATE TABLE {$observation} (
  window_start datetime NOT NULL,
  checkout_rendered int(10) unsigned NOT NULL DEFAULT 0,
  checkout_rendered_noscript int(10) unsigned NOT NULL DEFAULT 0,
  finish_rendered int(10) unsigned NOT NULL DEFAULT 0,
  script_loaded int(10) unsigned NOT NULL DEFAULT 0,
  form_ready int(10) unsigned NOT NULL DEFAULT 0,
  returned_with_error int(10) unsigned NOT NULL DEFAULT 0,
  submit_clicked int(10) unsigned NOT NULL DEFAULT 0,
  submit_stalled int(10) unsigned NOT NULL DEFAULT 0,
  finish_reached int(10) unsigned NOT NULL DEFAULT 0,
  js_errors int(10) unsigned NOT NULL DEFAULT 0,
  revision int(10) unsigned NOT NULL DEFAULT 1,
  reported tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (window_start),
  KEY unreported (reported,window_start)
) ENGINE=InnoDB {$charsetCollate};");

        foreach ([$outbox, $revisions, $state, $attempts, $observation] as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));

            if ($found !== $table) {
                throw new \RuntimeException('business_watchdog_schema_install_failed');
            }
        }

        update_option(self::OPTION, self::VERSION, false);
    }

    public static function drop(): void
    {
        global $wpdb;

        foreach ([self::outboxTable(), self::revisionsTable(), self::stateTable(), self::attemptsTable(), self::observationTable()] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        delete_option(self::OPTION);
    }
}
