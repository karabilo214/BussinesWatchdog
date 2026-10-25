import type { Editor } from '@tiptap/core';
import { Fragment } from '@tiptap/pm/model';

// The drag handle targets top-level blocks. Keep a whole section (including its
// nested content) together when it is moved with the keyboard.
export function moveBlock(editor: Editor, position: number, direction: -1 | 1): boolean {
  const { doc, tr } = editor.state;
  let offset = 0;
  let index = -1;

  for (let i = 0; i < doc.childCount; i += 1) {
    if (offset === position) {
      index = i;
      break;
    }
    offset += doc.child(i).nodeSize;
  }

  const other = index + direction;
  if (index < 0 || other < 0 || other >= doc.childCount) return false;
  if (other === doc.childCount - 1 && doc.child(other).type.name === 'paragraph' && doc.child(other).content.size === 0) return false;

  const first = doc.child(Math.min(index, other));
  const second = doc.child(Math.max(index, other));
  const from = direction === 1 ? position : position - first.nodeSize;
  tr.replaceWith(from, from + first.nodeSize + second.nodeSize, Fragment.fromArray([second, first]));
  editor.view.dispatch(tr);
  return true;
}
