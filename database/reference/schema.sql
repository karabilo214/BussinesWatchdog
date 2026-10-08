-- Business Watchdog reference schema 1.0 / PostgreSQL 18.
-- Empty database only. UUIDs are supplied by application.
-- This is a reviewed design snapshot, not Laravel migrations or production deployment.
-- All times UTC. All money bigint minor units. No float monetary values.
-- Application role must not be database/table owner. RLS activation is a separate gate.
BEGIN;

CREATE TABLE users (
 id uuid PRIMARY KEY,
 email text NOT NULL,
 password_hash text NOT NULL,
 name text NOT NULL,
 locale text NOT NULL DEFAULT 'ru',
 email_verified_at timestamptz,
 mfa_secret_ciphertext bytea,
 disabled_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX users_email_unique ON users (lower(email));

CREATE TABLE tenants (
 id uuid PRIMARY KEY,
 name text NOT NULL,
 status text NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended','deleting','deleted')),
 locale text NOT NULL DEFAULT 'ru',
 timezone text NOT NULL DEFAULT 'Europe/Kyiv',
 config_version bigint NOT NULL DEFAULT 1 CHECK (config_version > 0),
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE memberships (
 tenant_id uuid NOT NULL REFERENCES tenants(id),
 user_id uuid NOT NULL REFERENCES users(id),
 role text NOT NULL CHECK (role IN ('owner','admin','operator','viewer')),
 created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY (tenant_id,user_id)
);
CREATE INDEX memberships_user_idx ON memberships(user_id,tenant_id);

CREATE TABLE invitations (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL REFERENCES tenants(id),
 email text NOT NULL,
 role text NOT NULL CHECK (role IN ('admin','operator','viewer')),
 token_hash char(64) NOT NULL UNIQUE,
 invited_by uuid NOT NULL REFERENCES users(id),
 expires_at timestamptz NOT NULL,
 accepted_at timestamptz,
 revoked_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE stores (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL REFERENCES tenants(id),
 name text NOT NULL,
 base_url text NOT NULL CHECK (base_url LIKE 'https://%'),
 platform text NOT NULL DEFAULT 'woocommerce',
 timezone text NOT NULL,
 locale text NOT NULL DEFAULT 'ru',
 default_currency varchar(3) NOT NULL CHECK (default_currency ~ '^[A-Z]{3}$'),
 status text NOT NULL DEFAULT 'onboarding' CHECK (status IN ('onboarding','active','paused','degraded','deleted')),
 verified_at timestamptz,
 browser_enabled boolean NOT NULL DEFAULT false,
 telemetry_enabled boolean NOT NULL DEFAULT false,
 config_version bigint NOT NULL DEFAULT 1 CHECK (config_version > 0),
 settings jsonb NOT NULL DEFAULT '{}',
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,id)
);
CREATE INDEX stores_tenant_idx ON stores(tenant_id,status);

CREATE TABLE store_verifications (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 method text NOT NULL CHECK (method IN ('wordpress_challenge','dns')),
 challenge_hash char(64) NOT NULL,
 verified_origin text NOT NULL,
 status text NOT NULL CHECK (status IN ('pending','verified','expired','failed')),
 expires_at timestamptz NOT NULL,
 verified_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY (tenant_id,store_id) REFERENCES stores(tenant_id,id)
);

CREATE TABLE integrations (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 provider text NOT NULL CHECK (provider IN ('woocommerce','stripe')),
 external_account_id text,
 install_id uuid,
 mode text NOT NULL DEFAULT 'live' CHECK (mode IN ('live','test')),
 source_authority text NOT NULL CHECK (source_authority IN ('store_reported','independent_provider')),
 status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','active','degraded','revoked','disabled')),
 capabilities jsonb NOT NULL DEFAULT '{}',
 api_version text,
 connector_version text NOT NULL,
 last_heartbeat_at timestamptz,
 last_successful_sync_at timestamptz,
 health jsonb NOT NULL DEFAULT '{}',
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id) REFERENCES stores(tenant_id,id)
);
CREATE UNIQUE INDEX one_active_woo_integration ON integrations(store_id)
 WHERE provider='woocommerce' AND status IN ('pending','active','degraded');
CREATE INDEX integrations_sync_idx ON integrations(status,last_successful_sync_at);

CREATE TABLE integration_credentials (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 integration_id uuid NOT NULL,
 kind text NOT NULL CHECK (kind IN ('plugin_hmac','stripe_api','stripe_webhook')),
 key_id text NOT NULL UNIQUE,
 ciphertext bytea NOT NULL,
 key_version integer NOT NULL CHECK (key_version > 0),
 fingerprint text NOT NULL,
 status text NOT NULL DEFAULT 'active' CHECK (status IN ('active','draining','revoked')),
 expires_at timestamptz,
 rotated_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY (tenant_id,store_id,integration_id) REFERENCES integrations(tenant_id,store_id,id)
);

CREATE TABLE pairing_codes (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 code_hash char(64) NOT NULL UNIQUE,
 created_by uuid NOT NULL REFERENCES users(id),
 attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
 expires_at timestamptz NOT NULL,
 consumed_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY (tenant_id,store_id) REFERENCES stores(tenant_id,id)
);

CREATE TABLE sync_runs (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 integration_id uuid NOT NULL,
 kind text NOT NULL CHECK (kind IN ('backfill','delta','audit','targeted')),
 status text NOT NULL CHECK (status IN ('queued','running','completed','failed','cancelled')),
 object_family text NOT NULL,
 window_start timestamptz,
 window_end timestamptz,
 cursor jsonb NOT NULL DEFAULT '{}',
 fetched_count bigint NOT NULL DEFAULT 0 CHECK (fetched_count>=0),
 rejected_count bigint NOT NULL DEFAULT 0 CHECK (rejected_count>=0),
 config_version bigint NOT NULL,
 started_at timestamptz,
 finished_at timestamptz,
 error_code text,
 created_at timestamptz NOT NULL DEFAULT now(),
 CHECK (window_end IS NULL OR window_start IS NULL OR window_end>=window_start),
 CHECK (finished_at IS NULL OR started_at IS NULL OR finished_at>=started_at),
 FOREIGN KEY (tenant_id,store_id,integration_id) REFERENCES integrations(tenant_id,store_id,id)
);

CREATE TABLE source_watermarks (
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 integration_id uuid NOT NULL,
 object_family text NOT NULL,
 covered_from timestamptz,
 covered_through timestamptz,
 coverage text NOT NULL CHECK (coverage IN ('complete','warming_up','stale','partial','unsupported')),
 gaps jsonb NOT NULL DEFAULT '[]',
 cursor jsonb NOT NULL DEFAULT '{}',
 observed_at timestamptz NOT NULL,
 PRIMARY KEY (integration_id,object_family),
 CHECK (covered_through IS NULL OR covered_from IS NULL OR covered_through>=covered_from),
 FOREIGN KEY (tenant_id,store_id,integration_id) REFERENCES integrations(tenant_id,store_id,id)
);

CREATE TABLE event_inbox (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 integration_id uuid NOT NULL,
 provider_event_id text NOT NULL,
 schema_version text NOT NULL,
 event_type text NOT NULL,
 aggregate_type text NOT NULL,
 aggregate_external_id text NOT NULL,
 aggregate_revision bigint CHECK (aggregate_revision>=0),
 occurred_at timestamptz NOT NULL,
 observed_at timestamptz NOT NULL,
 received_at timestamptz NOT NULL DEFAULT now(),
 is_synthetic boolean NOT NULL DEFAULT false,
 payload jsonb NOT NULL,
 payload_hash char(64) NOT NULL,
 canonicalization_version integer NOT NULL DEFAULT 1,
 status text NOT NULL DEFAULT 'received' CHECK (status IN ('received','processing','processed','quarantined','dead_letter')),
 attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count>=0),
 next_attempt_at timestamptz NOT NULL DEFAULT now(),
 processing_lease_until timestamptz,
 processed_at timestamptz,
 error_code text,
 request_id uuid NOT NULL,
 UNIQUE (integration_id,provider_event_id),
 UNIQUE (tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,integration_id) REFERENCES integrations(tenant_id,store_id,id)
);
CREATE INDEX inbox_history_idx ON event_inbox(tenant_id,integration_id,received_at);
CREATE INDEX inbox_pending_idx ON event_inbox(next_attempt_at,received_at)
 WHERE status IN ('received','processing');

