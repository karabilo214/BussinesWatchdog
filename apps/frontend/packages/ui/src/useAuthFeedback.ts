import { nextTick, onMounted, ref } from 'vue';

/** Focusable form feedback shared by the two sign-in flows. No credentials are stored here. */
export function useAuthFeedback() {
  const form = ref<HTMLFormElement | null>(null);
  const summary = ref<HTMLElement | null>(null);
  const fields = ref<Record<string, string>>({});
  onMounted(() => summary.value?.focus());
  async function focusSummary(): Promise<void> { await nextTick(); summary.value?.focus(); }
  function focusField(name: string): void { (form.value?.elements.namedItem(name) as HTMLElement | null)?.focus(); }
  function validate(names: string[]): boolean {
    fields.value = {};
    for (const name of names) {
      const input = form.value?.elements.namedItem(name) as HTMLInputElement | null;
      if (!input) continue;
      const value = name === 'password' ? input.value : input.value.trim();
      if (value === '') fields.value[name] = 'forms.required';
      else if (input.validity.typeMismatch) fields.value[name] = 'forms.email_invalid';
      else if (name === 'code' && input.value.trim().length < 6) fields.value[name] = 'forms.code_short';
      else if (!input.validity.valid) fields.value[name] = 'forms.invalid';
    }
    return Object.keys(fields.value).length === 0;
  }
  return { form, summary, fields, validate, focusSummary, focusField };
}
