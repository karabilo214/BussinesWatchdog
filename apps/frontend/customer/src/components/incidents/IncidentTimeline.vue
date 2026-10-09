<script setup lang="ts">
import { computed, ref } from 'vue';
import { commentIncident } from '@/api/incidents';
import type { IncidentActivity } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';
import { useSession } from '@/composables/useSession';
import { useIncidentText } from './useIncidentText';

const props = defineProps<{ incidentId: string; activity: IncidentActivity[]; canComment: boolean }>();
const emit = defineEmits<{ commented: [] }>();
const { dateTime } = useFormat();
const { session } = useSession();
const { resolution } = useIncidentText();

const text = ref('');
const sending = ref(false);
const error = ref<unknown>(null);

const entries = computed(() => [...props.activity].reverse());

function actor(entry: IncidentActivity): string {
  if (entry.actor_id === null) return 'incident.timeline.actor.system';
  if (entry.actor_id === session.value?.user.id) return 'incident.timeline.actor.you';

  return 'incident.timeline.actor.member';
}

function detail(entry: IncidentActivity): string | null {
  const data = entry.data;

  if (entry.kind === 'comment' && typeof data.text === 'string') return data.text;
  if (entry.kind === 'resolved' && typeof data.reason === 'string') return resolution(data.reason);
  if (entry.kind === 'suppressed' && typeof data.reason === 'string') return data.reason;

  return null;
}

async function send(): Promise<void> {
  sending.value = true;
  error.value = null;

  try {
    await commentIncident(props.incidentId, text.value.trim());
    text.value = '';
    emit('commented');
  } catch (caught) {
    error.value = caught;
  } finally {
    sending.value = false;
  }
}
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="timeline-title" data-panel="incident-timeline">
    <h2 id="timeline-title" class="text-lg font-semibold">{{ $t('incident.timeline.title') }}</h2>

    <form v-if="canComment" class="flex flex-col gap-2" @submit.prevent="send">
      <label for="incident-comment" class="text-sm font-medium">{{ $t('incident.timeline.comment') }}</label>
      <textarea id="incident-comment" v-model="text" rows="3" maxlength="4000" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm"></textarea>
      <ErrorNotice v-if="error" :error="error" />
      <div>
        <button type="submit" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="sending || text.trim() === ''">
          {{ $t('incident.timeline.send') }}
        </button>
      </div>
    </form>

    <ol class="flex flex-col">
      <li v-for="entry in entries" :key="entry.id" class="relative flex flex-col gap-1 border-l-2 border-border pb-4 pl-4 last:pb-0" :data-activity-kind="entry.kind">
        <span class="absolute -left-[5px] top-1.5 size-2 rounded-full bg-border-strong" aria-hidden="true"></span>
        <div class="flex flex-wrap gap-x-2 text-sm">
          <span class="font-medium">{{ $t(`incident.timeline.kind.${entry.kind}`) }}</span>
          <span class="text-text-muted">{{ $t(actor(entry)) }} · {{ dateTime(entry.created_at) }}</span>
        </div>
        <p v-if="entry.kind === 'suppressed' && typeof entry.data.until === 'string'" class="text-[13px] text-text-muted">
          {{ $t('incident.timeline.suppressed_until', { time: dateTime(entry.data.until) }) }}
        </p>
        <p v-if="detail(entry)" class="whitespace-pre-line break-words text-sm">{{ detail(entry) }}</p>
      </li>
    </ol>
  </section>
</template>
