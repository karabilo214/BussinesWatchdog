import { readonly, ref } from 'vue';
import * as auth from '@/api/auth';
import { ApiError } from '@bw/api-client';
import type { AuthSession } from '@/api/types';

const session = ref<AuthSession | null>(null);
const loaded = ref(false);
let pending: Promise<AuthSession | null> | null = null;
let loadedAt = 0;

/** Permissions can change while the tab is open (ADR 0079); an older session is re-read in the background. */
const REFRESH_MS = 60_000;

async function load(force = false): Promise<AuthSession | null> {
  if (loaded.value && !force) {
    if (session.value !== null && pending === null && Date.now() - loadedAt > REFRESH_MS) {
      void fetchInto().catch(() => undefined);
    }

    return session.value;
  }

  return fetchInto();
}

function fetchInto(): Promise<AuthSession | null> {
  pending ??= auth.fetchSession()
    .then((value) => value)
    .catch((error: unknown) => {
      if (error instanceof ApiError && error.status === 401) {
        return null;
      }

      throw error;
    })
    .then((value) => {
      session.value = value;
      loaded.value = true;
      loadedAt = Date.now();

      return value;
    })
    .finally(() => {
      pending = null;
    });

  return pending;
}

/** Resolves to 'mfa' when the password was right and the second factor is still needed. */
async function signIn(email: string, password: string, remember: boolean): Promise<AuthSession | 'mfa'> {
  const result = await auth.login(email, password, remember);

  if ('mfa_required' in result) {
    return 'mfa';
  }

  session.value = result;
  loaded.value = true;

  return result;
}

async function completeMfa(code: string, rememberDevice = false): Promise<AuthSession> {
  session.value = await auth.completeMfaChallenge(code, rememberDevice);
  loaded.value = true;

  return session.value;
}

async function signInWithPasskey(remember: boolean): Promise<AuthSession> {
  session.value = await auth.loginWithPasskey(remember);
  loaded.value = true;

  return session.value;
}

async function signOut(): Promise<void> {
  try {
    await auth.logout();
  } finally {
    clear();
  }
}

/** Replaces the cached session after the server returned a fresh one (profile change, sign-up, joining a team). */
function replace(value: AuthSession): void {
  session.value = value;
  loaded.value = true;
}

function clear(): void {
  session.value = null;
  loaded.value = true;
}

export function useSession() {
  return { session: readonly(session), loaded: readonly(loaded), load, signIn, signInWithPasskey, completeMfa, signOut, clear, replace };
}
