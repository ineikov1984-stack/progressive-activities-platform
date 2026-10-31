import { execFileSync } from 'node:child_process';
import { appendFileSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/** Documentation and generated review artifacts do not need the application stacks. */
export function needsCodeChecks(paths) {
  return paths.some((path) => !(
    path.startsWith('docs/') ||
    path.startsWith('outputs/') || path.endsWith('.md')
  ));
}

/** Compare the whole PR, or every commit in a push; unknown history runs the full checks. */
export function changedCode(eventName, event, cwd = process.cwd()) {
  const base = eventName === 'pull_request' ? event.pull_request?.base?.sha : event.before;
  const head = eventName === 'pull_request' ? event.pull_request?.head?.sha : event.after;
  const valid = (sha) => typeof sha === 'string' && /^[a-f0-9]{40}$/.test(sha) && !/^0+$/.test(sha);
  if (!['pull_request', 'push'].includes(eventName) || !valid(base) || !valid(head)) return { code: true, paths: null };
  try {
    const range = `${base}${eventName === 'pull_request' ? '...' : '..'}${head}`;
    // No rename detection: moving code into docs must still count its deleted source path.
    // NUL separators preserve spaces/newlines and avoid shell or workflow-command interpretation.
    const names = execFileSync('git', ['diff', '--no-renames', '--name-only', '-z', range, '--'], {
      cwd, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'],
    }).split('\0').filter(Boolean);
    return { code: needsCodeChecks(names), paths: names.length };
  } catch {
    return { code: true, paths: null };
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  const event = JSON.parse(readFileSync(process.env.GITHUB_EVENT_PATH, 'utf8'));
  const result = changedCode(process.env.GITHUB_EVENT_NAME, event);
  appendFileSync(process.env.GITHUB_OUTPUT, `code=${result.code}\n`);
  console.log(result.paths === null ? 'Unknown comparison: run the full checks.'
    : `${result.paths} changed paths: ${result.code ? 'run the full checks' : 'documentation checks only'}.`);
}
