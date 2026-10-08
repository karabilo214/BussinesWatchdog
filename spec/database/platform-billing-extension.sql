-- Business Watchdog schema extension 1.1, PostgreSQL18.
-- Apply AFTER schema.sql to empty/reference database. Production uses reviewed Laravel migrations.
-- SaaS billing is separate from monitored-store payments/financial_transactions.
BEGIN;

CREATE TABLE platform_staff (
 id uuid PRIMARY KEY,
 user_id uuid NOT NULL UNIQUE REFERENCES users(id),
 role text NOT NULL CHECK (role IN ('platform_owner','platform_admin','platform_support','platform_billing')),
 state text NOT NULL CHECK (state IN ('active','disabled')),
 mfa_required boolean NOT NULL DEFAULT true CHECK (mfa_required=true),
 permission_version bigint NOT NULL DEFAULT 1 CHECK (permission_version>0),
 created_at timestamptz NOT NULL DEFAULT now(),
 disabled_at timestamptz
);
-- Last active owner protection is transactional under an owner-role advisory lock.
CREATE TABLE platform_staff_invitations (
 id uuid PRIMARY KEY,
 email text NOT NULL,
 role text NOT NULL CHECK (role IN ('platform_owner','platform_admin','platform_support','platform_billing')),
 token_hash char(64) NOT NULL UNIQUE,
 invited_by uuid NOT NULL REFERENCES platform_staff(id),
 expires_at timestamptz NOT NULL,
 accepted_at timestamptz,
 revoked_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE platform_audit_log (
 id uuid PRIMARY KEY,
 actor_staff_id uuid REFERENCES platform_staff(id),
 actor_kind text NOT NULL CHECK (actor_kind IN ('staff','system')),
 action text NOT NULL,
 target_tenant_id uuid,
 target_type text NOT NULL,
 target_id text NOT NULL,
 reason text,
 request_id uuid NOT NULL,
 sanitized_changes jsonb NOT NULL,
 result text NOT NULL CHECK (result IN ('requested','succeeded','failed','denied','unknown')),
 created_at timestamptz NOT NULL DEFAULT now()
);
-- target_tenant_id intentionally no FK: minimum operational audit survives domain purge.
CREATE INDEX platform_audit_time_idx ON platform_audit_log(created_at DESC);
CREATE INDEX platform_audit_target_idx ON platform_audit_log(target_tenant_id,created_at DESC);

CREATE TABLE support_access_grants (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL REFERENCES tenants(id),
 staff_id uuid NOT NULL REFERENCES platform_staff(id),
 requested_by uuid NOT NULL REFERENCES platform_staff(id),
 approved_by uuid REFERENCES users(id),
 scopes jsonb NOT NULL,
 reason text NOT NULL,
 state text NOT NULL CHECK (state IN ('pending','approved','denied','revoked','expired')),
 expires_at timestamptz NOT NULL,
 approved_at timestamptz,
 revoked_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,id)
);
CREATE INDEX support_grants_lookup_idx ON support_access_grants(tenant_id,staff_id,state,expires_at);

