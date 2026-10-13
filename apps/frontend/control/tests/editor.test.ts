import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import RichEditor from '@/components/editor/RichEditor.vue';
import { localization } from '@/i18n';

const EMPTY_RECT = { x: 0, y: 0, top: 0, left: 0, right: 0, bottom: 0, width: 0, height: 0, toJSON: () => ({}) } as DOMRect;
Range.prototype.getClientRects = () => ({ length: 0, item: () => null, [Symbol.iterator]: [][Symbol.iterator] }) as unknown as DOMRectList;
Range.prototype.getBoundingClientRect = () => EMPTY_RECT;

const SITE_HTML =
  '<section class="hero"><p class="eyebrow">Для магазинов</p><h1>Заголовок</h1><p class="lead">Вводный текст</p><p class="actions"><a class="button" href="/ru/pricing">Тарифы</a> <a class="button button-secondary" href="/ru/signup">Регистрация</a></p></section>' +
  '<section class="band"><h2>Что проверяем</h2><div class="grid-2"><div class="feature"><h3>Путь</h3><p>Текст</p></div><div class="feature"><h3>Деньги</h3><p>Сверка</p></div></div></section>' +
  '<p class="note">Важно <strong>жирно</strong> и <span class="muted">тихо</span></p><img src="https://cdn.example.test/a.png" alt="Схема"><table><tbody><tr><th><p>A</p></th></tr><tr><td><p>1</p></td></tr></tbody></table>';

async function settle(): Promise<void> {
  await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  await flushPromises();
}

async function mountEditor(value: string) {
  localization.setLocale('ru');
  const wrapper = mount(RichEditor, { props: { modelValue: value, label: 'Содержимое', 'onUpdate:modelValue': (next: string) => wrapper.setProps({ modelValue: next }) }, global: { plugins: [localization] }, attachTo: document.body });
  await flushPromises();

  return wrapper;
}

describe('visual page editor', () => {
  it('keeps the site layout classes, button links, images and tables of a page', async () => {
    const wrapper = await mountEditor(SITE_HTML);
    const editor = (wrapper.vm as unknown as { editor: { getHTML(): string } }).editor;
    const html = editor.getHTML();

    for (const fragment of ['<section class="hero">', '<p class="eyebrow">', '<p class="lead">', '<a class="button" href="/ru/pricing">', '<a class="button button-secondary" href="/ru/signup">', '<section class="band">', '<div class="grid-2"><div class="feature"><h3>Путь</h3>', '<p class="note">', '<strong>жирно</strong>', '<span class="muted">тихо</span>', '<img src="https://cdn.example.test/a.png" alt="Схема">', '<table', '<th']) {
      expect(html).toContain(fragment);
    }
    expect(wrapper.get('[data-visual]').classes()).toContain('site-content');
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    wrapper.unmount();
  });

  it('applies text styles, sections and button links from the toolbar', async () => {
    const wrapper = await mountEditor('<p>Первый абзац</p>');
    const editor = (wrapper.vm as unknown as { editor: { commands: { setTextSelection(range: { from: number; to: number }): boolean; selectAll(): boolean } } }).editor;

    editor.commands.setTextSelection({ from: 2, to: 2 });
    await wrapper.get('[data-tool="text-style"]').setValue('lead');
    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toBe('<p class="lead">Первый абзац</p>');

    await wrapper.get('[data-tool="block"]').setValue('h2');
    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toBe('<h2 class="lead">Первый абзац</h2>');
    await wrapper.get('[data-tool="block"]').setValue('paragraph');

    await wrapper.get('[data-tool="section"]').trigger('click');
    await settle();
    await wrapper.get('[data-tool="section-style"]').setValue('card');
    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toBe('<section class="card"><p class="lead">Первый абзац</p></section>');

    editor.commands.setTextSelection({ from: 2, to: 8 });
    await wrapper.get('[data-tool="link"]').trigger('click');
    await wrapper.get('[data-field="href"]').setValue('/ru/pricing');
    await wrapper.get('[data-field="link-style"]').setValue('button');
    await wrapper.get('[data-panel="link"]').trigger('submit');
    await settle();
    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toBe('<section class="card"><p class="lead"><a class="button" href="/ru/pricing">Первый</a> абзац</p></section>');

    await wrapper.get('[data-tool="unwrap"]').trigger('click');
    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toBe('<p class="lead"><a class="button" href="/ru/pricing">Первый</a> абзац</p>');

    editor.commands.selectAll();
    await wrapper.get('[data-tool="undo"]').trigger('click');
    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toContain('<section class="card">');
    wrapper.unmount();
  });

  it('inserts a ready block template at the cursor', async () => {
    localization.setLocale('ru');
    const blocks = [
      { id: 'b-1', name: 'Первый экран', category: 'hero', html: '<section class="hero"><h1>Обещание</h1><p class="actions"><a class="button" href="/ru/signup">Начать</a></p></section>' },
      { id: 'b-2', name: 'Свой блок', category: 'custom', html: '<p class="note">Важно</p>' },
    ];
    const wrapper = mount(RichEditor, { props: { modelValue: '<p>Текст</p>', label: 'Содержимое', blocks }, global: { plugins: [localization] }, attachTo: document.body });
    await flushPromises();
    const editor = (wrapper.vm as unknown as { editor: { commands: { setTextSelection(position: number): boolean } } }).editor;
    editor.commands.setTextSelection(6);

    await wrapper.get('[data-tool="blocks"]').trigger('click');
    const panel = wrapper.get('[data-panel="blocks"]');
    expect(panel.text()).toContain('Первый экран');
    expect(panel.text()).toContain('custom');
    await wrapper.get('[data-block="b-1"]').trigger('click');

    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toBe('<p>Текст</p><section class="hero"><h1>Обещание</h1><p class="actions"><a class="button" href="/ru/signup">Начать</a></p></section>');
    expect(wrapper.find('[data-panel="blocks"]').exists()).toBe(false);

    await wrapper.setProps({ modelValue: '<section class="card"><p>В карточке</p></section><p></p>' });
    await flushPromises();
    editor.commands.setTextSelection(4);
    await wrapper.get('[data-tool="blocks"]').trigger('click');
    await wrapper.get('[data-block="b-2"]').trigger('click');
    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toBe('<section class="card"><p>В карточке</p></section><p class="note">Важно</p>');
    wrapper.unmount();
  });

  it('has no block button without templates', async () => {
    const wrapper = await mountEditor('<p>Текст</p>');
    expect(wrapper.find('[data-tool="blocks"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('follows changes made outside, for example in HTML mode, without reporting them back', async () => {
    const wrapper = await mountEditor('<p>Старый</p>');
    await wrapper.setProps({ modelValue: '<h2>Новый</h2>' });
    await flushPromises();

    expect(wrapper.get('[data-visual]').html()).toContain('<h2>Новый</h2>');
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    wrapper.unmount();
  });
});
