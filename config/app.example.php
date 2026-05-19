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
         * Available types: 'faker', 'dummy'
         * - 'faker': Uses fakerphp/faker (requires `fakerphp/faker` package)
         * - 'dummy': Uses johnykvsky/dummygenerator (requires `johnykvsky/dummygenerator` package)
         *
         * Leave this commented out to auto-detect: faker is preferred when
         * both libraries are installed; dummy is used when only DummyGenerator
         * is present. An explicit value here always wins over auto-detect.
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
         * Default: true (setGenerator() is scoped to the current factory instance)
         */
        // 'instanceLevelGenerator' => true,

        /**
         * Controls the FK-in-definition() detector. When enabled (the default),
         * any factory whose definition() returns a column belonging to a
         * belongsTo association emits an E_USER_DEPRECATED naming the column
         * and the association. Foreign-key columns belong to ->with('Alias')
         * / ->for() / factory helpers — never to the scalar default template.
         *
         * Set to false to silence the detector while migrating a legacy test
         * suite. This opt-out is transitional and will be removed in the next
         * major release, when the deprecation graduates to a hard exception.
         *
         * Default: true
         */
        // 'strictDefinition' => true,

        /**
         * When enabled (the default), a belongsTo association composed in
         * configure() is automatically NOT composed for a given build when the
         * caller explicitly provides that association's foreign-key column at
         * the call site — via new(['foo_id' => $x]), setField('foo_id', $x),
         * state(['foo_id' => $x]) or sequence(). The explicitly-set FK then
         * wins instead of being silently overwritten by the composed parent's
         * fresh id, and no throw-away parent row is created (equivalent to an
         * automatic ->without('Alias')).
         *
         * An explicit ->with('Alias', ...) always wins over this auto-skip:
         * the caller clearly asked for composition, so it is never skipped.
         *
         * Set to false to restore the legacy behavior where a configure()-
         * composed parent overrides an explicitly-set foreign key.
         *
         * Default: true
         */
        // 'autoSkipComposeOnExplicitForeignKey' => true,

        /**
         * When enabled, emit an E_USER_WARNING the first time a given
         * factory+association default from configure() is auto-skipped because
         * caller-supplied state explicitly pinned the FK. Useful for finding
         * factories whose default graph is fighting call-site intent or adding
         * hidden overhead.
         *
         * Default: false
         */
        // 'warnOnAutoSkippedConfigureAssociation' => false,

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
