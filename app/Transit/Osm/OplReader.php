<?php

namespace App\Transit\Osm;

use Generator;

/** Streams objects from an OSM OPL file. Spaces, commas and equals signs inside values are %hex%-escaped. */
class OplReader
{
    /**
     * @return Generator<int, array{type: string, id: int, tags: array<string, string>, lon?: float, lat?: float, nodes?: list<int>, members?: list<array{0: string, 1: int, 2: string}>}>
     */
    public static function read(string $path): Generator
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $object = self::parse(rtrim($line, "\r\n"));
                if ($object !== null) {
                    yield $object;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    public static function parse(string $line): ?array
    {
        $type = $line[0] ?? '';
        if (! in_array($type, ['n', 'w', 'r'], true)) {
            return null;
        }

        $fields = explode(' ', $line);
        $object = ['type' => $type, 'id' => (int) substr($fields[0], 1), 'tags' => []];

        foreach (array_slice($fields, 1) as $field) {
            $value = substr($field, 1);
            switch ($field[0] ?? '') {
                case 'T':
                    $object['tags'] = self::tags($value);
                    break;
                case 'x':
                    $object['lon'] = (float) $value;
                    break;
                case 'y':
                    $object['lat'] = (float) $value;
                    break;
                case 'N':
                    $object['nodes'] = $value === '' ? [] : array_map(static fn (string $n): int => (int) ltrim($n, 'n'), explode(',', $value));
                    break;
                case 'M':
                    $object['members'] = self::members($value);
                    break;
            }
        }

        if ($type === 'n' && (! isset($object['lon']) || ! isset($object['lat']))) {
            return null;
        }

        return $object;
    }

    public static function decode(string $value): string
    {
        return str_contains($value, '%')
            ? (string) preg_replace_callback('/%([0-9a-fA-F]+)%/', static fn (array $m): string => mb_chr((int) hexdec($m[1]), 'UTF-8'), $value)
            : $value;
    }

    private static function tags(string $value): array
    {
        $tags = [];
        if ($value === '') {
            return $tags;
        }
        foreach (explode(',', $value) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $tags[self::decode($parts[0])] = self::decode($parts[1]);
            }
        }

        return $tags;
    }

    private static function members(string $value): array
    {
        $members = [];
        if ($value === '') {
            return $members;
        }
        foreach (explode(',', $value) as $member) {
            $at = strpos($member, '@');
            if ($at === false || $at < 2) {
                continue;
            }
            $members[] = [$member[0], (int) substr($member, 1, $at - 1), self::decode(substr($member, $at + 1))];
        }

        return $members;
    }
}
