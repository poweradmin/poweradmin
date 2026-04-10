<?php

/**
 * Supplementary RDAP servers list
 *
 * Known RDAP servers that are not in the IANA bootstrap registry but are functional.
 * These entries are merged into the main list during updates and are never
 * removed by the update script.
 *
 * Format: 'tld' => 'https://rdap.server.url/'
 *
 * To add a new entry:
 * 1. Verify the RDAP server is reachable: curl -s https://rdap.server.url/domain/example.tld
 * 2. Add the TLD and server URL below
 * 3. Run scripts/update_rdap_servers.php to regenerate the main list
 */

return [
    // No supplementary RDAP servers yet
    // 'za' => 'https://rdap.registry.net.za/',
];
