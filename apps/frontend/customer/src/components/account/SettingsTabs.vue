<template>
  <nav :aria-label="$t('settings.title')" class="flex gap-1 rounded-lg border border-border bg-surface p-1 self-start">
    <RouterLink
      v-for="tab in ['profile', 'team']"
      :key="tab"
      :to="{ name: tab }"
      class="rounded-md px-3 py-1.5 text-sm font-medium text-text-muted hover:bg-surface-muted hover:text-text"
      exact-active-class="bg-primary-soft !text-primary"
    >
      {{ $t(`settings.tab.${tab}`) }}
    </RouterLink>
  </nav>
</template>
