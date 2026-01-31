<?php

/**
 * CakePHP Fixture Factories configuration example.
 *
 * Add these settings to your application's config/app.php or
 * tests/bootstrap.php to customize fixture factory behavior.
 */
return [
    'FixtureFactories' => [
        /**
         * Generator type to use for generating fake data.
         *
         * Available types: 'faker' (default), 'dummy'
         * - 'faker': Uses fakerphp/faker (requires `fakerphp/faker` package)
         * - 'dummy': Uses johnykvsky/dummygenerator (requires `johnykvsky/dummygenerator` package)
         *
         * @see \CakephpFixtureFactories\Generator\CakeGeneratorFactory
         */
        // 'generatorType' => 'faker',

        /**
         * Default locale for the generator.
         * Falls back to I18n::getLocale() when not set.
         */
        // 'defaultLocale' => 'en_US',

        /**
         * Seed for the generator's random number generator.
         * A fixed seed ensures reproducible test data across runs.
         * Default: 1234
         */
        // 'seed' => 1234,

        /**
         * When enabled, setGenerator() only affects the current factory instance
         * instead of globally changing the generator for all factories.
         *
         * Use setDefaultGenerator() to explicitly set the global default when this is enabled.
         *
         * Default: false (setGenerator() affects all factories globally for BC)
         */
        // 'instanceLevelGenerator' => false,

        /**
         * Namespace where factory classes are located.
         * Default: App\Test\Factory (auto-detected from table registry name)
         */
        // 'testFixtureNamespace' => 'App\\Test\\Factory',

        /**
         * Output directory for baked factory files, relative to tests/.
         * Default: 'Factory/'
         */
        // 'testFixtureOutputDir' => 'Factory/',

        /**
         * Behaviors that should be active during fixture creation.
         * The 'Timestamp' behavior is always included by default.
         */
        // 'testFixtureGlobalBehaviors' => [],

        /**
         * Custom data mapping for the bake command.
         * Maps column names/types to generator methods.
         *
         * @see \CakephpFixtureFactories\Command\BakeFixtureFactoryCommand
         */
        // 'defaultDataMap' => [],

        /**
         * Custom column name patterns for the bake command.
         * Maps regex patterns to generator method calls.
         *
         * Example:
         * 'columnPatterns' => [
         *     '/^phone/' => '$generator->phoneNumber()',
         *     '/^zip/' => '$generator->postcode()',
         * ]
         */
        // 'columnPatterns' => [],
    ],
];
