<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { changeInvitationPermissions, changeMemberPermissions, inviteMember, listInvitations, listMembers, removeMember, resetMemberMfa, revokeInvitation, transferOwnership } from '@/api/account';
import type { PermissionCatalog, PresetRole, TeamInvitation, TeamMember, TenantPermission } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import PermissionPicker from '@/components/account/PermissionPicker.vue';
import SettingsTabs from '@/components/account/SettingsTabs.vue';
import { stepUpErrorKey } from '@/composables/errors';
import { useFormat } from '@/composables/useFormat';
import { useRole } from '@/composables/useRole';
import { useSession } from '@/composables/useSession';
import AppLayout from '@/layouts/AppLayout.vue';
import { StatusBadge } from '@bw/ui';

const router = useRouter();
const { dateTime } = useFormat();
const { canManageTeam, role: myRole } = useRole();
const { session, load: reloadSession } = useSession();

const members = ref<TeamMember[]>([]);
const presets = ref<PresetRole[]>([]);
const assignable = ref<TenantPermission[]>([]);
const catalog = ref<PermissionCatalog | null>(null);
const invitations = ref<TeamInvitation[]>([]);
const loading = ref(true);
const error = ref<unknown>(null);
const actionError = ref<unknown>(null);
const confirming = ref<string | null>(null);
const invite = reactive({ email: '', permissions: [] as TenantPermission[] });
const editing = ref<string | null>(null);
const draft = ref<TenantPermission[]>([]);
const savingPermissions = ref(false);
const savedFor = ref<string | null>(null);
const inviting = ref(false);
const invited = ref<string | null>(null);
const transferring = ref<string | null>(null);
const transfer = reactive({ password: '', code: '' });
const transferBusy = ref(false);
const transferError = ref<unknown>(null);
const transferStepUpKey = computed(() => stepUpErrorKey(transferError.value));
const resettingMfa = ref<string | null>(null);
const reset = reactive({ reason: '', password: '', code: '' });
const resetBusy = ref(false);
const resetError = ref<unknown>(null);
const resetDone = ref(false);
const needsCode = computed(() => session.value?.user.mfa_enabled === true);

const canInvite = computed(() => canManageTeam.value && assignable.value.length > 0 && catalog.value !== null);

function invitationEditable(item: TeamInvitation): boolean {
  return canInvite.value && item.permissions.every((permission) => assignable.value.includes(permission));
}

function startEdit(id: string, permissions: TenantPermission[]): void {
  editing.value = editing.value === id ? null : id;
  draft.value = [...permissions];
  savedFor.value = null;
  actionError.value = null;
}

async function savePermissions(id: string, kind: 'member' | 'invitation'): Promise<void> {
  savingPermissions.value = true;
  actionError.value = null;

  try {
    if (kind === 'member') {
      await changeMemberPermissions(id, draft.value);
    } else {
      await changeInvitationPermissions(id, draft.value);
    }

    editing.value = null;
    savedFor.value = id;
    await load();
  } catch (caught) {
    actionError.value = caught;
  } finally {
    savingPermissions.value = false;
  }
}

function canReceiveOwnership(member: TeamMember): boolean {
  return myRole.value === 'owner' && !member.is_you && member.role !== 'owner';
}

function openTransfer(member: TeamMember): void {
  confirming.value = null;
  transferring.value = member.user_id;
  transfer.password = '';
  transfer.code = '';
  transferError.value = null;
}

function openMfaReset(member: TeamMember): void {
  resettingMfa.value = member.user_id;
  reset.reason = '';
  reset.password = '';
  reset.code = '';
  resetError.value = null;
  resetDone.value = false;
}

async function submitMfaReset(member: TeamMember): Promise<void> {
  resetBusy.value = true;
  resetError.value = null;

  try {
    await resetMemberMfa(member.user_id, reset.reason.trim(), reset.password, needsCode.value ? reset.code.trim() : null);
    resettingMfa.value = null;
    resetDone.value = true;
    await load();
  } catch (caught) {
    resetError.value = caught;
  } finally {
    reset.password = '';
    reset.code = '';
    resetBusy.value = false;
  }
}

async function submitTransfer(member: TeamMember): Promise<void> {
  transferBusy.value = true;
  transferError.value = null;

  try {
    await transferOwnership(member.user_id, transfer.password, needsCode.value ? transfer.code.trim() : null);
    transferring.value = null;
    await reloadSession(true);
    await load();
  } catch (caught) {
    transferError.value = caught;
  } finally {
    transfer.password = '';
    transferBusy.value = false;
  }
}

