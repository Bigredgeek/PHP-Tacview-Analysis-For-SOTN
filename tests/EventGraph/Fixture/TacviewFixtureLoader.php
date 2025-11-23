<?php

declare(strict_types=1);

namespace Tests\EventGraph\Fixture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class TacviewFixtureLoader
{
    /**
     * @return list<string>
     */
    public static function build(string $fixtureName): array
    {
        $fixturePath = self::fixturePath($fixtureName);
        if (!is_file($fixturePath)) {
            throw new RuntimeException('Fixture not found: ' . $fixturePath);
        }

        $data = self::decodeFixture($fixturePath);
        $mission = self::validateMission($data['mission'] ?? null);
        $recordings = $data['recordings'] ?? null;
        if (!is_array($recordings) || $recordings === []) {
            throw new RuntimeException('Fixture must define at least one recording.');
        }

        $outputDir = self::createOutputDir($fixtureName);
        $paths = [];
        foreach ($recordings as $index => $recording) {
            if (!is_array($recording)) {
                continue;
            }

            $xml = self::renderRecording($mission, $recording);
            $file = $outputDir . DIRECTORY_SEPARATOR . sprintf('%s_%d.xml', $fixtureName, (int)$index + 1);
            file_put_contents($file, $xml);
            $paths[] = $file;
        }

        return $paths;
    }

    private static function fixturePath(string $fixtureName): string
    {
        return TEST_FIXTURE_DIR . DIRECTORY_SEPARATOR . $fixtureName . '.json';
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeFixture(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read fixture: ' . $path);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $mission
     * @return array<string, mixed>
     */
    private static function validateMission(?array $mission): array
    {
        if ($mission === null) {
            throw new RuntimeException('Fixture mission metadata missing.');
        }

        foreach (['title', 'category', 'mission_time', 'duration'] as $field) {
            if (!array_key_exists($field, $mission)) {
                throw new RuntimeException('Mission field missing: ' . $field);
            }
        }

        return $mission;
    }

    private static function createOutputDir(string $fixtureName): string
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-tacview-fixtures';
        $dir = $base . DIRECTORY_SEPARATOR . $fixtureName . '-' . bin2hex(random_bytes(4));
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create fixture directory: ' . $dir);
        }

        register_shutdown_function(static function () use ($dir): void {
            self::deleteDirectory($dir);
        });

        return $dir;
    }

    /**
     * @param array<string, mixed> $mission
     * @param array<string, mixed> $recording
     */
    private static function renderRecording(array $mission, array $recording): string
    {
        $author = self::escape((string)($recording['author'] ?? 'Unknown Pilot'));
        $recordingTime = self::escape((string)($recording['recording_time'] ?? $mission['mission_time']));
        $recorder = self::escape((string)($recording['recorder'] ?? ($mission['recorder'] ?? 'UnitTestRecorder 1.0')));
        $source = self::escape((string)($recording['source'] ?? ($mission['source'] ?? 'DCS 2.9')));
        $title = self::escape((string)$mission['title']);
        $category = self::escape((string)$mission['category']);
        $missionTime = self::escape((string)$mission['mission_time']);
        $duration = self::formatFloat((float)$mission['duration']);
        $events = $recording['events'] ?? [];

        if (!is_array($events) || $events === []) {
            throw new RuntimeException('Recording must define at least one event.');
        }

        $eventBlocks = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $eventBlocks[] = self::renderEvent($event);
        }

        $eventXml = implode("\n", $eventBlocks);

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<TacviewDebriefing Version="1.2.6">
    <FlightRecording>
        <Source>{$source}</Source>
        <Recorder>{$recorder}</Recorder>
        <RecordingTime>{$recordingTime}</RecordingTime>
        <Author>{$author}</Author>
    </FlightRecording>
    <Mission>
        <Title>{$title}</Title>
        <Category>{$category}</Category>
        <MissionTime>{$missionTime}</MissionTime>
        <Duration>{$duration}</Duration>
    </Mission>
    <Events>
{$eventXml}
    </Events>
</TacviewDebriefing>
XML;
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function renderEvent(array $event): string
    {
        foreach (['time', 'action', 'primary'] as $required) {
            if (!array_key_exists($required, $event)) {
                throw new RuntimeException('Event field missing: ' . $required);
            }
        }

        $time = self::formatFloat((float)$event['time']);
        $action = self::escape((string)$event['action']);
        $lines = [];
        $lines[] = '        <Event>';
        $lines[] = '            <Time>' . $time . '</Time>';

        if (isset($event['location']) && is_array($event['location'])) {
            $lines[] = '            <Location>';
            foreach (['Longitude', 'Latitude', 'Altitude'] as $axis) {
                if (isset($event['location'][$axis])) {
                    $lines[] = '                <' . $axis . '>' . self::formatFloat((float)$event['location'][$axis]) . '</' . $axis . '>';
                }
            }
            $lines[] = '            </Location>';
        }

        $lines[] = self::renderObject('PrimaryObject', $event['primary'], 3);
        if (isset($event['secondary'])) {
            $lines[] = self::renderObject('SecondaryObject', $event['secondary'], 3);
        }
        if (isset($event['parent'])) {
            $lines[] = self::renderObject('ParentObject', $event['parent'], 3);
        }

        $lines[] = '            <Action>' . $action . '</Action>';
        $lines[] = '        </Event>';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed>|null $object
     */
    private static function renderObject(string $tag, ?array $object, int $indentLevel): string
    {
        if ($object === null) {
            throw new RuntimeException($tag . ' cannot be null in fixture event.');
        }

        $indent = str_repeat('    ', $indentLevel);
        $lines = [];
        $attributes = '';
        if (isset($object['ID'])) {
            $attributes = ' ID="' . self::escape((string)$object['ID']) . '"';
        }
        $lines[] = $indent . '<' . $tag . $attributes . '>';
        foreach ($object as $key => $value) {
            if ($key === 'ID') {
                continue;
            }
            $lines[] = $indent . '    <' . $key . '>' . self::escape((string)$value) . '</' . $key . '>';
        }
        $lines[] = $indent . '</' . $tag . '>';

        return implode("\n", $lines);
    }

    private static function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $path) {
            if ($path->isDir()) {
                @rmdir($path->getPathname());
            } else {
                @unlink($path->getPathname());
            }
        }

        @rmdir($dir);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private static function formatFloat(float $value): string
    {
        return rtrim(rtrim(sprintf('%.5F', $value), '0'), '.');
    }
}
