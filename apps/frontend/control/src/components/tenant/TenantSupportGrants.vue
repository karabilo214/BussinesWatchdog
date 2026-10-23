<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { listPlatformSupportGrants, requestPlatformSupportGrant, type PlatformSupportGrant, type SupportGrantScope, type TenantCard } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';

const props = defineProps<{ card: TenantCard }>();
const { t } = useI18n();
const { dateTime } = useFormat();
const grants = ref<PlatformSupportGrant[]>([]);
const loading = ref(true);
const busy = ref(false);
const error = ref<unknown>(null);
const form = reactive({ store_id: props.card.stores[0]?.id ?? '', scopes: ['read_diagnostics'] as SupportGrantScope[], duration_hours: 24, reason: '', case_reference: '' });
const scopes: SupportGrantScope[] = ['read_diagnostics', 'read_artifacts', 'request_recheck'];

async function load(): Promise<void> {
  try { grants.value = await listPlatformSupportGrants(props.card.id); } catch (caught) { error.value = caught; } finally { loading.value = false; }
}
onMounted(load);

function toggle(scope: SupportGrantScope): void {
  form.scopes = form.scopes.includes(scope) ? form.scopes.filter((item) => item !== scope) : [...form.scopes, scope];
}

async function submit(): Promise<void> {
  if (!form.store_id || !form.scopes.length || form.reason.trim().length < 5 || !form.case_reference.trim() || busy.value) return;
  busy.value = true;
  error.value = null;
  try {
    await requestPlatformSupportGrant({ tenant_id: props.card.id, store_id: form.store_id, scopes: form.scopes, duration_hours: form.duration_hours, reason: form.reason.trim(), case_reference: form.case_reference.trim() });
    grants.value = await listPlatformSupportGrants(props.card.id);
    form.reason = '';
    form.case_reference = '';
  } catch (caught) { error.value = caught; } finally { busy.value = false; }
}

function active(grant: PlatformSupportGrant): boolean {
  return grant.state === 'approved' && grant.expires_at !== null && Date.parse(grant.expires_at) > Date.now();
}
</script>

<template>
  <section class="flex flex-col gap-4" data-section="support-grants">
    <p class="text-sm text-text-muted">{{ t('support_grants.staff_explanation') }}</p>
    <ErrorNotice v-if="error" :error="error" />
    <form class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-5 text-sm" @submit.prevent="submit">
      <h2 class="text-lg font-semibold">{{ t('support_grants.request_title') }}</h2>
      <label class="flex flex-col gap-1"><span>{{ t('support_grants.store') }}</span><select v-model="form.store_id" class="rounded-md border border-border-strong bg-surface p-2"><option v-for="store in card.stores" :key="store.id" :value="store.id">{{ store.name }}</option></select></label>
      <fieldset class="flex flex-wrap gap-4"><legend class="font-medium">{{ t('support_grants.scopes') }}</legend><label v-for="scope in scopes" :key="scope" class="flex items-center gap-2"><input type="checkbox" :checked="form.scopes.includes(scope)" @change="toggle(scope)" />{{ t(`support_grants.scope.${scope}`) }}</label></fieldset>
      <label class="flex flex-col gap-1"><span>{{ t('support_grants.hours') }}</span><input v-model.number="form.duration_hours" type="number" min="1" max="72" class="w-28 rounded-md border border-border-strong bg-surface p-2" /></label>
      <label class="flex flex-col gap-1"><span>{{ t('support_grants.reason') }}</span><textarea v-model="form.reason" required minlength="5" maxlength="500" class="rounded-md border border-border-strong bg-surface p-2" /></label>
      <label class="flex flex-col gap-1"><span>{{ t('support_grants.case') }}</span><input v-model="form.case_reference" required maxlength="120" class="rounded-md border border-border-strong bg-surface p-2" /></label>
      <button type="submit" :disabled="busy || !form.store_id || !form.scopes.length || !form.case_reference.trim()" class="self-start rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse disabled:opacity-60">{{ t('support_grants.request') }}</button>
    </form>
    <p v-if="loading">{{ t('common.loading') }}</p>
    <p v-else-if="!grants.length">{{ t('support_grants.empty') }}</p>
    <article v-for="grant in grants" :key="grant.id" class="flex flex-col gap-1 rounded-xl border border-border bg-surface p-4 text-sm">
      <strong>{{ card.stores.find((store) => store.id === grant.store_id)?.name ?? grant.store_id }} · {{ t(`support_grants.state.${active(grant) ? 'approved' : grant.state === 'approved' ? 'expired' : grant.state}`) }}</strong>
      <p>{{ grant.scopes.map((scope) => t(`support_grants.scope.${scope}`)).join(', ') }}</p>
      <p>{{ grant.reason }}<span v-if="grant.case_reference"> · {{ grant.case_reference }}</span></p>
      <p class="text-text-muted">{{ dateTime(grant.created_at) }}<span v-if="grant.expires_at"> · {{ t('support_grants.until') }} {{ dateTime(grant.expires_at) }}</span></p>
      <RouterLink v-if="active(grant) && grant.scopes.includes('read_diagnostics')" :to="{ name: 'owner-tenant-store', params: { id: card.id, storeId: grant.store_id } }" class="self-start text-primary underline">{{ t('support_grants.open_store') }}</RouterLink>
    </article>
  </section>
</template>
