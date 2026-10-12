import type { SiteLocale } from '@/api';

export const SITE_LOCALES: SiteLocale[] = ['ru', 'en', 'de'];

export function publicUrl(locale: SiteLocale, path: string): string {
  return `/${locale}${path === '' ? '' : `/${path}`}`;
}

export const CONTENT_CLASSES = ['lead', 'muted', 'small', 'center', 'eyebrow', 'hero', 'band', 'actions', 'button', 'button-secondary', 'grid-2', 'grid-3', 'card', 'feature', 'note', 'warning'];
