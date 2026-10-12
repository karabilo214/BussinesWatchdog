<template>
  <nav :aria-label="$t('section.plans.title')" class="flex gap-1 self-start rounded-lg border border-border bg-surface p-1">
    <RouterLink
      v-for="tab in ['owner-plans', 'owner-discounts']"
      :key="tab"
      :to="{ name: tab }"
      class="rounded-md px-3 py-1.5 text-sm font-medium text-text-muted hover:bg-surface-muted hover:text-text"
      exact-active-class="bg-primary-soft !text-primary"
    >
      {{ $t(`plans.tab.${tab}`) }}
    </RouterLink>
  </nav>
</template>
