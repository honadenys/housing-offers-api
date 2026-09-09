<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\If_\ObjectExplicitBoolCompareRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/tests',
    ])
    ->withSkip([
        FlipTypeControlToUseExclusiveTypeRector::class,
        ObjectExplicitBoolCompareRector::class,
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withCache(__DIR__.'/storage/framework/cache/rector');
