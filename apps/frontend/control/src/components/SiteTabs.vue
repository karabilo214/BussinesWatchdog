<script setup lang="ts">
defineProps<{ current: 'pages' | 'blocks' }>();
</script>

<template>
  <nav class="flex gap-1 border-b border-border" :aria-label="$t('section.site.title')" data-site-tabs>
    <RouterLink
      v-for="tab in (['pages', 'blocks'] as const)"
      :key="tab"
      :to="{ name: tab === 'pages' ? 'owner-site' : 'owner-blocks' }"
      class="-mb-px border-b-2 px-3 py-2 text-sm font-medium"
      :class="current === tab ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text'"
      :aria-current="current === tab ? 'page' : undefined"
      :data-site-tab="tab"
    >
      {{ $t(`site.tabs.${tab}`) }}
    </RouterLink>
  </nav>
</template>
