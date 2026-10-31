import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, renameSync, rmSync, writeFileSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { changedCode, needsCodeChecks } from './ci-changes.mjs';

function repository(t) {
  const cwd = mkdtempSync(join(tmpdir(), 'ci-changes-'));
  t.after(() => rmSync(cwd, { recursive: true, force: true }));
  const git = (...args) => execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  const file = (name, text = 'synthetic fixture\n') => { mkdirSync(dirname(join(cwd, name)), { recursive: true }); writeFileSync(join(cwd, name), text); };
  const commit = () => { git('add', '.'); git('-c', 'user.name=CI', '-c', 'user.email=ci@example.test', 'commit', '-qm', 'test fixture'); return git('rev-parse', 'HEAD'); };
  git('init', '-q'); file('README.md');
  const base = commit();
  return { cwd, git, file, commit, base };
}
const pr = (base, head) => ({ pull_request: { base: { sha: base }, head: { sha: head } } });

test('only documentation and output artifacts can skip the application checks', () => {
  assert.equal(needsCodeChecks(['README.md', 'platform/apps/api/README.md', 'docs/mockup.html', 'platform/docs/architecture/tenancy.md', 'outputs/preview.png']), false);
  for (const code of ['platform/apps/api/app/Action.php', '.github/workflows/ci.yml', 'package.json', 'platform/docs/api/openapi.yaml', 'README.md.js', 'docs-other/test.js']) {
    assert.equal(needsCodeChecks(['docs/readme.md', code]), true, code);
  }
});

test('a PR uses its merge base, ignoring unrelated changes that only landed on main', (t) => {
  const r = repository(t);
  r.git('checkout', '-qb', 'feature'); r.file('docs/note.md'); const head = r.commit();
  r.git('checkout', '--detach', r.base); r.file('server.php'); const advancedBase = r.commit();
  assert.deepEqual(changedCode('pull_request', pr(advancedBase, head), r.cwd), { code: false, paths: 1 });
});

test('a push covers all its commits, including earlier code followed by a docs commit', (t) => {
  const r = repository(t);
  r.file('server.php'); r.commit(); r.file('docs/note.md'); const head = r.commit();
  assert.deepEqual(changedCode('push', { before: r.base, after: head }, r.cwd), { code: true, paths: 2 });
});

test('moving code into a documentation path still runs the full checks', (t) => {
  const r = repository(t); r.file('server.php'); const base = r.commit();
  mkdirSync(join(r.cwd, 'docs')); renameSync(join(r.cwd, 'server.php'), join(r.cwd, 'docs/server.md')); const head = r.commit();
  assert.deepEqual(changedCode('pull_request', pr(base, head), r.cwd), { code: true, paths: 2 });
});

test('filenames with spaces or newlines do not hide code changes', (t) => {
  const r = repository(t); r.file('docs/with spaces.md'); r.file('server\nsecond.php'); const head = r.commit();
  assert.deepEqual(changedCode('push', { before: r.base, after: head }, r.cwd), { code: true, paths: 2 });
});

test('unknown events, initial pushes, bad SHAs and missing history run full checks', (t) => {
  const r = repository(t);
  for (const [name, event] of [
    ['workflow_dispatch', {}], ['push', { before: '0'.repeat(40), after: r.base }],
    ['pull_request', pr('--help', r.base)], ['push', { before: 'a'.repeat(40), after: r.base }],
  ]) assert.deepEqual(changedCode(name, event, r.cwd), { code: true, paths: null });
});

test('the workflow entry point emits a fixed output for a documentation-only PR', (t) => {
  const r = repository(t); r.file('docs/note.md'); const head = r.commit();
  const eventPath = join(r.cwd, 'event.json'); const output = join(r.cwd, 'output');
  writeFileSync(eventPath, JSON.stringify(pr(r.base, head)));
  execFileSync(process.execPath, [fileURLToPath(new URL('./ci-changes.mjs', import.meta.url))], {
    cwd: r.cwd, env: { ...process.env, GITHUB_EVENT_NAME: 'pull_request', GITHUB_EVENT_PATH: eventPath, GITHUB_OUTPUT: output },
  });
  assert.equal(readFileSync(output, 'utf8'), 'code=false\n');
});
