import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '@/api';
import type { PageBlock, StaffMe } from '@/api';
import App from '@/App.vue';
import { useStaff } from '@/composables/useStaff';
import { localization } from '@/i18n';
import { buildRouter } from '@/router';

vi.mock('@/api', () => ({
  fetchMe: vi.fn(),
  login: vi.fn(),
  submitMfa: vi.fn(),
  logout: vi.fn(),
  fetchPageBlocks: vi.fn(),
  fetchPageBlock: vi.fn(),
  createPageBlock: vi.fn(),
  updatePageBlock: vi.fn(),
  archivePageBlock: vi.fn(),
}));

const mocked = vi.mocked(api);

function staff(): StaffMe {
  return { staff_id: 's-1', name: 'Founder', email: 'f@example.test', locale: 'ru', role: 'platform_owner', mfa_enabled: true, capabilities: ['content.manage'], billing_mode: 'test', session: { idle_minutes: 30, absolute_hours: 8 } };
}

function block(overrides: Partial<PageBlock> = {}): PageBlock {
  return { id: 'b-1', category: 'faq', name: 'Вопросы и ответы', bodies: { ru: '<h2>Вопросы</h2>', en: '<h2>Questions</h2>' }, sort: 10, updated_at: '2026-10-13T10:00:00Z', ...overrides };
}

async function open(path: string) {
  mocked.fetchMe.mockResolvedValue(staff());
  await useStaff().load(true);
  const router = buildRouter();
  await router.push(path);
  await router.isReady();
  const wrapper = mount(App, { global: { plugins: [localization, router] }, attachTo: document.body });
  await flushPromises();
  await vi.waitFor(() => expect(wrapper.find('h1').exists()).toBe(true));
  await flushPromises();

  return { wrapper, router };
}

beforeEach(() => {
  vi.clearAllMocks();
  useStaff().forget();
  localization.setLocale('ru');
});

describe('block templates', () => {
  it('lists templates with their group and languages in a section of its own', async () => {
    mocked.fetchPageBlocks.mockResolvedValue([block(), block({ id: 'b-2', category: 'hero', name: 'Первый экран', bodies: { de: '<h1>Hallo</h1>' } })]);
    const { wrapper } = await open('/owner/blocks');

    expect(wrapper.get('nav').text()).toContain('Шаблоны блоков');
    const row = wrapper.get('[data-block-row="b-1"]');
    expect(row.text()).toContain('Вопросы и ответы');
    expect(row.text()).toContain('ru · en');
    expect(wrapper.get('[data-block-row="b-2"]').text()).toContain('Первый экран');
    expect(wrapper.get('[data-block-row="b-2"]').text()).toContain('de');
  });

  it('creates a template in HTML mode and opens it', async () => {
    mocked.createPageBlock.mockResolvedValue(block({ id: 'b-9', name: 'Отзывы', category: 'other', bodies: { ru: '<p>Отзыв</p>' } }));
    mocked.fetchPageBlock.mockResolvedValue(block({ id: 'b-9', name: 'Отзывы', category: 'other', bodies: { ru: '<p>Отзыв</p>' } }));
    const { wrapper, router } = await open('/owner/blocks/new');

    expect(wrapper.get('[data-action="save"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-field="name"]').setValue('Отзывы');
    await wrapper.get('[data-mode="html"]').trigger('click');
    await wrapper.get('[data-field="body"]').setValue('<p>Отзыв</p>');
    await wrapper.get('[data-form="block"]').trigger('submit');
    await flushPromises();

    expect(mocked.createPageBlock).toHaveBeenCalledWith({ name: 'Отзывы', category: 'other', sort: 0, bodies: { ru: '<p>Отзыв</p>', en: null, de: null } });
    expect(router.currentRoute.value.fullPath).toBe('/owner/blocks/b-9');
    expect(wrapper.get('[data-notice]').text()).toBe('Шаблон сохранён.');
    expect(wrapper.find('[data-unsaved]').exists()).toBe(false);
  });

  it('edits languages of a template in the visual editor and deletes it with a reason', async () => {
    mocked.fetchPageBlock.mockResolvedValue(block());
    mocked.updatePageBlock.mockResolvedValue(block({ name: 'FAQ', bodies: { ru: '<h2>Вопросы</h2>', de: '<h2>Fragen</h2>' } }));
    const { wrapper, router } = await open('/owner/blocks/b-1');

    expect(wrapper.get('[data-visual]').html()).toContain('Вопросы');
    expect(wrapper.get('[data-locale="en"]').text()).toContain('✓');
    expect(wrapper.get('[data-locale="de"]').text()).not.toContain('✓');
    await wrapper.get('[data-field="name"]').setValue('FAQ');
    await wrapper.get('[data-locale="de"]').trigger('click');
    await wrapper.get('[data-mode="html"]').trigger('click');
    await wrapper.get('[data-field="body"]').setValue('<h2>Fragen</h2>');
    await wrapper.get('[data-locale="en"]').trigger('click');
    await wrapper.get('[data-field="body"]').setValue('');
    await wrapper.get('[data-form="block"]').trigger('submit');
    await flushPromises();
    expect(mocked.updatePageBlock).toHaveBeenCalledWith('b-1', { name: 'FAQ', category: 'faq', sort: 10, bodies: { ru: '<h2>Вопросы</h2>', en: null, de: '<h2>Fragen</h2>' } });

    await wrapper.get('[data-action="archive"]').trigger('click');
    expect(wrapper.get('[data-action="confirm"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-field="reason"]').setValue('Не нужен');
    await wrapper.get('[data-form="archive"]').trigger('submit');
    await flushPromises();
    expect(mocked.archivePageBlock).toHaveBeenCalledWith('b-1', 'Не нужен');
    await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/owner/blocks'));
    wrapper.unmount();
  });
});
