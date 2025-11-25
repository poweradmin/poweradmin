<?php

namespace Clue\React\NDJson;

use Evenement\EventEmitter;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;
use React\Stream\Util;

/**
 * The Decoder / Parser reads from a plain stream and emits data objects for each JSON element
 */
class Decoder extends EventEmitter implements ReadableStreamInterface
{
    private $input;
    private $assoc;
    private $depth;
    private $options;
    private $maxlength;

    private $buffer = '';
    private $closed = false;

    public function __construct(ReadableStreamInterface $input, $assoc = false, $depth = 512, $options = 0, $maxlength = 65536)
    {
        // @codeCoverageIgnoreStart
        if ($options !== 0 && PHP_VERSION < 5.4) {
            throw new \BadMethodCallException('Options parameter is only supported on PHP 5.4+');
        }
        // @codeCoverageIgnoreEnd

        $this->input = $input;

        if (!$input->isReadable()) {
            return $this->close();
        }

        $this->assoc = $assoc;
        $this->depth = $depth;
        $this->options = $options;
        $this->maxlength = $maxlength;

        $this->input->on('data', array($this, 'handleData'));
        $this->input->on('end', array($this, 'handleEnd'));
        $this->input->on('error', array($this, 'handleError'));
        $this->input->on('close', array($this, 'close'));
    }

    public function isReadable()
    {
        return !$this->closed;
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->buffer = '';

        $this->input->close();

        $this->emit('close');
        $this->removeAllListeners();
    }

    public function pause()
    {
        $this->input->pause();
    }

    public function resume()
    {
        $this->input->resume();
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        Util::pipe($this, $dest, $options);

        return $dest;
    }

    /** @internal */
    public function handleData($data)
    {
        $this->buffer .= $data;

        // keep parsing while a newline has been found
        while (($newline = strpos($this->buffer, "\n")) !== false && $newline <= $this->maxlength) {
            // read data up until newline and remove from buffer
            $data = (string)substr($this->buffer, 0, $newline);
            $this->buffer = (string)substr($this->buffer, $newline + 1);

            // decode data with options given in ctor
            // @codeCoverageIgnoreStart
            if ($this->options === 0) {
                $data = json_decode($data, $this->assoc, $this->depth);
            } else {
                $data = json_decode($data, $this->assoc, $this->depth, $this->options);
            }
            // @codeCoverageIgnoreEnd

            // abort stream if decoding failed
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                return $this->handleError(new \RuntimeException('Unable to decode JSON', json_last_error()));
            }

            $this->emit('data', array($data));
        }

        if (isset($this->buffer[$this->maxlength])) {
            $this->handleError(new \OverflowException('Buffer size exceeded'));
        }
    }

    /** @internal */
    public function handleEnd()
    {
        if ($this->buffer !== '') {
            $this->handleData("\n");
        }

        if (!$this->closed) {
            $this->emit('end');
            $this->close();
        }
    }

    /** @internal */
    public function handleError(\Exception $error)
    {
        $this->emit('error', array($error));
        $this->close();
    }
}
