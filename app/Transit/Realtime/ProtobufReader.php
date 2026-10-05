<?php

namespace App\Transit\Realtime;

use RuntimeException;

/** Minimal protobuf wire-format reader (enough for GTFS-Realtime). */
class ProtobufReader
{
    private int $pos = 0;

    private int $length;

    public function __construct(private readonly string $buffer)
    {
        $this->length = strlen($buffer);
    }

    public function eof(): bool
    {
        return $this->pos >= $this->length;
    }

    /** @return array{0: int, 1: int} field number and wire type */
    public function tag(): array
    {
        $tag = $this->varint();

        return [$tag >> 3, $tag & 7];
    }

    public function varint(): int
    {
        $result = 0;
        $shift = 0;
        do {
            if ($this->pos >= $this->length || $shift > 63) {
                throw new RuntimeException('Malformed protobuf varint.');
            }
            $byte = ord($this->buffer[$this->pos++]);
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);

        return $result;
    }

    public function bytes(): string
    {
        $size = $this->varint();
        if ($size < 0 || $this->pos + $size > $this->length) {
            throw new RuntimeException('Malformed protobuf length.');
        }
        $value = substr($this->buffer, $this->pos, $size);
        $this->pos += $size;

        return $value;
    }

    public function float(): float
    {
        return unpack('g', $this->fixed(4))[1];
    }

    public function double(): float
    {
        return unpack('e', $this->fixed(8))[1];
    }

    public function skip(int $wireType): void
    {
        match ($wireType) {
            0 => $this->varint(),
            1 => $this->fixed(8),
            2 => $this->bytes(),
            5 => $this->fixed(4),
            default => throw new RuntimeException("Unsupported protobuf wire type {$wireType}."),
        };
    }

    private function fixed(int $size): string
    {
        if ($this->pos + $size > $this->length) {
            throw new RuntimeException('Malformed protobuf fixed field.');
        }
        $value = substr($this->buffer, $this->pos, $size);
        $this->pos += $size;

        return $value;
    }
}
