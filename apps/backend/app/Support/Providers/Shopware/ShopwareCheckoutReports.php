<?php

namespace App\Support\Providers\Shopware;

use App\Models\Integration;
use App\Support\Checkout\CheckoutObservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Checkout observation through the shop (ADR 0062): the app's script reports to the app's own storefront endpoint and
 * the shop keeps one row per report in the app's entity; the browser never contacts the service. This reads those rows
 * through the Admin API, adds them to the store's 5-minute windows and deletes them in the shop. Each row is counted
 * once: its id is remembered before the delete, so a failed delete only repeats the delete. It also keeps the app's
 * observe setting in line with the service's decision.
 */
class ShopwareCheckoutReports
{
    public const SETTING_ID = '0b1d5e8a2c4f4e6b9a7d3c1e5f2a4b6c';

    public const PAGE_SIZE = 100;

    public const MAX_PAGES = 10;

    public const SETTING_REFRESH_MINUTES = 60;

    public const COLLECTED_RETENTION_DAYS = 7;

    public function __construct(
        private readonly ShopwareClient $client,
        private readonly CheckoutObservation $observation,
    ) {}

    /**
     * @param  array<string, mixed>  $state  the previous `health.observation`
     * @return array<string, mixed> the new `health.observation`
     */
    public function collect(Integration $integration, string $baseUrl, string $token, array $state): array
    {
        $now = Carbon::now();
        $observe = $this->observation->decide($integration->id)['observe'];
        $setAt = isset($state['setting_at']) ? Carbon::parse($state['setting_at']) : null;

        if (($state['observe'] ?? null) !== $observe || $setAt === null || $setAt->lessThan($now->copy()->subMinutes(self::SETTING_REFRESH_MINUTES))) {
            $this->client->syncOwnEntities($baseUrl, $token, [['action' => 'upsert', 'entity' => ShopwareClient::SETTING_ENTITY, 'payload' => [['id' => self::SETTING_ID, 'observe' => $observe]]]]);
            $setAt = $now;
        }

        $counted = 0;
        $dropped = 0;
        $error = null;

        try {
            $this->drain($integration, $baseUrl, $token, $observe, $now, $counted, $dropped);
        } catch (ShopwareRequestFailed $failure) {
            $error = ['code' => $failure->reasonCode, 'at' => $now->toJSON()];
        }

        DB::table('checkout_observation_collected_reports')
            ->where('integration_id', $integration->id)
            ->where('collected_at', '<', $now->copy()->subDays(self::COLLECTED_RETENTION_DAYS))
            ->delete();

        return ['observe' => $observe, 'setting_at' => $setAt->toJSON(), 'last_collected_at' => $now->toJSON(), 'counted' => $counted, 'dropped' => $dropped, 'last_error' => $error];
    }

    private function drain(Integration $integration, string $baseUrl, string $token, bool $observe, Carbon $now, int &$counted, int &$dropped): void
    {
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $rows = $this->client->search($baseUrl, $token, ShopwareClient::REPORT_SEARCH_PATH, [
                'limit' => self::PAGE_SIZE,
                'sort' => [['field' => 'createdAt', 'order' => 'ASC']],
                'includes' => [ShopwareClient::REPORT_ENTITY => ['id', 'page', 'stages', 'errors', 'createdAt']],
            ])['data'] ?? [];
            $rows = array_values(array_filter(is_array($rows) ? $rows : [], fn (mixed $row): bool => is_array($row) && is_string($row['id'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $row['id']) === 1));

            if ($rows === []) {
                break;
            }

            if ($observe) {
                $counted += $this->count($integration, $rows, $now);
            } else {
                $dropped += count($rows);
            }

            $this->client->syncOwnEntities($baseUrl, $token, [['action' => 'delete', 'entity' => ShopwareClient::REPORT_ENTITY, 'payload' => array_map(fn (array $row): array => ['id' => $row['id']], $rows)]]);

            if (count($rows) < self::PAGE_SIZE) {
                break;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function count(Integration $integration, array $rows, Carbon $now): int
    {
        return DB::transaction(function () use ($integration, $rows, $now): int {
            $known = DB::table('checkout_observation_collected_reports')
                ->where('integration_id', $integration->id)
                ->whereIn('report_id', array_column($rows, 'id'))
                ->pluck('report_id')
                ->all();
            $fresh = array_values(array_filter($rows, fn (array $row): bool => ! in_array($row['id'], $known, true)));

            if ($fresh === []) {
                return 0;
            }

            DB::table('checkout_observation_collected_reports')->insert(array_map(fn (array $row): array => [
                'integration_id' => $integration->id, 'report_id' => $row['id'], 'tenant_id' => $integration->tenant_id, 'collected_at' => $now,
            ], $fresh));

            $windows = [];

            foreach ($fresh as $row) {
                $counts = CheckoutObservation::reportCounts((string) ($row['page'] ?? ''), (string) ($row['stages'] ?? ''), is_int($row['errors'] ?? null) ? max(0, $row['errors']) : 0);

                if ($counts === []) {
                    continue;
                }

                $at = is_string($row['createdAt'] ?? null) ? Carbon::parse($row['createdAt'])->utc()->min($now) : $now;
                $start = CheckoutObservation::windowStart($at)->toJSON();

                foreach ($counts as $counter => $count) {
                    $windows[$start][$counter] = ($windows[$start][$counter] ?? 0) + $count;
                }
            }

            foreach ($windows as $start => $counts) {
                $this->observation->add($integration, Carbon::parse($start), $counts);
            }

            return count($fresh);
        });
    }
}