CREATE TABLE billing_plans (
 id uuid PRIMARY KEY,
 code text NOT NULL UNIQUE,
 state text NOT NULL CHECK (state IN ('draft','published','retired')),
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE billing_plan_versions (
 id uuid PRIMARY KEY,
 plan_id uuid NOT NULL REFERENCES billing_plans(id),
 version bigint NOT NULL CHECK (version>0),
 state text NOT NULL CHECK (state IN ('draft','published','retired')),
 localized_content jsonb NOT NULL,
 entitlements jsonb NOT NULL,
 trial_days integer NOT NULL DEFAULT 14 CHECK (trial_days BETWEEN 0 AND 90),
 created_by uuid NOT NULL REFERENCES platform_staff(id),
 published_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (plan_id,version)
);
CREATE TABLE billing_prices (
 id uuid PRIMARY KEY,
 plan_version_id uuid NOT NULL REFERENCES billing_plan_versions(id),
 provider text NOT NULL,
 provider_account_ref text NOT NULL,
 provider_price_ref text,
 mode text NOT NULL CHECK (mode IN ('live','test')),
 currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
 currency_exponent smallint NOT NULL CHECK (currency_exponent BETWEEN 0 AND 6),
 amount_minor bigint NOT NULL CHECK (amount_minor>=0),
 billing_interval text NOT NULL CHECK (billing_interval IN ('month','year')),
 tax_behavior text NOT NULL CHECK (tax_behavior IN ('inclusive','exclusive','provider_calculated')),
 state text NOT NULL CHECK (state IN ('draft','published','retired')),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (provider,provider_account_ref,mode,provider_price_ref),
 UNIQUE (plan_version_id,currency,billing_interval,mode)
);
CREATE INDEX billing_prices_public_idx ON billing_prices(state,mode,currency,billing_interval);

CREATE TABLE billing_customers (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL REFERENCES tenants(id),
 provider text NOT NULL,
 provider_account_ref text NOT NULL,
 provider_customer_ref text NOT NULL,
 mode text NOT NULL CHECK (mode IN ('live','test')),
 profile_ciphertext bytea,
 key_version integer,
 source_updated_at timestamptz NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,provider,provider_account_ref,mode),
 UNIQUE (provider,provider_account_ref,mode,provider_customer_ref),
 UNIQUE (tenant_id,id)
);
CREATE TABLE billing_subscription_contracts (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 customer_id uuid,
 plan_version_id uuid NOT NULL REFERENCES billing_plan_versions(id),
 price_id uuid REFERENCES billing_prices(id),
 provider text NOT NULL,
 provider_account_ref text NOT NULL,
 provider_subscription_ref text,
 mode text NOT NULL CHECK (mode IN ('live','test')),
 normalized_state text NOT NULL CHECK (normalized_state IN ('trial','pending','requires_action','active','past_due','unpaid','cancelled','unknown')),
 provider_state text,
 currency varchar(3),
 entitlement_snapshot jsonb NOT NULL,
 period_start timestamptz,
 period_end timestamptz,
 paid_until timestamptz,
 cancel_at_period_end boolean NOT NULL DEFAULT false,
 next_charge_at timestamptz,
 source_updated_at timestamptz NOT NULL,
 version bigint NOT NULL DEFAULT 1 CHECK (version>0),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,id),
 UNIQUE (provider,provider_account_ref,mode,provider_subscription_ref),
 FOREIGN KEY (tenant_id) REFERENCES tenants(id),
 FOREIGN KEY (tenant_id,customer_id) REFERENCES billing_customers(tenant_id,id),
 CHECK (period_end IS NULL OR period_start IS NULL OR period_end>=period_start)
);
-- Application tenant lock prevents concurrent active commercial contracts;
-- history/cancelled/test contracts are intentionally not erased.

CREATE TABLE billing_checkout_sessions (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 actor_user_id uuid NOT NULL REFERENCES users(id),
 customer_id uuid,
 contract_id uuid,
 price_id uuid NOT NULL REFERENCES billing_prices(id),
 provider text NOT NULL,
 provider_account_ref text NOT NULL,
 provider_session_ref text,
 mode text NOT NULL CHECK (mode IN ('live','test')),
 status text NOT NULL CHECK (status IN ('creating','pending','requires_action','confirmed','failed','cancelled','expired','unknown')),
 idempotency_key text NOT NULL,
 request_hash char(64) NOT NULL,
 return_path text NOT NULL,
 expires_at timestamptz NOT NULL,
 confirmed_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,id),
 UNIQUE (tenant_id,idempotency_key),
 UNIQUE (provider,provider_account_ref,mode,provider_session_ref),
 FOREIGN KEY (tenant_id) REFERENCES tenants(id),
 FOREIGN KEY (tenant_id,customer_id) REFERENCES billing_customers(tenant_id,id),
 FOREIGN KEY (tenant_id,contract_id) REFERENCES billing_subscription_contracts(tenant_id,id)
);
CREATE INDEX billing_checkout_pending_idx ON billing_checkout_sessions(status,expires_at);

CREATE TABLE billing_event_inbox (
 id uuid PRIMARY KEY,
 provider text NOT NULL,
 provider_account_ref text NOT NULL,
 mode text NOT NULL CHECK (mode IN ('live','test')),
 provider_event_ref text NOT NULL,
 event_type text NOT NULL,
 entity_type text NOT NULL,
 entity_ref text NOT NULL,
 tenant_id uuid,
 sanitized_payload jsonb NOT NULL,
 payload_hash char(64) NOT NULL,
 api_version text NOT NULL,
 status text NOT NULL CHECK (status IN ('received','processing','processed','ignored','quarantined','dead_letter')),
 attempts integer NOT NULL DEFAULT 0 CHECK (attempts>=0),
 next_attempt_at timestamptz NOT NULL DEFAULT now(),
 lease_until timestamptz,
 occurred_at timestamptz NOT NULL,
 received_at timestamptz NOT NULL DEFAULT now(),
 processed_at timestamptz,
 error_code text,
 UNIQUE (provider,provider_account_ref,mode,provider_event_ref)
);
-- Unmatched or deleted customer billing event retained without tenant FK.
CREATE INDEX billing_inbox_due_idx ON billing_event_inbox(next_attempt_at) WHERE status IN ('received','processing');

