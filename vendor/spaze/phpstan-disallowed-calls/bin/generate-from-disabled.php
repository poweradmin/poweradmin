#!/usr/bin/env php
<?php
declare(strict_types = 1);

use Nette\Neon\Neon;

$autoloadFiles = [
	__DIR__ . '/../vendor/autoload.php',
	__DIR__ . '/../../../autoload.php',
];

$autoloadLoaded = false;
foreach ($autoloadFiles as $autoloadFile) {
	if (is_file($autoloadFile)) {
		require_once $autoloadFile;
		$autoloadLoaded = true;
		break;
	}
}

if (!$autoloadLoaded) {
	fwrite(STDERR, "Install packages using Composer.\n");
	exit(254);
}

$disallowedCalls = [
	[
		'disallowedFunctionCalls',
		ini_get('disable_functions'),
		'function',
	],
	[
		'disallowedClasses',
		ini_get('disable_classes'),
		'class',
	],
];

$config = [];
foreach ($disallowedCalls as [$section, $disabled, $key]) {
	if ($disabled === false) {
		continue;
	}
	$calls = preg_split('/,/', $disabled, -1, PREG_SPLIT_NO_EMPTY);
	if ($calls === false) {
		continue;
	}
	foreach ($calls as $call) {
		$config[$section][] = [$key => $call];
	}
}

if ($config) {
	echo Neon::encode(['parameters' => $config], true);
} else {
	fwrite(STDERR, "Both 'disable_functions' and 'disable_classes' directives are empty.\n");
	exit(1);
}
