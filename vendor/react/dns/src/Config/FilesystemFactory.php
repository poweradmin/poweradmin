<?php

namespace React\Dns\Config;

use React\EventLoop\LoopInterface;
use React\Promise;
use React\Promise\Deferred;
use React\Stream\ReadableResourceStream;
use React\Stream\Stream;

/**
 * @deprecated
 * @see Config see Config class instead.
 */
class FilesystemFactory
{
    private $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function create($filename)
    {
        return $this
            ->loadEtcResolvConf($filename)
            ->then(array($this, 'parseEtcResolvConf'));
    }

    /**
     * @param string $contents
     * @return Promise
     * @deprecated see Config instead
     */
    public function parseEtcResolvConf($contents)
    {
        return Promise\resolve(Config::loadResolvConfBlocking(
            'data://text/plain;base64,' . base64_encode($contents)
        ));
    }

    public function loadEtcResolvConf($filename)
    {
        if (!file_exists($filename)) {
            return Promise\reject(new \InvalidArgumentException("The filename for /etc/resolv.conf given does not exist: $filename"));
        }

        try {
            $deferred = new Deferred();

            $fd = fopen($filename, 'r');
            stream_set_blocking($fd, 0);

            $contents = '';

            $stream = class_exists('React\Stream\ReadableResourceStream') ? new ReadableResourceStream($fd, $this->loop) : new Stream($fd, $this->loop);
            $stream->on('data', function ($data) use (&$contents) {
                $contents .= $data;
            });
            $stream->on('end', function () use (&$contents, $deferred) {
                $deferred->resolve($contents);
            });
            $stream->on('error', function ($error) use ($deferred) {
                $deferred->reject($error);
            });

            return $deferred->promise();
        } catch (\Exception $e) {
            return Promise\reject($e);
        }
    }
}
