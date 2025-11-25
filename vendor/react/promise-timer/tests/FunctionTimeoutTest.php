<?php

namespace React\Tests\Promise\Timer;

use React\Promise\Timer;
use React\Promise;

class FunctionTimeoutTest extends TestCase
{
    public function testResolvedWillResolveRightAway()
    {
        $promise = Promise\resolve();

        $promise = Timer\timeout($promise, 3, $this->loop);

        $this->expectPromiseResolved($promise);
    }

    public function testResolvedExpiredWillResolveRightAway()
    {
        $promise = Promise\resolve();

        $promise = Timer\timeout($promise, -1, $this->loop);

        $this->expectPromiseResolved($promise);
    }

    public function testResolvedWillNotStartTimer()
    {
        $promise = Promise\resolve();

        Timer\timeout($promise, 3, $this->loop);

        $time = microtime(true);
        $this->loop->run();
        $time = microtime(true) - $time;

        $this->assertLessThan(0.5, $time);
    }

    public function testRejectedWillRejectRightAway()
    {
        $promise = Promise\reject();

        $promise = Timer\timeout($promise, 3, $this->loop);

        $this->expectPromiseRejected($promise);
    }

    public function testRejectedWillNotStartTimer()
    {
        $promise = Promise\reject();

        Timer\timeout($promise, 3, $this->loop);

        $time = microtime(true);
        $this->loop->run();
        $time = microtime(true) - $time;

        $this->assertLessThan(0.5, $time);
    }

    public function testPendingWillRejectOnTimeout()
    {
        $promise = $this->getMockBuilder('React\Promise\PromiseInterface')->getMock();

        $promise = Timer\timeout($promise, 0.01, $this->loop);

        $this->loop->run();

        $this->expectPromiseRejected($promise);
    }

    public function testPendingCancellableWillBeCancelledOnTimeout()
    {
        $promise = $this->getMockBuilder('React\Promise\CancellablePromiseInterface')->getMock();
        $promise->expects($this->once())->method('cancel');

        Timer\timeout($promise, 0.01, $this->loop);

        $this->loop->run();
    }

    public function testCancelTimeoutWithoutCancellationhandlerWillNotCancelTimerAndWillNotReject()
    {
        $promise = new \React\Promise\Promise(function () { });

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $timer = $this->getMockBuilder('React\EventLoop\Timer\TimerInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->will($this->returnValue($timer));
        $loop->expects($this->never())->method('cancelTimer');

        $timeout = Timer\timeout($promise, 0.01, $loop);

        $timeout->cancel();

        $this->expectPromisePending($timeout);
    }

    public function testResolvedPromiseWillNotStartTimer()
    {
        $promise = new \React\Promise\Promise(function ($resolve) { $resolve(true); });

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $timeout = Timer\timeout($promise, 0.01, $loop);

        $this->expectPromiseResolved($timeout);
    }

    public function testRejectedPromiseWillNotStartTimer()
    {
        $promise = Promise\reject(new \RuntimeException());

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $timeout = Timer\timeout($promise, 0.01, $loop);

        $this->expectPromiseRejected($timeout);
    }

    public function testCancelTimeoutWillCancelGivenPromise()
    {
        $promise = new \React\Promise\Promise(function () { }, $this->expectCallableOnce());

        $timeout = Timer\timeout($promise, 0.01, $this->loop);

        $timeout->cancel();
    }

    public function testCancelGivenPromiseWillReject()
    {
        $promise = new \React\Promise\Promise(function () { }, function ($resolve, $reject) { $reject(); });

        $timeout = Timer\timeout($promise, 0.01, $this->loop);

        $promise->cancel();

        $this->expectPromiseRejected($promise);
        $this->expectPromiseRejected($timeout);
    }

    public function testCancelTimeoutWillRejectIfGivenPromiseWillReject()
    {
        $promise = new \React\Promise\Promise(function () { }, function ($resolve, $reject) { $reject(); });

        $timeout = Timer\timeout($promise, 0.01, $this->loop);

        $timeout->cancel();

        $this->expectPromiseRejected($promise);
        $this->expectPromiseRejected($timeout);
    }

    public function testCancelTimeoutWillResolveIfGivenPromiseWillResolve()
    {
        $promise = new \React\Promise\Promise(function () { }, function ($resolve, $reject) { $resolve(); });

        $timeout = Timer\timeout($promise, 0.01, $this->loop);

        $timeout->cancel();

        $this->expectPromiseResolved($promise);
        $this->expectPromiseResolved($timeout);
    }

    public function testWaitingForPromiseToResolveBeforeTimeoutDoesNotLeaveGarbageCycles()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        gc_collect_cycles();

        $promise = Timer\resolve(0.01, $this->loop);

        $promise = Timer\timeout($promise, 1.0, $this->loop);

        $this->loop->run();
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testWaitingForPromiseToRejectBeforeTimeoutDoesNotLeaveGarbageCycles()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        gc_collect_cycles();

        $promise = Timer\reject(0.01, $this->loop);

        $promise = Timer\timeout($promise, 1.0, $this->loop);

        $this->loop->run();
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testWaitingForPromiseToTimeoutDoesNotLeaveGarbageCycles()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        gc_collect_cycles();

        $promise = new \React\Promise\Promise(function () { }, function () {
            throw new \RuntimeException();
        });

        $promise = Timer\timeout($promise, 0.01, $this->loop);

        $this->loop->run();
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testCancellingPromiseDoesNotLeaveGarbageCycles()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        gc_collect_cycles();

        $promise = new \React\Promise\Promise(function () { }, function () {
            throw new \RuntimeException();
        });

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $promise = Timer\timeout($promise, 0.01, $loop);
        $promise->cancel();
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }
}
