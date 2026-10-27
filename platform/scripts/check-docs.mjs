import { readFile } from 'node:fs/promises';

const architectureFiles = [
  'docs/architecture/0001-modular-monolith.md',
  'docs/architecture/0002-tenancy.md',
  'docs/architecture/0003-authentication.md',
  'docs/architecture/0004-data-classification.md',
];

const requiredSections = ['Status', 'Decision', 'Consequences'];
const failures = [];

for (const path of architectureFiles) {
  try {
    const content = await readFile(new URL(`../${path}`, import.meta.url), 'utf8');

    for (const section of requiredSections) {
      if (!new RegExp(`^## ${section}$`, 'm').test(content)) {
        failures.push(`${path}: missing section "${section}"`);
      }
    }
  } catch {
    failures.push(`${path}: missing file`);
  }
}

const matrixPath = 'docs/operations/environment-matrix.md';

try {
  const matrix = await readFile(new URL(`../${matrixPath}`, import.meta.url), 'utf8');
  const secretPatterns = [
    /-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/,
    /\b(?:sk_live|rk_live)_[A-Za-z0-9]+/,
    /\b(?:password|secret|token)\s*[:=]\s*(?!example|placeholder|managed-secret)[^\s|`]+/i,
  ];

  if (secretPatterns.some((pattern) => pattern.test(matrix))) {
    failures.push(`${matrixPath}: possible real credential detected`);
  }
} catch {
  failures.push(`${matrixPath}: missing file`);
}

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log('Architecture documentation checks passed.');
