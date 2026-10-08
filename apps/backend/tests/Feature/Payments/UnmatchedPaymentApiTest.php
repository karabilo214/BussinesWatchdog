<?php

namespace Tests\Feature\Payments;

use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Payments\PaymentAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UnmatchedPaymentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_suggests_an_exact_candidate_by_transaction_reference(): void
    {
        $context = $this->context('admin');
        $payment = $this->payment($context, 'pi_demo_42');
        $order = $this->order($context, '#2001', 18400, 'pi_demo_42');
        $capture = $this->capture($context, $payment, 18400);

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/stores/{$context['store']->id}/unmatched-payments");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.capture_transaction_id', $capture->id)
            ->assertJsonPath('data.0.payment_id', $payment->id)
            ->assertJsonPath('data.0.reason', 'review_required')
            ->assertJsonCount(1, 'data.0.candidates')
            ->assertJsonPath('data.0.candidates.0.order_id', $order->id)
            ->assertJsonPath('data.0.candidates.0.confidence', 'exact_candidate');
    }

    public function test_it_suggests_manual_review_candidates_by_amount_when_no_reference_matches(): void
    {
        $context = $this->context('admin');
        $payment = $this->payment($context, 'pi_no_match');
        $order = $this->order($context, '#2002', 7700, null);
        $capture = $this->capture($context, $payment, 7700);

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/stores/{$context['store']->id}/unmatched-payments");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.candidates.0.order_id', $order->id)
            ->assertJsonPath('data.0.candidates.0.confidence', 'manual_review');
    }

    public function test_it_reports_no_candidate_found_when_nothing_matches(): void
    {
        $context = $this->context('admin');
        $payment = $this->payment($context, 'pi_orphan');
        $this->capture($context, $payment, 12300);

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/stores/{$context['store']->id}/unmatched-payments");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'no_candidate_found')
            ->assertJsonCount(0, 'data.0.candidates');
    }

    public function test_it_excludes_already_allocated_captures(): void
    {
        $context = $this->context('admin');
        $payment = $this->payment($context, 'pi_allocated');
        $order = $this->order($context, '#2003', 5000, 'pi_allocated');
        $capture = $this->capture($context, $payment, 5000);

        app(PaymentAllocationService::class)->allocateCapture(
            $payment,
            $capture,
            $order,
            5000,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/stores/{$context['store']->id}/unmatched-payments")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * @return array{user: User, tenant: Tenant, store: Store, integration: Integration}
     */
    private function context(string $role): array
    {
        $user = User::query()->create([
            'name' => 'Unmatched Tester',
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('very-secure-password'),
            'locale' => 'ru',
        ]);
        $tenant = Tenant::query()->create([
            'name' => fake()->company(),
            'timezone' => 'Europe/Kyiv',
        ]);
        Membership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
        $store = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
            'base_url' => 'https://'.fake()->domainName(),
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);
        $integration = Integration::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'provider' => 'stripe',
            'install_id' => fake()->uuid(),
            'mode' => 'live',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [],
            'connector_version' => '1.0.0',
            'health' => [],
        ]);

        return compact('user', 'tenant', 'store', 'integration');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function payment(array $context, string $intentRef): Payment
    {
        return Payment::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'external_id' => fake()->uuid(),
            'intent_ref' => $intentRef,
            'charge_ref' => 'ch_'.fake()->uuid(),
            'mode' => 'live',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'status' => 'captured',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'source_updated_at' => now()->subHours(25),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now()->subHours(25),
            'updated_at' => now()->subHours(25),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function order(array $context, string $displayNumber, int $totalMinor, ?string $transactionRef): Order
    {
        return Order::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'external_id' => fake()->uuid(),
            'display_number' => $displayNumber,
            'source_revision' => 1,
            'status' => 'processing',
            'mode' => 'live',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'total_minor' => $totalMinor,
            'payment_expected' => true,
            'transaction_ref' => $transactionRef,
            'financial_support' => 'supported',
            'is_synthetic' => false,
            'source_created_at' => now()->subHours(26),
            'source_updated_at' => now()->subHours(25),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function capture(array $context, Payment $payment, int $amountMinor): FinancialTransaction
    {
        return FinancialTransaction::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'payment_id' => $payment->id,
            'external_operation_id' => 'cap_'.fake()->uuid(),
            'kind' => 'capture',
            'status' => 'succeeded',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'amount_minor' => $amountMinor,
            'occurred_at' => now()->subHours(25),
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'operation_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
        ]);
    }
}