CREATE TABLE billing_invoices (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 contract_id uuid NOT NULL,
 provider text NOT NULL,
 provider_account_ref text NOT NULL,
 provider_invoice_ref text NOT NULL,
 number text,
 mode text NOT NULL CHECK (mode IN ('live','test')),
 status text NOT NULL CHECK (status IN ('draft','open','paid','void','uncollectible','unknown')),
 currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
 currency_exponent smallint NOT NULL CHECK (currency_exponent BETWEEN 0 AND 6),
 subtotal_minor bigint NOT NULL CHECK (subtotal_minor>=0),
 discount_minor bigint NOT NULL DEFAULT 0 CHECK (discount_minor>=0),
 tax_minor bigint NOT NULL DEFAULT 0 CHECK (tax_minor>=0),
 total_minor bigint NOT NULL CHECK (total_minor>=0),
 paid_minor bigint NOT NULL CHECK (paid_minor>=0),
 due_minor bigint NOT NULL CHECK (due_minor>=0),
 service_period_start timestamptz,
 service_period_end timestamptz,
 issued_at timestamptz,
 due_at timestamptz,
 paid_at timestamptz,
 source_updated_at timestamptz NOT NULL,
 document_object_key text,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,id),
 UNIQUE (provider,provider_account_ref,mode,provider_invoice_ref),
 FOREIGN KEY (tenant_id,contract_id) REFERENCES billing_subscription_contracts(tenant_id,id)
);
CREATE INDEX billing_invoice_tenant_idx ON billing_invoices(tenant_id,status,issued_at DESC);

CREATE TABLE billing_payment_attempts (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 invoice_id uuid NOT NULL,
 provider_attempt_ref text NOT NULL,
 provider_operation_ref text,
 status text NOT NULL CHECK (status IN ('pending','requires_action','succeeded','failed','cancelled','unknown')),
 currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
 amount_minor bigint NOT NULL CHECK (amount_minor>=0),
 failure_code text,
 succeeded_at timestamptz,
 source_updated_at timestamptz NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,id),
 UNIQUE (invoice_id,provider_attempt_ref),
 FOREIGN KEY (tenant_id,invoice_id) REFERENCES billing_invoices(tenant_id,id)
);
CREATE UNIQUE INDEX billing_success_operation_idx ON billing_payment_attempts(invoice_id,provider_operation_ref) WHERE provider_operation_ref IS NOT NULL;

CREATE TABLE billing_refunds (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 payment_attempt_id uuid NOT NULL,
 provider_refund_ref text,
 operation_key text NOT NULL UNIQUE,
 currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
 amount_minor bigint NOT NULL CHECK (amount_minor>0),
 status text NOT NULL CHECK (status IN ('requested','approved','pending','succeeded','failed','denied','unknown')),
 reason text NOT NULL,
 requested_by uuid NOT NULL REFERENCES platform_staff(id),
 approved_by uuid REFERENCES platform_staff(id),
 succeeded_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (tenant_id,id),
 UNIQUE (payment_attempt_id,provider_refund_ref),
 FOREIGN KEY (tenant_id,payment_attempt_id) REFERENCES billing_payment_attempts(tenant_id,id)
);
-- Refund pending+succeeded reservations under payment lock cannot exceed refundable remainder.

CREATE TABLE entitlement_overrides (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL REFERENCES tenants(id),
 entitlements jsonb NOT NULL,
 reason text NOT NULL,
 created_by uuid NOT NULL REFERENCES platform_staff(id),
 approved_by uuid NOT NULL REFERENCES platform_staff(id),
 starts_at timestamptz NOT NULL,
 ends_at timestamptz NOT NULL,
 revoked_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 CHECK (ends_at>starts_at)
);
CREATE INDEX overrides_active_idx ON entitlement_overrides(tenant_id,starts_at,ends_at) WHERE revoked_at IS NULL;

CREATE TABLE platform_actions (
 id uuid PRIMARY KEY,
 actor_kind text NOT NULL CHECK (actor_kind IN ('staff','customer','system')),
 actor_staff_id uuid REFERENCES platform_staff(id),
 actor_user_id uuid REFERENCES users(id),
 target_tenant_id uuid,
 action_type text NOT NULL,
 operation_key text NOT NULL UNIQUE,
 request_hash char(64) NOT NULL,
 expected_version bigint,
 sanitized_parameters jsonb NOT NULL,
 reason text NOT NULL,
 status text NOT NULL CHECK (status IN ('queued','running','succeeded','failed','unknown','cancelled')),
 stage text,
 attempts integer NOT NULL DEFAULT 0 CHECK (attempts>=0),
 next_attempt_at timestamptz NOT NULL DEFAULT now(),
 lease_until timestamptz,
 result_refs jsonb NOT NULL DEFAULT '{}',
 error_code text,
 created_at timestamptz NOT NULL DEFAULT now(),
 finished_at timestamptz,
 CHECK ((actor_kind='staff' AND actor_staff_id IS NOT NULL AND actor_user_id IS NULL)
 OR (actor_kind='customer' AND actor_user_id IS NOT NULL AND actor_staff_id IS NULL)
 OR (actor_kind='system' AND actor_user_id IS NULL AND actor_staff_id IS NULL))
);
CREATE INDEX platform_actions_due_idx ON platform_actions(next_attempt_at) WHERE status IN ('queued','running');

