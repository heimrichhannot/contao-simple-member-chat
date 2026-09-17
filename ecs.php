<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\Basic\BracesPositionFixer;
use PhpCsFixer\Fixer\ClassNotation\FinalClassFixer;
use PhpCsFixer\Fixer\Import\GlobalNamespaceImportFixer;
use PhpCsFixer\Fixer\Import\NoUnusedImportsFixer;
use PhpCsFixer\Fixer\Operator\NotOperatorWithSuccessorSpaceFixer;
use PhpCsFixer\Fixer\Phpdoc\GeneralPhpdocAnnotationRemoveFixer;
use PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer;
use PhpCsFixer\Fixer\Whitespace\MethodChainingIndentationFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([
        __DIR__ . '/config',
        __DIR__ . '/contao',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withRootFiles()
    ->withParallel()
    ->withCache(__DIR__ . '/.ecs_cache')

    ->withRules([
        NoUnusedImportsFixer::class,
        BracesPositionFixer::class,
        DeclareStrictTypesFixer::class,
        FinalClassFixer::class,
    ])
    ->withConfiguredRule(GlobalNamespaceImportFixer::class, [
        'import_classes' => false,
        'import_constants' => false,
        'import_functions' => false,
    ])
    ->withConfiguredRule(GeneralPhpdocAnnotationRemoveFixer::class, [
        'annotations' => ['author', 'package', 'copyright'],
    ])

    ->withPreparedSets(
        psr12: true,
        common: true,
        strict: true,
        arrays: true,
        comments: true,
        docblocks: true,
        spaces: true,
        namespaces: true,
        controlStructures: true,
        phpunit: true,
    )
    ->withPhpCsFixerSets(
        symfony: true,
        symfonyRisky: true,
        php84Migration: true,
    )

    ->withSkip([
        NotOperatorWithSuccessorSpaceFixer::class,
        MethodChainingIndentationFixer::class => [
            '*/DependencyInjection/Configuration.php',
            'src/*Bundle.php',
        ],
        // DCA arrays, config.php and templates follow Contao conventions and are
        // not classes; strictness fixers make no sense there.
        FinalClassFixer::class => [
            'contao/*',
            // Bundle class and Manager plugin must stay extendable by Contao.
            'src/*Bundle.php',
            'src/ContaoManager/*',
        ],
    ]);
