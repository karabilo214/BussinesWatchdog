<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { fetchTenant, fetchTenantInvitations, inviteTenantMember, resetCustomerMfa, revokeTenantInvitation, type InvitableRole, type TenantCard, type TenantInvitation } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';

const ROLES: InvitableRole[] = ['admin', 'operator', 'viewer'];

const props = defineProps<{ card: TenantCard; canManage?: boolean; canResetMfa?: boolean }>();
const emit = defineEmits<{ updated: [card: TenantCard] }>();
const { dateTime } = useFormat();

const invitations = ref<TenantInvitation[]>([]);
const inviting = ref(false);
const busy = ref(false);
const error = ref<unknown>(null);
const notice = ref<string | null>(null);
const form = reactive({ email: '', role: 'operator' as InvitableRole, reason: '' });
const revoke = reactive({ id: null as string | null, reason: '' });
const reset = reactive({ userId: null as string | null, reason: '', code: '' });
const ready = computed(() => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim()) && form.reason.trim().length >= 3);

async function load(): Promise<void> {
  if (!props.canManage) return;

  try {
    invitations.value = await fetchTenantInvitations(props.card.id);
  } catch (caught) {
    error.value = caught;
  }
}

async function invite(): Promise<void> {
  if (!ready.value || busy.value) return;
  busy.value = true;
  error.value = null;
  notice.value = null;

  try {
    await inviteTenantMember(props.card.id, { email: form.email.trim(), role: form.role, reason: form.reason.trim() });
    Object.assign(form, { email: '', reason: '' });
    inviting.value = false;
    notice.value = 'tenant.team.invited';
    await load();
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

async function confirmRevoke(): Promise<void> {
  if (revoke.id === null || revoke.reason.trim().length < 3 || busy.value) return;
  busy.value = true;
  error.value = null;
  notice.value = null;

  try {
    await revokeTenantInvitation(props.card.id, revoke.id, revoke.reason.trim());
    Object.assign(revoke, { id: null, reason: '' });
    notice.value = 'tenant.team.revoked';
    await load();
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

async function confirmMfaReset(): Promise<void> {
  if (reset.userId === null || reset.reason.trim().length < 3 || reset.code.trim().length < 6 || busy.value) return;
  busy.value = true;
  error.value = null;
  notice.value = null;

  try {
    await resetCustomerMfa(props.card.id, reset.userId, reset.reason.trim(), reset.code.trim());
    Object.assign(reset, { userId: null, reason: '', code: '' });
    emit('updated', await fetchTenant(props.card.id));
    notice.value = 'tenant.team.mfa_reset_done';
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

onMounted(load);
</script>

<template>
  <section class="flex flex-col gap-4" data-section="members">
    <div v-if="canManage" class="flex flex-wrap items-start justify-between gap-3">
      <p class="text-sm text-text-muted">{{ $t('tenant.team.hint') }}</p>
      <button v-if="!inviting" type="button" class="rounded-md border border-border-strong px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft" data-action="invite" @click="inviting = true; notice = null">{{ $t('tenant.team.invite') }}</button>
    </div>
    <p v-if="notice" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" data-done="team">{{ $t(notice) }}</p>
    <ErrorNotice v-if="error" :error="error" />

    <form v-if="inviting" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5 text-sm" data-form="invite" @submit.prevent="invite">
      <h2 class="text-lg font-semibold">{{ $t('tenant.team.invite') }}</h2>
      <div class="flex flex-wrap items-end gap-3">
        <label class="flex min-w-64 flex-1 flex-col gap-1.5">
          <span class="font-medium">{{ $t('tenant.team.email') }}</span>
          <input v-model="form.email" type="email" maxlength="255" autocomplete="off" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="invite-email" />
        </label>
        <label class="flex flex-col gap-1.5">
          <span class="font-medium">{{ $t('tenant.team.role') }}</span>
          <select v-model="form.role" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="invite-role">
            <option v-for="role in ROLES" :key="role" :value="role">{{ $t(`member_role.${role}`, role) }}</option>
          </select>
        </label>
      </div>
      <label class="flex flex-col gap-1.5">
        <span class="font-medium">{{ $t('tenant.actions.reason') }}</span>
        <textarea v-model="form.reason" maxlength="500" rows="2" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="invite-reason" />
      </label>
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !ready" data-action="send-invite">{{ $t('tenant.team.send') }}</button>
        <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" @click="inviting = false">{{ $t('common.cancel') }}</button>
      </div>
    </form>

    <div class="overflow-x-auto rounded-xl border border-border bg-surface">
      <table class="w-full min-w-[32rem] text-sm">
        <tbody class="divide-y divide-border">
          <tr v-for="member in card.members" :key="`${member.email}-${member.role}`" :data-member="member.email">
            <td class="px-4 py-3 font-medium">{{ member.name ?? '—' }}</td>
            <td class="px-4 py-3">{{ member.email ?? '—' }} <span v-if="member.email_verified" class="text-ok">✓</span></td>
            <td class="px-4 py-3">{{ $t(`member_role.${member.role}`, member.role) }}</td>
            <td class="px-4 py-3 text-text-muted">{{ dateTime(member.joined_at) }}</td>
            <td class="px-4 py-3 text-right">
              <button v-if="canResetMfa && member.mfa_enabled" type="button" class="rounded-md border border-warn-border px-2.5 py-1 text-warn hover:bg-warn-soft" data-action="reset-mfa" @click="Object.assign(reset, { userId: member.user_id, reason: '', code: '' }); notice = null">{{ $t('tenant.team.mfa_reset') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <form v-if="reset.userId" class="flex flex-col gap-3 rounded-xl border border-warn-border bg-warn-soft p-4 text-sm" data-form="mfa-reset" @submit.prevent="confirmMfaReset">
      <p>{{ $t('tenant.team.mfa_reset_warning') }}</p>
      <label class="flex flex-col gap-1 font-medium">{{ $t('tenant.actions.reason') }}<input v-model="reset.reason" maxlength="500" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-normal" /></label>
      <label class="flex flex-col gap-1 font-medium">{{ $t('tenant.team.confirmation_code') }}<input v-model="reset.code" autocomplete="one-time-code" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-normal" /></label>
      <div class="flex gap-2">
        <button type="submit" class="rounded-md bg-crit-solid px-3 py-2 font-medium text-white disabled:opacity-60" :disabled="busy || reset.reason.trim().length < 3 || reset.code.trim().length < 6">{{ $t('tenant.team.mfa_reset') }}</button>
        <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-2" @click="reset.userId = null">{{ $t('common.cancel') }}</button>
      </div>
    </form>

    <div v-if="canManage && invitations.length > 0" class="flex flex-col gap-2">
      <h2 class="text-lg font-semibold">{{ $t('tenant.team.pending') }}</h2>
      <div class="overflow-x-auto rounded-xl border border-border bg-surface">
        <table class="w-full min-w-[32rem] text-sm">
          <tbody class="divide-y divide-border">
            <tr v-for="invitation in invitations" :key="invitation.id" :data-invitation="invitation.email">
              <td class="px-4 py-3">{{ invitation.email }}</td>
              <td class="px-4 py-3">{{ $t(`member_role.${invitation.role}`, invitation.role) }}</td>
              <td class="px-4 py-3 text-text-muted">{{ $t('tenant.team.expires', { date: dateTime(invitation.expires_at) }) }}</td>
              <td class="px-4 py-3 text-right">
                <button type="button" class="rounded-md border border-crit-border px-3 py-1 font-medium text-crit hover:bg-crit-soft" data-action="revoke" @click="revoke.id = invitation.id; revoke.reason = ''">{{ $t('tenant.team.revoke') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <form v-if="revoke.id" class="flex flex-col gap-3 rounded-xl border border-note-border bg-note-soft p-5 text-sm" data-form="revoke" @submit.prevent="confirmRevoke">
      <label class="flex flex-col gap-1.5">
        <span class="font-medium">{{ $t('tenant.actions.reason') }}</span>
        <textarea v-model="revoke.reason" maxlength="500" rows="2" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="revoke-reason" />
      </label>
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || revoke.reason.trim().length < 3" data-action="confirm-revoke">{{ $t('tenant.team.revoke') }}</button>
        <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" @click="revoke.id = null">{{ $t('common.cancel') }}</button>
      </div>
    </form>
  </section>
</template>
