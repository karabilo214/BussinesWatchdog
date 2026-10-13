<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { StatusBadge } from '@bw/ui';
import { fetchStaff, inviteStaff, revokeStaffInvitation, revokeStaffSessions, updateStaff, type StaffCatalog, type StaffMember, type StaffRole } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';
import { useStaff } from '@/composables/useStaff';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const ROLES: StaffRole[] = ['platform_owner', 'platform_admin', 'platform_support', 'platform_billing'];

const { dateTime } = useFormat();
const { can, me } = useStaff();
const allowed = computed(() => can('staff.manage'));
const catalog = ref<StaffCatalog | null>(null);
const error = ref<unknown>(null);
const actionError = ref<unknown>(null);
const busy = ref(false);
const notice = ref<string | null>(null);
const invite = reactive({ open: false, email: '', role: 'platform_support' as StaffRole, reason: '', code: '' });
const action = reactive({ kind: null as 'role' | 'disable' | 'enable' | 'sessions' | 'revoke-invitation' | null, target: '', role: 'platform_support' as StaffRole, reason: '', code: '' });

const needsCode = computed(() => action.kind === 'role' || action.kind === 'disable' || action.kind === 'enable');
const inviteReady = computed(() => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(invite.email.trim()) && invite.reason.trim().length >= 3 && invite.code.trim().length >= 6);
const actionReady = computed(() => action.reason.trim().length >= 3 && (!needsCode.value || action.code.trim().length >= 6));

async function load(): Promise<void> {
  if (!allowed.value) return;

  try {
    catalog.value = await fetchStaff();
  } catch (caught) {
    error.value = caught;
  }
}

async function run(task: () => Promise<StaffCatalog | void>, message: string): Promise<void> {
  busy.value = true;
  actionError.value = null;
  notice.value = null;

  try {
    const result = await task();
    if (result) catalog.value = result;
    else await load();
    notice.value = message;
    action.kind = null;
    invite.open = false;
  } catch (caught) {
    actionError.value = caught;
  } finally {
    busy.value = false;
  }
}

async function sendInvite(): Promise<void> {
  if (!inviteReady.value || busy.value) return;
  await run(() => inviteStaff({ email: invite.email.trim(), role: invite.role, reason: invite.reason.trim(), confirmation_code: invite.code.trim() }).then(() => undefined), 'staff.done.invited');
  if (notice.value) Object.assign(invite, { email: '', reason: '', code: '' });
}

function ask(kind: NonNullable<typeof action.kind>, target: string, role?: StaffRole): void {
  Object.assign(action, { kind, target, role: role ?? 'platform_support', reason: '', code: '' });
  actionError.value = null;
}

async function confirmAction(): Promise<void> {
  if (!actionReady.value || busy.value || action.kind === null) return;
  const reason = action.reason.trim();
  const code = action.code.trim();

  switch (action.kind) {
    case 'role':
      return run(() => updateStaff(action.target, { role: action.role, reason, confirmation_code: code }), 'staff.done.role');
    case 'disable':
      return run(() => updateStaff(action.target, { state: 'disabled', reason, confirmation_code: code }), 'staff.done.disabled');
    case 'enable':
      return run(() => updateStaff(action.target, { state: 'active', reason, confirmation_code: code }), 'staff.done.enabled');
    case 'sessions':
      return run(() => revokeStaffSessions(action.target, reason), 'staff.done.sessions');
    default:
      return run(() => revokeStaffInvitation(action.target, reason), 'staff.done.invitation_revoked');
  }
}

function self(member: StaffMember): boolean {
  return member.id === me.value?.staff_id;
}

