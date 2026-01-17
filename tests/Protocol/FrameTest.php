<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use SPOA\Protocol\Frame;
use SPOA\Protocol\FrameType;

final class FrameTest extends TestCase {
    public function testEncodeDecodeRoundtrip(): void {
        $payload = "HelloWorld";
        $frame = new Frame(FrameType::NOTIFY, FrameType::FLAG_FIN, 42, 0, $payload);

        $encoded = $frame->encode();
        $decoded = Frame::decode($encoded);

        $this->assertSame($frame->type, $decoded->type);
        $this->assertSame($frame->flags, $decoded->flags);
        $this->assertSame($frame->streamId, $decoded->streamId);
        $this->assertSame($frame->payload, $decoded->payload);
    }

    public function testEmptyPayload(): void {
        $frame = new Frame(FrameType::HELLO, 0, 0, 0, '');
        $encoded = $frame->encode();
        $decoded = Frame::decode($encoded);

        $this->assertSame('', $decoded->payload);
    }
}
