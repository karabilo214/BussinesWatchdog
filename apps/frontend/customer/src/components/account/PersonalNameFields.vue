<script setup lang="ts">
import type { PersonalName, Salutation } from '@/api/types';

defineProps<{ idPrefix: string }>();

const model = defineModel<PersonalName>({ required: true });
const salutations: Salutation[] = ['mr', 'ms', 'diverse'];
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="`${idPrefix}-salutation`" class="text-sm font-medium">{{ $t('account.profile.salutation') }}</label>
    <select :id="`${idPrefix}-salutation`" v-model="model.salutation" autocomplete="honorific-prefix" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base">
      <option :value="null">{{ $t('account.profile.salutations.none') }}</option>
      <option v-for="code in salutations" :key="code" :value="code">{{ $t(`account.profile.salutations.${code}`) }}</option>
    </select>
  </div>
  <div class="flex flex-col gap-1.5">
    <label :for="`${idPrefix}-first-name`" class="text-sm font-medium">{{ $t('account.profile.first_name') }}</label>
    <input :id="`${idPrefix}-first-name`" v-model="model.first_name" maxlength="100" autocomplete="given-name" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
  </div>
  <div class="flex flex-col gap-1.5">
    <label :for="`${idPrefix}-last-name`" class="text-sm font-medium">{{ $t('account.profile.last_name') }} <span class="text-crit" aria-hidden="true">*</span></label>
    <input :id="`${idPrefix}-last-name`" v-model="model.last_name" maxlength="100" autocomplete="family-name" required class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
  </div>
  <div class="flex flex-col gap-1.5">
    <label :for="`${idPrefix}-company`" class="text-sm font-medium">{{ $t('account.profile.company_name') }}</label>
    <input :id="`${idPrefix}-company`" v-model="model.company_name" maxlength="150" autocomplete="organization" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
    <p class="text-[13px] text-text-muted">{{ $t('account.profile.company_hint') }}</p>
  </div>
</template>