CREATE TABLE domain_outbox (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL REFERENCES tenants(id),
 topic text NOT NULL,
 dedupe_key text NOT NULL,
 payload jsonb NOT NULL,
 status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','leased','published','dead_letter')),
 attempts integer NOT NULL DEFAULT 0 CHECK (attempts>=0),
 next_attempt_at timestamptz NOT NULL DEFAULT now(),
 lease_until timestamptz,
 published_at timestamptz,
 error_code text,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,topic,dedupe_key)
);
CREATE INDEX outbox_due_idx ON domain_outbox(next_attempt_at) WHERE status IN ('pending','leased');

CREATE TABLE orders (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 integration_id uuid NOT NULL,
 external_id text NOT NULL,
 display_number text NOT NULL,
 source_revision bigint NOT NULL CHECK (source_revision>=0),
 status text NOT NULL,
 gateway text,
 mode text NOT NULL DEFAULT 'live' CHECK (mode IN ('live','test')),
 currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
 currency_exponent smallint NOT NULL CHECK (currency_exponent BETWEEN 0 AND 6),
 total_minor bigint NOT NULL CHECK (total_minor>=0),
 payment_expected boolean NOT NULL,
 paid_marked_at timestamptz,
 transaction_ref text,
 financial_support text NOT NULL DEFAULT 'unknown' CHECK (financial_support IN ('supported','unsupported','unknown')),
 is_synthetic boolean NOT NULL DEFAULT false,
 source_created_at timestamptz NOT NULL,
 source_updated_at timestamptz NOT NULL,
 deleted_at timestamptz,
 current_payload_hash char(64) NOT NULL,
 metadata jsonb NOT NULL DEFAULT '{}',
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (integration_id,external_id),
 UNIQUE (tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,integration_id) REFERENCES integrations(tenant_id,store_id,id)
);
CREATE INDEX orders_recent_idx ON orders(tenant_id,store_id,source_updated_at);
CREATE INDEX orders_transaction_ref_idx ON orders(store_id,gateway,transaction_ref) WHERE transaction_ref IS NOT NULL;

