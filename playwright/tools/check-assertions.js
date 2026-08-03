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
 * guard such as `if (await locator.count() > 0)`, or behind a bare
 * `if (!id) return;`. When the element or fixture is missing the body is
 * skipped, no assertion runs, and the test reports green - so the very
 * regression it guards against is what makes it pass.
 *
 * Advisory only: prints a report and always exits 0.
 */

const fs = require('fs');
const path = require('path');

const TESTS_DIR = path.join(__dirname, '..', 'tests');

// test.describe must not match - a describe block spans the whole file and would swallow its tests
const TEST_START = /^\s*test(?!\.describe)(?:\.\w+)*\s*\(\s*['"`](.+?)['"`]/;
const EXISTENCE_GUARD = /if\s*\(\s*await\s+.*\.(?:count\s*\(\s*\)\s*[>!=]|isVisible\s*\(\s*\))/;
const SILENT_RETURN = /if\s*\(\s*![A-Za-z_$][\w$]*\s*\)\s*return\s*;/;

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

    for (; j < lines.length; j++) {
      const raw = lines[j];
      const code = stripNoise(raw);
      const depthBefore = depth;

      if (EXISTENCE_GUARD.test(code)) {
        guardDepths.add(depthBefore);
      }
      if (SILENT_RETURN.test(code)) {
        skipsSilently = true;
      }
      if (code.includes('expect(')) {
        assertions.push({ depth: depthBefore, line: j + 1 });
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

    const unguarded = assertions.filter((a) => ![...guardDepths].some((g) => a.depth > g));
    if (assertions.length > 0 && unguarded.length === 0) {
      const reasons = ['every assertion is behind an existence guard'];
      if (skipsSilently) {
        reasons.push('silent early return');
      }
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

  if (total === 0) {
    console.log('No Playwright tests with unreachable assertions.');
    process.exit(0);
  }

  const verbose = process.argv.includes('--verbose');
  results.sort((a, b) => b.flagged.length - a.flagged.length);

  console.log(`Playwright tests whose assertions can never run: ${total} in ${results.length} files`);
  console.log('(advisory - these pass when the element under test is absent)\n');

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
