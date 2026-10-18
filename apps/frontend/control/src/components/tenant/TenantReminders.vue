<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { fetchTenantReminders, type SentReminder } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';

const props = defineProps<{ tenantId: string }>();

const { dateTime } = useFormat();
const reminders = ref<SentReminder[] | null>(null);
const error = ref<unknown>(null);

onMounted(async () => {
  try {
    reminders.value = await fetchTenantReminders(props.tenantId);
  } catch (caught) {
    error.value = caught;
  }
});
</script>

<template>
  <section class="flex flex-col gap-3 text-sm" data-reminder-history>
    <p class="text-text-muted">{{ $t('tenant.reminders.hint') }}</p>
    <ErrorNotice v-if="error" :error="error" />
    <p v-else-if="reminders === null" class="text-text-muted" role="status">{{ $t('common.loading') }}</p>
    <p v-else-if="reminders.length === 0" class="rounded-xl border border-dashed border-border-strong bg-surface p-6 text-text-muted">{{ $t('tenant.reminders.empty') }}</p>
    <details v-for="reminder in reminders ?? []" :key="reminder.id" class="rounded-xl border border-border bg-surface p-4" :data-reminder="reminder.id">
      <summary class="cursor-pointer">
        <span class="font-medium">{{ $t(`tenant.reminder.topics.${reminder.topic}`) }}</span>
        · {{ dateTime(reminder.sent_at) }}
        · {{ reminder.sender ? (reminder.sender.name ?? reminder.sender.email) : $t('tenant.reminders.unknown_sender') }}
        · {{ reminder.messages.map((message) => message.email).join(', ') }}
      </summary>
      <article v-for="message in reminder.messages" :key="message.email" class="mt-3 flex flex-col gap-1 rounded-md border border-border p-3" :data-message="message.email">
        <p class="text-xs text-text-muted">{{ message.email }} · {{ message.locale.toUpperCase() }}</p>
        <p class="font-semibold">{{ message.subject }}</p>
        <p class="whitespace-pre-line">{{ message.body }}</p>
      </article>
    </details>
  </section>
</template>
