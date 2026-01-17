<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use React\Socket\ConnectionInterface;

use SPOA\Protocol\Action;
use SPOA\Protocol\ActionType;
use SPOA\Protocol\Arg;
use SPOA\Protocol\Decoder;
use SPOA\Protocol\Encoder;
use SPOA\Protocol\Frame;
use SPOA\Protocol\FrameType;
use SPOA\Server\Connection;
use SPOA\Transport\Reader;
use SPOA\Transport\Writer;

final class ConnectionTest extends TestCase {
    public function testHandleNotifySendsAck(): void {
        $mockStream = $this->createMock(ConnectionInterface::class);

        // Expect the ACK frame to be written
        $mockStream->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) {
                $f = Frame::decode(substr($data, 4));
                return $f->type === FrameType::ACK;
            }));

        $conn = new Connection($mockStream);

        // Construct a NOTIFY frame
        $notifyFrame = new Frame(FrameType::NOTIFY, FrameType::FLAG_FIN, 42, 0, '');

        // Simulate receiving the frame
        $reader = new Reader();
        foreach ($reader->push($notifyFrame->encodeWire()) as $frame) {
            $conn->handleFrame($frame);
        }
    }

    /*
     * fragmentation of a single Frame across multiple transport callbacks
     */
    public function testFragmentedStream(): void {
        $mockStream = $this->createMock(ConnectionInterface::class);
        $streamId = 7;
        $frameId = 8;

        $mockStream->expects($this->once())
            ->method('write')
            ->with($this->callback(function($data) use($streamId, $frameId) {
                $f = Frame::decode(substr($data, 4));
                $d = new Decoder($f->payload);
                $d->extract(3); // skip type, count, scope
                $args = $d->decodeArgs();
                return $f->type === FrameType::ACK &&
                    $f->streamId === $streamId &&
                    $f->frameId === $frameId &&
                    $args["one"]->value == 12345678901;
             }));

        $conn = new Connection($mockStream);

        $conn->on('get-ip-reputation', function($args) {
            // check server-supplied arguments
            $this->assertEquals($args['foo']->value, 'bar');
            $this->assertEquals($args['binaryOne']->value, true);
            $this->assertEquals($args['int32']->value, -1234);
            $this->assertEquals($args['ipsrc']->value, '1.2.3.4');

            return [ "sess.one" => Arg::uint64(12345678901) ];
        });

        $args = [
              'foo' => Arg::str('bar'),
              'binaryOne' => Arg::bool(true),
              'int32' => Arg::int32(-1234),
              'ipsrc' => Arg::ipv4('1.2.3.4')
        ];

        // encode message
        $encoder = new Encoder();
        $message = $encoder->encodeString("get-ip-reputation");
        $message .= chr(count($args));
        $message .= $encoder->encodeArgs($args);
        $frame = new Frame(FrameType::NOTIFY, FrameType::FLAG_FIN, $streamId, $frameId, $message);

        $encoded = $frame->encodeWire();

        // Simulate fragmented reception
        $chunks = str_split($encoded, 3);
        $reader = new Reader();
        foreach ($chunks as $chunk) {
            foreach ($reader->push($chunk) as $f) {
                $conn->handleFrame($f);
            }
        }
    }

    /*
     * fragmentation of NOTIFY across multiple Frames
     */
    public function testFragmentedNotify(): void {
        $mockStream = $this->createMock(ConnectionInterface::class);
        $streamId = 7;
        $frameId = 8;

        $mockStream->expects($this->once())
            ->method('write')
            ->with($this->callback(function($data) use($streamId, $frameId) {
                $f = Frame::decode(substr($data, 4));
                $d = new Decoder($f->payload);
                $d->extract(3); // skip type, count, scope
                $args = $d->decodeArgs();
                return $f->type === FrameType::ACK &&
                    $f->streamId === $streamId &&
                    $f->frameId === $frameId &&
                    $args["one"]->value == 12345678901;
             }));

        $conn = new Connection($mockStream);

        $conn->on('get-ip-reputation', function($args) {
            // check server-supplied arguments
            $this->assertEquals($args['foo']->value, 'bar');
            $this->assertEquals($args['binaryOne']->value, true);
            $this->assertEquals($args['int32']->value, -1234);
            $this->assertEquals($args['ipsrc']->value, '1.2.3.4');

            return [ "sess.one" => Arg::uint64(12345678901) ];
        });

        $args = [
              'foo' => Arg::str('bar'),
              'binaryOne' => Arg::bool(true),
              'int32' => Arg::int32(-1234),
              'ipsrc' => Arg::ipv4('1.2.3.4')
        ];

        // encode message
        $encoder = new Encoder();
        $message = $encoder->encodeString("get-ip-reputation");
        $message .= chr(count($args));
        $message .= $encoder->encodeArgs($args);

        // fragment message across multiple NOTIFY frames
        $chunks = str_split($message, 3);
        $first = reset($chunks);
        $last = end($chunks);

        $reader = new Reader();
        foreach ($chunks as $chunk) {
            // Only first frame has type NOTIFY; reset are all type UNSET
            // Only the last frame has FLAG_FIN set
            $frame = new Frame(
                $chunk === $first ? FrameType::NOTIFY : FrameType::UNSET,
                $chunk === $last ? FrameType::FLAG_FIN : 0,
                $streamId, $frameId, $chunk);

            foreach ($reader->push($frame->encodeWire()) as $f) {
                $conn->handleFrame($f);
            }
        }
    }

    /*
     * multiple messages within a single Frame
     */
    public function testMultipleMessagesInFrame(): void {
        $mockStream = $this->createMock(ConnectionInterface::class);

        $mockStream->expects($this->once())
            ->method('write')
            ->with($this->callback(function($data) {
                $f = Frame::decode(substr($data, 4));
                $this->assertEquals($f->type, FrameType::ACK);

                $actions = [];
                $d = new Decoder($f->payload);
                while($d->hasMore()) {
                    $this->assertEquals(ord($d->extract(1)), ActionType::T_SET_VAR);
                    $d->extract(1); // skip count
                    $scope = ord($d->extract(1)); // group actions by scope
                    $actions[$scope] = array_merge($actions[$scope] ?? [], $d->decodeArgs(1));
                }

                // check client-supplied actions
                return
                    $actions[ActionType::S_SESSION]["one"]->value == 12345678901 &&
                    $actions[ActionType::S_REQUEST]["one"]->value == -1000 &&
                    $actions[ActionType::S_TRANSACTION]["two"]->value == true;
             }));

        $conn = new Connection($mockStream);

        $conn->on('get-ip-reputation', function($args) {
            // check server-supplied arguments
            $this->assertEquals($args['foo']->value, 'bar');
            $this->assertEquals($args['binaryOne']->value, true);

            return [
                "sess.one" => Arg::uint64(12345678901),
                "req.one" => Arg::int32(-1000),
            ];
        });

        $conn->on('second-message', function($args) {
            // check server-supplied arguments
            $this->assertEquals($args['int32']->value, -1234);
            $this->assertEquals($args['ipsrc']->value, '1.2.3.4');

            return [ "txn.two" => Arg::bool(true) ];
        });

        $args1 = [
              'foo' => Arg::str('bar'),
              'binaryOne' => Arg::bool(true),
        ];

        $args2 = [
              'int32' => Arg::int32(-1234),
              'ipsrc' => Arg::ipv4('1.2.3.4'),
        ];

        // encode message 1
        $encoder = new Encoder();
        $message = $encoder->encodeString("get-ip-reputation");
        $message .= chr(count($args1));
        $message .= $encoder->encodeArgs($args1);

        // encode message 2
        $message .= $encoder->encodeString("second-message");
        $message .= chr(count($args2));
        $message .= $encoder->encodeArgs($args2);

        $frame = new Frame(FrameType::NOTIFY, FrameType::FLAG_FIN, 20, 21, $message);
        $conn->handleFrame($frame);
    }
}