CREATE TABLE usage_entries (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL REFERENCES tenants(id),
 metric_key text NOT NULL,
 period_start timestamptz NOT NULL,
 operation_key text NOT NULL,
 delta bigint NOT NULL CHECK (delta<>0),
 reason_code text NOT NULL,
 occurred_at timestamptz NOT NULL,
 UNIQUE (tenant_id,metric_key,operation_key)
);

CREATE TABLE public_content_pages (
 id uuid PRIMARY KEY,
 slug text NOT NULL,
 locale text NOT NULL,
 version bigint NOT NULL CHECK (version>0),
 title text NOT NULL,
 sanitized_body text NOT NULL,
 metadata jsonb NOT NULL DEFAULT '{}',
 state text NOT NULL CHECK (state IN ('draft','published','retired')),
 created_by uuid NOT NULL REFERENCES platform_staff(id),
 published_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (slug,locale,version)
);
CREATE UNIQUE INDEX content_current_published ON public_content_pages(slug,locale) WHERE state='published';

CREATE TABLE policy_versions (
 id uuid PRIMARY KEY,
 policy_type text NOT NULL CHECK (policy_type IN ('service_terms','recurring_payment','privacy_notice')),
 locale text NOT NULL,
 version text NOT NULL,
 content_hash char(64) NOT NULL,
 public_content_page_id uuid NOT NULL REFERENCES public_content_pages(id),
 effective_at timestamptz NOT NULL,
 retired_at timestamptz,
 UNIQUE (policy_type,locale,version)
);
CREATE TABLE purchase_acceptances (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL REFERENCES tenants(id),
 user_id uuid NOT NULL REFERENCES users(id),
 policy_version_id uuid NOT NULL REFERENCES policy_versions(id),
 checkout_id uuid,
 purpose text NOT NULL CHECK (purpose IN ('signup','trial','subscription_purchase','plan_change')),
 accepted_at timestamptz NOT NULL,
 FOREIGN KEY (tenant_id,checkout_id) REFERENCES billing_checkout_sessions(tenant_id,id)
);

CREATE TABLE deletion_tombstones (
 tenant_uuid uuid PRIMARY KEY,
 deletion_request_ref text NOT NULL,
 completed_at timestamptz NOT NULL,
 restore_block_until timestamptz,
 journal_version bigint NOT NULL CHECK (journal_version>0)
);
-- No FK: this operational journal must survive tenant row removal/backup restore.

ALTER TABLE subscriptions ADD COLUMN active_contract_id uuid;
ALTER TABLE subscriptions ADD COLUMN access_state text NOT NULL DEFAULT 'onboarding'
 CHECK (access_state IN ('onboarding','trial','active','grace','paused_billing','paused_manual','expired','deleting','capacity_choice_required'));
ALTER TABLE subscriptions ADD COLUMN access_reason_codes jsonb NOT NULL DEFAULT '[]';
ALTER TABLE subscriptions ADD COLUMN paid_until timestamptz;
ALTER TABLE subscriptions ADD COLUMN trial_started_at timestamptz;
ALTER TABLE subscriptions ADD COLUMN trial_ends_at timestamptz;
ALTER TABLE subscriptions ADD COLUMN trial_consumed_at timestamptz;
ALTER TABLE subscriptions ADD COLUMN grace_until timestamptz;
ALTER TABLE subscriptions ADD COLUMN manual_suspended_at timestamptz;
ALTER TABLE subscriptions ADD COLUMN entitlement_version bigint NOT NULL DEFAULT 1 CHECK (entitlement_version>0);
ALTER TABLE subscriptions ADD CONSTRAINT subscription_active_contract_fk
 FOREIGN KEY (tenant_id,active_contract_id) REFERENCES billing_subscription_contracts(tenant_id,id);

COMMIT;
-- Implementation additionally needs billing coverage watermarks/preview tokens and staff sessions
-- with framework-specific schemas. Never store a staff session in a customer guard cookie.
-- Policy matching, external account/mode and currency consistency are transactionally checked.
