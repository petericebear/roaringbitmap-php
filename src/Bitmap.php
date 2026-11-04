<?php

declare(strict_types=1);

namespace RoaringBitmap;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use RoaringBitmap\Exception\NativeBackendUnavailable;
use RoaringBitmap\Internal\Engine;
use RoaringBitmap\Internal\NativeEngine;
use RoaringBitmap\Internal\NativeLibrary;
use RoaringBitmap\Internal\PhpEngine;
use RoaringBitmap\Internal\PortableCodec;
use Traversable;

/** @implements IteratorAggregate<int, int> */
final class Bitmap implements Countable, IteratorAggregate
{
    public const MAX_VALUE = 4294967295;

    private Engine $engine;

    /** @param iterable<int> $values */
    public function __construct(
        iterable $values = [],
        Backend $backend = Backend::Auto,
        ?string $libraryPath = null,
    ) {
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
        return $this->engine instanceof NativeEngine ? Backend::Native : Backend::Php;
    }

    /** Returns true if a new value was inserted. */
    public function add(int $value): bool
    {
        self::validateValue($value);

        return $this->engine->add($value);
    }

    /** @param iterable<int> $values Values are consumed in bounded batches. */
    public function addMany(iterable $values): void
    {
        $batch = [];
        foreach ($values as $value) {
            if (!is_int($value)) {
                throw new InvalidArgumentException('Bitmap values must be integers.');
            }
            if ($value < 0 || $value > self::MAX_VALUE) {
                throw new InvalidArgumentException('Bitmap values must be between 0 and 4294967295.');
            }
            $batch[] = $value;
            if (count($batch) === 1024) {
                $this->engine->addMany($batch);
                $batch = [];
            }
        }
        $this->engine->addMany($batch);
    }

    /** Adds the half-open interval [start, end); end may equal 2^32. */
    public function addRange(int $start, int $end): void
    {
        if ($start < 0 || $end < $start || $end > self::MAX_VALUE + 1) {
            throw new InvalidArgumentException('Range must satisfy 0 <= start <= end <= 4294967296.');
        }
        $this->engine->addRange($start, $end);
    }

    /** Returns true if an existing value was removed. */
    public function remove(int $value): bool
    {
        self::validateValue($value);

        return $this->engine->remove($value);
    }

    public function contains(int $value): bool
    {
        self::validateValue($value);

        return $this->engine->contains($value);
    }

    public function count(): int
    {
        return $this->engine->count();
    }

    public function cardinality(): int
    {
        return $this->count();
    }

    /** Number of IDs less than or equal to value, whether or not value is present. */
    public function rank(int $value): int
    {
        self::validateValue($value);

        return $this->engine->rank($value);
    }

    /** ID at the zero-based position, or null when index >= cardinality. */
    public function select(int $index): ?int
    {
        if ($index < 0) {
            throw new InvalidArgumentException('Select index must be nonnegative.');
        }

        return $this->engine->select($index);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
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

    /** Iterates an ascending snapshot, unaffected by later mutations. */
    public function getIterator(): Traversable
    {
        return (clone $this->engine)->values();
    }

    /** @return list<int> Materializes all values in a PHP array. */
    public function toArray(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    public function toPortableBytes(): string
    {
        return $this->engine->toPortableBytes();
    }

    public static function fromPortableBytes(
        string $bytes,
        Backend $backend = Backend::Auto,
        ?string $libraryPath = null,
    ): self {
        $result = new self(backend: Backend::Php);
        // Validate bounds, ordering, offsets, and counts before passing bytes to C.
        $validated = PortableCodec::decode($bytes);
        if ($backend !== Backend::Php) {
            try {
                $result->engine = NativeEngine::fromValidatedBytes(NativeLibrary::load($libraryPath), $bytes);

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

    /** PHP's serialize() uses portable data rather than backend objects or native pointers. */
    public function __serialize(): array
    {
        return ['portable' => $this->toPortableBytes()];
    }

    public function __unserialize(array $data): void
    {
        if (!isset($data['portable']) || !is_string($data['portable'])) {
            throw new \UnexpectedValueException('Missing portable bitmap data.');
        }
        $this->engine = self::fromPortableBytes($data['portable'])->engine;
    }

    private function combine(self $other, string $operation): self
    {
        $result = new self(backend: Backend::Php);
        $result->engine = $this->engine->combine($other->engine, $operation);

        return $result;
    }

    private static function createEngine(Backend $backend, ?string $libraryPath): Engine
    {
        if ($backend !== Backend::Php) {
            try {
                return new NativeEngine(NativeLibrary::load($libraryPath));
            } catch (NativeBackendUnavailable $error) {
                if ($backend === Backend::Native) {
                    throw $error;
                }
            }
        }

        return new PhpEngine();
    }

    private static function validateValue(int $value): void
    {
        if ($value < 0 || $value > self::MAX_VALUE) {
            throw new InvalidArgumentException('Bitmap values must be between 0 and 4294967295.');
        }
    }
}
