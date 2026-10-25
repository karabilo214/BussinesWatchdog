import { describe, expect, it } from 'vitest';
import { ownerBadges } from '@/composables/useOwnerBadges';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';

describe('admin menu badges', () => {
  it('shows nothing when nothing waits', () => {
    expect(ownerBadges({ contactsNew: 0, health: 'ok', refundsRequested: 0 })).toEqual({});
    expect(ownerBadges({})).toEqual({});
  });

  it('counts new requests and refunds awaiting approval and colours the service by its state', () => {
    const badges = ownerBadges({ contactsNew: 3, health: 'crit', refundsRequested: 120 });
    expect(badges.contacts).toEqual({ text: '3', level: 'warn', hint: { key: 'nav_badge.contacts_new', n: 3 } });
    expect(badges.service).toMatchObject({ text: '!', level: 'crit' });
    expect(badges.payments).toMatchObject({ text: '99+', level: 'warn' });
    expect(ownerBadges({ health: 'warn' }).service?.level).toBe('warn');
  });

  it('has the hints in ru, en and de', () => {
    for (const locale of [ru, en, de]) {
      const texts = locale.nav_badge as Record<string, string>;
      expect(['contacts_new', 'service_crit', 'service_warn', 'refunds_requested'].filter((key) => !texts[key])).toEqual([]);
    }
  });
});
