<?php

declare(strict_types=1);

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Rector\ClassMethod\FactorySetDefaultTemplateToDefinitionRector;
use CakephpFixtureFactories\Rector\PropertyFetch\GeneratorPropertyToMethodCallRector;
use CakephpFixtureFactories\Rector\StaticCall\FactoryLegacyMakeToNewRector;
use CakephpFixtureFactories\Rector\StaticCall\FactoryStaticQueryRector;
use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;
use Rector\Renaming\ValueObject\MethodCallRename;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->phpstanConfig(__DIR__ . '/phpstan.neon');
    $rectorConfig->disableParallel();

    $rectorConfig->ruleWithConfiguration(RenameMethodRector::class, [
        new MethodCallRename(BaseFactory::class, 'getEntity', 'build'),
        new MethodCallRename(BaseFactory::class, 'getEntities', 'buildMany'),
        new MethodCallRename(BaseFactory::class, 'persistEntity', 'save'),
        new MethodCallRename(BaseFactory::class, 'persistEntities', 'saveMany'),
        new MethodCallRename(BaseFactory::class, 'setTimes', 'count'),
        new MethodCallRename(BaseFactory::class, 'patchData', 'state'),
    ]);

    $rectorConfig->rule(FactoryLegacyMakeToNewRector::class);
    $rectorConfig->rule(FactoryStaticQueryRector::class);
    $rectorConfig->rule(FactorySetDefaultTemplateToDefinitionRector::class);
    $rectorConfig->rule(GeneratorPropertyToMethodCallRector::class);
};
