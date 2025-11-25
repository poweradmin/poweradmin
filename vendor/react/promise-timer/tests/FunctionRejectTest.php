<?php

namespace React\Tests\Promise\Timer;

use React\Promise\Timer;

class FunctionRejectTest extends TestCase
{
    public function testPromiseIsPendingWithoutRunningLoop()
    {
        $promise = Timer\reject(0.01, $this->loop);

        $this->expectPromisePending($promise);
    }

    public function testPromiseExpiredIsPendingWithoutRunningLoop()
    {
        $promise = Timer\reject(-1, $this->loop);

        $this->expectPromisePending($promise);
    }

    public function testPromiseWillBeRejectedOnTimeout()
    {
        $promise = Timer\reject(0.01, $this->loop);

        $this->loop->run();

        $this->expectPromiseRejected($promise);
    }

    public function testPromiseExpiredWillBeRejectedOnTimeout()
    {
        $promise = Timer\reject(-1, $this->loop);

        $this->loop->run();

        $this->expectPromiseRejected($promise);
    }

    public function testCancellingPromiseWillRejectTimer()
    {
        $promise = Timer\reject(0.01, $this->loop);

        $promise->cancel();

        $this->expectPromiseRejected($promise);
    }

    public function testWaitingForPromiseToRejectDoesNotLeaveGarbageCycles()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        gc_collect_cycles();

        $promise = Timer\reject(0.01, $this->loop);
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

        $promise = Timer\reject(0.01, $this->loop);
        $promise->cancel();
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }
}
