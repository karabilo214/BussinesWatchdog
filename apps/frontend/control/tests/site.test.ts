import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '@/api';
import type { ContactRequest, PageCard, PageSummary, StaffMe } from '@/api';
import App from '@/App.vue';
import { useStaff } from '@/composables/useStaff';
import { localization } from '@/i18n';
import { buildRouter } from '@/router';
import { code, setCode } from './code';

vi.mock('@/api', () => ({
  fetchMe: vi.fn(),
  login: vi.fn(),
  submitMfa: vi.fn(),
  logout: vi.fn(),
  fetchPages: vi.fn(),
  createPage: vi.fn(),
  fetchPage: vi.fn(),
  fetchPageBlocks: vi.fn(),
  updatePage: vi.fn(),
  savePageDraft: vi.fn(),
  discardPageDraft: vi.fn(),
  publishPage: vi.fn(),
  unpublishPage: vi.fn(),
  restorePageVersion: vi.fn(),
  deletePageVersion: vi.fn(),
  archivePage: vi.fn(),
  previewPage: vi.fn(),
  fetchContactRequests: vi.fn(),
  fetchContactRequest: vi.fn(),
  updateContactRequest: vi.fn(),
  replyToContactRequest: vi.fn(),
  addContactNote: vi.fn(),
}));

const mocked = vi.mocked(api);

function staff(capabilities: string[]): StaffMe {
  return { staff_id: 's-1', name: 'Founder', email: 'f@example.test', locale: 'ru', role: 'platform_owner', mfa_enabled: true, capabilities, billing_mode: 'test', session: { idle_minutes: 30, absolute_hours: 8 } };
}

const NONE = { published: null, draft: false, title: null };

function summary(overrides: Partial<PageSummary>): PageSummary {
  return { id: 'p-1', kind: 'page', parent_id: null, slug: 'about', path: 'about', menu: 'header', sort: 0, policy_type: null, editable: true, created_at: null, locales: { ru: NONE, en: NONE, de: NONE }, ...overrides };
}

function card(overrides: Partial<PageCard> = {}): PageCard {
  return {
    ...summary({ locales: { ru: { published: '2026-10-12T10:00:00Z', draft: true, title: 'О компании' }, en: NONE, de: NONE } }),
    content: {
      ru: {
        draft: { id: 'v-2', version: 2, title: 'О компании', description: null, body: '<p>Новый текст</p>', content_hash: 'h2', updated_at: '2026-10-12T11:00:00Z', published_at: null },
        published: { id: 'v-1', version: 1, title: 'О компании', description: null, body: '<p>Старый текст</p>', content_hash: 'h1', updated_at: null, published_at: '2026-10-12T10:00:00Z' },
      },
      en: { draft: null, published: null },
      de: { draft: null, published: null },
    },
    builtin: null,
    history: [{ id: 'v-1', locale: 'ru', version: 1, state: 'published', title: 'О компании', published_at: '2026-10-12T10:00:00Z', retired_at: null, published_by: 'Founder' }],
    ...overrides,
  };
}

async function open(path: string, capabilities = ['content.manage', 'contacts.read', 'contacts.manage']) {
  mocked.fetchMe.mockResolvedValue(staff(capabilities));
  await useStaff().load(true);
  const router = buildRouter();
  await router.push(path);
  await router.isReady();
  const wrapper = mount(App, { global: { plugins: [localization, router] } });
  await flushPromises();
  await vi.waitFor(() => expect(wrapper.find('h1').exists()).toBe(true));
  await flushPromises();

  return { wrapper, router };
}

beforeEach(() => {
  vi.clearAllMocks();
  window.localStorage.setItem('bw.page-editor-mode', 'html');
  useStaff().forget();
  localization.setLocale('ru');
  mocked.previewPage.mockResolvedValue({ body: '', html: '<p>preview</p>' });
  mocked.fetchPageBlocks.mockResolvedValue([]);
});

