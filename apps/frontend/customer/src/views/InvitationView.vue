<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { ApiError } from '@bw/api-client';
import { acceptInvitation, lookupInvitation, registerWithInvitation } from '@/api/account';
import type { InvitationLookup, Locale } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import PasswordFields from '@/components/account/PasswordFields.vue';
import { useSession } from '@/composables/useSession';
import { useFormat } from '@/composables/useFormat';
import AuthLayout from '@/layouts/AuthLayout.vue';

const route = useRoute();
const router = useRouter();
const { locale } = useI18n();
const { session, load, replace, signOut } = useSession();
const { dateTime } = useFormat();

const token = typeof route.query.token === 'string' ? route.query.token : '';
const invitation = ref<InvitationLookup | null>(null);
const loading = ref(true);
const lookupError = ref<unknown>(null);
const busy = ref(false);
const error = ref<unknown>(null);
const name = ref('');
const password = ref('');
const passwordValid = ref(false);

const signedInAs = computed(() => session.value?.user.email ?? null);
const invalid = computed(() => token === '' || (lookupError.value instanceof ApiError && lookupError.value.status === 422));
const sameAddress = computed(() => signedInAs.value !== null && invitation.value !== null && signedInAs.value.toLowerCase() === invitation.value.email.toLowerCase());

async function accept(): Promise<void> {
  busy.value = true;
  error.value = null;

  try {
    await acceptInvitation(token);
    await load(true);
    await router.replace({ name: 'overview' });
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

async function register(): Promise<void> {
  if (invitation.value === null) return;

  busy.value = true;
  error.value = null;

  try {
    replace(await registerWithInvitation({ token, name: name.value.trim(), email: invitation.value.email, password: password.value, locale: locale.value as Locale }));
    await router.replace({ name: 'overview' });
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

async function switchAccount(): Promise<void> {
  await signOut().catch(() => undefined);
}

onMounted(async () => {
  await load().catch(() => null);

  try {
    invitation.value = token === '' ? null : await lookupInvitation(token);
  } catch (caught) {
    lookupError.value = caught;
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <AuthLayout>
    <div class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-6 shadow-sm" data-panel="invitation">
      <h1 class="text-2xl font-semibold">{{ $t('account.invitation.title') }}</h1>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="!invitation && lookupError && !invalid" :error="lookupError" />
      <p v-else-if="!invitation" class="rounded-md border border-warn-border bg-warn-soft px-3 py-2 text-sm" data-invalid>{{ $t('account.invitation.invalid') }}</p>

      <template v-else>
        <p class="text-sm">{{ $t('account.invitation.offer', { team: invitation.tenant_name, role: $t(`team.role.${invitation.role}`) }) }}</p>
        <p class="text-[13px] text-text-muted">{{ $t('account.invitation.for', { email: invitation.email, time: dateTime(invitation.expires_at) }) }}</p>
        <ErrorNotice v-if="error" :error="error" />

        <template v-if="signedInAs">
          <div v-if="sameAddress" class="flex flex-col gap-3">
            <button type="button" class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy" @click="accept">
              {{ $t('account.invitation.accept') }}
            </button>
          </div>
          <div v-else class="flex flex-col gap-3 rounded-md border border-warn-border bg-warn-soft p-3 text-sm">
            <p>{{ $t('account.invitation.other_account', { email: signedInAs }) }}</p>
            <button type="button" class="self-start rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium text-primary" @click="switchAccount">{{ $t('account.invitation.switch') }}</button>
          </div>
        </template>

        <div v-else-if="invitation.account_exists" class="flex flex-col gap-3">
          <p class="text-sm">{{ $t('account.invitation.sign_in_first') }}</p>
          <RouterLink :to="{ name: 'login', query: { redirect: route.fullPath } }" class="self-start rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover">
            {{ $t('auth.submit') }}
          </RouterLink>
        </div>

        <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="register">
          <p class="text-sm">{{ $t('account.invitation.create_account') }}</p>
          <div class="flex flex-col gap-1.5">
            <label for="invite-name" class="text-sm font-medium">{{ $t('account.profile.name') }}</label>
            <input id="invite-name" v-model="name" maxlength="100" autocomplete="name" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
          </div>
          <PasswordFields id-prefix="invite" @update="(value, ok) => { password = value; passwordValid = ok; }" />
          <button type="submit" class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || name.trim() === '' || !passwordValid">
            {{ $t('account.invitation.join') }}
          </button>
        </form>
      </template>
    </div>
  </AuthLayout>
</template>
