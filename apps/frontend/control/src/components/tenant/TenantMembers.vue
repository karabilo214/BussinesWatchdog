<script setup lang="ts">
import type { TenantCard } from '@/api';
import { useFormat } from '@/composables/useFormat';

defineProps<{ card: TenantCard }>();
const { dateTime } = useFormat();
</script>

<template>
  <section class="overflow-x-auto rounded-xl border border-border bg-surface" data-section="members">
    <table class="w-full min-w-[32rem] text-sm">
      <tbody class="divide-y divide-border">
        <tr v-for="member in card.members" :key="`${member.email}-${member.role}`">
          <td class="px-4 py-3 font-medium">{{ member.name ?? '—' }}</td>
          <td class="px-4 py-3">{{ member.email ?? '—' }} <span v-if="member.email_verified" class="text-ok">✓</span></td>
          <td class="px-4 py-3">{{ $t(`member_role.${member.role}`, member.role) }}</td>
          <td class="px-4 py-3 text-text-muted">{{ dateTime(member.joined_at) }}</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
