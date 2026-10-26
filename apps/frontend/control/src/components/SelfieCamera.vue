<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

const emit = defineEmits<{ save: [photo: Blob]; cancel: [] }>();

const video = ref<HTMLVideoElement | null>(null);
const preview = ref<string | null>(null);
const shot = ref<Blob | null>(null);
const problem = ref<'unsupported' | 'denied' | 'no_camera' | 'failed' | null>(null);
let stream: MediaStream | null = null;

/** The camera is on only while the live picture is shown: off right after the shot, on cancel and on leaving. */
function stop(): void {
  stream?.getTracks().forEach((track) => track.stop());
  stream = null;
}

async function start(): Promise<void> {
  problem.value = null;

  if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
    problem.value = 'unsupported';

    return;
  }

  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 1024 }, height: { ideal: 1024 } }, audio: false });

    if (video.value) {
      video.value.srcObject = stream;
      await video.value.play().catch(() => undefined);
    }
  } catch (caught) {
    const name = caught instanceof DOMException ? caught.name : '';
    problem.value = name === 'NotAllowedError' || name === 'SecurityError' ? 'denied' : name === 'NotFoundError' || name === 'OverconstrainedError' ? 'no_camera' : 'failed';
    stop();
  }
}

function capture(): void {
  const source = video.value;

  if (!source || source.videoWidth === 0) return;

  const side = Math.min(source.videoWidth, source.videoHeight);
  const canvas = document.createElement('canvas');
  canvas.width = side;
  canvas.height = side;
  canvas.getContext('2d')?.drawImage(source, (source.videoWidth - side) / 2, (source.videoHeight - side) / 2, side, side, 0, 0, side, side);
  stop();
  canvas.toBlob((blob) => {
    if (!blob) return;
    shot.value = blob;
    preview.value = URL.createObjectURL(blob);
  }, 'image/jpeg', 0.9);
}

function retake(): void {
  if (preview.value) URL.revokeObjectURL(preview.value);
  preview.value = null;
  shot.value = null;
  void start();
}

function cancel(): void {
  stop();
  emit('cancel');
}

onMounted(start);
onBeforeUnmount(() => {
  stop();
  if (preview.value) URL.revokeObjectURL(preview.value);
});
</script>

<template>
  <div class="flex flex-col gap-3 rounded-lg border border-border bg-surface p-3 text-sm" data-selfie>
    <p v-if="problem" role="alert" class="rounded-md border border-warn-border bg-warn-soft px-3 py-2" :data-camera-problem="problem">{{ $t(`profile.camera.${problem}`) }}</p>
    <template v-else>
      <img v-if="preview" :src="preview" alt="" class="aspect-square w-full max-w-64 rounded-lg object-cover" data-selfie-preview />
      <video v-else ref="video" class="aspect-square w-full max-w-64 rounded-lg bg-brand-950 object-cover" muted playsinline autoplay data-selfie-video />
    </template>
    <div class="flex flex-wrap gap-2">
      <button v-if="!problem && !preview" type="button" class="rounded-md bg-primary px-3 py-1.5 font-semibold text-text-inverse hover:bg-primary-hover" data-selfie-capture @click="capture">{{ $t('profile.camera.capture') }}</button>
      <template v-if="preview">
        <button type="button" class="rounded-md bg-primary px-3 py-1.5 font-semibold text-text-inverse hover:bg-primary-hover" data-selfie-save @click="shot && emit('save', shot)">{{ $t('profile.camera.save') }}</button>
        <button type="button" class="rounded-md border border-border-strong px-3 py-1.5 font-medium hover:bg-surface-muted" data-selfie-retake @click="retake">{{ $t('profile.camera.retake') }}</button>
      </template>
      <button type="button" class="rounded-md border border-border-strong px-3 py-1.5 font-medium hover:bg-surface-muted" data-selfie-cancel @click="cancel">{{ $t('common.cancel') }}</button>
    </div>
  </div>
</template>
