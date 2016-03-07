<?php

function cloudflare_zone_set($id) {
    global $db;

    $zone_name = get_zone_name_from_id($id);

    $query = sprintf('SELECT r.`name`, m.`content` FROM `records` r, `records_meta` m WHERE r.`id` = m.`id` AND r.`domain_id` = %d AND m.`cloudflare` = 1'
        , $id
        );

    $result = $db->query($query);

    if (PEAR::isError($result)) {
        error($response->getMessage());
        return false;
    }

    $subdomains = [];
    while ($row = $result->fetchRow()) {
      $sub = substr($row['name'], 0, -(strlen($zone_name) + 1) );
      $subdomains[] = $sub . ':' . $row['content'];
    }
    $subdomains = implode(',', $subdomains);

    if (!$subdomains) return true;

    $response = cloudflare_request('zone_set'
        ,   [ 'zone_name' => $zone_name
            , 'resolve_to' => 'cloudflare-resolve-to.' . $zone_name
            , 'subdomains' => $subdomains
            ]
        );

    if ($response->result === 'error') {
        return error('CloudFlare: ' . $response->msg);
    }

    return true;
}

function cloudflare_request($act, $fields) {
    global $cloudflare_host_key, $cloudflare_user_key;
    $fields['act'] = $act;
    $fields['host_key'] = $cloudflare_host_key;

    if (in_array($act, [ 'zone_set', 'zone_lookup', 'zone_deletes' ])) {
      $fields['user_key'] = $cloudflare_user_key;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_URL, 'https://api.cloudflare.com/host-gw.html');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    if ($result === false) {
      throw new Exception(curl_error($ch));
      return;
    }
    curl_close($ch);

    return json_decode($result);
}
