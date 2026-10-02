#!/usr/bin/env node
// `npm audit --audit-level=high` with an allowlist: npm audit cannot accept an
// advisory, so one with no fixed release would block every PR until upstream
// ships. Fails on any high or critical advisory not listed in ACCEPTED, and warns
// about accepted ones that no longer show up, so the list gets pruned.
//
// Usage: node scripts/npm-audit.mjs [npm audit flags, e.g. --omit=dev --workspace mobile]

import { execFileSync } from 'node:child_process';

// GHSA id -> why it cannot be fixed and why it does not reach users.
const ACCEPTED = {
  'GHSA-86w9-cpqp-85rv':
    'node-forge, every version affected and no fix released. Pulled by @expo/cli ' +
    '(EAS update code signing), a build-time tool that is never bundled in the app.',
};

let report;
try {
  report = execFileSync('npm', ['audit', '--json', ...process.argv.slice(2)], {
    encoding: 'utf8',
    maxBuffer: 64 * 1024 * 1024,
  });
} catch (error) {
  // npm audit exits non-zero as soon as it finds anything; the JSON is still on stdout.
  report = error.stdout;
}

const { vulnerabilities = {} } = JSON.parse(report);
const blocking = new Map();
const seen = new Set();

// An advisory appears as an object in the `via` of the package it affects; every
// package above it in the tree only names that package, so the objects suffice.
for (const vulnerability of Object.values(vulnerabilities)) {
  for (const advisory of vulnerability.via) {
    if (typeof advisory !== 'object' || !['high', 'critical'].includes(advisory.severity)) {
      continue;
    }
    const id = advisory.url.split('/').pop();
    seen.add(id);
    if (!(id in ACCEPTED)) {
      blocking.set(id, `${advisory.severity} ${advisory.name}: ${advisory.title} (${advisory.url})`);
    }
  }
}

for (const id of Object.keys(ACCEPTED)) {
  if (!seen.has(id)) {
    console.warn(`Accepted advisory ${id} no longer reported: remove it from scripts/npm-audit.mjs.`);
  }
}
for (const id of seen) {
  if (id in ACCEPTED) {
    console.log(`Accepted ${id}: ${ACCEPTED[id]}`);
  }
}

if (blocking.size > 0) {
  console.error([...blocking.values()].join('\n'));
  process.exit(1);
}

console.log('No unaccepted high or critical advisory.');
