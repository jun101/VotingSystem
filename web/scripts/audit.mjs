// Dependency audit of the web application: every dependency, development tools included.
//
// `npm audit` has no way to accept a known advisory, so this wraps it: it fails on any
// advisory that is not in audit-accepted.json, and on any accepted advisory whose
// `review_by` date has passed (an acceptance is dated and must be looked at again).
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const accepted = JSON.parse(
  readFileSync(new URL('../audit-accepted.json', import.meta.url), 'utf8'),
);
const today = new Date().toISOString().slice(0, 10);

const run = spawnSync('npm', ['audit', '--json'], {
  encoding: 'utf8',
  maxBuffer: 64 * 1024 * 1024,
});
let report;
try {
  report = JSON.parse(run.stdout);
} catch {
  console.error('npm audit gave no readable answer:\n' + run.stdout + run.stderr);
  process.exit(1);
}
if (report.error) {
  console.error('npm audit failed: ' + JSON.stringify(report.error));
  process.exit(1);
}

// The advisories themselves: the entries of `via` that are objects (the others only name
// the package that depends on a vulnerable one).
const found = new Map();
for (const [name, vulnerability] of Object.entries(report.vulnerabilities ?? {})) {
  for (const via of vulnerability.via) {
    if (typeof via === 'object') {
      found.set(via.url.split('/').pop(), `${via.name ?? name}: ${via.title} (${via.severity})`);
    }
  }
}

let failed = false;

for (const [id, description] of found) {
  const entry = accepted.find((item) => item.id === id);
  if (!entry) {
    console.error(`NOT ACCEPTED  ${id}  ${description}`);
    failed = true;
  } else if (entry.review_by < today) {
    console.error(`REVIEW OVERDUE (${entry.review_by})  ${id}  ${description}`);
    failed = true;
  } else {
    console.log(`accepted until ${entry.review_by}  ${id}  ${description}`);
  }
}

for (const entry of accepted) {
  if (!found.has(entry.id))
    console.log(`no longer reported, remove it from audit-accepted.json: ${entry.id}`);
}

if (failed) process.exit(1);
console.log(`npm audit: ${found.size} advisory(ies), all accepted; nothing else.`);
