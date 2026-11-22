/**
 * Traits of the instance under test. The runner states them with E2E_BACKEND
 * (api|sql) and E2E_DB (sqlite|mysql|pgsql); when unset they fall back to the
 * devcontainer port map, which is the only place that knows the ports.
 */

const DEVCONTAINER_INSTANCES = {
  8080: { backend: 'sql', db: 'mysql' },
  8081: { backend: 'sql', db: 'pgsql' },
  8082: { backend: 'sql', db: 'sqlite' },
  8083: { backend: 'api', db: 'mysql' },
  8084: { backend: 'api', db: 'pgsql' },
  8085: { backend: 'api', db: 'sqlite' },
};

/**
 * @param {string} [baseURL] - Base URL under test
 * @returns {{backend: ?string, db: ?string}} - Null for a trait nobody declared
 */
export function instanceTraits(baseURL) {
  const port = (/:(\d+)(?:\/|$)/.exec(baseURL || '') || [])[1];
  const known = DEVCONTAINER_INSTANCES[port] || {};

  return {
    backend: process.env.E2E_BACKEND || known.backend || null,
    db: process.env.E2E_DB || known.db || null,
  };
}

export function isApiModeInstance(baseURL) {
  return instanceTraits(baseURL).backend === 'api';
}

export function isSqliteInstance(baseURL) {
  return instanceTraits(baseURL).db === 'sqlite';
}
