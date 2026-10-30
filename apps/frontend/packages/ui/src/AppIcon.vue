<script setup lang="ts">
import { ICONS, type IconName } from './icons/lucide';

withDefaults(defineProps<{ name: IconName; size?: number }>(), { size: 20 });
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <component :is="tag" v-for="([tag, attrs], index) in ICONS[name]" :key="index" v-bind="attrs" />
  </svg>
</template>
