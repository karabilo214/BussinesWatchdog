<?php

namespace App\Support\Ingest;

use App\Models\EventInbox;
use Illuminate\Support\Str;

class EventPayloadValidator
{
    private const MAX_SIGNED_BIGINT = '9223372036854775807';

    public function __construct(
        private readonly EventSchemaValidator $schemaValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function validate(array $event, mixed $rawEvent = null): EventValidationResult
    {
        foreach (['schema_version', 'event_id', 'type', 'aggregate_type', 'aggregate_id', 'occurred_at', 'observed_at', 'is_synthetic', 'data'] as $field) {
            if (! array_key_exists($field, $event)) {
                return EventValidationResult::invalid(EventValidationResult::ERROR_SCHEMA_INVALID);
            }
        }

        if (! $this->hasPersistableIdentity($event)) {
            return EventValidationResult::invalid(EventValidationResult::ERROR_SCHEMA_INVALID);
        }

        if (! $this->hasOnlyKeys($event, ['schema_version', 'event_id', 'type', 'aggregate_type', 'aggregate_id', 'aggregate_revision', 'occurred_at', 'observed_at', 'is_synthetic', 'data'])) {
            return EventValidationResult::invalid(EventValidationResult::ERROR_SCHEMA_INVALID, true);
        }

        if ($event['schema_version'] !== '1.0') {
            return EventValidationResult::invalid(EventValidationResult::ERROR_SCHEMA_UNSUPPORTED, true);
        }

        if (! EventInbox::supportsEventType($event['type']) || ! EventInbox::supportsAggregateType($event['aggregate_type'])) {
            return EventValidationResult::invalid(EventValidationResult::ERROR_SCHEMA_INVALID, true);
        }

        if (mb_strlen($event['aggregate_id']) > 255) {
            return EventValidationResult::invalid(EventValidationResult::ERROR_SCHEMA_INVALID, true);
        }

        if (array_key_exists('aggregate_revision', $event) && (! is_int($event['aggregate_revision']) || $event['aggregate_revision'] < 0)) {
            return EventValidationResult::invalid(EventValidationResult::ERROR_SCHEMA_INVALID, true);
        }

        if (! is_bool($event['is_synthetic']) || ! is_array($event['data'])) {
            return EventValidationResult::invalid(EventValidationResult::ERROR_SCHEMA_INVALID, true);
        }

        if (! $this->isDateTime($event['occurred_at']) || ! $this->isDateTime($event['observed_at'])) {
            return EventValidationResult::invalid(EventValidationResult::ERROR_SCHEMA_INVALID, true);
        }

        if (strtotime($event['observed_at']) > time() + 300 || strtotime($event['occurred_at']) > time() + 300) {
            return EventValidationResult::invalid(EventValidationResult::ERROR_CLOCK_SKEW, true);
        }

        if (! $this->matchesTypeContract($event)) {
            return EventValidationResult::invalid(EventValidationResult::ERROR_SCHEMA_INVALID, true);
        }

        if ($rawEvent !== null && ! $this->schemaValidator->isValid($rawEvent)) {
            return EventValidationResult::invalid(EventValidationResult::ERROR_SCHEMA_INVALID, true);
        }

        return EventValidationResult::ok();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function hasPersistableIdentity(array $event): bool
    {
        return is_string($event['schema_version'])
            && is_string($event['event_id'])
            && Str::isUuid($event['event_id'])
            && is_string($event['type'])
            && is_string($event['aggregate_type'])
            && is_string($event['aggregate_id'])
            && $event['aggregate_id'] !== '';
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function matchesTypeContract(array $event): bool
    {
        /** @var array<string, mixed> $data */
        $data = $event['data'];

        return match ($event['type']) {
            EventInbox::EVENT_ORDER_SNAPSHOT => $event['aggregate_type'] === EventInbox::AGGREGATE_ORDER
                && array_key_exists('aggregate_revision', $event)
                && $this->hasOnlyKeys($data, ['status', 'display_number', 'currency', 'currency_exponent', 'total_minor', 'gateway', 'transaction_ref', 'payment_expected', 'paid_marked_at', 'source_created_at', 'source_updated_at', 'mode', 'financial_support'])
                && $this->isRequiredString($data, 'status')
                && $this->isOptionalString($data, 'display_number')
                && $this->isCurrencyData($data)
                && $this->isMinorUnits($data['total_minor'] ?? null)
                && $this->isOptionalString($data, 'gateway', nullable: true)
                && $this->isOptionalString($data, 'transaction_ref', nullable: true)
                && is_bool($data['payment_expected'] ?? null)
                && $this->isOptionalDateTime($data, 'paid_marked_at', nullable: true)
                && $this->isOptionalDateTime($data, 'source_created_at')
                && $this->isOptionalDateTime($data, 'source_updated_at')
                && $this->isOptionalEnum($data, 'mode', ['live', 'test'])
                && $this->isOptionalEnum($data, 'financial_support', ['supported', 'unsupported', 'unknown']),
            EventInbox::EVENT_ORDER_DELETED => $event['aggregate_type'] === EventInbox::AGGREGATE_ORDER
                && array_key_exists('aggregate_revision', $event)
                && $this->hasOnlyKeys($data, ['reason_code'])
                && $this->isRequiredString($data, 'reason_code'),
            EventInbox::EVENT_REFUND_SNAPSHOT => $event['aggregate_type'] === EventInbox::AGGREGATE_REFUND
                && array_key_exists('aggregate_revision', $event)
                && $this->hasOnlyKeys($data, ['order_id', 'currency', 'currency_exponent', 'amount_minor', 'external_required', 'provider_ref', 'status'])
                && $this->isRequiredString($data, 'order_id')
                && $this->isCurrencyData($data)
                && $this->isMinorUnits($data['amount_minor'] ?? null)
                && (is_bool($data['external_required'] ?? null) || ($data['external_required'] ?? null) === null)
                && $this->isOptionalString($data, 'provider_ref', nullable: true)
                && $this->isRequiredEnum($data, 'status', ['requested', 'recorded', 'cancelled', 'deleted']),
            EventInbox::EVENT_PAYMENT_SNAPSHOT => $event['aggregate_type'] === EventInbox::AGGREGATE_PAYMENT
                && $this->hasOnlyKeys($data, ['intent_ref', 'charge_ref', 'mode', 'currency', 'currency_exponent', 'status', 'source_updated_at', 'source_authority'])
                && $this->isOptionalString($data, 'intent_ref', nullable: true)
                && $this->isOptionalString($data, 'charge_ref', nullable: true)
                && $this->isRequiredEnum($data, 'mode', ['live', 'test'])
                && $this->isCurrencyData($data)
                && $this->isRequiredEnum($data, 'status', ['pending', 'authorized', 'captured', 'failed', 'cancelled', 'unknown'])
                && is_string($data['source_updated_at'] ?? null)
                && $this->isDateTime($data['source_updated_at'])
                && $this->isRequiredEnum($data, 'source_authority', ['store_reported', 'independent_provider']),
            EventInbox::EVENT_TRANSACTION_OBSERVED => $event['aggregate_type'] === EventInbox::AGGREGATE_TRANSACTION
                && $this->hasOnlyKeys($data, ['external_operation_id', 'payment_external_id', 'kind', 'status', 'currency', 'currency_exponent', 'amount_minor', 'source_authority'])
                && $this->isRequiredString($data, 'external_operation_id')
                && $this->isOptionalString($data, 'payment_external_id', nullable: true)
                && $this->isRequiredEnum($data, 'kind', ['capture', 'refund', 'fee', 'dispute_debit', 'dispute_credit', 'adjustment'])
                && $this->isRequiredEnum($data, 'status', ['pending', 'succeeded', 'failed', 'cancelled'])
                && $this->isCurrencyData($data)
                && $this->isMinorUnits($data['amount_minor'] ?? null)
                && $this->isRequiredEnum($data, 'source_authority', ['store_reported', 'independent_provider']),
            EventInbox::EVENT_INTEGRATION_HEARTBEAT => $event['aggregate_type'] === EventInbox::AGGREGATE_INTEGRATION
                && $this->hasOnlyKeys($data, ['backlog_count', 'oldest_pending_at', 'plugin_version'])
                && is_int($data['backlog_count'] ?? null)
                && $data['backlog_count'] >= 0
                && $this->isOptionalDateTime($data, 'oldest_pending_at', nullable: true)
                && $this->isRequiredString($data, 'plugin_version'),
            EventInbox::EVENT_INTEGRATION_CAPABILITIES_CHANGED => $event['aggregate_type'] === EventInbox::AGGREGATE_INTEGRATION
                && $this->hasOnlyKeys($data, ['hpos', 'checkout_mode', 'order_snapshots', 'refund_snapshots', 'payment_form_check', 'funnel_telemetry'])
                && $this->isOptionalBoolean($data, 'hpos')
                && $this->isRequiredEnum($data, 'checkout_mode', ['classic', 'blocks', 'custom', 'unknown'])
                && is_bool($data['order_snapshots'] ?? null)
                && $this->isOptionalBoolean($data, 'refund_snapshots')
                && $this->isOptionalBoolean($data, 'payment_form_check')
                && $this->isOptionalBoolean($data, 'funnel_telemetry'),
            EventInbox::EVENT_DEPLOYMENT_OBSERVED => $event['aggregate_type'] === EventInbox::AGGREGATE_DEPLOYMENT
                && $this->hasOnlyKeys($data, ['component', 'component_id', 'old_version', 'new_version', 'observation_method'])
                && $this->isRequiredEnum($data, 'component', ['wordpress', 'woocommerce', 'theme', 'plugin', 'asset'])
                && $this->isRequiredString($data, 'component_id')
                && $this->isOptionalString($data, 'old_version', nullable: true)
                && $this->isRequiredString($data, 'new_version')
                && $this->isRequiredEnum($data, 'observation_method', ['version_scan', 'hash_scan', 'explicit_hook']),
            EventInbox::EVENT_FUNNEL_OBSERVED => $event['aggregate_type'] === EventInbox::AGGREGATE_SESSION
                && $this->hasOnlyKeys($data, ['stage', 'session_id', 'consent_scope', 'order_id'])
                && $this->isRequiredEnum($data, 'stage', ['cart_observed', 'checkout_observed', 'payment_attempt_observed', 'purchase_confirmed'])
                && is_string($data['session_id'] ?? null)
                && Str::isUuid($data['session_id'])
                && $this->isRequiredEnum($data, 'consent_scope', ['analytics_opt_in', 'server_transactional'])
                && $this->isOptionalString($data, 'order_id', nullable: true),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function hasOnlyKeys(array $data, array $keys): bool
    {
        return count(array_diff(array_keys($data), $keys)) === 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isCurrencyData(array $data): bool
    {
        return is_string($data['currency'] ?? null)
            && preg_match('/^[A-Z]{3}$/', $data['currency']) === 1
            && is_int($data['currency_exponent'] ?? null)
            && $data['currency_exponent'] >= 0
            && $data['currency_exponent'] <= 6;
    }

    private function isMinorUnits(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]{0,18})$/', $value) !== 1) {
            return false;
        }

        return strlen($value) < strlen(self::MAX_SIGNED_BIGINT)
            || strcmp($value, self::MAX_SIGNED_BIGINT) <= 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isRequiredString(array $data, string $key): bool
    {
        return is_string($data[$key] ?? null)
            && $data[$key] !== ''
            && mb_strlen($data[$key]) <= 255;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isOptionalString(array $data, string $key, bool $nullable = false): bool
    {
        if (! array_key_exists($key, $data)) {
            return true;
        }

        if ($nullable && $data[$key] === null) {
            return true;
        }

        return is_string($data[$key]) && mb_strlen($data[$key]) <= 255;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $values
     */
    private function isRequiredEnum(array $data, string $key, array $values): bool
    {
        return in_array($data[$key] ?? null, $values, true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $values
     */
    private function isOptionalEnum(array $data, string $key, array $values): bool
    {
        return ! array_key_exists($key, $data) || in_array($data[$key], $values, true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isOptionalDateTime(array $data, string $key, bool $nullable = false): bool
    {
        if (! array_key_exists($key, $data)) {
            return true;
        }

        if ($nullable && $data[$key] === null) {
            return true;
        }

        return $this->isDateTime($data[$key]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isOptionalBoolean(array $data, string $key): bool
    {
        return ! array_key_exists($key, $data) || is_bool($data[$key]);
    }

    private function isDateTime(mixed $value): bool
    {
        return is_string($value) && strtotime($value) !== false;
    }
}
