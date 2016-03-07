<?php

require_once('inc/ChangeLogger.class.php');
require_once('inc/ChangeMailer.class.php');
require_once('inc/CliParser.class.php');
include_once("inc/config-me.inc.php");
if (!@include_once("inc/config.inc.php")) {
    error(_('You have to create a inc/config.inc.php!'));
}
include_once("inc/database.inc.php");

function getindex($arr, $index, $default = null) {
    return isset($arr[$index]) ? $arr[$index] : $default;
}

$cli_args = array(
    '--dry-run' => array(
        'name' => 'dry-run',
        'type' => 'flag',
    ),
    '--changes-since' => array(
        'name' => 'changes-since',
        'type' => 'arg',
        'count' => 1,
    ),
    '--to' => array(
        'name' => 'to',
        'type' => 'vararg',
        'required' => true,
    ),
    '--subject' => array(
        'name' => 'subject',
        'type' => 'arg',
        'count' => 1,
        'required' => true,
    ),
    '--from' => array(
        'name' => 'from',
        'type' => 'arg',
        'count' => 1,
    ),
    '--header' => array(
        'name' => 'header',
        'type' => 'arg',
        'count' => 1,
        'default' => ''
    ),
    '--footer' => array(
        'name' => 'footer',
        'type' => 'arg',
        'count' => 1,
        'default' => '',
    )
);

$cli_parser = new CliParser($cli_args);
$params = $cli_parser->parse($argv);

$mail_config = array(
    "to" => implode(', ', $params['to']),
    "subject" => "[noris network AG] " . $params['subject'],
    "headers" => array(
        "MIME-Version" => "1.0",
        "Content-Type" => "text/html; charset=UTF-8",
        "From" => getindex($params, 'from', 'support@noris.de'),
    ),
    "before_diff" => getindex($params, 'header', ''),
    "after_diff" => getindex($params, 'footer', '')
);
$db = dbConnect();

$change_logger = ChangeLogger::with_db($db);
$mailer = new ChangeMailer($mail_config, $change_logger, $params);
$status = $mailer->send();

if ($status === EmailStatus::SUCCESS) {
    echo "Sent.";
    exit(0);
}

if ($status === EmailStatus::ERROR) {
    echo "Error sending mail. PHPs `mail()` returned `false`.\n";
    exit(1);
}
if ($status === EmailStatus::NO_CHANGES) {
    $changes_since = isset($params['changes-since']) ? $params['changes-since'] : null;

    if ($changes_since === null) {
        echo "No changes in forever.";
    } else {
        printf("No changes since %s.\n", $changes_since);
    }
    exit(0);
}
if ($status === EmailStatus::DRY_RUN) {
    exit(0);
}
