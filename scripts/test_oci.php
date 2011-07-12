<?php

$db_user = '';
$db_pass = '';
$db_host = '';

$conn = oci_connect($db_user, $db_pass, $db_host);
if (!$conn) {
	$e = oci_error();
	trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
}

oci_close($conn);

?>
