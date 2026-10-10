<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { changeMemberRole, inviteMember, listInvitations, listMembers, removeMember, revokeInvitation } from '@/api/account';
import type { TeamInvitation, TeamMember, TeamRole } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import SettingsTabs from '@/components/account/SettingsTabs.vue';
import { useFormat } from '@/composables/useFormat';
import { useRole } from '@/composables/useRole';
import { useSession } from '@/composables/useSession';
import AppLayout from '@/layouts/AppLayout.vue';
import { StatusBadge } from '@bw/ui';

type Assignable = Exclude<TeamRole, 'owner'>;

const router = useRouter();
const { dateTime } = useFormat();
const { canManageStores } = useRole();
const { load: reloadSession } = useSession();

const members = ref<TeamMember[]>([]);
const assignable = ref<Assignable[]>([]);
const invitations = ref<TeamInvitation[]>([]);
const loading = ref(true);
const error = ref<unknown>(null);
const actionError = ref<unknown>(null);
const confirming = ref<string | null>(null);
const invite = reactive({ email: '', role: 'viewer' as Assignable });
const inviting = ref(false);
const invited = ref<string | null>(null);

const canInvite = computed(() => canManageStores.value && assignable.value.length > 0);

function manageable(member: TeamMember): boolean {
  return !member.is_you && member.role !== 'owner' && assignable.value.includes(member.role as Assignable);
}

async function load(): Promise<void> {
  error.value = null;

  try {
    const result = await listMembers();
    members.value = result.data;
    assignable.value = result.assignable_roles;
    invitations.value = canManageStores.value ? await listInvitations() : [];

    if (!assignable.value.includes(invite.role)) {
      invite.role = assignable.value.at(-1) ?? 'viewer';
    }
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
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

function setRole(member: TeamMember, role: Assignable): Promise<void> {
  return act(() => changeMemberRole(member.user_id, role));
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
    const created = await inviteMember(invite.email.trim(), invite.role);
    invited.value = created.email;
    invite.email = '';
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
                  <select
                    v-if="manageable(member)"
                    :value="member.role"
                    :aria-label="$t('team.role_for', { name: member.name })"
                    class="rounded-md border border-border-strong bg-surface px-2 py-1.5 text-sm"
                    @change="setRole(member, ($event.target as HTMLSelectElement).value as Assignable)"
                  >
                    <option v-for="role in assignable" :key="role" :value="role">{{ $t(`team.role.${role}`) }}</option>
                  </select>
                  <StatusBadge v-else :tone="member.role === 'owner' ? 'note' : 'unknown'" :label="$t(`team.role.${member.role}`)" />
                  <button
                    v-if="(manageable(member) || (member.is_you && member.role !== 'owner')) && confirming !== member.user_id"
                    type="button"
                    class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-crit hover:bg-crit-soft"
                    @click="confirming = member.user_id"
                  >
                    {{ member.is_you ? $t('team.leave') : $t('team.remove') }}
                  </button>
                </div>
              </div>
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
          <form class="flex flex-wrap items-end gap-3" novalidate @submit.prevent="sendInvite">
            <label class="flex min-w-[14rem] flex-1 flex-col gap-1.5 text-sm font-medium">
              {{ $t('auth.email') }}
              <input v-model="invite.email" type="email" autocomplete="off" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base font-normal" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm font-medium">
              {{ $t('team.invite.role') }}
              <select v-model="invite.role" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base font-normal">
                <option v-for="role in assignable" :key="role" :value="role">{{ $t(`team.role.${role}`) }}</option>
              </select>
            </label>
            <button type="submit" class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="inviting || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(invite.email.trim())">
              {{ $t('team.invite.submit') }}
            </button>
          </form>
          <p v-if="invited" class="text-sm text-ok" role="status">{{ $t('team.invite.sent', { email: invited }) }}</p>

          <div v-if="invitations.length > 0" class="flex flex-col gap-2">
            <h3 class="text-sm font-semibold">{{ $t('team.invite.pending') }}</h3>
            <ul class="flex flex-col divide-y divide-border rounded-lg border border-border">
              <li v-for="item in invitations" :key="item.id" class="flex flex-wrap items-center justify-between gap-3 p-3 text-sm" :data-invitation="item.email">
                <div class="flex min-w-0 flex-col gap-0.5">
                  <span class="break-all font-medium">{{ item.email }}</span>
                  <span class="text-[13px] text-text-muted">{{ $t(`team.role.${item.role}`) }} · {{ $t('team.invite.until', { time: dateTime(item.expires_at) }) }}<template v-if="item.invited_by"> · {{ item.invited_by }}</template></span>
                </div>
                <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium text-crit hover:bg-crit-soft" @click="act(() => revokeInvitation(item.id))">{{ $t('team.invite.revoke') }}</button>
              </li>
            </ul>
          </div>
        </section>
      </template>
    </div>
  </AppLayout>
</template>
