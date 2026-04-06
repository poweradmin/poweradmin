<?php declare(strict_types=1);

namespace Amp\Sync;

/**
 * @template T
 *
 * A parcel object for sharing data across execution contexts.
 *
 * A parcel is an object that stores a value in a safe way that can be shared
 * between different threads or processes. Different handles to the same parcel
 * will access the same data, and a parcel handle itself is serializable and
 * can be transported to other execution contexts.
 *
 * Wrapping and unwrapping values in the parcel are not atomic. To prevent race
 * conditions and guarantee safety, you should use the provided synchronization
 * methods to acquire a lock for exclusive access to the parcel first before
 * accessing the contained value.
 */
interface Parcel
{
    /**
     * Invokes a callback while maintaining a lock on the parcel. The current value of the parcel is provided as
     * the first argument to the callback function. The return value of the callback is stored as the new value
     * of the parcel.
     *
     * @template R of T
     *
     * @param \Closure(T):R $closure The closure to invoke when a lock is obtained on the parcel. The parcel
     * value is given as the single argument to the closure. The return value is stored as the new parcel value.
     *
     * @return R The value of the parcel after the closure was invoked.
     */
    public function synchronized(\Closure $closure): mixed;

    /**
     * @return T The value inside the parcel.
     */
    public function unwrap(): mixed;
}
