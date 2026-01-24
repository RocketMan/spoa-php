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

class Decoder {
    private int $offset = 0;

    public function __construct(
        private string $data
    ) {}

    /**
     * Decode the variable-length integer at the current buffer position
     *
     * Upon completion, buffer position is advanced passed the value.
     *
     * @return int decoded integer
     */
    public function decodeVarint(): int {
        $byte = ord($this->data[$this->offset++]);
        if ($byte < 0xf0)
            return $byte;

        $result = $byte;
        $shift = 4;

        while (true) {
            $byte = ord($this->data[$this->offset++]);
            $result += $byte << $shift;

            // If MSB is 0, we are done
            if (!($byte & 0x80))
                return $result;

            $shift += 7;

            // Safety check for overflow (SPOP max is 10 bytes for 64-bit)
            if ($shift >= 70)
                throw new \RuntimeException("Varint too large (overflow)");
        }
    }

    private function decodeInt32(): int {
        $val = $this->decodeVarint();
        if ($val & 0x80000000)
            $val = -((~$val + 1) & 0x7fffffff);
        return $val;
    }

    private function decodeIpv4(): string {
        $raw = substr($this->data, $this->offset, 4);
        $this->offset += 4;
        return inet_ntop($raw);
    }

    private function decodeIpv6(): string {
        $raw = substr($this->data, $this->offset, 16);
        $this->offset += 16;
        return inet_ntop($raw);
    }

    /**
     * Decode an SPOA string from the current position and advance the buffer.
     *
     * @return string string value
     */
    public function decodeString(): string {
        $len = $this->decodeVarint();
        $val = substr($this->data, $this->offset, $len);
        $this->offset += $len;
        return $val;
    }

    /**
     * Decode the SPOA tagged value at the current buffer position into an Arg
     *
     * Upon completion, buffer position is advanced passed the value.
     *
     * @return Arg decoded value
     */
    public function decodeValue(): Arg {
        $typeOctet = ord($this->data[$this->offset++]);

        return match ($typeOctet & 0x0f) {
            ArgType::T_NULL   => Arg::null(),
            ArgType::T_BOOL   => Arg::bool($typeOctet & 0x10),
            ArgType::T_INT32  => Arg::int32($this->decodeInt32()),
            ArgType::T_UINT32 => Arg::uint32($this->decodeVarint()),
            ArgType::T_INT64  => Arg::int64($this->decodeVarint()),
            ArgType::T_UINT64 => Arg::uint64($this->decodeVarint()),
            ArgType::T_IPV4   => Arg::ipv4($this->decodeIpv4()),
            ArgType::T_IPV6   => Arg::ipv6($this->decodeIpv6()),
            ArgType::T_STR    => Arg::str($this->decodeString()),
            ArgType::T_BIN    => Arg::bin($this->decodeString()),
    
            default => throw new \RuntimeException("Unknown SPOP value type " . ($typeOctet & 0x0f))
        };
    }

    /**
     * Decode an SPOA argument string into a PHP array
     *
     * @param int $count number of args to decode (default 0, maximum)
     * @return array<string, Arg>
     */
    public function decodeArgs(int $count = 0): array {
        $args = [];

        $len = strlen($this->data);

        $index = 0;
        while ($this->offset < $len) {
            $key = $this->decodeString();
            $args[strlen($key) ? $key : "arg($index)"] = $this->decodeValue();
            if (--$count == 0) break;
            $index++;
        }

        return $args;
    }

    /**
     * Return $count bytes from the current position.
     *
     * Upon completion, the current buffer position is advanced $count bytes.
     *
     * @param int $count byte count to read
     * @return string bytes read
     */
    public function extract(int $count): string {
        $val = substr($this->data, $this->offset, $count);
        $this->offset += $count;
        return $val;
    }

    /**
     * Return remainder of buffer from the current position to the end.
     *
     * Upon completion, the buffer is advanced to the end.
     *
     * @return string remainder of buffer
     */
    public function rest(): string {
        $val = substr($this->data, $this->offset);
        $this->offset = strlen($this->data);
        return $val;
    }

    public function hasMore(): bool {
        return $this->offset < strlen($this->data);
    }
}
