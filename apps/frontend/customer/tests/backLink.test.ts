import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { useBackLink, type BackLink } from '@/composables/useBackLink';

const empty = defineComponent({ render: () => h('div') });

function linkFor(back: unknown) {
  window.history.replaceState({ back }, '');
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/incidents', name: 'incidents', component: empty },
      { path: '/incidents/:id', name: 'incident', component: empty },
      { path: '/checks', name: 'checks', component: empty },
      { path: '/stores/:id', name: 'store', component: empty },
      { path: '/login', name: 'login', component: empty },
    ],
  });
  let link: BackLink | null = null;
  mount(defineComponent({ setup: () => { link = useBackLink('incidents', () => ({ name: 'incidents', query: { store: 's1' } })); return () => h('div'); } }), { global: { plugins: [router] } });

  return { link: link!, router };
}

function click(): MouseEvent {
  return new MouseEvent('click', { button: 0, cancelable: true });
}

describe('useBackLink', () => {
  afterEach(() => window.history.replaceState(null, ''));

  it('returns to the exact page the user came from, with its store, filters and label', () => {
    const fromList = linkFor('/incidents?store=s1&severity=critical&tab=resolved').link;
    expect(fromList.href.value).toBe('/incidents?store=s1&severity=critical&tab=resolved');
    expect(fromList.label.value).toBe('nav.incidents');

    const fromStore = linkFor('/stores/s2').link;
    expect(fromStore.href.value).toBe('/stores/s2');
    expect(fromStore.label.value).toBe('nav.back.store');

    expect(linkFor('/checks?store=s2').link.label.value).toBe('nav.checks');
  });

  it('falls back to the list of the item store when opened directly or from an unrelated page', () => {
    for (const back of [undefined, '/login']) {
      const { link } = linkFor(back);
      expect(link.href.value).toBe('/incidents?store=s1');
      expect(link.label.value).toBe('nav.incidents');
    }
  });

  it('goes back through history so the scroll position returns, otherwise opens the fallback', () => {
    const known = linkFor('/incidents?store=s1');
    const back = vi.spyOn(known.router, 'back').mockImplementation(() => undefined);
    const event = click();
    known.link.go(event);
    expect(back).toHaveBeenCalledOnce();
    expect(event.defaultPrevented).toBe(true);

    const direct = linkFor(undefined);
    const push = vi.spyOn(direct.router, 'push');
    direct.link.go(click());
    expect(push).toHaveBeenCalledWith({ name: 'incidents', query: { store: 's1' } });

    const newTab = new MouseEvent('click', { button: 0, ctrlKey: true, cancelable: true });
    direct.link.go(newTab);
    expect(newTab.defaultPrevented).toBe(false);
  });
});
