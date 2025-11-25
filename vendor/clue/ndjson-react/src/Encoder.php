<?php

namespace Clue\React\NDJson;

use Evenement\EventEmitter;
use React\Stream\WritableStreamInterface;

/**
 * The Encoder / Serializer can be used to write any value, encode it as a JSON text and forward it to an output stream
 */
class Encoder extends EventEmitter implements WritableStreamInterface
{
    private $output;
    private $options;
    private $depth;

    private $closed = false;

    public function __construct(WritableStreamInterface $output, $options = 0, $depth = 512)
    {
        // @codeCoverageIgnoreStart
        if (defined('JSON_PRETTY_PRINT') && $options & JSON_PRETTY_PRINT) {
            throw new \InvalidArgumentException('Pretty printing not available for NDJSON');
        }
        if ($depth !== 512 && PHP_VERSION < 5.5) {
            throw new \BadMethodCallException('Depth parameter is only supported on PHP 5.5+');
        }
        // @codeCoverageIgnoreEnd

        $this->output = $output;

        if (!$output->isWritable()) {
            return $this->close();
        }

        $this->options = $options;
        $this->depth = $depth;

        $this->output->on('drain', array($this, 'handleDrain'));
        $this->output->on('error', array($this, 'handleError'));
        $this->output->on('close', array($this, 'close'));
    }

    public function write($data)
    {
        if ($this->closed) {
            return false;
        }

        // we have to handle PHP warning for legacy PHP < 5.5 (see below)
        // @codeCoverageIgnoreStart
        if (PHP_VERSION_ID < 50500) {
            $found = null;
            set_error_handler(function ($error) use (&$found) {
                $found = $error;
            });
        }

        // encode data with options given in ctor
        if ($this->depth === 512) {
            $data = json_encode($data, $this->options);
        } else {
            $data = json_encode($data, $this->options, $this->depth);
        }

        // legacy error handler for PHP < 5.5
        // certain values (such as INF etc.) emit a warning, but still encode successfully
        if (PHP_VERSION_ID < 50500) {
            restore_error_handler();

            // emit an error event if a warning has been raised
            if ($found !== null) {
                $this->handleError(new \RuntimeException('Unable to encode JSON: ' . $found));
                return false;
            }
        }
        // @codeCoverageIgnoreEnd

        // abort stream if encoding fails
        if ($data === false && json_last_error() !== JSON_ERROR_NONE) {
            $this->handleError(new \RuntimeException('Unable to encode JSON', json_last_error()));
            return false;
        }

        return $this->output->write($data . "\n");
    }

    public function end($data = null)
    {
        if ($data !== null) {
            $this->write($data);
        }

        $this->output->end();
    }

    public function isWritable()
    {
        return !$this->closed;
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->output->close();

        $this->emit('close');
        $this->removeAllListeners();
    }

    /** @internal */
    public function handleDrain()
    {
        $this->emit('drain');
    }

    /** @internal */
    public function handleError(\Exception $error)
    {
        $this->emit('error', array($error));
        $this->close();
    }
}
