<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use React\Socket\ConnectionInterface;

use SPOA\Protocol\Frame;
use SPOA\Protocol\FrameType;
use SPOA\Transport\Writer;

final class WriterTest extends TestCase {
    public function testSendFrameWritesEncodedData(): void {
        $mockStream = $this->createMock(ConnectionInterface::class);

        $mockStream->expects($this->once())
            ->method('write')
            ->with($this->callback(fn($data) => is_string($data) && strlen($data) > 0));

        $writer = new Writer($mockStream);

        $frame = new Frame(FrameType::NOTIFY, 0, 1, 0, 'payload');
        $writer->send($frame);
    }
}

