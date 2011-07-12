<?php
require_once 'MDB2.php';

$db_user = 'powerdns';
$db_pass = '';
$db_host = '';
$db_port = 1521;
$db_name = 'xe';

$dsn = "oci8://$db_user:$db_pass@$db_host:$db_port/?service=$db_name";
#$dsn = "oci8://$db_user:$db_pass@$db_host:$db_port/$db_name";

$options = array(
    'debug'       => 1,
    'portability' => MDB2_PORTABILITY_ALL,
);

$mdb2 = MDB2::connect($dsn, $options);
if (PEAR::isError($mdb2)) {
    die($mdb2->getDebugInfo().PHP_EOL);
}

$query = 'SELECT * FROM v$version WHERE banner LIKE \'Oracle%\'';
#$query = 'SELECT COUNT(distinct domains.id) AS count_zones FROM domains WHERE 1=1';

$res = $mdb2->query($query);
echo $res->fetchOne().PHP_EOL;

$mdb2->disconnect();
?>
