import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { useBackLink } from '@/composables/useBackLink';

const empty = defineComponent({ render: () => h('div') });

function linkFor(back: unknown): unknown {
  window.history.replaceState({ back }, '');
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/incidents', name: 'incidents', component: empty },
      { path: '/incidents/:id', name: 'incident', component: empty },
      { path: '/checks', name: 'checks', component: empty },
    ],
  });
  let result: unknown;
  mount(defineComponent({ setup: () => { result = useBackLink('incidents', () => ({ name: 'incidents', query: { store: 's1' } })).value; return () => h('div'); } }), { global: { plugins: [router] } });

  return result;
}

describe('useBackLink', () => {
  afterEach(() => window.history.replaceState(null, ''));

  it('returns to the exact list page with its store and filters', () => {
    expect(linkFor('/incidents?store=s1&severity=critical&tab=resolved')).toBe('/incidents?store=s1&severity=critical&tab=resolved');
  });

  it('falls back to the list of the item store when opened directly or from another page', () => {
    expect(linkFor(undefined)).toEqual({ name: 'incidents', query: { store: 's1' } });
    expect(linkFor('/checks?store=s2')).toEqual({ name: 'incidents', query: { store: 's1' } });
  });
});
