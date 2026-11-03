import { expect, test } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import ar from '../src/locales/ar.json' with { type: 'json' };
import en from '../src/locales/en.json' with { type: 'json' };

test('production page defaults to Arabic RTL with compiled Tailwind styles', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/app/');
  await expect(page.getByRole('heading', { name: ar.pageTitle })).toBeVisible();
  await expect(page.getByText(ar.description)).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.getByRole('main')).toHaveCSS('direction', 'rtl');
  await expect(page.getByRole('main')).toHaveCSS('text-align', 'start');
  await expect(page.getByRole('heading')).toHaveCSS('font-weight', '700');
  await expect(page).toHaveTitle(ar.appName);
  expect(errors).toEqual([]);
});

test('language switch translates the page and changes direction both ways', async ({ page }) => {
  await page.goto('/app/');
  await page.getByRole('combobox', { name: ar.language }).selectOption('en');
  await expect(page.getByRole('heading', { name: en.pageTitle })).toBeVisible();
  await expect(page.getByText(en.description)).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  await expect(page.getByRole('main')).toHaveCSS('direction', 'ltr');
  await expect(page).toHaveTitle(en.appName);
  await page.getByRole('combobox', { name: en.language }).selectOption('ar');
  await expect(page.getByRole('heading', { name: ar.pageTitle })).toBeVisible();
  await expect(page.getByRole('main')).toHaveCSS('direction', 'rtl');
});

test('router handles an unknown app URL and navigates home', async ({ page }) => {
  await page.goto('/app/nested/missing/');
  await expect(page.getByRole('heading', { name: ar.notFound })).toBeVisible();
  await page.getByRole('link', { name: ar.home, exact: true }).click();
  await expect(page).toHaveURL(/\/app\/$/);
  await expect(page.getByRole('heading', { name: ar.pageTitle })).toBeVisible();
});

test('dictionaries have matching non-empty entries and build manifest resolves assets', async () => {
  expect(Object.keys(ar).sort()).toEqual(Object.keys(en).sort());
  for (const value of [...Object.values(ar), ...Object.values(en)]) expect(value.trim()).not.toBe('');
  const manifest = JSON.parse(await readFile(new URL('../../build/manifest.json', import.meta.url), 'utf8'));
  const entry = manifest['index.html'];
  expect(entry.isEntry).toBe(true);
  expect(entry.file).toMatch(/^assets\/.+\.js$/);
  expect(entry.css.length).toBeGreaterThan(0);
  for (const asset of [entry.file, ...entry.css]) {
    expect((await readFile(new URL(`../../build/${asset}`, import.meta.url))).length).toBeGreaterThan(0);
  }
});
