<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 2.3.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Scenario;

use CakephpFixtureFactories\Error\FixtureScenarioException;
use CakephpFixtureFactories\Factory\FactoryAwareTrait;
use Exception;
use PHPUnit\Framework\Exception as PHPUnitException;
use Throwable;

trait ScenarioAwareTrait
{
    use FactoryAwareTrait;

    /**
     * Load a given fixture scenario
     *
     * @param string $scenario Name of the scenario or fully qualified class.
     * @param mixed ...$args Arguments passed to the scenario
     *
     * @throws \CakephpFixtureFactories\Error\FixtureScenarioException if the scenario could not be found.
     *
     * @return mixed
     */
    public function loadFixtureScenario(string $scenario, mixed ...$args): mixed
    {
        if (!class_exists($scenario)) {
            $parts = explode('.', $scenario);
            $scenarioName = (string)array_pop($parts);
            $plugin = $parts !== [] ? array_pop($parts) : null;
            $factoryNamespace = $this->getFactoryNamespace($plugin);
            if (str_ends_with($factoryNamespace, 'Factory')) {
                $factoryNamespace = substr($factoryNamespace, 0, -strlen('Factory'));
            }
            $scenarioNamespace = $factoryNamespace . 'Scenario';
            $scenarioName = str_replace('/', '\\', $scenarioName);
            // Two resolution candidates:
            // 1. `<name>Scenario` — the legacy convention; tried first so
            //    existing scenario classes keep resolving unchanged.
            // 2. `<name>` verbatim — a fallback for Story subclasses (or any
            //    other naming) where the class doesn't follow the legacy suffix.
            $legacy = $scenarioNamespace . '\\' . $scenarioName . 'Scenario';
            $verbatim = $scenarioNamespace . '\\' . $scenarioName;
            if (class_exists($legacy)) {
                $scenario = $legacy;
            } elseif (class_exists($verbatim)) {
                $scenario = $verbatim;
            } else {
                // Neither resolves; pick the legacy form so the eventual
                // FixtureScenarioException references the canonical name.
                $scenario = $legacy;
            }
        }

        try {
            $scenarioClass = new $scenario();
            if ($scenarioClass instanceof FixtureScenarioInterface) {
                return $scenarioClass->load(...$args);
            }

            throw new Exception("`{$scenario}` must implement `" . FixtureScenarioInterface::class . '`');
        } catch (Throwable $e) {
            // PHPUnit framework exceptions (AssertionFailedError,
            // ExpectationFailedException, IncompleteTestError, SkippedTest…)
            // MUST reach the runner untouched: wrapping them in
            // FixtureScenarioException makes assertion failures show as
            // errors instead of failures and breaks expectException / risky-
            // test handling. Use `instanceof` (no `use` import) so this
            // trait does NOT introduce a hard runtime dependency on PHPUnit
            // — `instanceof` against a not-loaded class is `false`, not a
            // fatal, so non-PHPUnit consumers of `src/` stay healthy.
            //
            // PHP `Error` is intentionally NOT passed through: a class-not-
            // found or constructor mismatch on the scenario class itself is a
            // scenario-plumbing problem (the documented FixtureScenarioException
            // case), so those keep wrapping.
            if ($e instanceof PHPUnitException) {
                throw $e;
            }

            // Genuine scenario plumbing problem — class load failure,
            // interface contract violation, scenario-internal RuntimeException
            // etc. Wrap so callers can catch a single domain type while still
            // preserving the original stack trace via $previous.
            throw new FixtureScenarioException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
