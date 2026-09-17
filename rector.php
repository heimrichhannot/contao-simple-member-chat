<?php

declare(strict_types=1);

use Contao\Rector\Set\ContaoLevelSetList;
use Contao\Rector\Set\ContaoSetList;
use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/config',
        __DIR__ . '/contao',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withRootFiles()
    ->withParallel()
    ->withCache(__DIR__ . '/.rector_cache')
    ->withPhpVersion(PhpVersion::PHP_84)

    ->withImportNames(
        importShortClasses: false,
        removeUnusedImports: true,
    )
    ->withComposerBased(
        twig: true,
        doctrine: true,
        phpunit: true,
        symfony: true,
    )
    ->withAttributesSets(
        symfony: true,
        doctrine: true,
        phpunit: true,
    )

    ->withSets([
        LevelSetList::UP_TO_PHP_84,
        ContaoLevelSetList::UP_TO_CONTAO_57,
        ContaoSetList::FQCN,
        ContaoSetList::ANNOTATIONS_TO_ATTRIBUTES,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        instanceOf: true,
        phpunitCodeQuality: true,
        symfonyCodeQuality: true,
        symfonyConfigs: true,
    )

    ->withSkip([
        ArrayToFirstClassCallableRector::class,
        // DCA files and config.php are procedural Contao resources.
        __DIR__ . '/contao/dca',
        __DIR__ . '/contao/config',
    ])
;
