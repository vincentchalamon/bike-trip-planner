<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Set\ValueObject\SetList;

$rector = RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/public',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withRootFiles()
    ->withPhpSets(
        php85: true,
    )
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::CODING_STYLE,
        SetList::DEAD_CODE,
        SetList::INSTANCEOF,
        SetList::PRIVATIZATION,
        SetList::TYPE_DECLARATION,
        PHPUnitSetList::PHPUNIT_120,
    ])
    ->withAttributesSets()
    ->withImportNames(removeUnusedImports: true)
    ->withComposerBased(phpunit: true, symfony: true)
;

if (is_file(__DIR__.'/var/cache/dev/App_KernelDevDebugContainer.php')) {
    $rector->withSymfonyContainerPhp(__DIR__.'/var/cache/dev/App_KernelDevDebugContainer.php');
} elseif (is_file(__DIR__.'/var/cache/test/App_KernelDevDebugContainer.php')) {
    $rector->withSymfonyContainerPhp(__DIR__.'/var/cache/test/App_KernelDevDebugContainer.php');
}

return $rector;
