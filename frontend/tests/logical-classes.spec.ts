import { expect, test } from '@playwright/test';
import { readFile, readdir } from 'node:fs/promises';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { physicalClasses } from './support/logical-classes';

test('source uses logical Tailwind directions only', async () => {
  const root = fileURLToPath(new URL('../src', import.meta.url));
  const violations: string[] = [];
  async function scan(directory: string) {
    for (const entry of await readdir(directory, { withFileTypes: true })) {
      const path = join(directory, entry.name);
      if (entry.isDirectory()) {
        await scan(path);
      } else if (entry.isFile()) {
        for (const { utility, line } of physicalClasses(await readFile(path, 'utf8'))) {
          violations.push(`${relative(root, path)}:${line}: ${utility}`);
        }
      }
    }
  }
  await scan(root);
  expect(violations, 'Use logical Tailwind directions instead of physical left/right classes').toEqual([]);
});

test('guard rejects physical fixtures including responsive variants', () => {
  const fixture = '<div className="md:ml-4" />';
  expect(physicalClasses(fixture)).toEqual([{ utility: 'ml-4', line: 1 }]);
  const utilities = [
    'ml-4', 'mr-4', 'pl-4', 'pr-4', 'left-0', 'right-0', 'text-left', 'text-right',
    'rounded-l-lg', 'rounded-r-lg', 'border-l-2', 'border-r-2', 'rounded-l', 'border-r',
    '-ml-4', '!mr-4', '!-left-4', 'ml-[3px]',
  ];
  for (const utility of utilities) {
    for (const prefix of ['', 'md:', 'hover:', 'md:hover:', '[&>span]:']) {
      expect(physicalClasses(`<div className="${prefix}${utility}" />`), prefix + utility).toHaveLength(1);
    }
  }
  expect(physicalClasses('first line\n<div className="md:ml-4" />')[0].line).toBe(2);
});

test('guard allows logical fixtures and unrelated class names', () => {
  expect(physicalClasses('<div className="ms-4" />')).toEqual([]);
  expect(physicalClasses(`
    <div className="md:ms-4 me-4 ps-4 pe-4 start-0 end-0 hover:text-start text-end
      rounded-s-lg rounded-e-lg border-s-2 border-e-2 mx-4 px-4 text-leftovers custom-ml-4" />
  `)).toEqual([]);
});
