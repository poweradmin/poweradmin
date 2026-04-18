<?php declare(strict_types=1);

namespace Amp\Sync;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\SerializationException;
use Amp\Serialization\Serializer;

/**
 * A container object for sharing a value across contexts.
 *
 * A shared object is a container that stores an object inside shared memory.
 * The object can be accessed and mutated by any thread or process.
 * Create a new parcel using {@see self::create()} within the owner process.
 *
 * The shared object itself is not serializable and therefore cannot be sent
 * to another process or thread. Send the integer returned from {@see self::getKey()}
 * to the other process and open the parcel using {@see self::use()}.
 *
 * Because each shared object uses its own shared memory segment, it is much
 * more efficient to store a larger object containing many values inside a
 * single shared container than to use many small shared containers.
 * The {@see Serializer} interface provided to this object provides a variety
 * of strategies for storing data in shared memory and can be used to serialize
 * data for consumption by programs written in languages other than PHP.
 *
 * @see http://php.net/manual/en/book.shmop.php The shared memory extension.
 * @see http://man7.org/linux/man-pages/man2/shmctl.2.html How shared memory works on Linux.
 * @see https://msdn.microsoft.com/en-us/library/ms810613.aspx How shared memory works on Windows.
 *
 * @template T
 * @template-implements Parcel<T>
 */
