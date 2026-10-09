type Tree = { [key: string]: string | Tree };

export function flattenMessages(tree: Tree, prefix = ''): Record<string, string> {
  return Object.entries(tree).reduce<Record<string, string>>((all, [key, value]) => {
    const path = prefix === '' ? key : `${prefix}.${key}`;

    return typeof value === 'string' ? { ...all, [path]: value } : { ...all, ...flattenMessages(value, path) };
  }, {});
}

export function placeholders(text: string): string[] {
  return [...text.matchAll(/\{(\w+)\}/g)].map((match) => match[1]!).sort();
}

/**
 * Problems that make a translation set unshippable: a key missing or extra compared to English,
 * an empty text, or placeholders that differ from the English text.
 */
export function translationProblems(sets: Record<string, Tree>): string[] {
  const base = flattenMessages(sets.en!);
  const problems: string[] = [];

  for (const [locale, messages] of Object.entries(sets)) {
    const flat = flattenMessages(messages);

    for (const key of Object.keys(base)) {
      if (!(key in flat)) problems.push(`${locale}: missing ${key}`);
    }

    for (const [key, text] of Object.entries(flat)) {
      if (!(key in base)) problems.push(`${locale}: extra ${key}`);
      else if (text.trim() === '') problems.push(`${locale}: empty ${key}`);
      else if (placeholders(text).join() !== placeholders(base[key]!).join()) problems.push(`${locale}: placeholders differ in ${key}`);
    }
  }

  return problems;
}
