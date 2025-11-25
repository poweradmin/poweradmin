<?php

use React\Stream\WritableResourceStream;
use Clue\React\NDJson\Encoder;

class EncoderTest extends TestCase
{
    private $output;
    private $encoder;

    public function setUp()
    {
        $stream = fopen('php://temp', 'r+');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $this->output = new WritableResourceStream($stream, $loop);
        $this->encoder = new Encoder($this->output);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testPrettyPrintDoesNotMakeSenseForNDJson()
    {
        if (!defined('JSON_PRETTY_PRINT')) {
            $this->markTestSkipped('Const JSON_PRETTY_PRINT only available in PHP 5.4+');
        }

        $this->encoder = new Encoder($this->output, JSON_PRETTY_PRINT);
    }

    public function testWriteString()
    {
        $this->output = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $this->output->expects($this->once())->method('isWritable')->willReturn(true);
        $this->encoder = new Encoder($this->output);

        $this->output->expects($this->once())->method('write')->with("\"hello\"\n")->willReturn(true);

        $ret = $this->encoder->write('hello');
        $this->assertTrue($ret);
    }

    public function testWriteNull()
    {
        $this->output = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $this->output->expects($this->once())->method('isWritable')->willReturn(true);
        $this->encoder = new Encoder($this->output);

        $this->output->expects($this->once())->method('write')->with("null\n");

        $this->encoder->write(null);
    }

    public function testWriteInfiniteWillEmitErrorAndClose()
    {
        $this->output = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $this->output->expects($this->once())->method('isWritable')->willReturn(true);
        $this->encoder = new Encoder($this->output);

        $this->output->expects($this->never())->method('write');

        $this->encoder->on('error', $this->expectCallableOnce());
        $this->encoder->on('close', $this->expectCallableOnce());

        $ret = $this->encoder->write(INF);
        $this->assertFalse($ret);

        $this->assertFalse($this->encoder->isWritable());
    }

    public function testEndWithoutDataWillEndOutputWithoutData()
    {
        $this->output = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $this->output->expects($this->once())->method('isWritable')->willReturn(true);
        $this->encoder = new Encoder($this->output);

        $this->output->expects($this->never())->method('write');
        $this->output->expects($this->once())->method('end')->with($this->equalTo(null));

        $this->encoder->end();
    }

    public function testEndWithDataWillForwardDataAndEndOutputWithoutData()
    {
        $this->output = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $this->output->expects($this->once())->method('isWritable')->willReturn(true);
        $this->encoder = new Encoder($this->output);

        $this->output->expects($this->once())->method('write')->with($this->equalTo("true\n"));
        $this->output->expects($this->once())->method('end')->with($this->equalTo(null));

        $this->encoder->end(true);
    }

    public function testClosingEncoderClosesOutput()
    {
        $this->encoder->on('close', $this->expectCallableOnce());
        $this->output->on('close', $this->expectCallableOnce());

        $this->encoder->close();
    }

    public function testClosingOutputClosesEncoder()
    {
        $this->encoder->on('close', $this->expectCallableOnce());
        $this->output->on('close', $this->expectCallableOnce());

        $this->output->close();
    }

    public function testPassingClosedStreamToEncoderWillCloseImmediately()
    {
        $this->output = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $this->output->expects($this->once())->method('isWritable')->willReturn(false);
        $this->encoder = new Encoder($this->output);

        $this->assertFalse($this->encoder->isWritable());
    }

    public function testWritingToClosedStreamWillNotForwardData()
    {
        $this->output = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $this->output->expects($this->once())->method('isWritable')->willReturn(false);
        $this->encoder = new Encoder($this->output);

        $this->output->expects($this->never())->method('write');

        $this->encoder->write("discarded");
    }

    public function testErrorEventWillForwardAndClose()
    {
        $this->encoder->on('error', $this->expectCallableOnce());
        $this->encoder->on('close', $this->expectCallableOnce());

        $this->output->emit('error', array(new \RuntimeException()));

        $this->assertFalse($this->output->isWritable());
    }

    public function testDrainEventWillForward()
    {
        $this->encoder->on('drain', $this->expectCallableOnce());

        $this->output->emit('drain');
    }
}
