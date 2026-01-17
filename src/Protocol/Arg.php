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

final class Arg {
    private function __construct(
        public readonly int $type,   // ArgType::T_*
        public readonly mixed $value // int|string|bool|null
    ) {}

    public static function null(): self {
        return new self(ArgType::T_NULL, null);
    }

    public static function bool(bool $v): self {
        return new self(ArgType::T_BOOL, $v);
    }

    public static function int32(int $v): self {
        return new self(ArgType::T_INT32, $v);
    }

    public static function uint32(int $v): self {
        return new self(ArgType::T_UINT32, $v);
    }

    public static function int64(int $v): self {
        return new self(ArgType::T_INT64, $v);
    }

    public static function uint64(int $v): self {
        return new self(ArgType::T_UINT64, $v);
    }

    public static function ipv4(string $v): self {
        return new self(ArgType::T_IPV4, $v);
    }

    public static function ipv6(string $v): self {
        return new self(ArgType::T_IPV6, $v);
    }

    public static function str(string $v): self {
        return new self(ArgType::T_STR, $v);
    }

    public static function bin(string $v): self {
        return new self(ArgType::T_BIN, $v);
    }
}
