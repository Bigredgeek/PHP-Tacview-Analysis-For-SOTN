<?php

declare(strict_types=1);

namespace EventGraph;

use function array_filter;
use function array_map;
use function explode;
use function implode;
use function preg_match;
use function preg_replace;
use function str_contains;
use function str_replace;
use function strtolower;
use function trim;

final class ObjectIdentity
{
    public const TIER_PILOT = 'pilot';
    public const TIER_ID = 'id';
    public const TIER_FALLBACK = 'fallback';

    private function __construct(
        private readonly string $key,
        private readonly string $tier,
        private readonly bool $hasPilot,
    ) {
    }

    public static function forObject(?array $object): ?self
    {
        if ($object === null) {
            return null;
        }

        $pilotKey = self::buildPilotKey($object);
        if ($pilotKey !== null) {
            return new self($pilotKey, self::TIER_PILOT, true);
        }

        $idKey = self::buildIdKey($object);
        if ($idKey !== null) {
            return new self($idKey, self::TIER_ID, false);
        }

        $fallbackKey = self::buildFallbackKey($object);
        if ($fallbackKey !== null) {
            return new self($fallbackKey, self::TIER_FALLBACK, false);
        }

        return null;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTier(): string
    {
        return $this->tier;
    }

    public function hasPilot(): bool
    {
        return $this->hasPilot;
    }

    public function isHardSignal(): bool
    {
        return $this->tier === self::TIER_PILOT || $this->tier === self::TIER_ID;
    }

    public static function buildFallbackSignature(?array $object): ?string
    {
        if ($object === null) {
            return null;
        }

        $type = isset($object['Type']) ? self::slug($object['Type']) : '';
        $name = isset($object['Name']) ? self::slug($object['Name']) : '';
        $coalition = isset($object['Coalition']) ? self::slug($object['Coalition']) : '';
        $country = isset($object['Country']) ? self::slug($object['Country']) : '';

        if ($type === '' && $name === '' && $coalition === '' && $country === '') {
            return null;
        }

        return implode('|', array_filter([
            $type === '' ? null : 'type:' . $type,
            $name === '' ? null : 'name:' . $name,
            $coalition === '' ? null : 'coalition:' . $coalition,
            $country === '' ? null : 'country:' . $country,
        ]));
    }

    public function isFallback(): bool
    {
        return $this->tier === self::TIER_FALLBACK;
    }

    private static function buildPilotKey(array $object): ?string
    {
        $raw = isset($object['Pilot']) ? trim((string)$object['Pilot']) : '';
        if ($raw === '') {
            return null;
        }

        $segments = array_map(static fn (string $segment): string => trim($segment), explode('|', $raw));
        $segments = array_filter($segments, static fn (string $segment): bool => $segment !== '');
        if ($segments === []) {
            return null;
        }

        $pilotName = strtolower(self::slug(array_pop($segments)));
        $packageSegment = $segments !== [] ? array_shift($segments) : null;

        $package = null;
        $slot = null;
        if ($packageSegment !== null && $packageSegment !== '') {
            if (preg_match('/(\d+-\d+)/', $packageSegment, $matches) === 1) {
                $slot = $matches[1];
                $packageSegment = trim(str_replace($matches[1], '', $packageSegment));
            }
            $package = $packageSegment;
        }

        $parts = [];
        if ($pilotName !== '') {
            $parts[] = 'pilot:' . $pilotName;
        }
        if ($package !== null && $package !== '') {
            $parts[] = 'package:' . self::slug($package);
        }
        if ($slot !== null && $slot !== '') {
            $parts[] = 'slot:' . self::slug($slot);
        }

        if ($parts === []) {
            return null;
        }

        return implode('|', $parts);
    }

    private static function buildIdKey(array $object): ?string
    {
        if (!isset($object['ID'])) {
            return null;
        }

        $id = trim((string)$object['ID']);
        if ($id === '') {
            return null;
        }

        return 'id:' . strtolower($id);
    }

    private static function buildFallbackKey(array $object): ?string
    {
        $signature = self::buildFallbackSignature($object);
        if ($signature === null) {
            return null;
        }

        $group = isset($object['Group']) ? self::slug((string)$object['Group']) : '';
        $type = isset($object['Type']) ? self::slug($object['Type']) : '';

        if ($group !== '' && !self::isAirObject($type)) {
            $signature .= '|group:' . $group;
        }

        return $signature;
    }

    private static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private static function isAirObject(string $type): bool
    {
        if ($type === '') {
            return false;
        }

        return str_contains($type, 'air')
            || str_contains($type, 'helicopter')
            || str_contains($type, 'plane')
            || str_contains($type, 'fighter')
            || str_contains($type, 'bomber');
    }
}