CREATE TABLE order_revisions (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 order_id uuid NOT NULL,
 event_id uuid,
 source_revision bigint NOT NULL CHECK (source_revision>=0),
 snapshot jsonb NOT NULL,
 payload_hash char(64) NOT NULL,
 observed_at timestamptz NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (order_id,source_revision),
 FOREIGN KEY (tenant_id,store_id,order_id) REFERENCES orders(tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,event_id) REFERENCES event_inbox(tenant_id,store_id,id) ON DELETE SET NULL (event_id)
);

CREATE TABLE refunds (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 integration_id uuid NOT NULL,
 order_id uuid NOT NULL,
 external_id text NOT NULL,
 source_revision bigint NOT NULL CHECK (source_revision>=0),
 currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
 currency_exponent smallint NOT NULL CHECK (currency_exponent BETWEEN 0 AND 6),
 amount_minor bigint NOT NULL CHECK (amount_minor>=0),
 external_required boolean,
 provider_ref text,
 status text NOT NULL CHECK (status IN ('requested','recorded','cancelled','deleted')),
 occurred_at timestamptz NOT NULL,
 updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (integration_id,external_id),
 UNIQUE (tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,integration_id) REFERENCES integrations(tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,order_id) REFERENCES orders(tenant_id,store_id,id)
);

