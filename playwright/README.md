# Playwright E2E specs

## Running

Start the devcontainer (see the `devcontainer-testing` skill). Instances:
MySQL `:8080`, PostgreSQL `:8081`, SQLite `:8082`; API backend mode `:8083`-`:8085`.

While `install/` exists the login page renders empty and a footer warning shows, so
rename it for the run (`mv install install.old`) and restore it afterwards
(`mv install.old install`); the maintainer-local `scripts/toggle_install.sh` does the same.

```bash
BASE_URL=http://localhost:8080 npx playwright test playwright/tests/zones --project=chromium --workers=2
```

`BASE_URL` picks the instance. `npm run test:e2e:mysql|pgsql|sqlite` run everything.
The multi-instance sweep script is maintainer-local; run one `npx` command per instance.
Reset test data with `.devcontainer/scripts/import-test-data.sh --clean` if logins fail.

## Isolation rules

Every file must pass alone, in parallel with other files, and on rerun without a data reset.

- Write only to throwaway objects. `fixtures/zones.json` and `users.json` are read-only.
- Do not assert exact counts on shared pages (zone lists, user lists, logs).
  Scope to your object, e.g. `/zones/{id}/edit?search=<label>`.
- Clean up in teardown. The fixtures do it even when the test failed.
- Never add a worker-scoped data fixture. Playwright shares worker fixtures
  across every file a worker runs, so state leaks between files.

## Fixtures and helpers

From `fixtures/test-fixtures.js`:

- `tempZone` (test fixture): fresh zone with apex NS per test, `{ id, name }`.
- `useFileZone()`: one zone per file or describe block; call at file level: `const zone = useFileZone();`.

From `helpers/zones.js`:

- `uniqueName(prefix, testInfo)` / `uniqueZoneName(prefix, testInfo, suffix)`: unique names across workers and reruns.
- `createZone(page, domainName, type)`: create a zone, returns its id.
- `createTempZone(page, { name, type, withApexNs, records, testInfo })`: zone ready for records and signing, returns `{ id, name }`.
- `addRecord(page, zoneId, { name, type, content, ttl, prio })`: add one record.
- `assignZoneOwner(page, zoneId, username)`: make a user the zone owner (admin page).
- `deleteZoneById(page, zoneId)`: delete a zone; throws if refused.
- `errorMessages(page)`: text of visible error and warning flashes, for assertion messages.

From `helpers/dnssec.js`:

- `ensureZoneSigned(page, zoneId)`: sign the zone (needs apex NS).
- `addDnssecKey(page, zoneId, { keyType })`: add a key, returns its id.
- `submitKeyToggle(page, zoneId, keyId)`: activate or deactivate a key, returns which.
- `pruneDnssecKeys(page, zoneId, keepIds)`: delete keys not in `keepIds`.

`tests/records/record-assert.js` has `expectRecordAdded(page, zoneId, { name, content, search })`
for specs that submit the add record form.

## Assertions

- Assert the change landed, not only that there was no fatal error. A refused
  form often stays on the same page without one.
- Record values render as input `value` attributes, not text. Read them with
  `evaluateAll` or `inputValue`, not `textContent`.
- Feature-gated UI: `test.skip(cond, reason)`. Never a silent `return` or an
  `if (count > 0)` guard around every `expect`; the test can then never fail.

## Checks

```bash
npx eslint playwright/
node playwright/tools/check-assertions.js
php playwright/tools/check-route-paths.php
```

`check-assertions.js` flags tests that cannot fail.
`check-route-paths.php` checks paths used in specs against the routes.
