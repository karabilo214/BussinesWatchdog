<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { decideSupportGrant, listSupportActivity, listSupportGrants, listSupportStaff, requestSupportGrant, type GrantActivity, type GrantScope, type SupportGrant, type SupportStaff } from '@/api/supportGrants';
import { listStores } from '@/api/stores';
import type { Store } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import SettingsTabs from '@/components/account/SettingsTabs.vue';
import { useFormat } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';

const { t } = useI18n();
const { dateTime } = useFormat();
const stores = ref<Store[]>([]);
const grants = ref<SupportGrant[]>([]);
const activity = ref<GrantActivity[]>([]);
const openedActivity = ref<string | null>(null);
const loading = ref(true);
const busy = ref(false);
const error = ref<unknown>(null);
const supportStaff = ref<SupportStaff[]>([]);
const form = reactive({ store_id: '', staff_id: '', scopes: ['read_diagnostics'] as GrantScope[], duration_hours: 24, reason: '' });
const allScopes: GrantScope[] = ['read_diagnostics', 'read_artifacts', 'request_recheck'];

async function load(): Promise<void> {
  try {
    [stores.value, grants.value, supportStaff.value] = await Promise.all([listStores(), listSupportGrants(), listSupportStaff()]);
    form.store_id ||= stores.value[0]?.id ?? '';
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

onMounted(load);

function toggle(scope: GrantScope): void {
  form.scopes = form.scopes.includes(scope) ? form.scopes.filter((item) => item !== scope) : [...form.scopes, scope];
}

async function create(): Promise<void> {
  if (busy.value || !form.store_id || form.scopes.length === 0 || form.reason.trim().length < 5) return;
  busy.value = true;
  error.value = null;
  try {
    await requestSupportGrant({ ...form, reason: form.reason.trim() });
    grants.value = await listSupportGrants();
    form.reason = '';
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

async function decide(grant: SupportGrant, action: 'approve' | 'deny' | 'revoke'): Promise<void> {
  if (busy.value) return;
  busy.value = true;
  error.value = null;
  try {
    const updated = await decideSupportGrant(grant.id, action);
    grants.value = grants.value.map((item) => item.id === updated.id ? updated : item);
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

async function showActivity(id: string): Promise<void> {
  if (openedActivity.value === id) { openedActivity.value = null; return; }
  try {
    activity.value = await listSupportActivity(id);
    openedActivity.value = id;
  } catch (caught) {
    error.value = caught;
  }
}
</script>

<template>
  <AppLayout>
    <div class="flex max-w-5xl flex-col gap-6">
      <h1 class="text-2xl font-semibold">{{ t('support_grants.title') }}</h1>
      <SettingsTabs />
      <p class="text-sm text-text-muted">{{ t('support_grants.explanation') }}</p>
      <ErrorNotice v-if="error" :error="error" />
      <p v-if="loading" role="status">{{ t('common.loading') }}</p>

      <form v-else class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-5" @submit.prevent="create">
        <h2 class="font-semibold">{{ t('support_grants.new') }}</h2>
        <p class="text-sm text-text-muted">{{ t('support_grants.number_after_create') }}</p>
        <label class="flex flex-col gap-1"><span>{{ t('support_grants.store') }}</span><select v-model="form.store_id" class="rounded-md border border-border-strong bg-surface p-2"><option v-for="store in stores" :key="store.id" :value="store.id">{{ store.name }}</option></select></label>
        <label class="flex flex-col gap-1"><span>{{ t('support_grants.staff') }}</span><select v-model="form.staff_id" required class="rounded-md border border-border-strong bg-surface p-2"><option value="" disabled>{{ t('support_grants.staff_choose') }}</option><option v-for="member in supportStaff" :key="member.id" :value="member.id">{{ member.name }}</option></select></label>
        <fieldset class="flex flex-wrap gap-4"><legend class="font-medium">{{ t('support_grants.scopes') }}</legend><label v-for="scope in allScopes" :key="scope" class="flex items-center gap-2"><input type="checkbox" :checked="form.scopes.includes(scope)" @change="toggle(scope)" />{{ t(`support_grants.scope.${scope}`) }}</label></fieldset>
        <label class="flex flex-col gap-1"><span>{{ t('support_grants.hours') }}</span><input v-model.number="form.duration_hours" type="number" min="1" max="72" required class="w-28 rounded-md border border-border-strong bg-surface p-2" /></label>
        <label class="flex flex-col gap-1"><span>{{ t('support_grants.reason') }}</span><textarea v-model="form.reason" required minlength="5" maxlength="500" class="rounded-md border border-border-strong bg-surface p-2" /></label>
        <button type="submit" :disabled="busy || !form.store_id || form.scopes.length === 0 || form.reason.trim().length < 5" class="self-start rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse disabled:opacity-60">{{ t('support_grants.request') }}</button>
      </form>

      <section class="flex flex-col gap-3"><h2 class="text-lg font-semibold">{{ t('support_grants.requests') }}</h2>
        <p v-if="!grants.length" class="text-text-muted">{{ t('support_grants.empty') }}</p>
        <article v-for="grant in grants" :key="grant.id" class="flex flex-col gap-2 rounded-xl border border-border bg-surface p-4 text-sm">
          <div class="flex flex-wrap justify-between gap-2"><strong>{{ grant.case_reference }} · {{ grant.store_name }} · {{ grant.staff?.name }}</strong><span>{{ t(`support_grants.state.${grant.state}`) }}</span></div>
          <p>{{ grant.scopes.map((scope) => t(`support_grants.scope.${scope}`)).join(', ') }}</p>
          <p>{{ t('support_grants.reason') }}: {{ grant.reason }}</p>
          <p class="text-text-muted">{{ t('support_grants.requested_at') }}: {{ dateTime(grant.created_at) }} · {{ t('support_grants.hours') }}: {{ grant.duration_hours }}<span v-if="grant.expires_at"> · {{ t('support_grants.until') }}: {{ dateTime(grant.expires_at) }}</span></p>
          <div class="flex gap-2"><button v-if="grant.state === 'pending'" type="button" :disabled="busy" class="rounded-md bg-primary px-3 py-1.5 text-text-inverse" @click="decide(grant, 'approve')">{{ t('support_grants.approve') }}</button><button v-if="grant.state === 'pending'" type="button" :disabled="busy" class="rounded-md border border-border-strong px-3 py-1.5" @click="decide(grant, 'deny')">{{ t('support_grants.deny') }}</button><button v-if="grant.state === 'approved'" type="button" :disabled="busy" class="rounded-md border border-border-strong px-3 py-1.5" @click="decide(grant, 'revoke')">{{ t('support_grants.revoke') }}</button><button type="button" class="text-primary underline" @click="showActivity(grant.id)">{{ t('support_grants.activity') }}</button></div>
          <div v-if="openedActivity === grant.id" class="rounded-md bg-surface-muted p-3"><p v-if="!activity.length">{{ t('support_grants.no_activity') }}</p><p v-for="entry in activity" :key="entry.id">{{ dateTime(entry.at) }} · {{ t(`support_grants.event.${entry.action}`, entry.action) }}<span v-if="entry.staff_name"> · {{ entry.staff_name }}</span><span v-if="entry.target_type"> · {{ t(`support_grants.target.${entry.target_type}`, entry.target_type) }}</span></p></div>
        </article>
      </section>
      <p class="text-sm text-text-muted">{{ t('support_grants.warning') }}</p>
    </div>
  </AppLayout>
</template>
