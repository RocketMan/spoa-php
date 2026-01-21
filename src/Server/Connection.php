<?php
/**
 * HAProxy Stream Processing Offload Agent (SPOA) framework for PHP
 *
 * @author Jim Mason <jmason@ibinx.com>
 * @copyright Copyright (C) 2026 Jim Mason <jmason@ibinx.com>
 * @link https://www.ibinx.com/
 * @license MIT
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 */

namespace SPOA\Server;

use React\Promise;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;

use SPOA\Protocol\Action;
use SPOA\Protocol\ActionType;
use SPOA\Protocol\Arg;
use SPOA\Protocol\Decoder;
use SPOA\Protocol\Encoder;
use SPOA\Protocol\Frame;
use SPOA\Protocol\FrameType;
use SPOA\Transport\Reader;
use SPOA\Transport\Writer;

class Connection {
    private const MAX_FRAME_SIZE = 14092;

    private Encoder $encoder;
    private Reader $reader;
    private Writer $writer;
    private int $serverMaxFrameSize = self::MAX_FRAME_SIZE;
    private array $serverCapabilities = [];
    private int $errorCode = 0;
    private string $errorMessage = 'normal';  // no error
    private array $handlers = [];
    private ?Frame $frame = null;

    public function __construct(
        private ConnectionInterface $conn,
        private bool $debug = false,
    ) {
        $this->encoder = new Encoder();
        $this->reader = new Reader();
        $this->writer  = new Writer($conn);

        $conn->on('data', fn($data) => $this->onData($data));
        $conn->on('close', fn() => $this->onClose());
    }

    public function on(string $messageName, callable $handler): void {
        $this->handlers[$messageName] = $handler;
    }

    private function serverSupports(string $name): bool {
        return in_array($name, $this->serverCapabilities);
    }

    private function log(string $message, int $level = E_NOTICE): void {
        if ($level != E_NOTICE || $this->debug)
            error_log($message);
    }

    private function onData(string $data): void {
        foreach ($this->reader->push($data) as $frame) {
            try {
                $this->handleFrame($frame);
            } catch(\Throwable $e) {
                $message = $e->getMessage();

                $this->log("SPOP FATAL $message", E_ERROR);

                $this->errorCode = 99; // unknown error
                $this->errorMessage = $message;

                $this->doDisconnect();
                return;
            }
        }
    }

    private static function decodeArgs(string $data): array {
        $decoder = new Decoder($data);
        return $decoder->decodeArgs();
    }

    private static function decodeMessages(string $data): array {
        if (empty($data))
            return [];

        $result = [];
        $decoder = new Decoder($data);
        while($decoder->hasMore()) {
            $message = $decoder->decodeString();
            $count = ord($decoder->extract(1));
            $args = $decoder->decodeArgs($count);
            $result[] = [ "name" => $message, "args" => $args ];
        }

        return $result;
    }

    private function encodeActions(array $actions): string {
        $payload = '';

        foreach ($actions as $key => $value) {
            // variable name can contain optional scope prefix
            $keyParts = explode('.', $key);
            switch($keyParts[0]) {
            case 'proc':
                $scope = ActionType::S_PROCESS;
                $name = $keyParts[1] ?? null;
                break;
            case 'sess':
                $scope = ActionType::S_SESSION;
                $name = $keyParts[1] ?? null;
                break;
            case 'txn':
                $scope = ActionType::S_TRANSACTION;
                $name = $keyParts[1] ?? null;
                break;
            case 'req':
                $scope = ActionType::S_REQUEST;
                $name = $keyParts[1] ?? null;
                break;
            case 'res':
                $scope = ActionType::S_RESPONSE;
                $name = $keyParts[1] ?? null;
                break;
            default:
                // variable without prefix defaults to process-level scope
                $scope = ActionType::S_PROCESS;
                $name = $keyParts[0];
                break;
            }

            if ($name === null) {
                $this->log("SPOP invalid ACTION name", E_ERROR);
                continue;
            }

            if ($value && !($value instanceof Arg)) {
                $this->log("SPOP invalid ACTION value", E_ERROR);
                continue;
            }

            $payload .= $this->encoder->encodeAction(
                new Action(
                    $value ? ActionType::T_SET_VAR : ActionType::T_UNSET_VAR,
                    $scope,
                    $name,
                    $value
                )
            );
        }

        return $payload;
    }

    // public visibility for phpunit
    public function handleFrame(Frame $frame): void {
        switch ($frame->type) {
            case FrameType::HELLO:
                $this->handleHello($frame);
                break;

            case FrameType::UNSET:    // fragmented NOTIFY
            case FrameType::NOTIFY:
                $this->handleNotify($frame);
                break;

            case FrameType::DISCONNECT:
                $this->handleDisconnect($frame);
                break;
        }
    }

    private function handleHello(Frame $frame): void {
        $args = self::decodeArgs($frame->payload);

        if (key_exists('max-frame-size', $args))
            $this->serverMaxFrameSize = $args['max-frame-size']->value;

        if (key_exists('capabilities', $args))
            $this->serverCapabilities = array_map('trim', explode(',', $args['capabilities']->value));

        $this->log("SPOP HELLO versions=" .
            $args['supported-versions']?->value . ", max-frame-size=" .
            $this->serverMaxFrameSize . ", capabilities=" .
            implode(',', $this->serverCapabilities));

        $responseArgs = [
            'version' => Arg::str('2.0'),
            'max-frame-size' => Arg::uint32($this->serverMaxFrameSize),
            'capabilities' => Arg::str('fragmentation'),
        ];

        $resp = new Frame(
            FrameType::AGENT_HELLO,
            FrameType::FLAG_FIN, // fragmentation not permitted on HELLO
            0, // HELLO always uses stream 0
            0, // HELLO always uses frameid 0
            $this->encoder->encodeArgs($responseArgs)
        );

        $this->writer->send($resp);

        if (key_exists('healthcheck', $args) && $args['healthcheck']->value)
            $this->conn->end();
    }

