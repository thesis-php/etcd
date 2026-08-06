<?php

declare(strict_types=1);

namespace Thesis\Etcd\Internal;

/**
 * @internal
 */
final readonly class Range
{
    /**
     * @param non-empty-string $start
     * @param non-empty-string $end
     */
    public function __construct(
        public string $start,
        public string $end,
    ) {}

    public static function fromPrefix(string $prefix): self
    {
        $start = "\x00";
        $end = $start;

        if ($prefix !== '') {
            $start = $prefix;
            $end = self::computeRangeEnd($prefix);
        }

        return new self($start, $end);
    }

    /**
     * Computes the exclusive range end for a prefix scan.
     *
     * Edge cases:
     * - `'/flags/'`      → `'/flags0'`   (last byte incremented)
     * - `''`             → `"\x00"`      (whole key space)
     * - `"a\xFF\xFF"`    → `'b'`         (trailing `0xFF` bytes dropped)
     * - `"\xFF\xFF"`     → `"\x00"`      (no incrementable byte: to the end)
     *
     * @return non-empty-string
     */
    private static function computeRangeEnd(string $prefix): string
    {
        for ($i = \strlen($prefix) - 1; $i >= 0; --$i) {
            if ($prefix[$i] !== "\xFF") {
                return substr($prefix, 0, $i) . \chr(\ord($prefix[$i]) + 1);
            }
        }

        return "\x00";
    }
}
