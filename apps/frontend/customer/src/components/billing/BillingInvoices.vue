<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatMinor, StatusBadge, type Tone } from '@bw/ui';
import { invoiceLink, listInvoices, paymentMethodCheckout, type BillingInvoice } from '@/api/billing';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { openPaddleCheckout } from '@/composables/paddle';
import { useFormat } from '@/composables/useFormat';

const props = defineProps<{ canChangePaymentMethod: boolean }>();

const TONES: Record<BillingInvoice['status'], Tone> = { billed: 'note', paid: 'ok', completed: 'ok', canceled: 'unknown', past_due: 'crit' };

const { locale } = useI18n();
const { dateTime } = useFormat();
const invoices = ref<BillingInvoice[]>([]);
const cursor = ref<string | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const busy = ref<string | null>(null);
const paymentMethodUpdated = ref(false);

function money(invoice: BillingInvoice, minor: string | null): string {
  return minor === null ? '—' : (formatMinor(minor, invoice.currency, invoice.currency_exponent, locale.value) ?? `${minor} ${invoice.currency}`);
}

async function load(more = false): Promise<void> {
  try {
    const page = await listInvoices(more ? cursor.value : null);
    invoices.value = more ? [...invoices.value, ...page.data] : page.data;
    cursor.value = page.next_cursor;
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function download(invoice: BillingInvoice): Promise<void> {
  busy.value = invoice.id;
  error.value = null;

  try {
    window.location.assign(await invoiceLink(invoice.id));
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = null;
  }
}

async function changePaymentMethod(): Promise<void> {
  busy.value = 'payment-method';
  error.value = null;
  paymentMethodUpdated.value = false;

  try {
    await openPaddleCheckout(await paymentMethodCheckout(), (name) => {
      if (name === 'checkout.completed') {
        paymentMethodUpdated.value = true;
      }
    });
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = null;
  }
}

onMounted(() => void load());
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="invoices-title" data-panel="invoices">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex flex-col gap-1">
        <h2 id="invoices-title" class="text-lg font-semibold">{{ $t('billing.invoices.title') }}</h2>
        <p class="text-sm text-text-muted">{{ $t('billing.invoices.hint') }}</p>
      </div>
      <button
        v-if="props.canChangePaymentMethod"
        type="button"
        class="rounded-md border border-border-strong px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60"
        :disabled="busy !== null"
        data-action="payment-method"
        @click="changePaymentMethod"
      >
        {{ $t('billing.invoices.payment_method') }}
      </button>
    </div>

    <p v-if="paymentMethodUpdated" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" data-payment-method="updated">{{ $t('billing.invoices.payment_method_updated') }}</p>
    <ErrorNotice v-if="error" :error="error" />
    <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
    <p v-else-if="invoices.length === 0" class="text-sm text-text-muted" data-invoices="empty">{{ $t('billing.invoices.empty') }}</p>

    <div v-else class="overflow-x-auto">
      <table class="w-full min-w-[36rem] text-sm">
        <thead class="border-b border-border text-left text-text-muted">
          <tr>
            <th class="py-2 pr-4 font-medium">{{ $t('billing.invoices.date') }}</th>
            <th class="py-2 pr-4 font-medium">{{ $t('billing.invoices.period') }}</th>
            <th class="py-2 pr-4 text-right font-medium">{{ $t('billing.invoices.amount') }}</th>
            <th class="py-2 pr-4 font-medium">{{ $t('billing.invoices.status_label') }}</th>
            <th class="py-2" />
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="invoice in invoices" :key="invoice.id" :data-invoice="invoice.invoice_number ?? invoice.id">
            <td class="py-2 pr-4 whitespace-nowrap">{{ dateTime(invoice.billed_at) }}<span v-if="invoice.invoice_number" class="block text-[13px] text-text-muted">{{ invoice.invoice_number }}</span></td>
            <td class="py-2 pr-4 whitespace-nowrap">{{ invoice.period_start ? `${dateTime(invoice.period_start)} — ${dateTime(invoice.period_end)}` : '—' }}</td>
            <td class="py-2 pr-4 text-right whitespace-nowrap tabular-nums">
              {{ money(invoice, invoice.total_minor) }}
              <span v-if="invoice.tax_minor && invoice.tax_minor !== '0'" class="block text-[13px] text-text-muted">{{ $t('billing.invoices.tax', { amount: money(invoice, invoice.tax_minor) }) }}</span>
              <span v-if="invoice.discount_minor && invoice.discount_minor !== '0'" class="block text-[13px] text-ok">{{ $t('billing.invoices.discount', { amount: money(invoice, invoice.discount_minor) }) }}</span>
            </td>
            <td class="py-2 pr-4"><StatusBadge :tone="TONES[invoice.status]" :label="$t(`billing.invoices.status.${invoice.status}`)" /></td>
            <td class="py-2 text-right">
              <button
                v-if="invoice.invoice_available"
                type="button"
                class="rounded-md border border-border-strong px-3 py-1 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60"
                :disabled="busy !== null"
                data-action="download-invoice"
                @click="download(invoice)"
              >
                {{ $t('billing.invoices.download') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <button v-if="cursor" type="button" class="self-start rounded-md border border-border-strong px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft" data-action="more-invoices" @click="load(true)">{{ $t('billing.invoices.more') }}</button>
  </section>
</template>
