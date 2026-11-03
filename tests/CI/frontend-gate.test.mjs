import assert from 'node:assert/strict';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { tmpdir } from 'node:os';
import { test } from 'node:test';
import { frontendState } from '../../bin/ci-frontend.mjs';

async function fixture(files, run) {
  const directory = await mkdtemp(join(tmpdir(), 'rm-ci-frontend-'));
  try {
    for (const [name, value] of Object.entries(files)) {
      await writeFile(join(directory, name), value);
    }
    await run(directory);
  } finally {
    assert.equal(dirname(directory), tmpdir());
    assert.ok(directory.startsWith(join(tmpdir(), 'rm-ci-frontend-')));
    await rm(directory, { recursive: true });
  }
}

test('the existing placeholder reports the pre-T0.5 state', async () => {
  await fixture({ '.gitkeep': '' }, async (dir) => assert.equal(await frontendState(dir), false));
});

test('source without a manifest fails instead of silently skipping', async () => {
  await fixture({ 'app.tsx': '' }, async (dir) => {
    await assert.rejects(frontendState(dir), /without package.json/);
  });
});

test('a manifest without a lockfile fails', async () => {
  await fixture({ 'package.json': '{}' }, async (dir) => {
    await assert.rejects(frontendState(dir), /package-lock.json/);
  });
});

for (const missing of ['build', 'test']) {
  test(`missing ${missing} script fails`, async () => {
    const scripts = { build: 'vite build', test: 'test-runner' };
    delete scripts[missing];
    await fixture({ 'package.json': JSON.stringify({ scripts }), 'package-lock.json': '{}' }, async (dir) => {
      await assert.rejects(frontendState(dir), new RegExp(`scripts.${missing}`));
    });
  });
}

test('a complete scaffold enables mandatory build/test', async () => {
  await fixture({
    'package.json': JSON.stringify({ scripts: { build: 'vite build', test: 'test-runner' } }),
    'package-lock.json': '{}',
  }, async (dir) => assert.equal(await frontendState(dir), true));
});

test('invalid manifest JSON fails', async () => {
  await fixture({ 'package.json': 'invalid', 'package-lock.json': '{}' }, async (dir) => {
    await assert.rejects(frontendState(dir), SyntaxError);
  });
});