CREATE TABLE payments (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 integration_id uuid NOT NULL,
 external_id text NOT NULL,
 intent_ref text,
 charge_ref text,
 mode text NOT NULL CHECK (mode IN ('live','test')),
 currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
 currency_exponent smallint NOT NULL CHECK (currency_exponent BETWEEN 0 AND 6),
 status text NOT NULL CHECK (status IN ('pending','authorized','captured','failed','cancelled','unknown')),
 source_authority text NOT NULL CHECK (source_authority IN ('store_reported','independent_provider')),
 source_updated_at timestamptz NOT NULL,
 metadata jsonb NOT NULL DEFAULT '{}',
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (integration_id,external_id),
 UNIQUE (tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,integration_id) REFERENCES integrations(tenant_id,store_id,id)
);
CREATE INDEX payments_refs_idx ON payments(store_id,intent_ref,charge_ref);

CREATE TABLE financial_transactions (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 integration_id uuid NOT NULL,
 payment_id uuid,
 external_operation_id text NOT NULL,
 kind text NOT NULL CHECK (kind IN ('capture','refund','fee','dispute_debit','dispute_credit','adjustment')),
 status text NOT NULL CHECK (status IN ('pending','succeeded','failed','cancelled')),
 currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
 currency_exponent smallint NOT NULL CHECK (currency_exponent BETWEEN 0 AND 6),
 amount_minor bigint NOT NULL CHECK (amount_minor>=0),
 occurred_at timestamptz NOT NULL,
 source_event_id uuid,
 source_authority text NOT NULL CHECK (source_authority IN ('store_reported','independent_provider')),
 operation_hash char(64) NOT NULL,
 metadata jsonb NOT NULL DEFAULT '{}',
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (integration_id,kind,external_operation_id),
 UNIQUE (tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,integration_id) REFERENCES integrations(tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,payment_id) REFERENCES payments(tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,source_event_id) REFERENCES event_inbox(tenant_id,store_id,id) ON DELETE SET NULL (source_event_id)
);
CREATE INDEX transactions_window_idx ON financial_transactions(tenant_id,store_id,occurred_at);
CREATE INDEX transactions_payment_idx ON financial_transactions(payment_id,kind,status);

CREATE TABLE payment_allocations (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 payment_id uuid NOT NULL,
 capture_transaction_id uuid NOT NULL,
 order_id uuid NOT NULL,
 currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
 amount_minor bigint NOT NULL CHECK (amount_minor>=0),
 strategy text NOT NULL CHECK (strategy IN ('exact_reference','verified_metadata','manual')),
 evidence jsonb NOT NULL,
 created_by uuid REFERENCES users(id),
 revoked_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,payment_id) REFERENCES payments(tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,capture_transaction_id) REFERENCES financial_transactions(tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,order_id) REFERENCES orders(tenant_id,store_id,id)
);
CREATE UNIQUE INDEX active_capture_order_allocation ON payment_allocations(capture_transaction_id,order_id) WHERE revoked_at IS NULL;
CREATE INDEX allocations_order_idx ON payment_allocations(order_id) WHERE revoked_at IS NULL;
-- MUST validate under payment/capture row locks: kind=capture, matching payment/currency,
-- active allocation sum <= succeeded capture amount; CHECK cannot validate cross-row sums.

CREATE TABLE refund_allocations (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 refund_id uuid NOT NULL,
 refund_transaction_id uuid NOT NULL,
 payment_allocation_id uuid NOT NULL,
 amount_minor bigint NOT NULL CHECK (amount_minor>=0),
 currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
 strategy text NOT NULL CHECK (strategy IN ('exact_reference','verified_metadata','manual')),
 evidence jsonb NOT NULL,
 created_by uuid REFERENCES users(id),
 revoked_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY (tenant_id,store_id,refund_id) REFERENCES refunds(tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,refund_transaction_id) REFERENCES financial_transactions(tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,payment_allocation_id) REFERENCES payment_allocations(tenant_id,store_id,id)
);
CREATE UNIQUE INDEX active_refund_allocation ON refund_allocations(refund_id,refund_transaction_id,payment_allocation_id) WHERE revoked_at IS NULL;

