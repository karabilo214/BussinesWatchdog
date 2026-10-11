export function telegramBotHandle(username: string | null): string | null {
  const normalized = username?.trim().replace(/^@+/, '') ?? '';

  return normalized === '' ? null : `@${normalized}`;
}
