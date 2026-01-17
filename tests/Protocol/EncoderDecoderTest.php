<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SPOA\Protocol\Arg;
use SPOA\Protocol\Encoder;
use SPOA\Protocol\Decoder;

final class EncoderDecoderTest extends TestCase {
    public function testEncodeDecodeSimpleArgs(): void {
        $args = [
            'nullValue' => Arg::null(),
            'boolTrue'  => Arg::bool(true),
            'boolFalse' => Arg::bool(false),
            'intValue' => Arg::int32(-123456789),
            'intValue2' => Arg::int32(1023456),
            'intValue3' => Arg::uint64(39483948934),
            'intValue4' => Arg::int64(-1234),
            'intValue5' => Arg::int64(10392428382),
            'intValue6' => Arg::int64(-1),
            'stringValue' => Arg::str('foobar'),
            'ipv4' => Arg::ipv4('1.2.3.4'),
            'ipv6' => Arg::ipv6('fe80::beee:7bff:1234:5678'),
        ];

        $encoded = (new Encoder())->encodeArgs($args);
        $decoded = (new Decoder($encoded))->decodeArgs();

        $this->assertEquals($args, $decoded);
    }

    public function testEmptyArgs(): void {
        $args = [];
        $encoded = (new Encoder())->encodeArgs($args);

        // Ensure encoding an empty array produces an empty string
        $this->assertSame('', $encoded);

        $decoded = (new Decoder($encoded))->decodeArgs();

        // Decoding empty string should return empty array
        $this->assertSame([], $decoded);
    }
}

