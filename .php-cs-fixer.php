<?php

declare(strict_types=1);

use AdamczykPiotr\UltimateFormatter\PhpCsFixer\FixerConfigFactory;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__ . '/config',
        __DIR__ . '/database',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->name('*.php');

return FixerConfigFactory::create($finder, __DIR__ . '/build/.php-cs-fixer.cache');
