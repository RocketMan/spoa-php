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

namespace SPOA\Protocol;

class Frame {
    public function __construct(
        public int $type,
        public int $flags,
        public int $streamId,
        public int $frameId,
        public string $payload
    ) {}

    public function payload(): string {
        return $this->payload;
    }

    /**
     * Encode the frame to a binary string suitable for sending over a SPOA connection.
     */
    public function encode(): string {
        $encoder = new Encoder();
        return
            chr($this->type) .
            pack('N', $this->flags) .
            $encoder->encodeVarint($this->streamId) .
            $encoder->encodeVarint($this->frameId) .
            $this->payload;
    }

    public function encodeWire(): string {
        $frame = $this->encode();
        return
            pack('N', strlen($frame)) .
            $frame;
    }

    /**
     * Decode a frame from a binary string.
     *
     * @param string $data The raw binary frame (header + payload)
     * @return self
     * @throws \RuntimeException if the frame is too short or incomplete
     */
    public static function decode(string $data): self {
        if (strlen($data) < 7) {
            throw new \RuntimeException('Frame too short, must be at least 7 bytes for header');
        }

        $type  = ord($data[0]);
        $flags = unpack('N', substr($data, 1, 4))[1];

        $decoder = new Decoder(substr($data, 5));

        $streamId = $decoder->decodeVarint();
        $frameId = $decoder->decodeVarint();
        $payload = $decoder->rest();

        return new self($type, $flags, $streamId, $frameId, $payload);
    }
}
