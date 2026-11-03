import { appendFile, readFile, readdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export async function frontendState(directory) {
  const entries = await readdir(directory);
  if (!entries.includes('package.json')) {
    if (entries.some((name) => name !== '.gitkeep')) {
      throw new Error('Frontend files exist without package.json; refusing to skip build/test.');
    }
    return false;
  }
  if (!entries.includes('package-lock.json')) {
    throw new Error('Frontend package-lock.json is required for npm ci.');
  }
  const pkg = JSON.parse(await readFile(resolve(directory, 'package.json'), 'utf8'));
  for (const script of ['build', 'test']) {
    if (typeof pkg.scripts?.[script] !== 'string' || !pkg.scripts[script].trim()) {
      throw new Error(`Frontend scripts.${script} is required.`);
    }
  }
  return true;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    const enabled = await frontendState(resolve('frontend'));
    const message = enabled
      ? 'Frontend manifest, npm lockfile and build/test scripts found; build/test are required.'
      : 'Frontend contains only the T0.5 placeholder; application build/test are not available yet.';
    console.log(enabled ? message : `::notice::${message}`);
    if (process.env.GITHUB_OUTPUT) {
      await appendFile(process.env.GITHUB_OUTPUT, `enabled=${enabled}\n`);
    }
    if (process.env.GITHUB_STEP_SUMMARY) {
      await appendFile(process.env.GITHUB_STEP_SUMMARY, `${message}\n`);
    }
  } catch (error) {
    console.error(`::error::${error.message}`);
    process.exitCode = 1;
  }
}
