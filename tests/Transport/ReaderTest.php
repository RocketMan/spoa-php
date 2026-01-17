<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use SPOA\Protocol\Frame;
use SPOA\Protocol\FrameType;
use SPOA\Transport\Reader;

final class ReaderTest extends TestCase {
    public function testFragmentedFrame(): void {
        $payload = "abcdef123456";
        $frame = new Frame(FrameType::NOTIFY, 0, 1, 7, $payload);
        $encoded = $frame->encodeWire();
        $reader = new Reader();
        $chunks = str_split($encoded, 4); // simulate ReactPHP delivering chunks

        $frames = [];
        foreach ($chunks as $chunk) {
            foreach ($reader->push($chunk) as $f) {
                $frames[] = $f;
            }
        }

        $this->assertCount(1, $frames);
        $this->assertSame($payload, $frames[0]->payload);
        $this->assertSame(FrameType::NOTIFY, $frames[0]->type);
    }

    public function testMultipleFramesInSingleChunk(): void {
        $f1 = new Frame(FrameType::HELLO, 0, 1, 0, 'foo');
        $f2 = new Frame(FrameType::NOTIFY, 0, 2, 0, 'bar');

        $reader = new Reader();
        $frames = iterator_to_array($reader->push($f1->encodeWire() . $f2->encodeWire()));

        $this->assertCount(2, $frames);
        $this->assertSame('foo', $frames[0]->payload);
        $this->assertSame('bar', $frames[1]->payload);
    }
}

