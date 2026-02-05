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

trait LoggerTrait {
    private bool $debug;

    protected function log(string $message, int $dir = 0, array $args = [], int $level = E_NOTICE): void {
        if ($level != E_NOTICE || $this->debug) {
            $text = "SPOP ";

            switch ($dir) {
            case -1:
                $text .= "<-- ";
                break;
            case 0:
                $text .= "    ";
                break;
            case 1:
                $text .= "--> ";
                break;
            }

            $text .= "$message ";

            $text .= implode(' ',
                    array_map(fn($key, $value) => "$key=$value",
                        array_keys($args),
                        array_values($args)
                    ));

            error_log($text);
        }
    }

    protected function logMessage(string $message, int $level = E_NOTICE): void {
        $this->log($message, 0, [], $level);
    }
}
