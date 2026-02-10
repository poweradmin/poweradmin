<?php

$finder = PhpCsFixer\Finder::create()
    ->exclude('vendor')
    ->in(__DIR__);

$config = new PhpCsFixer\Config();
$config->setRules([
    '@PSR1' => true,
    '@Symfony' => true,
    'blank_line_between_import_groups' => false,
    'nullable_type_declaration' => [
        'syntax' => 'question_mark',
    ],
    'nullable_type_declaration_for_default_null_value' => true,
]);
$config->setFinder($finder);
return $config;