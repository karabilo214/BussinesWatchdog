<?php

namespace App\Http\Dto\Checks;

use App\Models\Artifact;
use App\Models\CheckAttempt;
use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\CheckStep;
use App\Support\Browser\ScenarioDefinition;

class CheckDto
{
    /**
     * @return array<string, mixed>
     */
    public function scenario(CheckScenario $scenario): array
    {
        $definition = $scenario->definition ?? [];

        return [
            'id' => $scenario->id,
            'store_id' => $scenario->store_id,
            'name' => $scenario->name,
            'mode' => $scenario->mode,
            'version' => $scenario->version,
            'enabled' => $scenario->enabled,
            'adapter_version' => $scenario->adapter_version,
            'product_external_id' => $scenario->product_external_id,
            'product_url' => $definition['product_url'] ?? null,
            'cart_url' => $definition['cart_url'] ?? null,
            'checkout_url' => $definition['checkout_url'] ?? null,
            'extra_allowed_origins' => $definition['extra_allowed_origins'] ?? [],
            'synthetic_location' => $definition['synthetic_location'] ?? null,
            'interval_seconds' => $scenario->interval_seconds,
            'next_due_at' => $scenario->next_due_at?->toJSON(),
            'supported_steps' => array_column(app(ScenarioDefinition::class)->steps(), 'code'),
            'untested_components' => ['variable_products', 'shipping_required_before_payment', 'third_party_gateway_iframes', 'redirect_gateways'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(CheckRun $run): array
    {
        return [
            'id' => $run->id,
            'store_id' => $run->store_id,
            'scenario_id' => $run->scenario_id,
            'scenario_version' => $run->scenario_version,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'error_code' => $run->error_code,
            'scheduled_at' => $run->scheduled_at->toJSON(),
            'started_at' => $run->started_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function runDetail(CheckRun $run): array
    {
        $attempts = CheckAttempt::query()
            ->where('tenant_id', $run->tenant_id)
            ->where('run_id', $run->id)
            ->with('steps')
            ->orderBy('attempt_number')
            ->get();
        $artifacts = Artifact::query()
            ->where('tenant_id', $run->tenant_id)
            ->whereIn('attempt_id', $attempts->pluck('id'))
            ->where('state', Artifact::STATE_READY)
            ->orderBy('created_at')
            ->get()
            ->groupBy('attempt_id');

        return array_merge($this->run($run), [
            'attempts' => $attempts->map(fn (CheckAttempt $attempt): array => [
                'id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'status' => $attempt->status,
                'error_code' => $attempt->error_code,
                'location' => $attempt->location,
                'browser_version' => $attempt->browser_version,
                'started_at' => $attempt->started_at->toJSON(),
                'finished_at' => $attempt->finished_at?->toJSON(),
                'diagnostics' => $attempt->sanitized_error,
                'artifacts' => $artifacts->get($attempt->id, collect())->map(fn (Artifact $artifact): array => [
                    'id' => $artifact->id,
                    'kind' => $artifact->kind,
                    'content_type' => $artifact->content_type,
                    'size_bytes' => $artifact->size_bytes,
                    'redaction_version' => $artifact->redaction_version,
                    'expires_at' => $artifact->expires_at->toJSON(),
                ])->values()->all(),
                'steps' => $attempt->steps->map(fn (CheckStep $step): array => [
                    'index' => $step->step_index,
                    'code' => $step->step_code,
                    'status' => $step->status,
                    'error_code' => $step->error_code,
                    'started_at' => $step->started_at->toJSON(),
                    'finished_at' => $step->finished_at->toJSON(),
                    'assertions' => $step->assertions,
                    'network_summary' => $step->network_summary,
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }
}