CREATE TABLE reconciliation_runs (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 status text NOT NULL CHECK (status IN ('queued','running','completed','failed','cancelled')),
 algorithm_version text NOT NULL,
 config_version bigint NOT NULL,
 currency varchar(3) CHECK (currency ~ '^[A-Z]{3}$'),
 scope jsonb NOT NULL,
 coverage_snapshot jsonb NOT NULL,
 counters jsonb NOT NULL DEFAULT '{}',
 started_at timestamptz,
 finished_at timestamptz,
 error_code text,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,store_id,id),
 CHECK (finished_at IS NULL OR started_at IS NULL OR finished_at>=started_at),
 FOREIGN KEY (tenant_id,store_id) REFERENCES stores(tenant_id,id)
);

CREATE TABLE reconciliation_findings (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 run_id uuid NOT NULL,
 order_id uuid,
 payment_id uuid,
 rule_code text NOT NULL,
 status text NOT NULL CHECK (status IN ('ok','pending','mismatch','unsupported','unknown')),
 reason_code text NOT NULL,
 currency varchar(3) CHECK (currency ~ '^[A-Z]{3}$'),
 currency_exponent smallint CHECK (currency_exponent BETWEEN 0 AND 6),
 expected_minor bigint,
 actual_minor bigint,
 difference_minor bigint,
 gross_minor bigint,
 captured_minor bigint,
 refund_expected_minor bigint,
 refund_actual_minor bigint,
 evidence jsonb NOT NULL,
 config_snapshot jsonb NOT NULL,
 evaluated_at timestamptz NOT NULL,
 UNIQUE (tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,run_id) REFERENCES reconciliation_runs(tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,order_id) REFERENCES orders(tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,payment_id) REFERENCES payments(tenant_id,store_id,id)
);
CREATE INDEX findings_recent_idx ON reconciliation_findings(tenant_id,store_id,status,evaluated_at DESC);

CREATE TABLE rule_configs (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 rule_code text NOT NULL,
 version bigint NOT NULL CHECK (version>0),
 enabled boolean NOT NULL DEFAULT true,
 parameters jsonb NOT NULL,
 effective_at timestamptz NOT NULL,
 created_by uuid NOT NULL REFERENCES users(id),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (store_id,rule_code,version),
 UNIQUE (tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id) REFERENCES stores(tenant_id,id)
);

CREATE TABLE metric_buckets (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 metric_key text NOT NULL,
 currency varchar(3),
 bucket_start timestamptz NOT NULL,
 bucket_seconds integer NOT NULL CHECK (bucket_seconds IN (300,3600,86400)),
 config_version bigint NOT NULL,
 count_value bigint CHECK (count_value>=0),
 amount_minor bigint,
 coverage text NOT NULL CHECK (coverage IN ('complete','warming_up','stale','partial','unsupported')),
 finalized_at timestamptz,
 updated_at timestamptz NOT NULL DEFAULT now(),
 CHECK (count_value IS NULL OR amount_minor IS NULL),
 UNIQUE NULLS NOT DISTINCT (tenant_id,store_id,metric_key,currency,bucket_start,bucket_seconds,config_version),
 FOREIGN KEY (tenant_id,store_id) REFERENCES stores(tenant_id,id)
);

CREATE TABLE baseline_snapshots (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 metric_key text NOT NULL,
 currency varchar(3),
 algorithm_version text NOT NULL,
 config_version bigint NOT NULL,
 timezone text NOT NULL,
 reference_window_start timestamptz NOT NULL,
 sample_count integer NOT NULL CHECK (sample_count>=0),
 samples jsonb NOT NULL,
 statistics jsonb NOT NULL,
 coverage text NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id) REFERENCES stores(tenant_id,id)
);

CREATE TABLE check_scenarios (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 name text NOT NULL,
 mode text NOT NULL DEFAULT 'payment_form' CHECK (mode='payment_form'),
 version bigint NOT NULL CHECK (version>0),
 enabled boolean NOT NULL DEFAULT false,
 adapter_version text NOT NULL,
 definition jsonb NOT NULL,
 product_external_id text NOT NULL,
 interval_seconds integer NOT NULL DEFAULT 900 CHECK (interval_seconds BETWEEN 300 AND 86400),
 next_due_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id) REFERENCES stores(tenant_id,id)
);

