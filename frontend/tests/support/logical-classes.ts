// Match utilities at token/variant boundaries, including negative and important forms.
// This conservative source-text guard also covers class strings in helpers and @apply.
const physicalClass = /(?<![\w-])!?-?(?:(?:ml|mr|pl|pr|left|right)-[^\s"'`<>{};,]+|text-(?:left|right)(?![\w-])|(?:rounded|border)-(?:l|r)(?:-[^\s"'`<>{};,]+)?(?![\w-]))/g;

export function physicalClasses(source: string) {
  return [...source.matchAll(physicalClass)].map((match) => ({
    utility: match[0],
    line: source.slice(0, match.index).split('\n').length,
  }));
}
