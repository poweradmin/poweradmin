<?php declare(strict_types=1);

namespace Amp\Dns\Internal;

use Amp\Dns\DnsException;
use LibDNS\Decoder\Decoder;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\Encoder;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\Message;

/** @internal */
final class UdpSocket extends Socket
{
    /**
     * @throws DnsException
     */
    public static function connect(string $uri): self
    {
        return new self(self::openSocket($uri));
    }

    private readonly Encoder $encoder;
    private readonly Decoder $decoder;

    /**
     * @param resource $socket
     */
    protected function __construct($socket)
    {
        parent::__construct($socket);

        $this->encoder = (new EncoderFactory)->create();
        $this->decoder = (new DecoderFactory)->create();
    }

    public function isAlive(): bool
    {
        return true;
    }

    protected function send(Message $message): void
    {
        $data = $this->encoder->encode($message);
        $this->write($data);
    }

    protected function receive(): Message
    {
        while (true) {
            $data = $this->read();

            if ($data === null) {
                throw new DnsException("Reading from the server failed");
            }

            try {
                return $this->decoder->decode($data);
            } catch (\Exception) {
                $this->invalidPacketsReceived++;

                continue;
            }
        }
    }
}
