<?php

/**
 * Supplementary WHOIS servers list
 *
 * Known WHOIS servers that are not registered with IANA but are functional.
 * These entries are merged into the main list during updates and are never
 * removed by the update script.
 *
 * Format: 'tld' => 'whois.server.hostname'
 *
 * To add a new entry:
 * 1. Verify the WHOIS server is reachable: echo "example.tld" | nc -w 5 server 43
 * 2. Add the TLD and server hostname below
 * 3. Run scripts/update_whois_servers.php to regenerate the main list
 */

return [
    'za' => 'whois.registry.net.za',
    'co.za' => 'whois.registry.net.za',
];