final class SharedMemoryParcel implements Parcel
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var int The byte offset to the start of the object data in memory. */
    private const MEM_DATA_OFFSET = 7;

    private const MAX_ID = 0x7fffffff;

    // A list of valid states the object can be in.
    private const STATE_UNALLOCATED = 0;
    private const STATE_ALLOCATED = 1;
    private const STATE_MOVED = 2;

    private static int $nextId = 0;

    /**
     * @param Mutex $mutex Mutex to control access to the shared memory. Recommended: Single lock {@see PosixSemaphore}
     *     wrapped in an instance of {@see SemaphoreMutex}.
     * @param int $size The initial size in bytes of the shared memory segment. It will automatically be
     *     expanded as necessary.
     * @param int $permissions Permissions to access the semaphore. Use file permission format specified as 0xxx.
     *
     * @throws ParcelException
     * @throws SyncException
     * @throws \Error If the size or permissions are invalid.
     */
    public static function create(
        Mutex $mutex,
        mixed $value,
        int $size = 8192,
        int $permissions = 0600,
        ?Serializer $serializer = null
    ): self {
        if ($size <= 0 || $size > 1 << 27) {
            throw new \ValueError('The memory size must be greater than 0 and less than 128 MB');
        }

        if ($permissions <= 0 || $permissions > 0777) {
            throw new \ValueError('Invalid permissions');
        }

        $parcel = new self(0, $mutex, $serializer);
        $parcel->init($value, $size, $permissions);
        return $parcel;
    }

    /**
     * @param int $key Use {@see getKey()} on the creating process and send this key to another process.
     *
     * @throws ParcelException
     */
    public static function use(Mutex $mutex, int $key, ?Serializer $serializer = null): self
    {
        $parcel = new self($key, $mutex, $serializer);
        $parcel->open();
        return $parcel;
    }

    /** @var \Shmop An open handle to the shared memory segment. */
    private ?\Shmop $handle = null;

    private int $initializer = 0;

    private readonly Serializer $serializer;

    /**
     * @param int $key The shared memory segment key.
     * @param Mutex $mutex A mutex for synchronizing on the parcel.
     */
    private function __construct(
        private int $key,
        private readonly Mutex $mutex,
        ?Serializer $serializer = null
    ) {
        if (!\extension_loaded("shmop")) {
            throw new \Error(__CLASS__ . " requires the shmop extension");
        }

        $this->serializer = $serializer ?? new NativeSerializer;
    }

    public function getKey(): int
    {
        return $this->key;
    }

    public function unwrap(): mixed
    {
        $lock = $this->mutex->acquire();

        try {
            return $this->getValue();
        } finally {
            $lock->release();
        }
    }

    public function synchronized(\Closure $closure): mixed
    {
        $lock = $this->mutex->acquire();

        try {
            $result = $closure($this->getValue());
            $this->wrap($result);
        } finally {
            $lock->release();
        }

        return $result;
    }

    /**
     * Frees the shared object from memory.
     *
     * The memory containing the shared value will be invalidated. When all
     * process disconnect from the object, the shared memory block will be
     * destroyed by the OS.
     */
    public function __destruct()
    {
        if ($this->initializer === 0 || $this->initializer !== \getmypid()) {
            return;
        }

        if ($this->isFreed()) {
            return;
        }

        // Request the block to be deleted, then close our local handle.
        $this->deleteSegment();
        $this->handle = null;
    }

    /**
     * @throws ParcelException
     * @throws \Error If the size or permissions are invalid.
     */
    private function init(mixed $value, int $size = 8192, int $permissions = 0600): void
    {
        $this->initializer = \getmypid();

        $lock = $this->mutex->acquire();

        try {
            [$this->key, $this->handle] = self::createSegment($permissions, $size + self::MEM_DATA_OFFSET);
            $this->writeSegment(self::generateHeader(self::STATE_ALLOCATED, 0, $permissions));
            $this->wrap($value);
        } finally {
            $lock->release();
        }
    }

    private function open(): void
    {
        \set_error_handler(static function (int $errno, string $errstr): never {
            throw new ParcelException('Failed to open shared memory block: ' . $errstr, $errno);
        });

        try {
            /** @psalm-suppress InvalidPropertyAssignmentValue Psalm needs to be updated for ext-shmop using objects. */
            $this->handle = \shmop_open($this->key, 'w', 0, 0);
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * Checks if the object has been freed.
     *
     * Note that this does not check if the object has been destroyed; it only
     * checks if this handle has freed its reference to the object.
     *
     * @return bool True if the object is freed, otherwise false.
     */
    private function isFreed(): bool
    {
        // If we are still connected to the memory segment, check if it has
        // been invalidated.
        if ($this->handle !== null) {
            ['state' => $state] = $this->readHeader();
            return $state !== self::STATE_ALLOCATED;
        }

        return true;
    }

    /**
     * @throws ParcelException
     * @throws SerializationException
     */
    private function getValue(): mixed
    {
        if ($this->isFreed()) {
            throw new ParcelException('The object has already been freed');
        }

        ['state' => $state, 'size' => $size] = $this->readHeader();

        // Make sure the header is in a valid state and format.
        if ($state !== self::STATE_ALLOCATED || $size <= 0) {
            throw new ParcelException('Shared object memory is corrupt');
        }

        // Read the actual value data from memory and unserialize it.
        $data = $this->readSegment(self::MEM_DATA_OFFSET, $size);
        return $this->serializer->unserialize($data);
    }

    /**
     * If the value requires more memory to store than currently allocated, a
     * new shared memory segment will be allocated with a larger size to store
     * the value in. The previous memory segment will be cleaned up and marked
     * for deletion. Other processes and threads will be notified of the new
     * memory segment on the next read attempt. Once all running processes and
     * threads disconnect from the old segment, it will be freed by the OS.
     */
    private function wrap(mixed $value): void
    {
        if ($this->isFreed()) {
            throw new ParcelException('The object has already been freed');
        }

        ['permissions' => $permissions] = $this->readHeader();

        $serialized = $this->serializer->serialize($value);
        $size = \strlen($serialized);

        /* If we run out of space, we need to allocate a new shared memory
           segment that is larger than the current one. To coordinate with other
           processes, we will leave a message in the old segment that the segment
           has moved and along with the new key. The old segment will be discarded
           automatically after all other processes notice the change and close
           the old handle.
        */
        /** @psalm-suppress InvalidArgument Psalm needs to be updated for ext-shmop using objects. */
        if (\shmop_size($this->handle) < $size + self::MEM_DATA_OFFSET) {
            [$key, $handle] = self::createSegment($permissions, $size * 2);

            $this->writeSegment(self::generateHeader(self::STATE_MOVED, $key, 0));
            $this->deleteSegment();

            $this->key = $key;
            $this->handle = $handle;
        }

        // Rewrite the header and the serialized value to memory.
        $this->writeSegment(self::generateHeader(self::STATE_ALLOCATED, $size, $permissions) . $serialized);
    }

    /**
     * Reads and returns the data header at the current memory segment. If the memory has been moved, this method
     * updates the current memory segment handle, handling any moves made on the data.
     *
     * @return array{state: int, size: int, permissions: int} An associative array of header data.
     *
     * @throws ParcelException
     */
    private function readHeader(): array
    {
        // Read from the memory block and handle moved blocks until we find the
        // correct block.
        while (true) {
            $data = $this->readSegment(0, self::MEM_DATA_OFFSET);
            $header = \unpack('Cstate/Lsize/Spermissions', $data);

            // If the state is STATE_MOVED, the memory is stale and has been moved
            // to a new location. Move handle and try to read again.
            if ($header['state'] !== self::STATE_MOVED) {
                return $header;
            }

            $this->key = $header['size'];
            $this->open();
        }
    }

    /**
     * @param int $state An object state.
     * @param int $size The size of the stored data, or other value.
     * @param int $permissions The permissions mask on the memory segment.
     */
    private static function generateHeader(int $state, int $size, int $permissions): string
    {
        return \pack('CLS', $state, $size, $permissions);
    }

    /**
     * Opens a shared memory handle.
     *
     * @param int $permissions Process permissions on the shared memory.
     * @param int $size The size to crate the shared memory in bytes.
     *
     * @return array{int, \Shmop}
     *
     * @throws ParcelException
     *
     * @psalm-suppress InvalidReturnType
     */
    private static function createSegment(int $permissions, int $size): array
    {
        if (!self::$nextId) {
            self::$nextId = \random_int(1, self::MAX_ID);
        }

        \set_error_handler(static function (int $errno, string $errstr): bool {
            if (\str_contains($errstr, 'Unable to attach or create shared memory segment')) {
                return true;
            }

            throw new ParcelException('Failed to create shared memory block: ' . $errstr, $errno);
        });

        try {
            do {
                $id = self::$nextId;

                if ($handle = \shmop_open($id, 'n', $permissions, $size)) {
                    /** @psalm-suppress InvalidReturnStatement Psalm needs to be updated for ext-shmop using objects. */
                    return [$id, $handle];
                }

                self::$nextId = self::$nextId % self::MAX_ID + 1;
            } while (true);
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * Reads binary data from shared memory.
     *
     * @param int $offset The offset to read from.
     * @param int $size The number of bytes to read.
     *
     * @return string The binary data at the given offset.
     *
     * @throws ParcelException
     */
    private function readSegment(int $offset, int $size): string
    {
        \assert($this->handle !== null);

        try {
            return \shmop_read($this->handle, $offset, $size);
        } catch (\ValueError $error) {
            throw new ParcelException(
                'Failed to read from shared memory block: ' . ($error->getMessage() ?? 'unknown error')
            );
        }
    }

    /**
     * Writes binary data to shared memory.
     *
     * @param string $data The binary data to write.
     *
     * @throws ParcelException
     */
    private function writeSegment(string $data): void
    {
        \assert($this->handle !== null);

        try {
            \shmop_write($this->handle, $data, 0);
        } catch (\ValueError $error) {
            throw new ParcelException(
                'Failed to write to shared memory block: ' . ($error->getMessage() ?? 'unknown error')
            );
        }
    }

    /**
     * Requests the shared memory segment to be deleted.
     *
     * @throws ParcelException
     */
    private function deleteSegment(): void
    {
        \assert($this->handle !== null);

        /** @psalm-suppress InvalidArgument Psalm needs to be updated for ext-shmop using objects. */
        if (!\shmop_delete($this->handle)) {
            $error = \error_get_last();
            throw new ParcelException(
                'Failed to discard shared memory block' . ($error['message'] ?? 'unknown error')
            );
        }
    }
}
