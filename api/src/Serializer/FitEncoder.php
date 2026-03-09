<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Geo\GeoDistanceInterface;
use App\Serializer\Mapper\WaypointMapper;
use Symfony\Component\Serializer\Encoder\EncoderInterface;

/**
 * Encodes normalized stage data into Garmin FIT binary format (course type).
 *
 * @see https://developer.garmin.com/fit/protocol/
 */
final readonly class FitEncoder implements EncoderInterface
{
    private const float SEMICIRCLES_PER_180_DEGREES = 2_147_483_648.0; // 2^31

    // FIT protocol constants
    private const int FIT_HEADER_SIZE = 14;

    private const int PROTOCOL_VERSION = 0x20;

    // 2.0
    private const int PROFILE_VERSION = 0x0814; // 20.84

    // Global Message Numbers
    private const int MESG_FILE_ID = 0;

    private const int MESG_EVENT = 21;

    private const int MESG_RECORD = 20;

    private const int MESG_LAP = 19;

    private const int MESG_COURSE = 31;

    private const int MESG_COURSE_POINT = 32;

    private const int FIT_SINT32 = 0x85;

    private const int FIT_UINT16 = 0x84;

    private const int FIT_UINT32 = 0x86;

    private const int FIT_STRING = 0x07;

    private const int FIT_ENUM = 0x00;

    // File type: Course
    private const int FILE_COURSE = 6;

    // Event types
    private const int EVENT_TIMER = 0;

    private const int EVENT_TYPE_START = 0;

    private const int EVENT_TYPE_STOP_ALL = 9;

    // Manufacturer / Product
    private const int MANUFACTURER_DEVELOPMENT = 255;

    private const int PRODUCT_GENERIC = 0;

    // Sport type
    private const int SPORT_CYCLING = 2;

    public function __construct(
        private GeoDistanceInterface $haversine,
    ) {
    }

    /**
     * @param array{courseName: string, points: list<array{lat: float, lon: float, ele: float}>, waypoints: list<array{lat: float, lon: float, name: string, type: string}>} $data
     */
    public function encode(mixed $data, string $format, array $context = []): string
    {
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('FitEncoder expects an array.');
        }

        $body = '';
        $localMesgIndex = 0;

        // FILE_ID message
        $body .= $this->writeFileIdMessage($localMesgIndex++);

        // COURSE message
        $body .= $this->writeCourseMessage($localMesgIndex++, $data['courseName']);

        // EVENT (timer start)
        $body .= $this->writeEventMessage($localMesgIndex++, self::EVENT_TYPE_START);

        // RECORD messages
        /** @var list<array{lat: float, lon: float, ele: float}> $points */
        $points = $data['points'];
        $recordLocalMesg = $localMesgIndex++;
        $recordDefinitionWritten = false;
        $cumulativeDistance = 0.0;
        $prevPoint = null;

        foreach ($points as $point) {
            if (null !== $prevPoint) {
                $cumulativeDistance += $this->haversine->inKilometers($prevPoint['lat'], $prevPoint['lon'], $point['lat'], $point['lon']);
            }

            if (!$recordDefinitionWritten) {
                $body .= $this->writeRecordDefinition($recordLocalMesg);
                $recordDefinitionWritten = true;
            }

            $body .= $this->writeRecordData($recordLocalMesg, $point, $cumulativeDistance);
            $prevPoint = $point;
        }

        // COURSE_POINT messages
        /** @var list<array{lat: float, lon: float, name: string, type: string}> $waypoints */
        $waypoints = $data['waypoints'];
        if ([] !== $waypoints) {
            $cpLocalMesg = $localMesgIndex++;
            $cpDefinitionWritten = false;

            foreach ($waypoints as $waypoint) {
                if (!$cpDefinitionWritten) {
                    $body .= $this->writeCoursePointDefinition($cpLocalMesg);
                    $cpDefinitionWritten = true;
                }

                $body .= $this->writeCoursePointData($cpLocalMesg, $waypoint);
            }
        }

        // LAP message
        if ([] !== $points) {
            $body .= $this->writeLapMessage($localMesgIndex++, $points[0], $points[\count($points) - 1], $cumulativeDistance);
        }

        // EVENT (timer stop)
        $body .= $this->writeEventMessage($localMesgIndex, self::EVENT_TYPE_STOP_ALL);

        // Build complete file: header + data + CRC16
        $dataSize = \strlen($body);
        $header = $this->buildHeader($dataSize);
        $headerCrc = $this->crc16($header);
        $headerWithCrc = substr($header, 0, 12).pack('v', $headerCrc);

        $fileCrc = $this->crc16($headerWithCrc.$body);

        return $headerWithCrc.$body.pack('v', $fileCrc);
    }

    public function supportsEncoding(string $format): bool
    {
        return 'fit' === $format;
    }

    private function buildHeader(int $dataSize): string
    {
        return pack(
            'CCvVa4',
            self::FIT_HEADER_SIZE,        // header size (14)
            self::PROTOCOL_VERSION,       // protocol version
            self::PROFILE_VERSION,        // profile version
            $dataSize,                    // data size
            '.FIT',                       // data type
        ).pack('v', 0);                   // placeholder for header CRC
    }

    private function writeFileIdMessage(int $localMesg): string
    {
        // Definition
        $fields = [
            $this->fieldDef(0, 1, self::FIT_ENUM),    // type (enum)
            $this->fieldDef(1, 2, self::FIT_UINT16),   // manufacturer
            $this->fieldDef(2, 2, self::FIT_UINT16),   // product
        ];

        $def = $this->writeDefinition($localMesg, self::MESG_FILE_ID, $fields);

        // Data
        $data = pack('C', $localMesg & 0x0F); // record header
        $data .= pack('C', self::FILE_COURSE);
        $data .= pack('v', self::MANUFACTURER_DEVELOPMENT);
        $data .= pack('v', self::PRODUCT_GENERIC);

        return $def.$data;
    }

    private function writeCourseMessage(int $localMesg, string $name): string
    {
        $nameBytes = substr($name, 0, 15);
        $nameLen = \strlen($nameBytes) + 1; // null-terminated

        // Definition
        $fields = [
            $this->fieldDef(5, $nameLen, self::FIT_STRING),  // name
            $this->fieldDef(4, 1, self::FIT_ENUM),            // sport
        ];

        $def = $this->writeDefinition($localMesg, self::MESG_COURSE, $fields);

        // Data
        $data = pack('C', $localMesg & 0x0F);
        $data .= str_pad($nameBytes."\0", $nameLen, "\0");
        $data .= pack('C', self::SPORT_CYCLING);

        return $def.$data;
    }

    private function writeEventMessage(int $localMesg, int $eventType): string
    {
        // Definition
        $fields = [
            $this->fieldDef(0, 1, self::FIT_ENUM),   // event
            $this->fieldDef(1, 1, self::FIT_ENUM),   // event_type
        ];

        $def = $this->writeDefinition($localMesg, self::MESG_EVENT, $fields);

        // Data
        $data = pack('C', $localMesg & 0x0F);
        $data .= pack('C', self::EVENT_TIMER);
        $data .= pack('C', $eventType);

        return $def.$data;
    }

    private function writeRecordDefinition(int $localMesg): string
    {
        $fields = [
            $this->fieldDef(0, 4, self::FIT_SINT32),  // position_lat (semicircles)
            $this->fieldDef(1, 4, self::FIT_SINT32),  // position_long (semicircles)
            $this->fieldDef(2, 2, self::FIT_UINT16),  // altitude (offset 500, scale 5)
            $this->fieldDef(5, 4, self::FIT_UINT32),  // distance (scale 100, in cm)
        ];

        return $this->writeDefinition($localMesg, self::MESG_RECORD, $fields);
    }

    /**
     * @param array{lat: float, lon: float, ele: float} $point
     */
    private function writeRecordData(int $localMesg, array $point, float $cumulativeDistance): string
    {
        $data = pack('C', $localMesg & 0x0F);
        $data .= pack('V', $this->toSemicircles($point['lat']));
        $data .= pack('V', $this->toSemicircles($point['lon']));
        $data .= pack('v', (int) round(($point['ele'] + 500.0) * 5.0)); // altitude with offset and scale
        $data .= pack('V', (int) round($cumulativeDistance * 100000.0)); // distance in cm (from km)

        return $data;
    }

    private function writeCoursePointDefinition(int $localMesg): string
    {
        $fields = [
            $this->fieldDef(1, 4, self::FIT_SINT32),  // position_lat
            $this->fieldDef(2, 4, self::FIT_SINT32),  // position_long
            $this->fieldDef(5, 16, self::FIT_STRING),  // name (16 bytes max)
            $this->fieldDef(6, 1, self::FIT_ENUM),     // type (course_point_type)
        ];

        return $this->writeDefinition($localMesg, self::MESG_COURSE_POINT, $fields);
    }

    /**
     * @param array{lat: float, lon: float, name: string, type: string} $waypoint
     */
    private function writeCoursePointData(int $localMesg, array $waypoint): string
    {
        $data = pack('C', $localMesg & 0x0F);
        $data .= pack('V', $this->toSemicircles($waypoint['lat']));
        $data .= pack('V', $this->toSemicircles($waypoint['lon']));
        $data .= str_pad(substr($waypoint['name'], 0, 15)."\0", 16, "\0");

        return $data.pack('C', WaypointMapper::fitCoursePointType($waypoint['type']));
    }

    /**
     * @param array{lat: float, lon: float, ele: float} $start
     * @param array{lat: float, lon: float, ele: float} $end
     */
    private function writeLapMessage(int $localMesg, array $start, array $end, float $totalDistance): string
    {
        $fields = [
            $this->fieldDef(3, 4, self::FIT_SINT32),  // start_position_lat
            $this->fieldDef(4, 4, self::FIT_SINT32),  // start_position_long
            $this->fieldDef(5, 4, self::FIT_SINT32),  // end_position_lat
            $this->fieldDef(6, 4, self::FIT_SINT32),  // end_position_long
            $this->fieldDef(9, 4, self::FIT_UINT32),  // total_distance (scale 100)
        ];

        $def = $this->writeDefinition($localMesg, self::MESG_LAP, $fields);

        $data = pack('C', $localMesg & 0x0F);
        $data .= pack('V', $this->toSemicircles($start['lat']));
        $data .= pack('V', $this->toSemicircles($start['lon']));
        $data .= pack('V', $this->toSemicircles($end['lat']));
        $data .= pack('V', $this->toSemicircles($end['lon']));
        $data .= pack('V', (int) round($totalDistance * 100000.0));

        return $def.$data;
    }

    /**
     * @param list<string> $fields Each from fieldDef()
     */
    private function writeDefinition(int $localMesg, int $globalMesgNum, array $fields): string
    {
        $header = pack('C', 0x40 | ($localMesg & 0x0F)); // definition record header
        $header .= pack('C', 0);         // reserved
        $header .= pack('C', 0);         // architecture (0 = little-endian)
        $header .= pack('v', $globalMesgNum);
        $header .= pack('C', \count($fields));

        return $header.implode('', $fields);
    }

    private function fieldDef(int $fieldNum, int $size, int $baseType): string
    {
        return pack('CCC', $fieldNum, $size, $baseType);
    }

    private function toSemicircles(float $degrees): int
    {
        return (int) round($degrees / 180.0 * self::SEMICIRCLES_PER_180_DEGREES);
    }

    private function crc16(string $data): int
    {
        $crc = 0;
        $crcTable = $this->buildCrcTable();

        for ($i = 0, $len = \strlen($data); $i < $len; ++$i) {
            $byte = \ord($data[$i]);
            $crc = ($crc >> 8) ^ $crcTable[($crc ^ $byte) & 0xFF];
        }

        return $crc & 0xFFFF;
    }

    /**
     * @return list<int>
     */
    private function buildCrcTable(): array
    {
        /** @var list<int>|null $table */
        static $table = null;

        if (null !== $table) {
            return $table;
        }

        $table = [];
        for ($i = 0; $i < 256; ++$i) {
            $crc = $i;
            for ($j = 0; $j < 8; ++$j) {
                $crc = (($crc & 1) !== 0) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
            }

            $table[] = $crc;
        }

        return $table;
    }
}
