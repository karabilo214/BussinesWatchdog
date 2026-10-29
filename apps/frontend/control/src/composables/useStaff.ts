import { ref } from 'vue';
import { ApiError } from '@bw/api-client';
import { fetchMe, login, logout, submitMfa, type StaffMe } from '@/api';

const me = ref<StaffMe | null>(null);
let loaded: Promise<StaffMe | null> | null = null;

export function useStaff() {
  async function load(force = false): Promise<StaffMe | null> {
    if (force || loaded === null) {
      loaded = fetchMe()
        .then((value) => (me.value = value))
        .catch((error: unknown) => {
          if (error instanceof ApiError && error.status === 401) {
            me.value = null;

            return null;
          }

          loaded = null;
          throw error;
        });
    }

    return loaded;
  }

  async function signIn(email: string, password: string): Promise<void> {
    await login(email, password);
  }

  async function completeMfa(code: string): Promise<StaffMe> {
    me.value = await submitMfa(code);
    loaded = Promise.resolve(me.value);

    return me.value;
  }

  async function signOut(): Promise<void> {
    try {
      await logout();
    } finally {
      forget();
    }
  }

  function acceptSession(value: StaffMe): void {
    me.value = value;
    loaded = Promise.resolve(value);
  }

  function forget(): void {
    me.value = null;
    loaded = Promise.resolve(null);
  }

  function can(capability: string): boolean {
    return me.value?.capabilities.includes(capability) ?? false;
  }

  return { me, load, signIn, completeMfa, acceptSession, signOut, forget, can };
}
