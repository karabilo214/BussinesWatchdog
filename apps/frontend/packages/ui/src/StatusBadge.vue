<script setup lang="ts">
import { computed } from 'vue';
import { SOLID_TONE_CLASSES, TONE_CLASSES, type Tone } from './tones';

const props = withDefaults(defineProps<{ tone: Tone; label: string; solid?: boolean }>(), { solid: false });

const classes = computed(() => (props.solid ? SOLID_TONE_CLASSES : TONE_CLASSES)[props.tone]);
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-0.5 text-[13px] font-medium leading-5"
    :class="classes"
    :data-tone="tone"
  >
    <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
    {{ label }}
  </span>
</template>