CREATE TABLE check_runs (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 scenario_id uuid NOT NULL,
 scenario_version bigint NOT NULL,
 trigger text NOT NULL CHECK (trigger IN ('scheduled','manual','incident')),
 dedupe_key text NOT NULL,
 status text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','running','passed','failed','inconclusive','blocked','unsupported','cancelled')),
 config_snapshot jsonb NOT NULL,
 scheduled_at timestamptz NOT NULL,
 started_at timestamptz,
 finished_at timestamptz,
 last_fencing_token bigint NOT NULL DEFAULT 0 CHECK (last_fencing_token>=0),
 next_attempt_at timestamptz NOT NULL,
 error_code text,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (store_id,dedupe_key),
 UNIQUE (tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,scenario_id) REFERENCES check_scenarios(tenant_id,store_id,id),
 CHECK (finished_at IS NULL OR started_at IS NULL OR finished_at>=started_at)
);
CREATE UNIQUE INDEX one_active_scenario_run ON check_runs(scenario_id) WHERE status IN ('queued','running');
CREATE UNIQUE INDEX one_active_store_run ON check_runs(store_id) WHERE status IN ('queued','running');
CREATE INDEX checks_due_idx ON check_runs(next_attempt_at,scheduled_at) WHERE status IN ('queued','running');

CREATE TABLE check_attempts (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 run_id uuid NOT NULL,
 attempt_number integer NOT NULL CHECK (attempt_number>0),
 worker_id text NOT NULL,
 fencing_token bigint NOT NULL CHECK (fencing_token>0),
 lease_token_hash char(64) NOT NULL,
 lease_until timestamptz NOT NULL,
 absolute_deadline_at timestamptz NOT NULL,
 last_heartbeat_at timestamptz,
 status text NOT NULL CHECK (status IN ('running','passed','failed','inconclusive','blocked','unsupported','cancelled','expired')),
 browser_version text NOT NULL,
 location text NOT NULL,
 started_at timestamptz NOT NULL,
 finished_at timestamptz,
 result_hash char(64),
 error_code text,
 sanitized_error jsonb,
 UNIQUE (run_id,attempt_number),
 UNIQUE (run_id,fencing_token),
 UNIQUE (tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,run_id) REFERENCES check_runs(tenant_id,store_id,id),
 CHECK (absolute_deadline_at>=started_at),
 CHECK (finished_at IS NULL OR finished_at>=started_at)
);
CREATE INDEX attempt_lease_idx ON check_attempts(lease_until) WHERE status='running';

CREATE TABLE check_steps (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 attempt_id uuid NOT NULL,
 step_index integer NOT NULL CHECK (step_index>=0),
 step_code text NOT NULL,
 status text NOT NULL CHECK (status IN ('passed','failed','skipped','inconclusive','blocked')),
 started_at timestamptz NOT NULL,
 finished_at timestamptz NOT NULL,
 assertions jsonb NOT NULL DEFAULT '[]',
 network_summary jsonb NOT NULL DEFAULT '[]',
 error_code text,
 UNIQUE (attempt_id,step_index),
 FOREIGN KEY (tenant_id,store_id,attempt_id) REFERENCES check_attempts(tenant_id,store_id,id),
 CHECK (finished_at>=started_at)
);

CREATE TABLE artifacts (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 attempt_id uuid NOT NULL,
 kind text NOT NULL CHECK (kind IN ('screenshot','trace','diagnostics')),
 object_key text NOT NULL UNIQUE,
 content_type text NOT NULL,
 size_bytes bigint NOT NULL CHECK (size_bytes>=0),
 sha256 char(64) NOT NULL,
 redaction_version text NOT NULL,
 state text NOT NULL CHECK (state IN ('pending','ready','rejected','deleted')),
 expires_at timestamptz NOT NULL,
 deleted_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY (tenant_id,store_id,attempt_id) REFERENCES check_attempts(tenant_id,store_id,id)
);

