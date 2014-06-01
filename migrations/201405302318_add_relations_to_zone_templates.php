<?php

include_once('../inc/config-me.inc.php');

if (!@include_once('../inc/config.inc.php')) {
    error(_('You have to create a config.inc.php!'));
}

require '../inc/error.inc.php';
require '../inc/database.inc.php';
require '../inc/file.inc.php';
require '../inc/record.inc.php';
require '../inc/migrations.inc.php';

$db = dbConnect();

$file_name = file_get_name_without_extension(__FILE__);
if (migration_exists($db, $file_name)) {
    migration_message('The migration had already been applied!');
    exit;
}

$zones = get_zones_with_templates($db);
foreach ($zones as $zone) {
    $domain = get_zone_name_from_id($zone['id']);
    $templ_records = get_zone_templ_records($zone['zone_templ_id']);

    $generated_templ_records = array();
    foreach ($templ_records as $templ_record) {
        $name = parse_template_value($templ_record['name'], $domain);
        $type = $templ_record['type'];
        $content = parse_template_value($templ_record['content'], $domain);
        $generated_templ_records[] = array(
            'name' => $name,
            'type' => $type,
            'content' => $content,
        );
    }

    $records = get_records_by_domain_id($db, $zone['domain_id']);
    foreach ($records as $record) {
        foreach ($generated_templ_records as $generated_templ_record) {
            if ($record['name'] == $generated_templ_record['name'] &&
                    $record['type'] == $generated_templ_record['type'] &&
                    $record['content'] == $generated_templ_record['content']) {
                if (!record_relation_to_templ_exists($db, $zone['domain_id'], $record['id'], $zone['zone_templ_id'])) {
                    add_record_relation_to_templ($db, $zone['domain_id'], $record['id'], $zone['zone_templ_id']);
                }
                break;
            }
        }
    }
}

migration_message('Relations between records and zone templates added successfully');
migration_save($db, $file_name);