    private function handleNotify(Frame $frame): void {
        if ($this->frame && (
                $this->frame->streamId != $frame->streamId ||
                $this->frame->frameId != $frame->frameId ) ) {
            // interleaved frames are not permitted
            $this->log("SPOP invalid interleaved frame", E_ERROR);

            // stash error for subsequent DISCONNECT
            $this->errorCode = 11;
            $this->errorMessage = 'invalid interlaced frames';

            $this->writer->send(
                new Frame(
                    FrameType::ACK,
                    FrameType::FLAG_ABRT | FrameType::FLAG_FIN,
                    $frame->streamId,
                    $frame->frameId,
                    ''
                )
            );

            $this->frame = null;

            return;
        }

        if (!($frame->flags & FrameType::FLAG_FIN)) {
            // receiving fragmented payload
            $this->log("SPOP NOTIFY fragmented frame");
            if (!$this->frame)
                $this->frame = $frame;
            else
                $this->frame->payload .= $frame->payload;

            return;
        }

        if ($this->frame) {
            // final frame of fragmented payload
            $this->frame->payload .= $frame->payload;
            $frame = $this->frame;
            $this->frame = null;
        }

        $messages = self::decodeMessages($frame->payload);
        $promises = [];
        try {
            foreach ($messages as $message) {
                $this->log("SPOP NOTIFY message={$message['name']}");

                if (!key_exists($message['name'], $this->handlers)) {
                    $this->log("SPOP NOTIFY unknown message={$message['name']}", E_ERROR);
                    continue;
                }

                $handler = $this->handlers[$message['name']];
                $actions = $handler($message['args']);
                if ($actions)
                    $promises[] = $actions instanceof PromiseInterface
                            ? $actions
                            : Promise\resolve($actions);
            }
        } catch(\Throwable $t) {
            $promises = [ Promise\reject($t) ];
        }

        Promise\all($promises)->then(
            function(array $results) use ($frame) {
                $payload = '';
                foreach($results as $actions)
                    $payload .= $this->encodeActions($actions);

                // check for frame overflow
                if (strlen($payload) > $this->serverMaxFrameSize - 11) {
                    if (!$this->serverSupports('fragmentation')) {
                        $this->log('SPOP frame too big', E_ERROR);

                        // stash error for subsequent DISCONNECT
                        $this->errorCode = 3;
                        $this->errorMessage = 'frame is too big';
            
                        $this->writer->send(
                            new Frame(
                                FrameType::ACK,
                                FrameType::FLAG_ABRT | FrameType::FLAG_FIN,
                                $frame->streamId,
                                $frame->frameId,
                                ''
                            )
                        );

                        return;
                    }

                    $chunks = str_split($payload, $this->conn->serverMaxFrameSize - 11);
                    $first = reset($chunks);
                    $last = end($chunks);
                    foreach ($chunks as $chunk) {
                        // Only first frame has type ACK; reset are all UNSET
                        // Only the last frame has the FIN flag
                        $f = new Frame(
                            $chunk === $first ? FrameType::ACK : FrameType::UNSET,
                            $chunk === $last ? FrameType::FLAG_FIN : 0,
                            $frame->streamId,
                            $frame->frameId,
                            $chunk
                        );

                        $this->writer->send($f);
                    }

                    return;
                }

                $this->writer->send(
                    new Frame(
                        FrameType::ACK,
                        FrameType::FLAG_FIN,
                        $frame->streamId,
                        $frame->frameId,
                        $payload
                    )
                );
            },
            function(\Throwable $reject) use ($frame) {
                $message = $reject->getMessage();
                $this->log("SPOP NOTIFY handler exception: $message", E_ERROR);

                // stash error for subsequent DISCONNECT
                $this->errorCode = 99; // unknown error
                $this->errorMessage = "agent handler exception: $message";

                $this->writer->send(
                    new Frame(
                        FrameType::ACK,
                        FrameType::FLAG_ABRT | FrameType::FLAG_FIN,
                        $frame->streamId,
                        $frame->frameId,
                        ''
                    )
                );
            }
        );
    }

    private function doDisconnect(): void {
        $args = [
            'status-code' => Arg::uint32($this->errorCode),
            'message' => Arg::str($this->errorMessage)
        ];

        $resp = new Frame(
            FrameType::AGENT_DISCONNECT,
            FrameType::FLAG_FIN, // fragmentation not permitted on DISCONNECT
            0, // DISCONNECT always uses stream 0
            0, // DISCONNECT always uses frameid 0
            $this->encoder->encodeArgs($args)
        );

        $this->writer->send($resp);

        $this->conn->end();
    }

    private function handleDisconnect(Frame $frame): void {
        $args = self::decodeArgs($frame->payload);
        $this->log("SPOP DISCONNECT code=" . 
            $args['status-code']->value . ", message=" .
            $args['message']->value);

        $this->doDisconnect();
    }

    private function onClose(): void {
        $this->log("SPOP closing connection");
    }
}