CREATE TABLE signals (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 signal_type text NOT NULL,
 family text NOT NULL,
 component text NOT NULL,
 dedupe_key text NOT NULL,
 severity text NOT NULL CHECK (severity IN ('info','warning','critical')),
 confidence text NOT NULL CHECK (confidence IN ('observed','corroborated','inferred','unknown')),
 rule_version text NOT NULL,
 config_version bigint NOT NULL,
 currency varchar(3),
 finding_id uuid,
 check_run_id uuid,
 baseline_id uuid,
 observed_start timestamptz,
 observed_end timestamptz,
 evidence jsonb NOT NULL,
 data_quality jsonb NOT NULL,
 detected_at timestamptz NOT NULL,
 UNIQUE (tenant_id,dedupe_key),
 UNIQUE (tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id) REFERENCES stores(tenant_id,id),
 FOREIGN KEY (tenant_id,store_id,finding_id) REFERENCES reconciliation_findings(tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,check_run_id) REFERENCES check_runs(tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,baseline_id) REFERENCES baseline_snapshots(tenant_id,store_id,id),
 CHECK (observed_end IS NULL OR observed_start IS NULL OR observed_end>=observed_start)
);

CREATE TABLE incidents (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 family text NOT NULL,
 component text NOT NULL,
 fingerprint text NOT NULL,
 state text NOT NULL DEFAULT 'open' CHECK (state IN ('open','acknowledged','resolved')),
 severity text NOT NULL CHECK (severity IN ('info','warning','critical')),
 title_code text NOT NULL,
 currency varchar(3),
 verified_discrepancy_minor bigint,
 first_seen_at timestamptz NOT NULL,
 last_seen_at timestamptz NOT NULL,
 last_good_at timestamptz,
 first_bad_at timestamptz,
 acknowledged_by uuid REFERENCES users(id),
 acknowledged_at timestamptz,
 resolved_at timestamptz,
 resolution_reason text,
 revision bigint NOT NULL DEFAULT 1 CHECK (revision>0),
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,store_id,id),
 UNIQUE (tenant_id,id),
 FOREIGN KEY (tenant_id,store_id) REFERENCES stores(tenant_id,id),
 CHECK (last_seen_at>=first_seen_at)
);
CREATE UNIQUE INDEX incidents_active_fingerprint ON incidents(tenant_id,store_id,fingerprint) WHERE state IN ('open','acknowledged');
CREATE INDEX incidents_list_idx ON incidents(tenant_id,store_id,state,last_seen_at DESC);

CREATE TABLE incident_signals (
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 incident_id uuid NOT NULL,
 signal_id uuid NOT NULL,
 association_reason text NOT NULL,
 linked_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY (incident_id,signal_id),
 FOREIGN KEY (tenant_id,store_id,incident_id) REFERENCES incidents(tenant_id,store_id,id),
 FOREIGN KEY (tenant_id,store_id,signal_id) REFERENCES signals(tenant_id,store_id,id)
);

CREATE TABLE incident_activity (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 incident_id uuid NOT NULL,
 kind text NOT NULL CHECK (kind IN ('created','signal_linked','acknowledged','comment','resolved','reopened','severity_changed','suppressed')),
 actor_id uuid REFERENCES users(id),
 incident_revision bigint NOT NULL,
 sanitized_data jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY (tenant_id,store_id,incident_id) REFERENCES incidents(tenant_id,store_id,id)
);

CREATE TABLE suppressions (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 store_id uuid NOT NULL,
 incident_id uuid,
 scope jsonb NOT NULL,
 reason text NOT NULL,
 created_by uuid NOT NULL REFERENCES users(id),
 starts_at timestamptz NOT NULL,
 ends_at timestamptz NOT NULL,
 revoked_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 CHECK (ends_at>starts_at),
 FOREIGN KEY (tenant_id,store_id) REFERENCES stores(tenant_id,id),
 FOREIGN KEY (tenant_id,store_id,incident_id) REFERENCES incidents(tenant_id,store_id,id)
);

