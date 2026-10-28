<?php

namespace App\Support\Plugin;

use App\Models\PluginFunnelEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The plugin connection funnel (ADR 0084): a journey id lives in the server session from the click to the sign-up;
 * after that the organization's sign-up row links the later stages. Each stage is counted at most once.
 */
class PluginFunnel
{
    public const SESSION_KEY = 'plugin_funnel';

    public const RETENTION_MONTHS = 13;

    public function click(Request $request, string $placement, string $path, string $locale): string
    {
        $journey = Str::lower(Str::random(32));
        $request->session()->put(self::SESSION_KEY, ['journey' => $journey, 'placement' => $placement, 'path' => $path, 'locale' => $locale]);

        if ($request->isMethod('HEAD')) {
            return $journey;
        }

        $this->record(PluginFunnelEvent::STAGE_CLICK, "click:{$journey}", ['journey' => $journey, 'placement' => $placement, 'path' => $path, 'locale' => $locale]);

        return $journey;
    }

    public function signupView(Request $request, string $locale): void
    {
        $journey = $this->journey($request);

        if ($journey !== null) {
            $this->record(PluginFunnelEvent::STAGE_SIGNUP_VIEW, "signup_view:{$journey['journey']}", $journey + ['locale' => $locale]);
        }
    }

    public function signup(Request $request, string $tenantId, string $locale): void
    {
        $journey = $this->journey($request);

        if ($journey !== null) {
            $this->record(PluginFunnelEvent::STAGE_SIGNUP, "signup:{$tenantId}", $journey + ['locale' => $locale, 'tenant_id' => $tenantId]);
        }
    }

    public function code(string $tenantId, string $storeId, ?string $journey = null): void
    {
        $this->record(PluginFunnelEvent::STAGE_CODE, "code:{$tenantId}:{$storeId}", $this->attribution($tenantId, $journey) + ['tenant_id' => $tenantId, 'store_id' => $storeId]);
    }

    public function connected(string $tenantId, string $storeId, ?string $journey = null): void
    {
        $this->record(PluginFunnelEvent::STAGE_CONNECTED, "connected:{$tenantId}:{$storeId}", $this->attribution($tenantId, $journey) + ['tenant_id' => $tenantId, 'store_id' => $storeId]);
    }

    /**
     * @return array{journey: string, placement: ?string, path: ?string}|null
     */
    public function journey(Request $request): ?array
    {
        $value = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (! is_array($value) || ! is_string($value['journey'] ?? null)) {
            return null;
        }

        return ['journey' => $value['journey'], 'placement' => $value['placement'] ?? null, 'path' => $value['path'] ?? null];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(Carbon $from, Carbon $to): array
    {
        $rows = PluginFunnelEvent::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->selectRaw('stage, placement, journey IS NOT NULL AS attributed, COUNT(*) AS total')
            ->groupBy('stage', 'placement', 'attributed')
            ->get();

        $stages = [];
        $placements = [];

        foreach (PluginFunnelEvent::STAGES as $stage) {
            $stageRows = $rows->where('stage', $stage);
            $attributed = (int) $stageRows->filter(fn ($row): bool => (bool) $row->attributed)->sum('total');
            $stages[] = ['stage' => $stage, 'attributed' => $attributed, 'total' => (int) $stageRows->sum('total')];

            foreach ($stageRows->filter(fn ($row): bool => (bool) $row->attributed) as $row) {
                $key = $row->placement ?? 'unknown';
                $placements[$key][$stage] = ($placements[$key][$stage] ?? 0) + (int) $row->total;
            }
        }

        foreach ($stages as $index => $stage) {
            $previous = $index === 0 ? null : $stages[$index - 1]['attributed'];
            $stages[$index]['conversion_percent'] = $previous === null || $previous === 0 ? null : round($stage['attributed'] * 100 / $previous, 1);
        }

        ksort($placements);

        return [
            'from' => $from->toJSON(),
            'to' => $to->toJSON(),
            'stages' => $stages,
            'placements' => array_map(
                fn (string $placement, array $counts): array => ['placement' => $placement] + array_combine(PluginFunnelEvent::STAGES, array_map(fn (string $stage): int => $counts[$stage] ?? 0, PluginFunnelEvent::STAGES)),
                array_keys($placements),
                $placements,
            ),
        ];
    }

    public function prune(?Carbon $now = null): int
    {
        return PluginFunnelEvent::query()->where('created_at', '<', ($now ?? Carbon::now())->copy()->subMonths(self::RETENTION_MONTHS))->delete();
    }

    /**
     * @return array{journey: ?string, placement: ?string, path: ?string}
     */
    private function attribution(string $tenantId, ?string $journey): array
    {
        $row = PluginFunnelEvent::query()
            ->whereNotNull('journey')
            ->when($journey !== null, fn ($query) => $query->where('journey', $journey), fn ($query) => $query->where('tenant_id', $tenantId)->where('stage', PluginFunnelEvent::STAGE_SIGNUP))
            ->orderBy('created_at')
            ->first(['journey', 'placement', 'path']);

        if ($row === null) {
            return ['journey' => $journey, 'placement' => null, 'path' => null];
        }

        return ['journey' => $row->journey, 'placement' => $row->placement, 'path' => $row->path];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(string $stage, string $dedupeKey, array $attributes): void
    {
        PluginFunnelEvent::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'stage' => $stage,
            'dedupe_key' => $dedupeKey,
            'journey' => $attributes['journey'] ?? null,
            'placement' => $attributes['placement'] ?? null,
            'path' => $attributes['path'] ?? null,
            'locale' => $attributes['locale'] ?? null,
            'tenant_id' => $attributes['tenant_id'] ?? null,
            'store_id' => $attributes['store_id'] ?? null,
            'created_at' => Carbon::now(),
        ]);
    }
}
