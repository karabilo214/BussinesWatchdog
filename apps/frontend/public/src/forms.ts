// Progressive enhancement only: ordinary HTML POST/validation remains available without JavaScript.
for (const form of document.querySelectorAll<HTMLFormElement>('[data-form="signup"], [data-form="contact"]')) {
  const summary = document.getElementById('form-errors');
  summary?.focus();
  form.addEventListener('invalid', (event) => (event.target as HTMLElement).setAttribute('aria-invalid', 'true'), true);
  form.addEventListener('input', (event) => (event.target as HTMLElement).removeAttribute('aria-invalid'));
  for (const button of form.querySelectorAll<HTMLButtonElement>('[data-toggle-password]')) {
    button.hidden = false;
    button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.togglePassword!) as HTMLInputElement;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      button.setAttribute('aria-label', show ? button.dataset.hide! : button.dataset.show!);
      button.title = button.getAttribute('aria-label')!;
      button.setAttribute('aria-pressed', String(show));
      button.querySelector<SVGElement>('[data-icon="show"]')!.toggleAttribute('hidden', show);
      button.querySelector<SVGElement>('[data-icon="hide"]')!.toggleAttribute('hidden', !show);
    });
  }
  const password = form.querySelector<HTMLInputElement>('[name=password]');
  const confirmation = form.querySelector<HTMLInputElement>('[name=password_confirmation]');
  const checkConfirmation = () => confirmation?.setCustomValidity(confirmation.value && password?.value !== confirmation.value ? confirmation.dataset.mismatch! : '');
  password?.addEventListener('input', checkConfirmation);
  confirmation?.addEventListener('input', checkConfirmation);
  const attachment = form.querySelector<HTMLInputElement>('[name=attachment]');
  attachment?.addEventListener('change', () => {
    attachment.setCustomValidity(attachment.files?.[0] && attachment.files[0].size > Number(attachment.dataset.maxBytes) ? attachment.dataset.sizeError! : '');
  });
  let submitting = false;
  const submit = form.querySelector<HTMLButtonElement>('[type=submit]')!;
  const original = submit.textContent;
  form.addEventListener('submit', (event) => {
    checkConfirmation();
    if (submitting || !form.reportValidity()) { event.preventDefault(); return; }
    submitting = true;
    form.setAttribute('aria-busy', 'true');
    submit.disabled = true;
    submit.textContent = form.dataset.submitting!;
  });
  window.addEventListener('pageshow', () => { submitting = false; submit.disabled = false; submit.textContent = original; form.removeAttribute('aria-busy'); });
  const showText = form.querySelector<HTMLButtonElement>('[data-captcha-text]');
  if (showText) {
    showText.hidden = false;
    showText.addEventListener('click', async () => {
      showText.disabled = true;
      const output = document.getElementById(showText.getAttribute('aria-controls')!)!;
      try {
        const response = await fetch(showText.dataset.captchaText!, { cache: 'no-store', redirect: 'error' });
        if (!response.ok) throw new Error('captcha_unavailable');
        output.textContent = await response.text();
        output.hidden = false;
        showText.setAttribute('aria-expanded', 'true');
      } catch { output.textContent = showText.dataset.error!; output.hidden = false; }
      finally { showText.disabled = false; }
    });
  }
}
document.querySelector<HTMLElement>('[data-contact="sent"]')?.focus();
