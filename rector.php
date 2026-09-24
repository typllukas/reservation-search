<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\FuncCall\SortCallLikeNamedArgsRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools', __DIR__ . '/config', __DIR__ . '/public'])
    ->withRootFiles()
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withImportNames(removeUnusedImports: true)
    ->withSkip([
        // it would add the Elasticsearch HTTP status as the exception code
        ThrowWithPreviousExceptionRector::class,
        // Symfony Flex matches the fully qualified ::class lines; imported names would duplicate each recipe's entry
        __DIR__ . '/config/bundles.php',
    ])
    // the flush rule reads a named argument by name, so its sample has to keep one out of declaration order
    ->withSkip([
        SortCallLikeNamedArgsRector::class => [__DIR__ . '/tools/Architecture/RuleSamples/ReservationUpdateCalls.php'],
    ]);
