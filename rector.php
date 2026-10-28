<?php

declare(strict_types=1);

use AdamczykPiotr\UltimateFormatter\Rector\RectorConfigFactory;

return RectorConfigFactory::create()
    ->withPaths([
        __DIR__ . '/config',
        __DIR__ . '/database',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/database/migrations',
    ]);
