<?php

use React\EventLoop\Factory;
use React\Stream\ReadableResourceStream;
use React\Stream\WritableResourceStream;
use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();

$exit = 0;
$in = new ReadableResourceStream(STDIN, $loop);
$out = new WritableResourceStream(STDOUT, $loop);
$info = new WritableResourceStream(STDERR, $loop);

$decoder = new Decoder($in);
$encoder = new Encoder($out);
$decoder->pipe($encoder);

$decoder->on('error', function (Exception $e) use ($info, &$exit) {
    $info->write('ERROR: ' . $e->getMessage() . PHP_EOL);
    $exit = 1;
});

$info->write('You can pipe/write a valid NDJson stream to STDIN' . PHP_EOL);
$info->write('Valid NDJson will be forwarded to STDOUT' . PHP_EOL);
$info->write('Invalid NDJson will raise an error on STDERR and exit with code 1' . PHP_EOL);

$loop->run();

exit($exit);
