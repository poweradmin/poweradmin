<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\UsageFactory;

class NamespaceUsage
{

	private string $namespace;

	private bool $isUseItem;


	public function __construct(string $namespace, bool $isUseItem = false)
	{
		$this->namespace = $namespace;
		$this->isUseItem = $isUseItem;
	}


	public function getNamespace(): string
	{
		return $this->namespace;
	}


	public function isUseItem(): bool
	{
		return $this->isUseItem;
	}

}
