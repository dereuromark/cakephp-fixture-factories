<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 3.1.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Generator;

use BackedEnum;
use Faker\Generator;
use Faker\UniqueGenerator;
use InvalidArgumentException;
use OverflowException;
use ReflectionEnum;
use ReflectionObject;

/**
 * Adapter for Faker's unique generator
 */
class FakerUniqueAdapter implements UniqueGeneratorInterface
{
    /**
     * @var \Faker\UniqueGenerator
     */
    private UniqueGenerator $generator;

    /**
     * Constructor
     *
     * @param \Faker\UniqueGenerator $generator The unique generator
     */
    public function __construct(UniqueGenerator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        // Faker's UniqueGenerator doesn't have a reset method
        // We need to access the protected $uniques property via reflection
        $reflection = new ReflectionObject($this->generator);
        $uniquesProperty = $reflection->getProperty('uniques');
        $uniquesProperty->setValue($this->generator, []);
    }

    /**
     * @inheritDoc
     */
    public function seed(?int $seed = null): void
    {
        // UniqueGenerator doesn't support seeding directly
    }

    /**
     * @inheritDoc
     */
    public function __get(string $property): mixed
    {
        return $this->generator->$property;
    }

    /**
     * @inheritDoc
     */
    public function __call(string $name, array $arguments): mixed
    {
        // Handle enum methods that Faker's unique generator doesn't understand.
        // Picking from a fixed set of enum cases is naturally bounded, so we
        // dedup against the UniqueGenerator's $uniques bucket and stop early
        // once all distinct cases have been emitted.
        if ($name === 'enumValue' && count($arguments) === 1) {
            return $this->pickUniqueEnum('enumValue', $arguments[0], true);
        }

        if ($name === 'enumCase' && count($arguments) === 1) {
            return $this->pickUniqueEnum('enumCase', $arguments[0], false);
        }

        return $this->generator->$name(...$arguments);
    }

    /**
     * Manually dedup enum cases against UniqueGenerator's existing tracking.
     *
     * Reuses the parent UniqueGenerator's underlying \Faker\Generator (so locale,
     * seed, and any custom providers carry through) instead of constructing a
     * fresh FakerAdapter per attempt — that previously dropped seeds and was
     * wasteful in tight unique loops.
     *
     * @param string $key Bucket key in UniqueGenerator::$uniques.
     * @param mixed $enumClass Enum FQCN provided by the caller.
     * @param bool $extractValue Whether to return BackedEnum->value or the case itself.
     *
     * @throws \InvalidArgumentException
     * @throws \OverflowException
     */
    private function pickUniqueEnum(string $key, mixed $enumClass, bool $extractValue): mixed
    {
        if (!is_string($enumClass) || !enum_exists($enumClass)) {
            throw new InvalidArgumentException("Invalid enum class: `$enumClass`");
        }

        if ($extractValue && !(new ReflectionEnum($enumClass))->isBacked()) {
            throw new InvalidArgumentException("Only backed enums are supported: `$enumClass`");
        }

        /** @var array<\UnitEnum> $cases */
        $cases = $enumClass::cases();
        if (!$cases) {
            throw new InvalidArgumentException("Enum has no cases: `$enumClass`");
        }

        $reflection = new ReflectionObject($this->generator);
        $uniquesProperty = $reflection->getProperty('uniques');
        /** @var array<string, array<mixed>> $uniques */
        $uniques = $uniquesProperty->getValue($this->generator);
        $existing = $uniques[$key] ?? [];

        // Naturally bounded: short-circuit once every case has been emitted.
        if (count($existing) >= count($cases)) {
            throw new OverflowException("Unable to generate unique value for `$key`: all enum cases exhausted");
        }

        $underlying = $this->getUnderlyingGenerator();
        $maxRetries = 10000;

        for ($i = 0; $i < $maxRetries; $i++) {
            /** @var \UnitEnum $case */
            $case = $underlying->randomElement($cases);
            $value = $extractValue && $case instanceof BackedEnum ? $case->value : $case;

            if (!in_array($value, $existing, true)) {
                $existing[] = $value;
                $uniques[$key] = $existing;
                $uniquesProperty->setValue($this->generator, $uniques);

                return $value;
            }
        }

        throw new OverflowException("Unable to generate unique value for `$key`");
    }

    private function getUnderlyingGenerator(): Generator
    {
        $reflection = new ReflectionObject($this->generator);
        /** @var \Faker\Generator $generator */
        $generator = $reflection->getProperty('generator')->getValue($this->generator);

        return $generator;
    }

    /**
     * @inheritDoc
     */
    public function unique(): UniqueGeneratorInterface
    {
        // Already unique, return self
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function optional(float $weight = 0.5): OptionalGeneratorInterface
    {
        // unique()->optional() is unusual but supported; reuse the underlying
        // generator so locale + seed flow through.
        return new FakerOptionalAdapter($this->getUnderlyingGenerator()->optional($weight));
    }
}
