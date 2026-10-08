#!/usr/bin/env node

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Reports Playwright tests that can never fail.
 *
 * A test is flagged when every expect() it contains sits inside an existence
 * guard such as `if (await locator.count() > 0)`, or after a bare early
 * return at the top level of the test body (`if (!id) return;` or
 * `if (!id) { return; }`). When the element or fixture is missing the body is
 * skipped, no assertion runs, and the test reports green - so the very
 * regression it guards against is what makes it pass. Returns inside nested
 * callbacks or helpers do not count, and hooks (beforeAll, afterAll) are not tests.
 *
 * Exits 1 on a flagged test missing from assertions-baseline.txt or a baseline
 * entry that no longer flags; otherwise prints the baselined backlog and exits 0.
 */

const fs = require('fs');
const path = require('path');

const TESTS_DIR = path.join(__dirname, '..', 'tests');

// test.describe must not match - a describe block spans the whole file and would swallow its tests
const TEST_START = /^\s*test(?!\.describe)(?:\.\w+)*\s*\(\s*['"`](.+?)['"`]/;
const EXISTENCE_GUARD = /if\s*\(\s*await\s+.*\.(?:count\s*\(\s*\)\s*[>!=]|isVisible\s*\(\s*\))/;
const SILENT_RETURN = /^\s*if\s*\(\s*![A-Za-z_$][\w$]*\s*\)\s*(?:return\s*;?\s*$|\{\s*return\s*;?\s*\})/;
const SILENT_RETURN_BLOCK_OPEN = /^\s*if\s*\(\s*![A-Za-z_$][\w$]*\s*\)\s*\{\s*$/;
const BARE_RETURN = /^\s*return\s*;?\s*$/;
const BLOCK_ENDERS = /expect\(|test\.skip\(|\bthrow\b/;

/** Blank out comments, strings and regex literals so brace counting is not fooled by braces inside them. */
function stripNoise(line) {
  return line
    .replace(/\\./g, '')
    .replace(/'[^']*'/g, "''")
    .replace(/"[^"]*"/g, '""')
    .replace(/`[^`]*`/g, '``')
    .replace(/\/\/.*$/, '')
    .replace(/\/\*.*?\*\//g, '');
}

function collectSpecFiles(dir) {
  const found = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      found.push(...collectSpecFiles(full));
    } else if (entry.name.endsWith('.spec.js')) {
      found.push(full);
    }
  }
  return found;
}

/** Walk one file and return every test whose assertions are all unreachable-when-absent. */
function analyseFile(file) {
  const lines = fs.readFileSync(file, 'utf8').split('\n');
  const flagged = [];

  for (let i = 0; i < lines.length; i++) {
    const start = TEST_START.exec(lines[i]);
    if (!start) {
      continue;
    }

    const name = start[1];
    const guardDepths = new Set();
    const assertions = [];
    let depth = 0;
    let opened = false;
    let base = null;
    let j = i;
    let skipsSilently = false;
    let pendingBlockReturn = null;
    let returnDepth = null;

    for (; j < lines.length; j++) {
      const raw = lines[j];
      const code = stripNoise(raw);
      const depthBefore = depth;

      if (EXISTENCE_GUARD.test(code)) {
        guardDepths.add(depthBefore);
      }
      // Only a return directly in the test body counts; deeper ones belong to callbacks or loops.
      const bodyDepth = base === null ? null : base + 1;
      if (opened && depthBefore === bodyDepth && returnDepth === null) {
        if (SILENT_RETURN.test(code)) {
          returnDepth = depthBefore;
          skipsSilently = true;
        } else if (SILENT_RETURN_BLOCK_OPEN.test(code)) {
          pendingBlockReturn = depthBefore;
        }
      } else if (pendingBlockReturn !== null && code.trim() !== '') {
        // Other statements (an annotation push, a log) may sit between the guard and its return
        if (BARE_RETURN.test(code) && depthBefore === pendingBlockReturn + 1) {
          returnDepth = pendingBlockReturn;
          skipsSilently = true;
          pendingBlockReturn = null;
        } else if (BLOCK_ENDERS.test(code) || depthBefore <= pendingBlockReturn || code.trim().startsWith('}')) {
          pendingBlockReturn = null;
        }
      }
      if (code.includes('expect(')) {
        assertions.push({ depth: depthBefore, line: j + 1, afterReturn: returnDepth !== null });
      }

      depth += (code.match(/{/g) || []).length - (code.match(/}/g) || []).length;
      if (!opened && code.includes('{')) {
        opened = true;
        base = depth - 1;
      }
      if (opened && depth <= base) {
        break;
      }
    }

    const unguarded = assertions.filter((a) => !a.afterReturn && ![...guardDepths].some((g) => a.depth > g));
    if (assertions.length > 0 && unguarded.length === 0) {
      const reasons = [skipsSilently ? 'assertions sit behind a silent early return' : 'every assertion is behind an existence guard'];
      flagged.push({ name, line: i + 1, assertions: assertions.length, reason: reasons.join('; ') });
    }

    i = j;
  }

  return flagged;
}

function main() {
  if (!fs.existsSync(TESTS_DIR)) {
    console.error(`No Playwright tests directory at ${TESTS_DIR}`);
    process.exit(0);
  }

  const files = collectSpecFiles(TESTS_DIR).sort();
  const results = [];
  let total = 0;

  for (const file of files) {
    const flagged = analyseFile(file);
    if (flagged.length > 0) {
      results.push({ file: path.relative(path.join(__dirname, '..', '..'), file), flagged });
      total += flagged.length;
    }
  }

  // The known cases are frozen in a baseline so the backlog can be worked down
  // without blocking, while anything new fails the run.
  const baselinePath = path.join(__dirname, 'assertions-baseline.txt');
  const baseline = fs.existsSync(baselinePath)
    ? new Set(fs.readFileSync(baselinePath, 'utf8').split('\n').map(l => l.trim()).filter(Boolean))
    : new Set();

  const seen = new Set();
  const added = [];
  for (const { file, flagged } of results) {
    for (const t of flagged) {
      const key = `${file}::${t.name}`;
      seen.add(key);
      if (!baseline.has(key)) {
        added.push(`${file}:${t.line}  ${t.name}`);
      }
    }
  }
  const stale = [...baseline].filter(key => !seen.has(key));

  if (added.length > 0) {
    console.log(`New Playwright tests whose assertions can never run: ${added.length}`);
    console.log('Give the test an assertion that runs when the element is absent, or');
    console.log('add it to playwright/tools/assertions-baseline.txt with a reason in the commit.\n');
    added.forEach(line => console.log(`  ${line}`));
    process.exit(1);
  }

  if (stale.length > 0) {
    console.log(`These baseline entries no longer flag; remove them from playwright/tools/assertions-baseline.txt:\n`);
    stale.forEach(key => console.log(`  ${key}`));
    process.exit(1);
  }

  if (total === 0) {
    console.log('No Playwright tests with unreachable assertions.');
    process.exit(0);
  }

  const verbose = process.argv.includes('--verbose');
  results.sort((a, b) => b.flagged.length - a.flagged.length);

  console.log(`Playwright tests whose assertions can never run: ${total} in ${results.length} files`);
  console.log('(all are in the baseline; these pass when the element under test is absent)\n');

  for (const { file, flagged } of results) {
    console.log(`${String(flagged.length).padStart(3)}  ${file}`);
    if (verbose) {
      for (const t of flagged) {
        console.log(`       ${file}:${t.line}  ${t.name}`);
      }
    }
  }

  console.log('\nRun with --verbose to list individual tests.');
  process.exit(0);
}

main();