CREATE TABLE notification_channels (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL REFERENCES tenants(id),
 kind text NOT NULL CHECK (kind IN ('email','telegram')),
 destination_ciphertext bytea NOT NULL,
 key_version integer NOT NULL CHECK (key_version>0),
 label text NOT NULL,
 enabled boolean NOT NULL DEFAULT false,
 verified_at timestamptz,
 preferences jsonb NOT NULL,
 health jsonb NOT NULL DEFAULT '{}',
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,id)
);

CREATE TABLE notification_deliveries (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 incident_id uuid,
 channel_id uuid NOT NULL,
 notification_kind text NOT NULL,
 dedupe_key text NOT NULL,
 incident_revision bigint,
 template_version text NOT NULL,
 sanitized_content jsonb NOT NULL,
 status text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','sending','sent','uncertain','failed','dead_letter','suppressed')),
 attempts integer NOT NULL DEFAULT 0 CHECK (attempts>=0),
 next_attempt_at timestamptz NOT NULL,
 provider_message_id text,
 sent_at timestamptz,
 error_code text,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,channel_id,dedupe_key),
 FOREIGN KEY (tenant_id,channel_id) REFERENCES notification_channels(tenant_id,id),
 FOREIGN KEY (tenant_id,incident_id) REFERENCES incidents(tenant_id,id)
);
CREATE INDEX deliveries_due_idx ON notification_deliveries(next_attempt_at) WHERE status IN ('queued','failed');

CREATE TABLE subscriptions (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL UNIQUE REFERENCES tenants(id),
 provider text NOT NULL,
 provider_customer_ref text,
 provider_subscription_ref text,
 plan_code text NOT NULL,
 entitlements jsonb NOT NULL,
 status text NOT NULL CHECK (status IN ('trial','active','past_due','suspended','cancelled')),
 period_start timestamptz,
 period_end timestamptz,
 cancel_at_period_end boolean NOT NULL DEFAULT false,
 version bigint NOT NULL DEFAULT 1 CHECK (version>0),
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 CHECK (period_end IS NULL OR period_start IS NULL OR period_end>=period_start)
);

CREATE TABLE usage_buckets (
 tenant_id uuid NOT NULL REFERENCES tenants(id),
 metric_key text NOT NULL,
 period_start timestamptz NOT NULL,
 period_end timestamptz NOT NULL,
 value bigint NOT NULL DEFAULT 0 CHECK (value>=0),
 updated_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY (tenant_id,metric_key,period_start),
 CHECK (period_end>period_start)
);
-- Consumer idempotency uses domain_outbox dedupe key. A billing adapter can add
-- a ledger for external billing webhook IDs; these must not reuse store financial rows.

CREATE TABLE audit_log (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL REFERENCES tenants(id),
 actor_id uuid REFERENCES users(id),
 actor_kind text NOT NULL CHECK (actor_kind IN ('user','system','worker')),
 action text NOT NULL,
 entity_type text NOT NULL,
 entity_id text NOT NULL,
 request_id uuid,
 sanitized_changes jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX audit_tenant_time_idx ON audit_log(tenant_id,created_at DESC);

CREATE TABLE data_deletion_requests (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL REFERENCES tenants(id),
 requested_by uuid NOT NULL REFERENCES users(id),
 status text NOT NULL CHECK (status IN ('requested','revoking','deleting_objects','purging','completed','failed')),
 cursor jsonb NOT NULL DEFAULT '{}',
 error_code text,
 requested_at timestamptz NOT NULL,
 completed_at timestamptz
);

COMMIT;

-- Additional implementation-owned tables: Laravel sessions/password reset,
-- API idempotency records, nonce replay cache (Redis), worker identities/tokens,
-- hourly rollup (metric_buckets bucket_seconds=3600), application failed_jobs.
-- Their concrete framework schemas must be added in bootstrap with contracts/tests.
-- No DELETE CASCADE by default: tenant deletion must revoke external access and
-- delete object storage first, then delete domain rows in reverse dependency order.
