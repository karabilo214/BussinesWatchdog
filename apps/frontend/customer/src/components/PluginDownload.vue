<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { PLUGIN_DOWNLOAD_URL, getPluginPackage, type PluginPackage } from '@/api/plugin';

const pack = ref<PluginPackage | null>(null);
const emit = defineEmits<{ downloaded: [] }>();

onMounted(async () => {
  try {
    pack.value = await getPluginPackage();
  } catch {
    pack.value = { available: false };
  }
});
</script>

<template>
  <div v-if="pack" class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-4 text-sm" data-plugin-download>
    <template v-if="pack.available">
      <div class="flex flex-wrap items-center gap-3">
        <a :href="PLUGIN_DOWNLOAD_URL" :download="pack.file" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover" data-action="download-plugin" @click="emit('downloaded')">{{ $t('store.connector.download.button') }}</a>
        <span class="text-text-muted">{{ $t('store.connector.download.meta', { version: pack.version, size: Math.max(1, Math.round((pack.size_bytes ?? 0) / 1024)) }) }}</span>
      </div>
      <p class="text-text-muted">{{ $t('store.connector.download.install') }}</p>
      <p class="break-all text-[12px] text-text-muted"><span class="font-medium">{{ $t('store.connector.download.checksum') }}</span> <span class="font-mono">{{ pack.sha256 }}</span></p>
      <p class="text-[12px] text-text-muted">{{ $t('store.connector.download.checksum_hint') }}</p>
    </template>
    <p v-else class="text-text-muted" data-plugin-missing>{{ $t('store.connector.download.missing') }}</p>
  </div>
</template>
