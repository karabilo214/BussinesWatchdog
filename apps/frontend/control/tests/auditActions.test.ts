import { describe, expect, it } from 'vitest';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';

const RECORDED = [
  'billing.refund_requested', 'billing.refund_approved', 'billing.refund_rejected', 'billing.withdrawal_requested', 'billing.plan_change_cancelled',
  'billing.subscription_cancel_scheduled', 'billing.subscription_cancelled', 'billing.subscription_cancelled_after_refund', 'billing.subscription_cancelled_for_deletion', 'billing.subscription_resumed',
  'discount.created', 'discount.updated', 'discount.archived', 'plan.created', 'plan_price.added', 'plan_price.removed',
  'plan_version.created', 'plan_version.updated', 'plan_version.published', 'plan_version.retired',
  'staff.login', 'staff.logout', 'support_grant.requested', 'support_grant.approved', 'support_grant.denied', 'support_grant.revoked', 'support_access.opened', 'support_access.artifact',
  'tenant.store_created', 'tenant.store_deleted', 'tenant.reminder_sent', 'tenant.check_rerun', 'tenant.incident_acknowledged', 'tenant.incident_resolved', 'tenant.member_mfa_reset',
  'user.mfa_enabled', 'user.mfa_disabled', 'user.mfa_reset', 'user.mfa_recovery_code_used', 'user.mfa_recovery_codes_regenerated',
];

function keys(node: unknown, prefix = ''): string[] {
  return Object.entries(node as Record<string, unknown>).flatMap(([key, value]) => (typeof value === 'object' && value !== null ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]));
}

describe('audit action names', () => {
  it('are translated in every language for the actions the backend records', () => {
    for (const locale of [ru, en, de]) {
      const known = keys(locale.audit_action);
      expect(RECORDED.filter((code) => !known.includes(code))).toEqual([]);
    }
  });

  it('have the same codes in ru, en and de', () => {
    expect(keys(en.audit_action).sort()).toEqual(keys(ru.audit_action).sort());
    expect(keys(de.audit_action).sort()).toEqual(keys(ru.audit_action).sort());
  });
});
