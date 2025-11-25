<?php

use React\Stream\ReadableResourceStream;
use Clue\React\NDJson\Decoder;

class DecoderTest extends TestCase
{
    private $input;
    private $decoder;

    public function setUp()
    {
        $stream = fopen('php://temp', 'r');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $this->input = new ReadableResourceStream($stream, $loop);
        $this->decoder = new Decoder($this->input);
    }

    public function testEmitDataArrayWillForward()
    {
        $this->decoder->on('data', $this->expectCallableOnceWith(array(1, 2)));

        $this->input->emit('data', array("[1, 2]\n"));
    }

    public function testEmitDataStringWillForward()
    {
        $this->decoder->on('data', $this->expectCallableOnceWith('hello'));

        $this->input->emit('data', array("\"hello\"\n"));
    }

    public function testEmitDataNullWillForward()
    {
        $this->decoder->on('data', $this->expectCallableOnceWith(null));

        $this->input->emit('data', array("null\n"));
    }

    public function testEmitDataNullWithoutNewlineWillNotForward()
    {
        $this->decoder->on('data', $this->expectCallableNever());

        $this->input->emit('data', array("null"));
    }

    public function testEmitDataNullInMultipleChunksWillForward()
    {
        $this->decoder->on('data', $this->expectCallableOnceWith(null));

        $this->input->emit('data', array("nu"));
        $this->input->emit('data', array("ll"));
        $this->input->emit('data', array("\n"));
    }

    public function testEmitDataErrorWillForwardError()
    {
        $this->decoder->on('data', $this->expectCallableNever());
        $this->decoder->on('error', $this->expectCallableOnce());

        $this->input->emit('data', array("invalid\n"));
    }

    public function testEmitDataOverflowWillForwardError()
    {
        $this->decoder->on('data', $this->expectCallableNever());
        $this->decoder->on('error', $this->expectCallableOnce());

        $this->input->emit('data', array("\"" . str_repeat(".", 40000)));
        $this->input->emit('data', array(str_repeat(".", 40000) . "\"\n"));
    }

    public function testEmitDataWithExactLimitWillForward()
    {
        $this->decoder = new Decoder($this->input, false, 512, 0, 4);

        $this->decoder->on('data', $this->expectCallableOnceWith(null));
        $this->decoder->on('error', $this->expectCallableNever());

        $this->input->emit('data', array("null\n"));
    }

    public function testEmitDataOverflowBehindExactLimitWillForwardError()
    {
        $this->decoder = new Decoder($this->input, false, 512, 0, 3);

        $this->decoder->on('data', $this->expectCallableNever());
        $this->decoder->on('error', $this->expectCallableOnce());

        $this->input->emit('data', array("null"));
    }

    public function testEmitDataErrorWithoutNewlineWillNotForward()
    {
        $this->decoder->on('data', $this->expectCallableNever());
        $this->decoder->on('error', $this->expectCallableNever());

        $this->input->emit('data', array("invalid"));
    }

    public function testEmitDataErrorInMultipleChunksWillForwardError()
    {
        $this->decoder->on('data', $this->expectCallableNever());
        $this->decoder->on('error', $this->expectCallableOnce());

        $this->input->emit('data', array("inval"));
        $this->input->emit('data', array("id\n"));
    }

    public function testEmitEndWillForwardEnd()
    {
        $this->decoder->on('data', $this->expectCallableNever());
        $this->decoder->on('end', $this->expectCallableOnce());

        $this->input->emit('end');
    }

    public function testEmitDataNullWithoutNewlineWillForwardOnEnd()
    {
        $this->decoder->on('data', $this->expectCallableOnceWith(null));
        $this->decoder->on('end', $this->expectCallableOnce());

        $this->input->emit('data', array("null"));
        $this->input->emit('end');
    }

    public function testEmitDataErrorWithoutNewlineWillForwardErrorOnEnd()
    {
        $this->decoder->on('data', $this->expectCallableNever());
        $this->decoder->on('error', $this->expectCallableOnce());
        $this->decoder->on('end', $this->expectCallableNever());

        $this->input->emit('data', array("invalid"));
        $this->input->emit('end');
    }

    public function testClosingInputWillCloseDecoder()
    {
        $this->decoder->on('close', $this->expectCallableOnce());

        $this->assertTrue($this->decoder->isReadable());

        $this->input->close();

        $this->assertFalse($this->decoder->isReadable());
    }

    public function testClosingInputWillRemoveAllDataListeners()
    {
        $this->input->close();

        $this->assertEquals(array(), $this->input->listeners('data'));
        $this->assertEquals(array(), $this->decoder->listeners('data'));
    }

    public function testClosingDecoderWillCloseInput()
    {
        $this->input->on('close', $this->expectCallableOnce());
        $this->decoder->on('close', $this->expectCallableOnce());

        $this->assertTrue($this->decoder->isReadable());

        $this->decoder->close();

        $this->assertFalse($this->decoder->isReadable());
    }

    public function testClosingDecoderWillRemoveAllDataListeners()
    {
        $this->decoder->close();

        $this->assertEquals(array(), $this->input->listeners('data'));
        $this->assertEquals(array(), $this->decoder->listeners('data'));
    }

    public function testClosingDecoderDuringFinalDataEventFromEndWillNotEmitEnd()
    {
        $this->decoder->on('data', $this->expectCallableOnceWith(null));
        $this->decoder->on('data', array($this->decoder, 'close'));

        $this->decoder->on('end', $this->expectCallableNever());

        $this->input->emit('data', array("null"));
        $this->input->emit('end');
    }

    public function testUnreadableInputWillResultInUnreadableDecoder()
    {
        $this->input->close();
        $this->decoder = new Decoder($this->input);

        $this->assertFalse($this->decoder->isReadable());
    }

    public function testUnreadableInputWillNotAddAnyEventListeners()
    {
        $this->input->close();
        $this->decoder = new Decoder($this->input);

        $this->assertEquals(array(), $this->input->listeners('data'));
        $this->assertEquals(array(), $this->decoder->listeners('data'));
    }

    public function testEmitErrorEventWillForwardAndClose()
    {
        $this->decoder->on('error', $this->expectCallableOnce());
        $this->decoder->on('close', $this->expectCallableOnce());

        $this->input->emit('error', array(new \RuntimeException()));
    }

    public function testPipeReturnsDestStream()
    {
        $dest = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();

        $ret = $this->decoder->pipe($dest);

        $this->assertSame($dest, $ret);
    }

    public function testForwardPauseToInput()
    {
        $this->input = $this->getMockBuilder('React\Stream\ReadableStreamInterface')->getMock();
        $this->input->expects($this->once())->method('pause');

        $this->decoder = new Decoder($this->input);
        $this->decoder->pause();
    }

    public function testForwardResumeToInput()
    {
        $this->input = $this->getMockBuilder('React\Stream\ReadableStreamInterface')->getMock();
        $this->input->expects($this->once())->method('resume');

        $this->decoder = new Decoder($this->input);
        $this->decoder->resume();
    }
}
