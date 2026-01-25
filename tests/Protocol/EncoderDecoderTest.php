<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SPOA\Protocol\Arg;
use SPOA\Protocol\Encoder;
use SPOA\Protocol\Decoder;

final class EncoderDecoderTest extends TestCase {
    // expected canonical varint encodings, per SPOE section 3.1
    const CANONICAL = [
        239 => "\xef",
        240 => "\xf0\x00",
        256 => "\xf0\x01",
        2287 => "\xff\x7f",
        2289 => "\xf1\x80\x00",
        264431 => "\xff\xff\x7f",
        264432 => "\xf0\x80\x80\x00",
        33818863 => "\xff\xff\xff\x7f",
        33818867 => "\xf3\x80\x80\x80\x00",
    ];

    public function testCanonicalEncoding(): void {
        $encoder = new Encoder();
        $encoded = array_map(fn($i) => $encoder->encodeVarint($i), array_keys(self::CANONICAL));
        $this->assertEquals($encoded, array_values(self::CANONICAL));
    }

    public function testCanonicalDecoding(): void {
        $decoded = array_map(fn($enc) => (new Decoder($enc))->decodeVarint(), array_values(self::CANONICAL));
        $this->assertEquals($decoded, array_keys(self::CANONICAL));
    }

    public function testEncodeDecodeString(): void {
        $test = 'hello world';
        $encoded = (new Encoder())->encodeString($test);

        $decoder = new Decoder($encoded);
        $len = $decoder->decodeVarint();
        $this->assertSame($len, strlen($test));

        $data = $decoder->rest();
        $this->assertSame($data, $test);
    }

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
