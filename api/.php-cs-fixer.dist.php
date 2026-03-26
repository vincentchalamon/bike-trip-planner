<?php

use PhpCsFixer\Finder;
use PhpCsFixer\Config;

$finder = new Finder()
    ->in(__DIR__)
    ->exclude(['var', 'vendor'])
    ->notPath([
        'config/reference.php',
    ])
;

return new Config()
    ->setRules([
        '@Symfony' => true,
        '@PSR12' => true,
    ])
    ->setFinder($finder)
;
