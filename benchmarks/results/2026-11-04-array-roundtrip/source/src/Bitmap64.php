<?php

declare(strict_types=1);

namespace RoaringBitmap;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use RoaringBitmap\Exception\NativeBackendUnavailable;
use RoaringBitmap\Internal\Engine64;
use RoaringBitmap\Internal\NativeEngine64;
use RoaringBitmap\Internal\NativeLibrary;
use RoaringBitmap\Internal\PhpEngine64;
use RoaringBitmap\Internal\PortableCodec64;
use RoaringBitmap\Internal\UInt64;
use Traversable;

/**
 * Unsigned 64-bit IDs. Outputs are always canonical decimal strings.
 * Value parameters use mixed so weak PHP callers cannot silently coerce floats.
 *
 * @implements IteratorAggregate<int, string>
 */
final class Bitmap64 implements Countable, IteratorAggregate
{
    public const MAX_VALUE = UInt64::MAX;
    public const END_EXCLUSIVE = UInt64::END;

    private Engine64 $engine;

    /** @param iterable<int|string> $values */
    public function __construct(iterable $values = [], Backend $backend = Backend::Auto, ?string $libraryPath = null)
    {
        if (PHP_INT_SIZE !== 8) {
            throw new \LogicException('RoaringBitmap requires a 64-bit PHP build.');
        }
        $this->engine = self::createEngine($backend, $libraryPath);
        $this->addMany($values);
    }

    public function __clone(): void
    {
        $this->engine = clone $this->engine;
    }

    public function backend(): Backend
    {
        return $this->engine instanceof NativeEngine64 ? Backend::Native : Backend::Php;
    }

    /** @param int|string $value Returns true when membership changes. */
    public function add(mixed $value): bool
    {
        return $this->engine->add(UInt64::parse($value));
    }

    /** @param iterable<int|string> $values Earlier completed batches remain if later input fails. */
    public function addMany(iterable $values): void
    {
        $batch = [];
        foreach ($values as $value) {
            $batch[] = UInt64::parse($value);
            if (count($batch) === 1024) {
                $this->engine->addMany($batch);
                $batch = [];
            }
        }
        $this->engine->addMany($batch);
    }

    /** @param int|string $value */
    public function remove(mixed $value): bool
    {
        return $this->engine->remove(UInt64::parse($value));
    }

    /** @param int|string $value */
    public function contains(mixed $value): bool
    {
        return $this->engine->contains(UInt64::parse($value));
    }

    /** Adds [start, end). END_EXCLUSIVE (2^64) is accepted as an endpoint. @param int|string $start @param int|string $end */
    public function addRange(mixed $start, mixed $end): void
    {
        $first = UInt64::endpoint($start);
        $after = UInt64::endpoint($end);
        if ($first === $after) {
            return;
        }
        if ($first === null || ($after !== null && UInt64::compare($first, $after) > 0)) {
            throw new InvalidArgumentException('Range start must not exceed its end.');
        }
        $this->addClosedBits($first, $after === null ? -1 : UInt64::previous($after));
    }

    /** Adds [start, end], including both endpoints. @param int|string $start @param int|string $end */
    public function addRangeClosed(mixed $start, mixed $end): void
    {
        $this->addClosedBits(UInt64::parse($start), UInt64::parse($end));
    }

    private function addClosedBits(int $start, int $end): void
    {
        $length = UInt64::rangeSize($start, $end);
        $existing = $this->engine->rank($end) - ($start === 0 ? 0 : $this->engine->rank(UInt64::previous($start)));
        if ($length - $existing > PHP_INT_MAX - $this->count()) {
            throw new \OverflowException('Bitmap cardinality cannot exceed PHP_INT_MAX.');
        }
        $this->engine->addRangeClosed($start, $end);
    }

    public function count(): int
    {
        return $this->engine->count();
    }

    public function cardinality(): int
    {
        return $this->count();
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /** Number of IDs <= value. @param int|string $value */
    public function rank(mixed $value): int
    {
        return $this->engine->rank(UInt64::parse($value));
    }

    /** ID at the zero-based position, or null when index >= cardinality. */
    public function select(int $index): ?string
    {
        if ($index < 0) {
            throw new InvalidArgumentException('Select index must be nonnegative.');
        }
        $bits = $this->engine->select($index);

        return $bits === null ? null : UInt64::decimal($bits);
    }

    public function union(self $other): self
    {
        return $this->combine($other, 'union');
    }

    public function intersect(self $other): self
    {
        return $this->combine($other, 'intersect');
    }

    public function difference(self $other): self
    {
        return $this->combine($other, 'difference');
    }

    public function symmetricDifference(self $other): self
    {
        return $this->combine($other, 'xor');
    }

    public function equals(self $other): bool
    {
        return $this->count() === $other->count() && $this->difference($other)->isEmpty();
    }

    /** Sorted snapshot captured immediately when getIterator() is called. */
    public function getIterator(): Traversable
    {
        $snapshot = clone $this->engine;

        return (static function () use ($snapshot): \Generator {
            foreach ($snapshot->values() as $bits) {
                yield UInt64::decimal($bits);
            }
        })();
    }

    /** @return list<string> */
    public function toArray(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    public function toPortableBytes(): string
    {
        return $this->engine->toPortableBytes();
    }

    public static function fromPortableBytes(string $bytes, Backend $backend = Backend::Auto, ?string $libraryPath = null): self
    {
        $result = new self(backend: Backend::Php);
        $validated = PortableCodec64::decode($bytes);
        if ($backend !== Backend::Php) {
            try {
                $result->engine = NativeEngine64::fromValidatedBytes(NativeLibrary::load($libraryPath, true), $bytes);

                return $result;
            } catch (NativeBackendUnavailable $error) {
                if ($backend === Backend::Native) {
                    throw $error;
                }
            }
        }
        $result->engine = $validated;

        return $result;
    }

    public function __serialize(): array
    {
        return ['portable64' => $this->toPortableBytes()];
    }

    public function __unserialize(array $data): void
    {
        if (!isset($data['portable64']) || !is_string($data['portable64'])) {
            throw new \UnexpectedValueException('Missing 64-bit portable bitmap data.');
        }
        $this->engine = self::fromPortableBytes($data['portable64'])->engine;
    }

    private function combine(self $other, string $operation): self
    {
        $result = new self(backend: Backend::Php);
        $result->engine = $this->engine->combine($other->engine, $operation);

        return $result;
    }

    private static function createEngine(Backend $backend, ?string $libraryPath): Engine64
    {
        if ($backend !== Backend::Php) {
            try {
                return new NativeEngine64(NativeLibrary::load($libraryPath, true));
            } catch (NativeBackendUnavailable $error) {
                if ($backend === Backend::Native) {
                    throw $error;
                }
            }
        }

        return new PhpEngine64();
    }
}