onMounted(load);
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-6xl flex-col gap-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ $t('section.staff.title') }}</h1>
          <p class="text-sm text-text-muted">{{ allowed ? $t('staff.hint') : $t('staff.forbidden') }}</p>
        </div>
        <button v-if="allowed && !invite.open" type="button" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover" data-action="invite" @click="invite.open = true; notice = null">{{ $t('staff.invite') }}</button>
      </div>

      <ErrorNotice v-if="error" :error="error" />
      <p v-if="notice" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" data-notice>{{ $t(notice) }}</p>
      <ErrorNotice v-if="actionError" :error="actionError" />

      <form v-if="invite.open" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5 text-sm" data-form="invite" @submit.prevent="sendInvite">
        <h2 class="text-lg font-semibold">{{ $t('staff.invite') }}</h2>
        <p class="text-text-muted">{{ $t('staff.invite_hint') }}</p>
        <div class="flex flex-wrap items-end gap-3">
          <label class="flex min-w-64 flex-1 flex-col gap-1.5">
            <span class="font-medium">{{ $t('staff.email') }}</span>
            <input v-model="invite.email" type="email" maxlength="254" autocomplete="off" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="email" />
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('staff.role') }}</span>
            <select v-model="invite.role" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="role">
              <option v-for="role in ROLES" :key="role" :value="role">{{ $t(`role.${role}`) }}</option>
            </select>
          </label>
        </div>
        <label class="flex flex-col gap-1.5">
          <span class="font-medium">{{ $t('tenant.actions.reason') }}</span>
          <textarea v-model="invite.reason" maxlength="500" rows="2" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reason" />
        </label>
        <label class="flex max-w-xs flex-col gap-1.5">
          <span class="font-medium">{{ $t('plans.code_confirm') }}</span>
          <input v-model="invite.code" autocomplete="one-time-code" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono tracking-wider" data-field="code" />
        </label>
        <div class="flex flex-wrap gap-2">
          <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !inviteReady" data-action="send-invite">{{ $t('staff.send') }}</button>
          <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" @click="invite.open = false">{{ $t('common.cancel') }}</button>
        </div>
      </form>

      <template v-if="catalog">
        <div class="overflow-x-auto rounded-xl border border-border bg-surface">
          <table class="w-full min-w-[56rem] text-sm">
            <thead class="border-b border-border text-left text-text-muted">
              <tr>
                <th class="px-4 py-3 font-medium">{{ $t('staff.col.person') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('staff.role') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('staff.col.state') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('staff.col.last_login') }}</th>
                <th class="px-4 py-3 font-medium" />
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="member in catalog.staff" :key="member.id" :data-staff="member.email">
                <td class="px-4 py-3">
                  <span class="font-medium">{{ member.name ?? '—' }}</span>
                  <span v-if="self(member)" class="ml-1 text-text-muted">({{ $t('staff.you') }})</span>
                  <span class="block text-[13px] text-text-muted">{{ member.email }}</span>
                </td>
                <td class="px-4 py-3">{{ $t(`role.${member.role}`) }}</td>
                <td class="px-4 py-3">
                  <div class="flex flex-wrap gap-1.5">
                    <StatusBadge :tone="member.state === 'active' ? 'ok' : 'unknown'" :label="$t(`staff.state.${member.state}`)" />
                    <StatusBadge v-if="!member.mfa_enabled" tone="warn" :label="$t('staff.no_mfa')" />
                  </div>
                </td>
                <td class="px-4 py-3 whitespace-nowrap">{{ dateTime(member.last_login_at) }}</td>
                <td class="px-4 py-3">
                  <div v-if="!self(member)" class="flex flex-wrap justify-end gap-1.5">
                    <button type="button" class="rounded-md border border-border-strong px-2.5 py-1 font-medium text-primary hover:bg-primary-soft" data-action="role" @click="ask('role', member.id, member.role)">{{ $t('staff.change_role') }}</button>
                    <button v-if="member.state === 'active'" type="button" class="rounded-md border border-border-strong px-2.5 py-1 font-medium text-primary hover:bg-primary-soft" data-action="sessions" @click="ask('sessions', member.id)">{{ $t('staff.sign_out') }}</button>
                    <button v-if="member.state === 'active'" type="button" class="rounded-md border border-crit-border px-2.5 py-1 font-medium text-crit hover:bg-crit-soft" data-action="disable" @click="ask('disable', member.id)">{{ $t('staff.disable') }}</button>
                    <button v-else type="button" class="rounded-md border border-border-strong px-2.5 py-1 font-medium text-primary hover:bg-primary-soft" data-action="enable" @click="ask('enable', member.id)">{{ $t('staff.enable') }}</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <section v-if="catalog.invitations.length > 0" class="flex flex-col gap-2">
          <h2 class="text-lg font-semibold">{{ $t('staff.pending') }}</h2>
          <div class="overflow-x-auto rounded-xl border border-border bg-surface">
            <table class="w-full min-w-[40rem] text-sm">
              <tbody class="divide-y divide-border">
                <tr v-for="invitation in catalog.invitations" :key="invitation.id" :data-invitation="invitation.email">
                  <td class="px-4 py-3">{{ invitation.email }}</td>
                  <td class="px-4 py-3">{{ $t(`role.${invitation.role}`) }}</td>
                  <td class="px-4 py-3 text-text-muted">{{ $t('staff.expires', { date: dateTime(invitation.expires_at) }) }}</td>
                  <td class="px-4 py-3 text-right"><button type="button" class="rounded-md border border-crit-border px-2.5 py-1 font-medium text-crit hover:bg-crit-soft" data-action="revoke-invitation" @click="ask('revoke-invitation', invitation.id)">{{ $t('staff.revoke') }}</button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
        <p class="text-sm text-text-muted">{{ $t('staff.mfa_reset') }}</p>
      </template>

      <form v-if="action.kind" class="flex flex-col gap-3 rounded-xl border border-note-border bg-note-soft p-5 text-sm" :data-form="action.kind" @submit.prevent="confirmAction">
        <p class="font-medium">{{ $t(`staff.confirm.${action.kind}`) }}</p>
        <label v-if="action.kind === 'role'" class="flex max-w-xs flex-col gap-1.5">
          <span class="font-medium">{{ $t('staff.role') }}</span>
          <select v-model="action.role" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="new-role">
            <option v-for="role in ROLES" :key="role" :value="role">{{ $t(`role.${role}`) }}</option>
          </select>
        </label>
        <label class="flex flex-col gap-1.5">
          <span class="font-medium">{{ $t('tenant.actions.reason') }}</span>
          <textarea v-model="action.reason" maxlength="500" rows="2" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="action-reason" />
        </label>
        <label v-if="needsCode" class="flex max-w-xs flex-col gap-1.5">
          <span class="font-medium">{{ $t('plans.code_confirm') }}</span>
          <input v-model="action.code" autocomplete="one-time-code" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono tracking-wider" data-field="action-code" />
        </label>
        <div class="flex flex-wrap gap-2">
          <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !actionReady" data-action="confirm">{{ $t('staff.apply') }}</button>
          <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" @click="action.kind = null">{{ $t('common.cancel') }}</button>
        </div>
      </form>
    </div>
  </OwnerLayout>
</template>
