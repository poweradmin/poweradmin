<?php

$client_ip = '';

if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $client_ip = filter_var($_SERVER['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP);
} else if (isset($_SERVER['REMOTE_ADDR'])) {
    $client_ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP);
}

echo htmlspecialchars($client_ip);
