import { StepOutcome } from './common.js';

export const INTERRUPTIONS = [
  { code: 'cookie_shopware', action: '.cookie-consent-accept-only-functional-button' },
  { code: 'cookie_shopware_bar', action: '.cookie-permission-container .js-cookie-permission-button' },
  { code: 'cookie_cookiebot', action: '#CybotCookiebotDialogBodyButtonDecline, #CybotCookiebotDialogBodyLevelButtonLevelOptinDeclineAll' },
  { code: 'cookie_onetrust', action: '#onetrust-reject-all-handler' },
  { code: 'cookie_usercentrics', action: '[data-testid="uc-deny-all-button"]' },
  { code: 'cookie_borlabs', action: '.brlbs-btn-accept-only-essential, a._brlbs-refuse-btn, a[data-cookie-refuse]' },
  { code: 'cookie_complianz', action: '.cmplz-cookiebanner .cmplz-deny' },
  { code: 'cookie_cookieyes', action: '.cky-consent-container .cky-btn-reject' },
  { code: 'cookie_notice', action: '#cookie-notice #cn-refuse-cookie' },
  { code: 'cookie_law_info', action: '#cookie_action_close_header_reject' },
  { code: 'cookie_consentmanager', action: '#cmpbox .cmpboxbtnno' },
  { code: 'cookie_klaro', action: '.klaro .cm-btn-decline, .klaro .cn-decline' },
  { code: 'cookie_cookiefirst', action: '[data-cookiefirst-action="reject"]' },
  { code: 'age_netzp', action: '#netzpCheckAgeOverlay a[href*="confirm: true"]' },
  { code: 'age_gate_wp', action: '.age-gate-submit-yes, .age-gate__submit--yes' },
];

const AGE_DIALOG = ['[role="dialog"]', '[aria-modal="true"]', '.modal.show', ...['agecheck', 'age-check', 'age_check', 'agegate', 'age-gate', 'age_gate', 'ageverif', 'age-verif', 'altersverifi', 'alterspruef', 'altersabfrage']
  .flatMap((name) => [`[id*="${name}" i]`, `[class*="${name}" i]`])].join(', ');
const AGE_TEXT = /\b(18|21)\b.*\b(jahre|years|ans)\b|\b(alter|age)\s*(prüfung|pruefung|verifi|check|gate)|altersverifi/i;
const AGE_CONFIRM = /^\s*(ja|yes|oui|ja,? ich bin (über |ueber )?(18|21)( jahre( alt)?)?|ich bin (über |ueber )?(18|21)( jahre( alt)?)?|i am (over )?(18|21)( years( old)?)?|i'?m (over )?(18|21)|enter|eintreten|betreten|bestätigen|confirm)\s*[.!]?\s*$/i;

const COOKIE_DIALOG = ['cookie', 'consent', 'gdpr', 'dsgvo', 'privacy-banner'].flatMap((name) => [`[id*="${name}" i]`, `[class*="${name}" i]`]).join(', ');
export const COOKIE_REJECT = /^\s*(nur (technisch )?(notwendige|erforderliche|essenzielle)( cookies)?( akzeptieren| zulassen| erlauben)?|nur essenzielle cookies( akzeptieren)?|(alle )?ablehnen|alle cookies ablehnen|only (necessary|essential|required)( cookies)?|(accept |allow )?(necessary|essential|required) (cookies )?only|reject( all)?( cookies)?|decline( all)?( cookies)?|deny( all)?|refuser( tout)?|continuer sans accepter)\s*$/i;
export const COOKIE_ACKNOWLEDGE = /^\s*(ok|okay|verstanden|alles klar|got it|understood|schließen|close|×|x)\s*[.!]?\s*$/i;
export const AGE_CONFIRM_LABEL = AGE_CONFIRM;

export async function installInterruptionHandlers(page) {
  const handled = new Set();

  if (typeof page.addLocatorHandler !== 'function') {
    return handled;
  }

  for (const { code, action } of INTERRUPTIONS) {
    const control = page.locator(`${action} >> visible=true`).first();
    await page.addLocatorHandler(control, async () => {
      await control.click({ timeout: 3000 }).catch(() => null);
      handled.add(code);
    }, { times: 3, noWaitAfter: true });
  }

  const ageConfirm = page.locator(`${AGE_DIALOG} >> visible=true`)
    .filter({ hasText: AGE_TEXT })
    .locator('button, a, [role="button"], input[type="submit"], input[type="button"]')
    .filter({ hasText: AGE_CONFIRM })
    .first();
  await page.addLocatorHandler(ageConfirm, async () => {
    await ageConfirm.click({ timeout: 3000 }).catch(() => null);
    handled.add('age_generic');
  }, { times: 3, noWaitAfter: true });

  for (const [code, label] of [['cookie_generic_reject', COOKIE_REJECT], ['cookie_generic_acknowledge', COOKIE_ACKNOWLEDGE]]) {
    const control = page.locator(`${COOKIE_DIALOG} >> visible=true`)
      .locator('button, a, [role="button"], input[type="submit"], input[type="button"]')
      .filter({ hasText: label })
      .first();
    await page.addLocatorHandler(control, async () => {
      await control.click({ timeout: 3000 }).catch(() => null);
      handled.add(code);
    }, { times: 3, noWaitAfter: true });
  }

  return handled;
}

export function interceptedOutcome(error) {
  if (error?.name !== 'TimeoutError' || !/intercepts pointer events/.test(String(error?.message ?? ''))) {
    return null;
  }

  return new StepOutcome('blocked', 'blocking_overlay', 'overlay_intercepts_click');
}