async function load(): Promise<void> {
  error.value = null;

  try {
    const result = await listMembers();
    members.value = result.data;
    presets.value = result.assignable_roles;
    assignable.value = result.assignable_permissions;
    catalog.value = result.permission_catalog;
    invitations.value = canManageTeam.value ? await listInvitations() : [];

    if (invite.permissions.length === 0) {
      resetInvitePermissions();
    }
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

/** A new invitation starts as the smallest preset the inviter may give, with amounts if they may give them. */
function resetInvitePermissions(): void {
  const base = catalog.value?.base ?? ['stores.read'];
  const role = presets.value.at(-1);
  const start = role && catalog.value ? catalog.value.presets[role] : base;
  const money: TenantPermission[] = assignable.value.includes('money.view') ? ['money.view'] : [];

  invite.permissions = [...new Set([...base, ...start, ...money])];
}

async function act(action: () => Promise<unknown>): Promise<void> {
  actionError.value = null;

  try {
    await action();
    confirming.value = null;
    await load();
  } catch (caught) {
    actionError.value = caught;
  }
}

async function remove(member: TeamMember): Promise<void> {
  if (member.is_you) {
    actionError.value = null;

    try {
      await removeMember(member.user_id);
      await reloadSession(true);
      await router.replace({ name: 'overview' });
    } catch (caught) {
      actionError.value = caught;
    }

    return;
  }

  await act(() => removeMember(member.user_id));
}

async function sendInvite(): Promise<void> {
  inviting.value = true;
  actionError.value = null;
  invited.value = null;

  try {
    const created = await inviteMember(invite.email.trim(), invite.permissions);
    invited.value = created.email;
    invite.email = '';
    resetInvitePermissions();
    invitations.value = await listInvitations();
  } catch (caught) {
    actionError.value = caught;
  } finally {
    inviting.value = false;
  }
}

onMounted(load);
</script>

<template>
  <AppLayout>
    <div class="flex max-w-4xl flex-col gap-6">
      <h1 class="text-2xl font-semibold">{{ $t('settings.title') }}</h1>
      <SettingsTabs />

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error" :error="error" />

      <template v-else>
        <ErrorNotice v-if="actionError" :error="actionError" />
        <p v-if="resetDone" role="status" class="rounded-md border border-ok-border bg-ok-soft p-3 text-sm">{{ $t('team.mfa_reset.done') }}</p>

        <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="members-title" data-panel="members">
          <div class="flex flex-col gap-1">
            <h2 id="members-title" class="text-lg font-semibold">{{ $t('team.members') }}</h2>
            <p class="text-sm text-text-muted">{{ $t('team.roles_hint') }}</p>
          </div>
          <ul class="flex flex-col divide-y divide-border rounded-lg border border-border">
            <li v-for="member in members" :key="member.user_id" class="flex flex-col gap-2 p-3" :data-member="member.email">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex min-w-0 flex-col gap-0.5">
                  <span class="font-medium">{{ member.name }}<template v-if="member.is_you"> · {{ $t('team.you') }}</template></span>
                  <span class="break-all text-[13px] text-text-muted">{{ member.email }}</span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                  <StatusBadge :tone="member.role === 'owner' ? 'note' : 'unknown'" :label="$t(`team.role.${member.role}`)" />
                  <StatusBadge v-if="member.role !== 'owner' && !member.permissions.includes('money.view')" tone="unknown" :label="$t('team.permissions.no_amounts')" data-no-amounts />
                  <button
                    v-if="member.can_edit && catalog && editing !== member.user_id"
                    type="button"
                    class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium hover:bg-surface-muted"
                    :aria-label="$t('team.permissions.edit_for', { name: member.name })"
                    data-edit-permissions
                    @click="startEdit(member.user_id, member.permissions)"
                  >
                    {{ $t('team.permissions.edit') }}
                  </button>
                  <StatusBadge :tone="member.mfa_enabled ? 'ok' : 'warn'" :label="$t(member.mfa_enabled ? 'account.mfa.on' : 'account.mfa.off')" />
                  <button v-if="myRole === 'owner' && !member.is_you && member.mfa_enabled && resettingMfa !== member.user_id" type="button" class="rounded-md border border-warn-border px-3 py-1.5 text-sm font-medium hover:bg-warn-soft" @click="openMfaReset(member)">{{ $t('team.mfa_reset.open') }}</button>
                  <button
                    v-if="(member.can_edit || (member.is_you && member.role !== 'owner')) && confirming !== member.user_id"
                    type="button"
                    class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-crit hover:bg-crit-soft"
                    @click="confirming = member.user_id"
                  >
                    {{ member.is_you ? $t('team.leave') : $t('team.remove') }}
                  </button>
                  <button
                    v-if="canReceiveOwnership(member) && transferring !== member.user_id"
                    type="button"
                    class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium hover:bg-surface-muted"
                    @click="openTransfer(member)"
                  >
                    {{ $t('team.transfer.open') }}
                  </button>
                </div>
              </div>
              <p v-if="savedFor === member.user_id" class="text-sm text-ok" role="status">{{ $t('team.permissions.saved') }}</p>
              <form v-if="editing === member.user_id && catalog" class="flex flex-col gap-3 rounded-md border border-border bg-surface-muted p-3" data-member-permissions @submit.prevent="savePermissions(member.user_id, 'member')">
                <PermissionPicker v-model="draft" :id-prefix="`member-${member.user_id}`" :catalog="catalog" :assignable="assignable" :presets="presets" />
                <p class="text-[13px] text-text-muted">{{ $t('team.permissions.applies_now') }}</p>
                <div class="flex gap-2">
                  <button type="submit" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="savingPermissions">{{ $t('common.save') }}</button>
                  <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm" @click="editing = null">{{ $t('common.cancel') }}</button>
                </div>
              </form>
              <form v-if="resettingMfa === member.user_id" class="flex flex-col gap-3 rounded-md border border-warn-border bg-warn-soft p-3 text-sm" data-mfa-reset @submit.prevent="submitMfaReset(member)">
                <p>{{ $t('team.mfa_reset.warning', { name: member.name }) }}</p>
                <label class="flex flex-col gap-1 font-medium">{{ $t('team.mfa_reset.reason') }}<input v-model="reset.reason" maxlength="500" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-normal" /></label>
                <label class="flex flex-col gap-1 font-medium">{{ $t('account.password.current') }}<input v-model="reset.password" type="password" autocomplete="current-password" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-normal" /></label>
                <label v-if="needsCode" class="flex flex-col gap-1 font-medium">{{ $t('account.mfa.code_or_recovery') }}<input v-model="reset.code" autocomplete="one-time-code" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-normal" /></label>
                <ErrorNotice v-if="resetError" :error="resetError" />
                <div class="flex gap-2">
                  <button type="submit" class="rounded-md bg-crit-solid px-3 py-1.5 font-medium text-white disabled:opacity-60" :disabled="resetBusy || reset.reason.trim().length < 3 || !reset.password || (needsCode && reset.code.trim().length < 6)">{{ $t('team.mfa_reset.confirm') }}</button>
                  <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5" @click="resettingMfa = null">{{ $t('common.cancel') }}</button>
                </div>
              </form>
              <form
                v-if="transferring === member.user_id"
                class="flex flex-col gap-3 rounded-md border border-warn-border bg-warn-soft p-3 text-sm"
                data-transfer
                @submit.prevent="submitTransfer(member)"
              >
                <p v-if="member.email_verified === false">{{ $t('team.transfer.unverified', { name: member.name }) }}</p>
                <template v-else>
                  <p>{{ $t('team.transfer.warning', { name: member.name }) }}</p>
                  <div class="flex flex-wrap items-end gap-3">
                    <label class="flex min-w-[12rem] flex-1 flex-col gap-1.5 font-medium">
                      {{ $t('account.password.current') }}
                      <input v-model="transfer.password" type="password" autocomplete="current-password" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base font-normal" />
                    </label>
                    <label v-if="needsCode" class="flex min-w-[10rem] flex-col gap-1.5 font-medium">
                      {{ $t('account.mfa.code_or_recovery') }}
                      <input v-model="transfer.code" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-base font-normal" />
                    </label>
                  </div>
                  <p v-if="transferStepUpKey" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-crit">{{ $t(transferStepUpKey) }}</p>
                  <ErrorNotice v-else-if="transferError" :error="transferError" />
                </template>
                <div class="flex flex-wrap gap-2">
                  <button
                    v-if="member.email_verified !== false"
                    type="submit"
                    class="rounded-md bg-primary px-3 py-1.5 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
                    :disabled="transferBusy || transfer.password === '' || (needsCode && transfer.code.trim().length < 6)"
                  >
                    {{ $t('team.transfer.submit') }}
                  </button>
                  <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium" @click="transferring = null">{{ $t('common.cancel') }}</button>
                </div>
              </form>
              <div v-if="confirming === member.user_id" class="flex flex-wrap items-center gap-2 rounded-md border border-warn-border bg-warn-soft p-3 text-sm">
                <span class="flex-1">{{ member.is_you ? $t('team.leave_warning') : $t('team.remove_warning', { name: member.name }) }}</span>
                <button type="button" class="rounded-md bg-crit-solid px-3 py-1.5 font-medium text-white" @click="remove(member)">{{ member.is_you ? $t('team.leave') : $t('team.remove') }}</button>
                <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium" @click="confirming = null">{{ $t('common.cancel') }}</button>
              </div>
            </li>
          </ul>
        </section>

        <section v-if="canInvite" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="invite-title" data-panel="invite">
          <h2 id="invite-title" class="text-lg font-semibold">{{ $t('team.invite.title') }}</h2>
          <form class="flex flex-col gap-4" novalidate @submit.prevent="sendInvite">
            <label class="flex max-w-md flex-col gap-1.5 text-sm font-medium">
              {{ $t('auth.email') }}
              <input v-model="invite.email" type="email" autocomplete="off" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base font-normal" />
            </label>
            <PermissionPicker v-if="catalog" v-model="invite.permissions" id-prefix="invite" :catalog="catalog" :assignable="assignable" :presets="presets" />
            <button type="submit" class="self-start rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="inviting || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(invite.email.trim())">
              {{ $t('team.invite.submit') }}
            </button>
          </form>
          <p v-if="invited" class="text-sm text-ok" role="status">{{ $t('team.invite.sent', { email: invited }) }}</p>

          <div v-if="invitations.length > 0" class="flex flex-col gap-2">
            <h3 class="text-sm font-semibold">{{ $t('team.invite.pending') }}</h3>
            <ul class="flex flex-col divide-y divide-border rounded-lg border border-border">
              <li v-for="item in invitations" :key="item.id" class="flex flex-col gap-2 p-3 text-sm" :data-invitation="item.email">
                <div class="flex flex-wrap items-center justify-between gap-3">
                  <div class="flex min-w-0 flex-col gap-0.5">
                    <span class="break-all font-medium">{{ item.email }}</span>
                    <span class="text-[13px] text-text-muted">{{ $t(`team.role.${item.role}`) }}<template v-if="!item.permissions.includes('money.view')"> · {{ $t('team.permissions.no_amounts') }}</template> · {{ $t('team.invite.until', { time: dateTime(item.expires_at) }) }}<template v-if="item.invited_by"> · {{ item.invited_by }}</template></span>
                  </div>
                  <div class="flex flex-wrap gap-2">
                    <button v-if="invitationEditable(item) && editing !== item.id" type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium hover:bg-surface-muted" data-edit-invitation @click="startEdit(item.id, item.permissions)">{{ $t('team.permissions.edit') }}</button>
                    <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium text-crit hover:bg-crit-soft" @click="act(() => revokeInvitation(item.id))">{{ $t('team.invite.revoke') }}</button>
                  </div>
                </div>
                <p v-if="savedFor === item.id" class="text-ok" role="status">{{ $t('team.permissions.saved') }}</p>
                <form v-if="editing === item.id && catalog" class="flex flex-col gap-3 rounded-md border border-border bg-surface-muted p-3" data-invitation-permissions @submit.prevent="savePermissions(item.id, 'invitation')">
                  <PermissionPicker v-model="draft" :id-prefix="`invitation-${item.id}`" :catalog="catalog" :assignable="assignable" :presets="presets" />
                  <div class="flex gap-2">
                    <button type="submit" class="rounded-md bg-primary px-3 py-1.5 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="savingPermissions">{{ $t('common.save') }}</button>
                    <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5" @click="editing = null">{{ $t('common.cancel') }}</button>
                  </div>
                </form>
              </li>
            </ul>
          </div>
        </section>
      </template>
    </div>
  </AppLayout>
</template>
