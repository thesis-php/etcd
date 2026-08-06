<?php

declare(strict_types=1);

namespace Thesis\Etcd\Internal;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Range::class)]
final readonly class RangeTest
{
    #[DataSet(['/flags/', '/flags0'], 'increments the last byte')]
    #[DataSet(['', "\x00"], 'empty prefix spans the whole key space')]
    #[DataSet(["a\xFF\xFF", 'b'], 'drops trailing 0xFF bytes')]
    #[DataSet(["\xFF\xFF", "\x00"], 'no incrementable byte goes to the end')]
    #[DataSet(['a', 'b'], 'single byte is incremented')]
    #[DataSet(['ab', 'ac'], 'multi-byte increments only the last byte')]
    public function fromPrefix(string $prefix, string $expected): void
    {
        $range = Range::fromPrefix($prefix);

        Assert::same($range->end, $expected);
    }
}
