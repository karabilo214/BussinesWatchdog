<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { listTrustedDevices, revokeTrustedDevice, type TrustedDevice } from '@/api/account';
import ErrorNotice from '@/components/ErrorNotice.vue';

const devices = ref<TrustedDevice[]>([]);
const loading = ref(true);
const busy = ref(false);
const error = ref<unknown>(null);

async function load(): Promise<void> {
  loading.value = true;
  try {
    devices.value = await listTrustedDevices();
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function revoke(id: string): Promise<void> {
  busy.value = true;
  error.value = null;
  try {
    await revokeTrustedDevice(id);
    await load();
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

onMounted(() => void load());
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="trusted-devices-title" data-panel="trusted-devices">
    <h2 id="trusted-devices-title" class="text-lg font-semibold">{{ $t('account.trusted_devices.title') }}</h2>
    <p class="text-sm text-text-muted">{{ $t('account.trusted_devices.description') }}</p>
    <p v-if="loading" class="text-sm text-text-muted">{{ $t('common.loading') }}</p>
    <p v-else-if="devices.length === 0" class="text-sm text-text-muted">{{ $t('account.trusted_devices.empty') }}</p>
    <ul v-else class="divide-y divide-border">
      <li v-for="device in devices" :key="device.id" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
        <span>
          <strong>{{ $t(`account.sessions.device.${device.device}`) }}</strong>
          <span v-if="device.current" class="ml-2 text-text-muted">{{ $t('account.trusted_devices.current') }}</span>
          <span class="block text-text-muted">{{ $t('account.trusted_devices.expires') }} {{ new Date(device.expires_at).toLocaleDateString() }}</span>
        </span>
        <button type="button" class="text-crit hover:underline disabled:opacity-60" :disabled="busy" @click="revoke(device.id)">{{ $t('account.trusted_devices.revoke') }}</button>
      </li>
    </ul>
    <ErrorNotice v-if="error" :error="error" />
  </section>
</template>
