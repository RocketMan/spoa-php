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

class Encoder {
    /**
     * Encode an integer value into a varint (variable-length integer)
     *
     * @param int $value integer to encode
     * @return string encoded integer
     */
    public function encodeVarint(int $value): string {
        // Fast path
        if ($value >= 0 && $value < 0xf0)
            return chr($value);

        // First byte (high nibble is f)
        $out = chr(0xf0 | $value & 0x0f);
        $value -= 0xf0;
        $value >>= 4;

        // Correctly encode signed (negative) value
        if ($value < 0)
            $value &= ~(0xf << 60);

        while ($value > 0x7f) {
            // Take 7 bits, set MSB to 1
            $out .= chr(0x80 | $value & 0x7f);
            $value -= 0x80;
            $value >>= 7;
        }

        // Final byte (MSB is 0)
        $out .= chr($value & 0x7f);
        return $out;
    }

    /**
     * Encode a string to SPOA wire format
     *
     * @param string $s string to encode
     * @return string encoded string
     */
    public function encodeString(string $s): string {
        return $this->encodeVarint(strlen($s)) . $s;
    }

    /**
     * Encode an argument to SPOA wire format
     *
     * @param Arg $arg value to encode
     * @return string encoded value
     */
    public function encodeValue(Arg $arg): string {
        return match ($arg->type) {
            ArgType::T_NULL    => chr(ArgType::T_NULL),
            ArgType::T_BOOL    => chr(ArgType::T_BOOL) | chr($arg->value ? 0x10 : 0),
            ArgType::T_UINT32  => chr(ArgType::T_UINT32) . $this->encodeVarint($arg->value & 0xffffffff),
            // It seems signed 32 bit values are expected to be wire-encoded
            // with sign-extension to 64 bits, so we deliberately avoid masking.
            ArgType::T_INT32   => chr(ArgType::T_INT32) . $this->encodeVarint($arg->value),
            ArgType::T_UINT64  => chr(ArgType::T_UINT64) . $this->encodeVarint($arg->value),
            ArgType::T_INT64   => chr(ArgType::T_INT64) . $this->encodeVarint($arg->value),
            ArgType::T_IPV4    => chr(ArgType::T_IPV4) . inet_pton($arg->value),
            ArgType::T_IPV6    => chr(ArgType::T_IPV6) . inet_pton($arg->value),
            ArgType::T_STR     => chr(ArgType::T_STR) . $this->encodeString($arg->value),
            ArgType::T_BIN     => chr(ArgType::T_BIN) . $this->encodeString($arg->value),
            default            => throw new \RuntimeException('Unknown SPOP value type {$arg->type}')
        };
    }

    /**
     * Encode a map of key=>Arg arguments into SPOA wire format
     *
     * @param array<string, Arg> $args
     * @return string
     */
    public function encodeArgs(array $args): string {
        $out = '';
        foreach ($args as $key => $arg) {
            $out .= $this->encodeString($key);
            $out .= $this->encodeValue($arg);
        }
        return $out;
    }

    /**
     * Encode an Action to SPOA wire format
     *
     * @param Action $action
     * @return string encoded value
     */
    public function encodeAction(Action $action): string {
        $out = '';
        switch($action->type) {
        case ActionType::T_SET_VAR:
            $out .= chr(ActionType::T_SET_VAR);
            $out .= chr(3);  // fixed arg count for T_SET_VAR
            $out .= chr($action->scope);
            $out .= $this->encodeString($action->name);
            $out .= $this->encodeValue($action->value);
            break;
        case ActionType::T_UNSET_VAR:
            $out .= chr(ActionType::T_UNSET_VAR);
            $out .= chr(2);  // fixed arg count for T_UNSET_VAR
            $out .= chr($action->scope);
            $out .= $this->encodeString($action->name);
            break;
        }

        return $out;
    }
}
