import { EditorView } from '@codemirror/view';
import { flushPromises, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';

async function codeView(wrapper: VueWrapper): Promise<EditorView> {
  for (let attempt = 0; attempt < 50; attempt++) {
    const element = wrapper.find('[data-code-editor] .cm-editor');
    const view = element.exists() ? EditorView.findFromDOM(element.element as HTMLElement) : null;
    if (view) return view;
    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 0));
  }

  throw new Error('no code editor');
}

export async function code(wrapper: VueWrapper): Promise<string> {
  return (await codeView(wrapper)).state.doc.toString();
}

export async function setCode(wrapper: VueWrapper, value: string): Promise<void> {
  const view = await codeView(wrapper);
  view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: value } });
  await nextTick();
}
