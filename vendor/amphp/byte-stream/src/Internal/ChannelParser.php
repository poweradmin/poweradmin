<?php declare(strict_types=1);

namespace Amp\ByteStream\Internal;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Parser\Parser;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\SerializationException;
use Amp\Serialization\Serializer;
use Amp\Sync\ChannelException;
use function Amp\Serialization\encodeUnprintableChars;

/** @internal */
final class ChannelParser extends Parser
{
    use ForbidCloning;
    use ForbidSerialization;

    private const HEADER_LENGTH = 5;

    /**
     * @param \Closure(mixed):void $push
     *
     * @throws ChannelException
     * @throws SerializationException
     */
    private static function parser(\Closure $push, Serializer $serializer): \Generator
    {
        while (true) {
            /** @var string $header */
            $header = yield self::HEADER_LENGTH;
            ['prefix' => $prefix, 'length' => $length] = \unpack("Cprefix/Llength", $header);

            if ($prefix !== 0) {
                $data = $header . yield;
                throw new ChannelException("Invalid packet received: " . encodeUnprintableChars($data));
            }

            $data = $serializer->unserialize(yield $length);

            try {
                $push($data);
            } catch (\Throwable $exception) {
                throw new ChannelException(
                    "Invoking the parser callback failed: " . $exception->getMessage(),
                    0,
                    $exception,
                );
            }
        }
    }

    private readonly Serializer $serializer;

    /**
     * @param \Closure(mixed):void $onMessage Closure invoked when data is parsed.
     */
    public function __construct(\Closure $onMessage, ?Serializer $serializer = null)
    {
        $this->serializer = $serializer ?? new NativeSerializer;
        parent::__construct(self::parser($onMessage, $this->serializer));
    }

    /**
     * @param mixed $data Data to encode to send over a channel.
     *
     * @return string Encoded data that can be parsed by this class.
     *
     * @throws SerializationException
     */
    public function encode(mixed $data): string
    {
        $data = $this->serializer->serialize($data);
        return \pack("CL", 0, \strlen($data)) . $data;
    }
}
