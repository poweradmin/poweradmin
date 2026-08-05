<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\UsageFactory;

use Spaze\PHPStan\Rules\Disallowed\Normalizer\Normalizer;
use Spaze\PHPStan\Rules\Disallowed\UsageFactory\NamespaceUsage;

class NamespaceUsageFactory
{

	private Normalizer $normalizer;


	public function __construct(Normalizer $normalizer)
	{
		$this->normalizer = $normalizer;
	}


	public function create(string $namespace, bool $isUseItem = false): NamespaceUsage
	{
		return new NamespaceUsage($this->normalizer->normalizeNamespace($namespace), $isUseItem);
	}

}