describe('site', () => {
  it('lists pages with their state per language and creates a nested page with a fixed address', async () => {
    mocked.fetchPages.mockResolvedValue([
      summary({ id: 'home', kind: 'home', slug: null, path: '', menu: 'none' }),
      summary({ id: 'pricing', kind: 'pricing', slug: 'pricing', path: 'pricing', editable: false }),
      summary({ id: 'legal', slug: 'legal', path: 'legal', menu: 'none', locales: { ru: { published: '2026-10-12T10:00:00Z', draft: false, title: 'Документы' }, en: NONE, de: { published: null, draft: true, title: 'Rechtliches' } } }),
    ]);
    mocked.createPage.mockResolvedValue(card({ id: 'p-9' }));
    mocked.fetchPage.mockResolvedValue(card({ id: 'p-9' }));
    const { wrapper, router } = await open('/owner/site');

    expect(wrapper.get('[data-page="/"]').text()).toContain('Встроенный текст');
    expect(wrapper.get('[data-page="pricing"]').text()).toContain('Редактируется в коде');
    const legal = wrapper.get('[data-page="legal"]');
    expect(legal.text()).toContain('Документы');
    expect(legal.text()).toContain('Опубликовано');
    expect(legal.text()).toContain('Черновик');

    await wrapper.get('[data-field="slug"]').setValue('Terms');
    expect(wrapper.get('[data-action="create"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-field="slug"]').setValue('terms');
    await wrapper.get('[data-field="parent"]').setValue('legal');
    expect(wrapper.get('[data-slug-preview]').text()).toBe('/ru/legal/terms');
    await wrapper.get('[data-form="page-create"]').trigger('submit');
    await flushPromises();

    expect(mocked.createPage).toHaveBeenCalledWith({ slug: 'terms', parent_id: 'legal', menu: 'none', sort: 0 });
    await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/owner/site/pages/p-9'));
  });

  it('edits the draft with a sandboxed preview, saves it and publishes with a reason', async () => {
    mocked.fetchPage.mockResolvedValue(card());
    mocked.savePageDraft.mockResolvedValue(card());
    mocked.publishPage.mockResolvedValue(card({ content: { ...card().content, ru: { draft: null, published: { ...card().content.ru.draft!, published_at: '2026-10-12T12:00:00Z' } } } }));
    const { wrapper } = await open('/owner/site/pages/p-1');

    expect(await code(wrapper)).toBe('<p>Новый текст</p>');
    const frame = wrapper.get('[data-preview]');
    expect(frame.attributes('sandbox')).toBe('');
    await vi.waitFor(() => expect(mocked.previewPage).toHaveBeenCalledWith('p-1', 'ru', { title: 'О компании', description: null, body: '<p>Новый текст</p>' }));
    await flushPromises();
    expect(frame.attributes('srcdoc')).toBe('<p>preview</p>');

    expect(wrapper.get('[data-action="save"]').attributes('disabled')).toBeDefined();
    await setCode(wrapper, '<h2>Мы</h2>');
    expect(wrapper.find('[data-unsaved]').exists()).toBe(true);
    expect(wrapper.get('[data-action="publish"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-form="page-draft"]').trigger('submit');
    await flushPromises();
    expect(mocked.savePageDraft).toHaveBeenCalledWith('p-1', 'ru', { title: 'О компании', description: null, body: '<h2>Мы</h2>' });

    await wrapper.get('[data-action="publish"]').trigger('click');
    expect(wrapper.get('[data-action="confirm"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-form="publish"]').trigger('submit');
    expect(mocked.publishPage).not.toHaveBeenCalled();
    await wrapper.get('[data-field="reason"]').setValue('Новая редакция');
    await wrapper.get('[data-form="publish"]').trigger('submit');
    await flushPromises();

    expect(mocked.publishPage).toHaveBeenCalledWith('p-1', 'ru', 'Новая редакция');
    expect(wrapper.get('[data-notice]').text()).toBe('Опубликовано.');
  });

  it('opens the home page with its built-in text ready to edit', async () => {
    const empty = { draft: null, published: null };
    const builtin = { title: 'Business Watchdog', description: 'Контроль оплаты', body: '<section class="hero"><h1>Встроенная главная</h1></section>' };
    mocked.fetchPage.mockResolvedValue(card({ ...summary({ id: 'home', kind: 'home', slug: null, path: '', menu: 'none' }), content: { ru: empty, en: empty, de: empty }, builtin: { ru: builtin, en: { ...builtin, body: '<h1>Built-in home</h1>' }, de: null }, history: [] }));
    mocked.savePageDraft.mockResolvedValue(card());
    const { wrapper } = await open('/owner/site/pages/home');

    expect((wrapper.get('[data-field="title"]').element as HTMLInputElement).value).toBe('Business Watchdog');
    expect(await code(wrapper)).toBe(builtin.body);
    await vi.waitFor(() => expect(mocked.previewPage).toHaveBeenCalledWith('home', 'ru', { title: 'Business Watchdog', description: 'Контроль оплаты', body: builtin.body }));
    expect(wrapper.get('[data-action="save"]').attributes('disabled')).toBeDefined();

    await setCode(wrapper, '<section class="hero"><h1>Своя главная</h1></section>');
    await wrapper.get('[data-form="page-draft"]').trigger('submit');
    await flushPromises();
    expect(mocked.savePageDraft).toHaveBeenCalledWith('home', 'ru', { title: 'Business Watchdog', description: 'Контроль оплаты', body: '<section class="hero"><h1>Своя главная</h1></section>' });
  });

  it('offers block templates of the page language in the visual editor', async () => {
    window.localStorage.setItem('bw.page-editor-mode', 'visual');
    mocked.fetchPage.mockResolvedValue(card());
    mocked.fetchPageBlocks.mockResolvedValue([
      { id: 'b-1', category: 'faq', name: 'Вопросы и ответы', bodies: { ru: '<h2>Вопросы</h2>', de: '<h2>Fragen</h2>' }, sort: 10, updated_at: null },
      { id: 'b-2', category: 'cta', name: 'Только немецкий', bodies: { de: '<h2>Los</h2>' }, sort: 20, updated_at: null },
    ]);
    const { wrapper } = await open('/owner/site/pages/p-1');

    await wrapper.get('[data-tool="blocks"]').trigger('click');
    expect(wrapper.get('[data-panel="blocks"]').text()).toContain('Вопросы и ответы');
    expect(wrapper.get('[data-panel="blocks"]').text()).toContain('Только немецкий');
    expect(wrapper.findAll('[data-block]')).toHaveLength(2);
    await wrapper.get('[data-block="b-1"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-unsaved]').exists()).toBe(true);
  });

  it('shows pricing and contact as edited in code with only the menu settings', async () => {
    mocked.fetchPage.mockResolvedValue(card({ ...summary({ id: 'pricing', kind: 'pricing', slug: 'pricing', path: 'pricing', editable: false }), builtin: null, history: [] }));
    mocked.updatePage.mockResolvedValue(card({ ...summary({ id: 'pricing', kind: 'pricing', slug: 'pricing', path: 'pricing', menu: 'footer', editable: false }), builtin: null, history: [] }));
    const { wrapper } = await open('/owner/site/pages/pricing');

    expect(wrapper.get('[data-in-code]').text()).toContain('меняется только в коде сайта');
    expect(wrapper.find('[data-form="page-draft"]').exists()).toBe(false);
    expect(wrapper.find('[data-preview]').exists()).toBe(false);
    expect(wrapper.find('[data-history]').exists()).toBe(false);
    expect(wrapper.find('[data-field="policy"]').exists()).toBe(false);
    expect(mocked.previewPage).not.toHaveBeenCalled();

    await wrapper.get('[data-field="menu"]').setValue('footer');
    await wrapper.get('[data-form="page-settings"]').trigger('submit');
    await flushPromises();
    expect(mocked.updatePage).toHaveBeenCalledWith('pricing', { menu: 'footer', sort: 0 });
  });

  it('deletes an old version with a reason but offers nothing for the current version or a legal document', async () => {
    const history = [
      { id: 'v-2', locale: 'ru' as const, version: 2, state: 'published' as const, title: 'О компании', published_at: '2026-10-12T11:00:00Z', retired_at: null, published_by: 'Founder' },
      { id: 'v-1', locale: 'ru' as const, version: 1, state: 'retired' as const, title: 'О компании', published_at: '2026-10-12T10:00:00Z', retired_at: '2026-10-12T11:00:00Z', published_by: 'Founder' },
    ];
    mocked.fetchPage.mockResolvedValue(card({ history }));
    mocked.deletePageVersion.mockResolvedValue(card({ history: [history[0]!] }));
    const { wrapper } = await open('/owner/site/pages/p-1');

    expect(wrapper.get('[data-version="2"]').find('[data-action="remove"]').exists()).toBe(false);
    await wrapper.get('[data-version="1"] [data-action="remove"]').trigger('click');
    expect(wrapper.get('[data-form="delete_version"]').text()).toContain('версия 1');
    await wrapper.get('[data-field="reason"]').setValue('Устарела');
    await wrapper.get('[data-form="delete_version"]').trigger('submit');
    await flushPromises();
    expect(mocked.deletePageVersion).toHaveBeenCalledWith('p-1', 'v-1', 'Устарела');
    expect(wrapper.find('[data-version="1"]').exists()).toBe(false);
    expect(wrapper.get('[data-notice]').text()).toBe('Старая версия удалена.');

    mocked.fetchPage.mockResolvedValue(card({ history, policy_type: 'service_terms' }));
    const legal = await open('/owner/site/pages/p-1');
    expect(legal.wrapper.find('[data-action="remove"]').exists()).toBe(false);
  });

  it('marks a page as the terms of service in its settings', async () => {
    mocked.fetchPage.mockResolvedValue(card());
    mocked.updatePage.mockResolvedValue(card({ policy_type: 'service_terms' }));
    const { wrapper } = await open('/owner/site/pages/p-1');

    await wrapper.get('[data-field="policy"]').setValue('service_terms');
    await wrapper.get('[data-form="page-settings"]').trigger('submit');
    await flushPromises();

    expect(mocked.updatePage).toHaveBeenCalledWith('p-1', { menu: 'header', sort: 0, policy_type: 'service_terms' });
    expect(wrapper.text()).toContain('С опубликованной версией этой страницы клиент соглашается');
  });

  it('restores an old version into the draft and switches languages', async () => {
    mocked.fetchPage.mockResolvedValue(card());
    mocked.restorePageVersion.mockResolvedValue(card());
    const { wrapper } = await open('/owner/site/pages/p-1');

    expect(wrapper.get('[data-version="1"]').text()).toContain('Founder');
    await wrapper.get('[data-version="1"] [data-action="restore"]').trigger('click');
    await flushPromises();
    expect(mocked.restorePageVersion).toHaveBeenCalledWith('p-1', 'v-1');

    await wrapper.get('[data-locale="de"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-path]').text()).toBe('/de/about');
    expect((wrapper.get('[data-field="title"]').element as HTMLInputElement).value).toBe('');
    expect(wrapper.get('[data-history]').text()).toContain('Опубликованных версий ещё не было.');
  });

  it('lists contact messages by status, adds internal notes one by one and closes a message', async () => {
    const message: ContactRequest = { id: 'c-1', locale: 'de', name: 'Jonas', email: 'jonas@shop.test', company: 'Kaffee', message: 'Wie verbinde ich zwei Shops?', status: 'new', handled_by: null, created_at: '2026-10-12T10:00:00Z', updated_at: null, closed_at: null };
    mocked.fetchContactRequests.mockResolvedValue({ data: [message], next_cursor: null, counts: { new: 1, in_progress: 0, closed: 4 } });
    mocked.fetchContactRequest.mockResolvedValue(message);
    mocked.updateContactRequest.mockResolvedValue({ ...message, status: 'closed', handled_by: 'Founder', notes: [{ id: 'n-1', body: 'Ответили', author: 'Founder', created_at: '2026-10-12T11:00:00Z' }] });
    mocked.addContactNote.mockResolvedValue({ ...message, handled_by: 'Founder', notes: [{ id: 'n-1', body: 'Ответили', author: 'Founder', created_at: '2026-10-12T11:00:00Z' }] });
    const { wrapper, router } = await open('/owner/contacts');

    expect(mocked.fetchContactRequests).toHaveBeenCalledWith({ status: 'new' });
    expect(wrapper.get('[data-status="closed"]').text()).toContain('(4)');
    expect(wrapper.get('[data-contact="jonas@shop.test"]').text()).toContain('Wie verbinde ich zwei Shops?');
    await wrapper.get('[data-status="all"]').trigger('click');
    await flushPromises();
    expect(mocked.fetchContactRequests).toHaveBeenLastCalledWith({});
    expect(router.currentRoute.value.query).toEqual({ status: 'all' });

    await router.push('/owner/contact-requests/c-1');
    await flushPromises();
    expect(wrapper.get('[data-reply]').attributes('href')).toBe('mailto:jonas@shop.test');
    expect(wrapper.text()).toContain('Заметок пока нет.');
    expect(wrapper.get('[data-action="add-note"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-field="note"]').setValue('Ответили');
    await wrapper.get('[data-form="note"]').trigger('submit');
    await flushPromises();
    expect(mocked.addContactNote).toHaveBeenCalledWith('c-1', 'Ответили');
    expect(wrapper.get('[data-note="n-1"]').text()).toContain('Founder');
    expect((wrapper.get('[data-field="note"]').element as HTMLTextAreaElement).value).toBe('');

    await wrapper.get('[data-field="status"]').setValue('closed');
    await wrapper.get('[data-form="contact"]').trigger('submit');
    await flushPromises();

    expect(mocked.updateContactRequest).toHaveBeenCalledWith('c-1', { status: 'closed' });
    expect(wrapper.find('[data-saved]').exists()).toBe(true);
  });

  it('replies to a message from a template and shows the reply in the message', async () => {
    const templates = [
      { code: 'answer' as const, subject: 'Antwort auf Ihre Nachricht an Business Watchdog', body: 'Vielen Dank für Ihre Nachricht.\n\n' },
      { code: 'more_details' as const, subject: 'Business Watchdog: bitte ein paar Details', body: 'Wir brauchen noch ein paar Angaben.' },
      { code: 'resolved' as const, subject: 'Business Watchdog: Ihre Frage ist geklärt', body: 'Geklärt.' },
    ];
    const message: ContactRequest = { id: 'c-1', locale: 'de', name: 'Jonas', email: 'jonas@shop.test', company: null, message: 'Wie verbinde ich zwei Shops?', status: 'new', handled_by: null, created_at: '2026-10-12T10:00:00Z', updated_at: null, closed_at: null, replies: [], reply_templates: templates };
    mocked.fetchContactRequest.mockResolvedValue(message);
    mocked.replyToContactRequest.mockResolvedValue({ ...message, status: 'in_progress', handled_by: 'Founder', replies: [{ id: 'r-1', template: 'more_details', subject: 'Business Watchdog: bitte ein paar Details', body: 'Welche Shop-Adresse?', sent_by: 'Founder', sent_at: '2026-10-12T11:00:00Z' }] });
    const { wrapper } = await open('/owner/contact-requests/c-1');

    expect(wrapper.text()).toContain('Ответов ещё не было.');
    await wrapper.get('[data-action="reply"]').trigger('click');
    expect((wrapper.get('[data-field="reply-subject"]').element as HTMLInputElement).value).toBe('Antwort auf Ihre Nachricht an Business Watchdog');
    await wrapper.get('[data-field="reply-template"]').setValue('more_details');
    expect((wrapper.get('[data-field="reply-body"]').element as HTMLTextAreaElement).value).toBe('Wir brauchen noch ein paar Angaben.');
    await wrapper.get('[data-field="reply-body"]').setValue('Welche Shop-Adresse?');
    await wrapper.get('[data-form="reply"]').trigger('submit');
    await flushPromises();

    expect(mocked.replyToContactRequest).toHaveBeenCalledWith('c-1', { template: 'more_details', subject: 'Business Watchdog: bitte ein paar Details', body: 'Welche Shop-Adresse?' });
    expect(wrapper.get('[data-sent]').text()).toContain('jonas@shop.test');
    expect(wrapper.get('[data-reply-item="r-1"]').text()).toContain('Welche Shop-Adresse?');
    expect(wrapper.get('[data-reply-item="r-1"]').text()).toContain('Нужны подробности');
    expect((wrapper.get('[data-field="status"]').element as HTMLSelectElement).value).toBe('in_progress');
  });

  it('shows a message without the form to staff who may only read', async () => {
    mocked.fetchContactRequest.mockResolvedValue({ id: 'c-1', locale: 'en', name: 'Ann', email: 'ann@shop.test', company: null, message: 'Hello there', status: 'in_progress', handled_by: 'Founder', created_at: null, updated_at: null, closed_at: null, notes: [{ id: 'n-1', body: 'Called back', author: 'Founder', created_at: null }] });
    const { wrapper } = await open('/owner/contact-requests/c-1', ['contacts.read']);

    expect(wrapper.get('[data-message]').text()).toBe('Hello there');
    expect(wrapper.find('[data-form="contact"]').exists()).toBe(false);
    expect(wrapper.find('[data-action="reply"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('Called back');
    expect(wrapper.find('[data-form="note"]').exists()).toBe(false);
  });
});
