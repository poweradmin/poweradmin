<?php declare(strict_types=1);

namespace Amp\ByteStream;

interface ResourceStream
{
    /**
     * References the underlying watcher, so the loop keeps running in case there's an active stream operation.
     *
     * @see EventLoop::reference()
     */
    public function reference(): void;

    /**
     * Unreferences the underlying watcher, so the loop doesn't keep running even if there are active stream operations.
     *
     * @see EventLoop::unreference()
     */
    public function unreference(): void;

    /**
     * @return resource|object|null Stream resource (or object if PHP switches to object-based streams).
     */
    public function getResource();
}
