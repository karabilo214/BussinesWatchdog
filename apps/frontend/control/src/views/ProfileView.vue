<script setup lang="ts">
import StaffProfileEditor from '@/components/StaffProfileEditor.vue';
import { useStaff } from '@/composables/useStaff';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const { me, load } = useStaff();
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-3xl flex-col gap-6">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('profile.title') }}</h1>
        <p class="text-sm text-text-muted">{{ me?.email }}</p>
      </div>
      <div v-if="me" class="rounded-xl border border-border bg-surface p-5">
        <StaffProfileEditor :staff-id="me.staff_id" :first-name="me.first_name" :last-name="me.last_name" :name="me.name" :photo-url="me.photo_url" self @changed="load(true)" />
      </div>
    </div>
  </OwnerLayout>
</template>
