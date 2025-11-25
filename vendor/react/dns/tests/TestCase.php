<?php

namespace React\Tests\Dns;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function expectCallableOnce()
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke');

        return $mock;
    }

    protected function expectCallableOnceWith($value)
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with($value);

        return $mock;
    }

    protected function expectCallableNever()
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->never())
            ->method('__invoke');

        return $mock;
    }

    protected function createCallableMock()
    {
        return $this->getMockBuilder('React\Tests\Dns\CallableStub')->getMock();
    }

    public function setExpectedException($exception, $exceptionMessage = '', $exceptionCode = null)
    {
         if (method_exists($this, 'expectException')) {
             // PHPUnit 5
             $this->expectException($exception);
             if ($exceptionMessage !== '') {
                 $this->expectExceptionMessage($exceptionMessage);
             }
             if ($exceptionCode !== null) {
                 $this->expectExceptionCode($exceptionCode);
             }
         } else {
             // legacy PHPUnit 4
             parent::setExpectedException($exception, $exceptionMessage, $exceptionCode);
         }
     }
}
