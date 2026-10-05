<?php

namespace App\Transit\Realtime;

/**
 * Decodes the VehiclePosition entities of a GTFS-Realtime FeedMessage.
 * Field numbers follow gtfs-realtime.proto.
 */
class GtfsRealtimeDecoder
{
    /**
     * @return list<array{id: string, label: ?string, trip_id: ?string, route_id: ?string, direction_id: ?int, lat: float, lon: float, bearing: ?float, speed: ?float, stop_id: ?string, timestamp: ?int}>
     */
    public function vehicles(string $payload): array
    {
        $vehicles = [];
        $reader = new ProtobufReader($payload);

        while (! $reader->eof()) {
            [$field, $type] = $reader->tag();
            if ($field !== 2 || $type !== 2) { // FeedMessage.entity
                $reader->skip($type);

                continue;
            }
            $vehicle = $this->entity($reader->bytes());
            if ($vehicle !== null) {
                $vehicles[] = $vehicle;
            }
        }

        return $vehicles;
    }

    private function entity(string $bytes): ?array
    {
        $reader = new ProtobufReader($bytes);
        $entityId = '';
        $position = null;
        $deleted = false;

        while (! $reader->eof()) {
            [$field, $type] = $reader->tag();
            if ($field === 1 && $type === 2) {
                $entityId = $reader->bytes();
            } elseif ($field === 2 && $type === 0) {
                $deleted = $reader->varint() === 1;
            } elseif ($field === 4 && $type === 2) { // FeedEntity.vehicle
                $position = $this->vehiclePosition($reader->bytes());
            } else {
                $reader->skip($type);
            }
        }

        if ($deleted || $position === null || $position['lat'] === null || $position['lon'] === null) {
            return null;
        }
        if (abs($position['lat']) > 90 || abs($position['lon']) > 180 || ($position['lat'] == 0 && $position['lon'] == 0)) {
            return null;
        }
        $position['id'] = $position['vehicle_id'] ?? $entityId;
        unset($position['vehicle_id']);

        return $position;
    }

    private function vehiclePosition(string $bytes): array
    {
        $v = [
            'id' => '', 'vehicle_id' => null, 'label' => null, 'trip_id' => null, 'route_id' => null,
            'direction_id' => null, 'lat' => null, 'lon' => null, 'bearing' => null, 'speed' => null,
            'stop_id' => null, 'timestamp' => null,
        ];
        $reader = new ProtobufReader($bytes);

        while (! $reader->eof()) {
            [$field, $type] = $reader->tag();
            if ($field === 1 && $type === 2) {
                $this->trip($reader->bytes(), $v);
            } elseif ($field === 2 && $type === 2) {
                $this->position($reader->bytes(), $v);
            } elseif ($field === 5 && $type === 0) {
                $v['timestamp'] = $reader->varint();
            } elseif ($field === 7 && $type === 2) {
                $v['stop_id'] = $reader->bytes();
            } elseif ($field === 8 && $type === 2) {
                $this->descriptor($reader->bytes(), $v);
            } else {
                $reader->skip($type);
            }
        }

        return $v;
    }

    private function trip(string $bytes, array &$v): void
    {
        $reader = new ProtobufReader($bytes);
        while (! $reader->eof()) {
            [$field, $type] = $reader->tag();
            if ($field === 1 && $type === 2) {
                $v['trip_id'] = $reader->bytes();
            } elseif ($field === 5 && $type === 2) {
                $v['route_id'] = $reader->bytes();
            } elseif ($field === 6 && $type === 0) {
                $v['direction_id'] = $reader->varint();
            } else {
                $reader->skip($type);
            }
        }
    }

    private function position(string $bytes, array &$v): void
    {
        $reader = new ProtobufReader($bytes);
        while (! $reader->eof()) {
            [$field, $type] = $reader->tag();
            if ($type === 5 && in_array($field, [1, 2, 3, 5], true)) {
                $value = round($reader->float(), 6);
                match ($field) {
                    1 => $v['lat'] = $value,
                    2 => $v['lon'] = $value,
                    3 => $v['bearing'] = $value,
                    5 => $v['speed'] = $value,
                };
            } else {
                $reader->skip($type);
            }
        }
    }

    private function descriptor(string $bytes, array &$v): void
    {
        $reader = new ProtobufReader($bytes);
        while (! $reader->eof()) {
            [$field, $type] = $reader->tag();
            if ($field === 1 && $type === 2) {
                $v['vehicle_id'] = $reader->bytes();
            } elseif ($field === 2 && $type === 2) {
                $v['label'] = $reader->bytes();
            } else {
                $reader->skip($type);
            }
        }
    }
}
