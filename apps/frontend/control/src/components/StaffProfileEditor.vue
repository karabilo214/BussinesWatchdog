<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { changeOwnPassword, removeStaffPhoto, sendStaffPasswordLink, updateStaffProfile, uploadStaffPhoto, type StaffProfile } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import SelfieCamera from '@/components/SelfieCamera.vue';
import StaffAvatar from '@/components/StaffAvatar.vue';

const props = defineProps<{ staffId: string; firstName: string | null; lastName: string | null; name: string | null; photoUrl: string | null; self: boolean }>();
const emit = defineEmits<{ changed: [profile: StaffProfile | null] }>();

const form = reactive({ first_name: props.firstName ?? '', last_name: props.lastName ?? '' });
const photo = ref(props.photoUrl);
const camera = ref(false);
const busy = ref(false);
const error = ref<unknown>(null);
const notice = ref<string | null>(null);
const password = reactive({ current: '', next: '', repeat: '', code: '' });
const linkCode = ref('');

watch(() => props.photoUrl, (value) => (photo.value = value));

async function run(task: () => Promise<StaffProfile | void>, message: string): Promise<boolean> {
  busy.value = true;
  error.value = null;
  notice.value = null;

  try {
    const result = await task();
    if (result) photo.value = result.photo_url;
    notice.value = message;
    emit('changed', result ?? null);

    return true;
  } catch (caught) {
    error.value = caught;

    return false;
  } finally {
    busy.value = false;
  }
}

function saveName(): Promise<boolean> {
  return run(() => updateStaffProfile(props.staffId, { first_name: form.first_name.trim() || null, last_name: form.last_name.trim() }), 'profile.saved');
}

async function pick(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  input.value = '';
  if (file) await run(() => uploadStaffPhoto(props.staffId, file, file.name), 'profile.photo_saved');
}

async function saveSelfie(shot: Blob): Promise<void> {
  if (await run(() => uploadStaffPhoto(props.staffId, shot, 'selfie.jpg'), 'profile.photo_saved')) camera.value = false;
}

async function savePassword(): Promise<void> {
  if (await run(() => changeOwnPassword({ current_password: password.current, password: password.next, confirmation_code: password.code.trim() }), 'profile.password_saved')) {
    Object.assign(password, { current: '', next: '', repeat: '', code: '' });
  }
}

async function sendLink(): Promise<void> {
  if (await run(() => sendStaffPasswordLink(props.staffId, linkCode.value.trim()), 'profile.link_sent')) linkCode.value = '';
}
</script>

<template>
  <section class="flex flex-col gap-5 text-sm rounded-xl border border-border bg-surface p-5" data-profile-editor>
    <ErrorNotice v-if="error" :error="error" />
    <p v-if="notice" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-ok" data-profile-notice>{{ $t(notice) }}</p>

    <section class="flex flex-col gap-3" aria-labelledby="profile-photo-title">
      <h3 id="profile-photo-title" class="font-semibold">{{ $t('profile.photo') }}</h3>
      <div class="flex flex-wrap items-center gap-4">
        <StaffAvatar :name="name" :photo-url="photo" size="lg" />
        <div class="flex flex-col gap-2">
          <div class="flex flex-wrap gap-2">
            <label class="cursor-pointer rounded-md border border-border-strong px-3 py-1.5 font-medium text-primary hover:bg-primary-soft">
              {{ $t('profile.choose_file') }}
              <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" :disabled="busy" data-photo-file @change="pick" />
            </label>
            <button v-if="!camera" type="button" class="rounded-md border border-border-strong px-3 py-1.5 font-medium text-primary hover:bg-primary-soft" :disabled="busy" data-selfie-open @click="camera = true">{{ $t('profile.selfie') }}</button>
            <button v-if="photo" type="button" class="rounded-md border border-crit-border px-3 py-1.5 font-medium text-crit hover:bg-crit-soft" :disabled="busy" data-photo-remove @click="run(() => removeStaffPhoto(staffId), 'profile.photo_removed')">{{ $t('profile.remove_photo') }}</button>
          </div>
          <p class="text-[13px] text-text-muted">{{ $t('profile.photo_hint') }}</p>
        </div>
      </div>
      <SelfieCamera v-if="camera" @save="saveSelfie" @cancel="camera = false" />
    </section>

    <form class="flex flex-col gap-3 sm:max-w-md" data-profile-name @submit.prevent="saveName">
      <h3 class="font-semibold">{{ $t('profile.name') }}</h3>
      <label class="flex flex-col gap-1.5"><span class="font-medium">{{ $t('profile.first_name') }}</span><input v-model="form.first_name" maxlength="100" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="first-name" /></label>
      <label class="flex flex-col gap-1.5"><span class="font-medium">{{ $t('profile.last_name') }} *</span><input v-model="form.last_name" maxlength="100" required class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="last-name" /></label>
      <button type="submit" class="self-start rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || form.last_name.trim() === ''">{{ $t('common.save') }}</button>
    </form>

    <form v-if="self" class="flex flex-col gap-3 sm:max-w-md" data-profile-password @submit.prevent="savePassword">
      <h3 class="font-semibold">{{ $t('profile.password') }}</h3>
      <label class="flex flex-col gap-1.5"><span class="font-medium">{{ $t('profile.current_password') }}</span><input v-model="password.current" type="password" autocomplete="current-password" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="current-password" /></label>
      <label class="flex flex-col gap-1.5"><span class="font-medium">{{ $t('profile.new_password') }}</span><input v-model="password.next" type="password" autocomplete="new-password" minlength="12" maxlength="128" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="new-password" /></label>
      <label class="flex flex-col gap-1.5"><span class="font-medium">{{ $t('profile.repeat_password') }}</span><input v-model="password.repeat" type="password" autocomplete="new-password" maxlength="128" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="repeat-password" /></label>
      <p v-if="password.repeat !== '' && password.repeat !== password.next" class="text-crit">{{ $t('profile.passwords_differ') }}</p>
      <label class="flex max-w-xs flex-col gap-1.5"><span class="font-medium">{{ $t('plans.code_confirm') }}</span><input v-model="password.code" autocomplete="one-time-code" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono tracking-wider" data-field="password-code" /></label>
      <p class="text-[13px] text-text-muted">{{ $t('profile.password_hint') }}</p>
      <button type="submit" class="self-start rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || password.current === '' || password.next.length < 12 || password.next !== password.repeat || password.code.trim().length < 6">{{ $t('profile.change_password') }}</button>
    </form>

    <form v-else class="flex flex-col gap-3 sm:max-w-md" data-profile-password-link @submit.prevent="sendLink">
      <h3 class="font-semibold">{{ $t('profile.password') }}</h3>
      <p class="text-text-muted">{{ $t('profile.link_hint') }}</p>
      <label class="flex max-w-xs flex-col gap-1.5"><span class="font-medium">{{ $t('plans.code_confirm') }}</span><input v-model="linkCode" autocomplete="one-time-code" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono tracking-wider" data-field="link-code" /></label>
      <button type="submit" class="self-start rounded-md border border-border-strong px-4 py-2 font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy || linkCode.trim().length < 6">{{ $t('profile.send_link') }}</button>
    </form>
  </section>
</template>
