<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { cancelOrganizationDeletion, getOrganizationDeletion, requestOrganizationDeletion } from '@/api/account';
import type { OrganizationDeletionState } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import OrganizationSummary from '@/components/account/OrganizationSummary.vue';
import SettingsTabs from '@/components/account/SettingsTabs.vue';
import { stepUpErrorKey } from '@/composables/errors';
import { useFormat } from '@/composables/useFormat';
import { useRole } from '@/composables/useRole';
import { useSession } from '@/composables/useSession';
import AppLayout from '@/layouts/AppLayout.vue';

const router = useRouter();
const { session, load } = useSession();
const { role } = useRole();
const { dateTime } = useFormat();

const state = ref<OrganizationDeletionState | null>(null);
const loadError = ref<unknown>(null);
const opened = ref(false);
const form = reactive({ name: '', password: '', code: '' });
const busy = ref(false);
const error = ref<unknown>(null);
const stepUpKey = computed(() => stepUpErrorKey(error.value));
const needsCode = computed(() => session.value?.user.mfa_enabled === true);
const deleting = computed(() => state.value?.request !== null && state.value?.request !== undefined);
const codeReady = computed(() => !needsCode.value || form.code.trim().length >= 6);

onMounted(async () => {
  try {
    state.value = await getOrganizationDeletion();
  } catch (caught) {
    loadError.value = caught;
  }
});

function reset(): void {
  form.password = '';
  form.code = '';
  error.value = null;
}

async function submit(): Promise<void> {
  busy.value = true;
  error.value = null;

  try {
    state.value = await requestOrganizationDeletion(form.name.trim(), form.password, needsCode.value ? form.code.trim() : null);
    opened.value = false;
    form.name = '';
    await load(true);
  } catch (caught) {
    error.value = caught;
  } finally {
    form.password = '';
    busy.value = false;
  }
}

async function cancel(): Promise<void> {
  busy.value = true;
  error.value = null;

  try {
    state.value = await cancelOrganizationDeletion(form.password, needsCode.value ? form.code.trim() : null);
    reset();
    await load(true);
    await router.push({ name: 'overview' });
  } catch (caught) {
    error.value = caught;
  } finally {
    form.password = '';
    busy.value = false;
  }
}
</script>

<template>
  <AppLayout>
    <div class="flex flex-col gap-6">
      <h1 class="text-2xl font-semibold">{{ $t('settings.title') }}</h1>
      <SettingsTabs v-if="!deleting" />


      <ErrorNotice v-if="loadError" :error="loadError" />

      <OrganizationSummary v-if="state && !deleting" :name="state.tenant_name" />

      <section
        v-if="state && deleting && state.request"
        class="flex flex-col gap-4 rounded-xl border border-crit-border bg-crit-soft p-5"
        aria-labelledby="deletion-title"
        data-panel="deletion-scheduled"
      >
        <h2 id="deletion-title" class="text-lg font-semibold text-crit">{{ $t('organization.deleting.title', { name: state.tenant_name }) }}</h2>
        <p>{{ $t(`organization.deleting.by.${state.request.requested_via}`, { date: dateTime(state.request.requested_at) }) }}</p>
        <p class="font-medium">{{ $t('organization.deleting.purge', { date: dateTime(state.request.purge_after) }) }}</p>
        <p class="text-sm">{{ $t('organization.deleting.stopped') }}</p>

        <form v-if="state.request.can_cancel" class="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 text-sm" data-form="cancel-deletion" @submit.prevent="cancel">
          <p>{{ $t('organization.deleting.cancel_hint') }}</p>
          <div class="flex flex-wrap items-end gap-3">
            <label class="flex min-w-[12rem] flex-1 flex-col gap-1.5 font-medium">
              {{ $t('account.password.current') }}
              <input v-model="form.password" type="password" autocomplete="current-password" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base font-normal" />
            </label>
            <label v-if="needsCode" class="flex min-w-[10rem] flex-col gap-1.5 font-medium">
              {{ $t('account.mfa.code_or_recovery') }}
              <input v-model="form.code" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-base font-normal" />
            </label>
          </div>
          <p v-if="stepUpKey" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-crit">{{ $t(stepUpKey) }}</p>
          <ErrorNotice v-else-if="error" :error="error" />
          <div>
            <button type="submit" class="rounded-md bg-primary px-3 py-1.5 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || form.password === '' || !codeReady">
              {{ $t('organization.deleting.cancel') }}
            </button>
          </div>
        </form>
        <p v-else-if="state.request.status === 'requested'" class="text-sm">{{ $t('organization.deleting.ask_owner') }}</p>
        <p v-else class="text-sm">{{ $t('organization.deleting.in_progress') }}</p>
      </section>

      <section v-else-if="state" class="flex flex-col gap-4 rounded-xl border border-crit-border bg-surface p-5" aria-labelledby="delete-title" data-panel="delete-organization">
        <h2 id="delete-title" class="text-lg font-semibold">{{ $t('organization.delete.title') }}</h2>
        <ul class="flex list-disc flex-col gap-1.5 pl-5 text-sm">
          <li>{{ $t('organization.delete.pause') }}</li>
          <li>{{ $t('organization.delete.cancel_subscription') }}</li>
          <li>{{ $t('organization.delete.delete') }}</li>
        </ul>

        <p v-if="role !== 'owner'" class="text-sm text-text-muted">{{ $t('organization.delete.owner_only') }}</p>

        <template v-else-if="state.can_request">
          <button
            v-if="!opened"
            type="button"
            class="self-start rounded-md border border-crit-border bg-surface px-3 py-1.5 font-medium text-crit hover:bg-crit-soft"
            data-action="open-delete"
            @click="opened = true; reset()"
          >
            {{ $t('organization.delete.open') }}
          </button>

          <form v-else class="flex flex-col gap-3 rounded-lg border border-crit-border bg-crit-soft p-4 text-sm" data-form="delete-organization" @submit.prevent="submit">
            <p class="font-medium">{{ $t('organization.delete.warning') }}</p>
            <label class="flex flex-col gap-1.5 font-medium">
              {{ $t('organization.delete.type_name', { name: state.tenant_name }) }}
              <input v-model="form.name" autocomplete="off" spellcheck="false" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base font-normal" data-field="organization-name" />
            </label>
            <div class="flex flex-wrap items-end gap-3">
              <label class="flex min-w-[12rem] flex-1 flex-col gap-1.5 font-medium">
                {{ $t('account.password.current') }}
                <input v-model="form.password" type="password" autocomplete="current-password" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base font-normal" />
              </label>
              <label v-if="needsCode" class="flex min-w-[10rem] flex-col gap-1.5 font-medium">
                {{ $t('account.mfa.code_or_recovery') }}
                <input v-model="form.code" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-base font-normal" />
              </label>
            </div>
            <p v-if="stepUpKey" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-crit">{{ $t(stepUpKey) }}</p>
            <ErrorNotice v-else-if="error" :error="error" />
            <div class="flex flex-wrap gap-2">
              <button
                type="submit"
                class="rounded-md bg-crit-solid px-3 py-1.5 font-semibold text-white disabled:opacity-60"
                :disabled="busy || form.name.trim() !== state.tenant_name.trim() || form.password === '' || !codeReady"
                data-action="confirm-delete"
              >
                {{ $t('organization.delete.submit') }}
              </button>
              <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium" @click="opened = false">{{ $t('common.cancel') }}</button>
            </div>
          </form>
        </template>
      </section>
    </div>
  </AppLayout>
</template>
