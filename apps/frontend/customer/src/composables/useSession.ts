import { readonly, ref } from 'vue';
import * as auth from '@/api/auth';
import { ApiError } from '@bw/api-client';
import type { AuthSession } from '@/api/types';

const session = ref<AuthSession | null>(null);
const loaded = ref(false);
let pending: Promise<AuthSession | null> | null = null;

async function load(force = false): Promise<AuthSession | null> {
  if (loaded.value && !force) {
    return session.value;
  }

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

      return value;
    })
    .finally(() => {
      pending = null;
    });

  return pending;
}

async function signIn(email: string, password: string, remember: boolean): Promise<AuthSession> {
  session.value = await auth.login(email, password, remember);
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

function clear(): void {
  session.value = null;
  loaded.value = true;
}

export function useSession() {
  return { session: readonly(session), loaded: readonly(loaded), load, signIn, signOut, clear };
}
