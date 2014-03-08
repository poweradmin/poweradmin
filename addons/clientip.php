<?php

$client_ip = '';
if (isset($_SERVER['X_HTTP_FORWARDED_FOR'])) {
    $client_ip = $_SERVER['X_HTTP_FORWARDED_FOR'];
} else if (isset($_SERVER['REMOTE_ADDR'])) {
    $client_ip = $_SERVER['REMOTE_ADDR'];
}
echo $client_ip;
