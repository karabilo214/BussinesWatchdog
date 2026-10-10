<script setup lang="ts">
import { computed, ref, watch } from 'vue';

/** New password + confirmation with the backend's rules (12–128 characters); the value is reported only when valid. */
const props = defineProps<{ idPrefix: string }>();
const emit = defineEmits<{ update: [password: string, valid: boolean] }>();

const password = ref('');
const confirmation = ref('');
const tooShort = computed(() => password.value.length > 0 && password.value.length < 12);
const mismatch = computed(() => confirmation.value.length > 0 && confirmation.value !== password.value);
const valid = computed(() => password.value.length >= 12 && password.value.length <= 128 && password.value === confirmation.value);

watch([password, valid], () => emit('update', password.value, valid.value));
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-col gap-1.5">
      <label :for="`${props.idPrefix}-password`" class="text-sm font-medium">{{ $t('account.password.new') }}</label>
      <input :id="`${props.idPrefix}-password`" v-model="password" type="password" autocomplete="new-password" maxlength="128" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
      <p class="text-[13px]" :class="tooShort ? 'text-crit' : 'text-text-muted'">{{ $t('account.password.rule') }}</p>
    </div>
    <div class="flex flex-col gap-1.5">
      <label :for="`${props.idPrefix}-confirmation`" class="text-sm font-medium">{{ $t('account.password.repeat') }}</label>
      <input :id="`${props.idPrefix}-confirmation`" v-model="confirmation" type="password" autocomplete="new-password" maxlength="128" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
      <p v-if="mismatch" class="text-[13px] text-crit">{{ $t('account.password.mismatch') }}</p>
    </div>
  </div>
</template>
