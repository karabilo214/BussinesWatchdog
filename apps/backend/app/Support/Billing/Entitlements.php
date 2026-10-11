<?php

namespace App\Support\Billing;

/**
 * What one tenant may use right now and why. `enforced` is false in pilot mode: the result is reported but every
 * check allows (ADR 0029).
 */
final class Entitlements
{
    public const ACCESS_PILOT = 'pilot';

    public const ACCESS_ACTIVE = 'active';

    public const ACCESS_OVERRIDE = 'override';

    public const ACCESS_TRIAL = 'trial';

    public const ACCESS_GRACE = 'grace';

    public const ACCESS_PAUSED_BILLING = 'paused_billing';

    public const ACCESS_PAUSED_MANUAL = 'paused_manual';

    public const ACCESS_NO_SERVICE = 'no_service';

    public const ACCESS_ONBOARDING = 'onboarding';

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $features
     * @param  array<string, ?int>  $limits
     */
    public function __construct(
        public readonly string $access,
        public readonly array $reasons,
        public readonly ?string $planCode,
        public readonly array $features,
        public readonly array $limits,
        public readonly bool $enforced,
    ) {}

    public function serviceActive(): bool
    {
        return in_array($this->access, [self::ACCESS_PILOT, self::ACCESS_ACTIVE, self::ACCESS_OVERRIDE, self::ACCESS_TRIAL, self::ACCESS_GRACE], true);
    }

    public function allows(string $feature): bool
    {
        return ! $this->enforced || ($this->serviceActive() && in_array($feature, $this->features, true));
    }

    /** The limit value, or null when there is none (or the check is not enforced). */
    public function limit(string $limit): ?int
    {
        return $this->enforced ? ($this->limits[$limit] ?? null) : null;
    }

    /** Why a feature is (not) available: `included`, `not_in_plan` or the access state that pauses the service. */
    public function reasonFor(string $feature): string
    {
        if (! $this->serviceActive()) {
            return $this->access;
        }

        return in_array($feature, $this->features, true) ? 'included' : 'not_in_plan';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'access_state' => $this->access,
            'reason_codes' => $this->reasons,
            'enforced' => $this->enforced,
            'plan_code' => $this->planCode,
            'features' => array_map(fn (string $feature): array => [
                'code' => $feature,
                'included' => $this->allows($feature),
                'reason' => $this->enforced ? $this->reasonFor($feature) : 'pilot',
            ], FeatureCatalog::FEATURES),
            'limits' => array_map(fn (string $limit): ?int => $this->limits[$limit] ?? null, array_combine(FeatureCatalog::LIMITS, FeatureCatalog::LIMITS)),
        ];
    }
}
