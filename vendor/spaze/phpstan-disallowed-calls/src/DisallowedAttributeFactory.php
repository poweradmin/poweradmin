<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed;

use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\Allowed\AllowedConfigFactory;
use Spaze\PHPStan\Rules\Disallowed\Exceptions\InvalidConfigException;
use Spaze\PHPStan\Rules\Disallowed\Formatter\Formatter;
use Spaze\PHPStan\Rules\Disallowed\Normalizer\Normalizer;

class DisallowedAttributeFactory
{

	private AllowedConfigFactory $allowedConfigFactory;

	private Normalizer $normalizer;

	private Formatter $formatter;


	public function __construct(AllowedConfigFactory $allowedConfigFactory, Normalizer $normalizer, Formatter $formatter)
	{
		$this->allowedConfigFactory = $allowedConfigFactory;
		$this->normalizer = $normalizer;
		$this->formatter = $formatter;
	}


	/**
	 * @param array $config
	 * @phpstan-param DisallowedAttributesConfig $config
	 * @return list<DisallowedAttribute>
	 * @throws ShouldNotHappenException
	 */
	public function createFromConfig(array $config): array
	{
		$disallowedAttributes = [];
		foreach ($config as $disallowed) {
			$attributes = (array)$disallowed['attribute'];
			$excludes = [];
			foreach ((array)($disallowed['exclude'] ?? []) as $exclude) {
				$excludes[] = $this->normalizer->normalizeAttribute($exclude);
			}
			try {
				foreach ($attributes as $attribute) {
					$disallowedAttribute = new DisallowedAttribute(
						$this->normalizer->normalizeAttribute($attribute),
						$excludes,
						$disallowed['message'] ?? null,
						$this->allowedConfigFactory->getConfig($disallowed),
						$disallowed['errorIdentifier'] ?? null,
						$disallowed['errorTip'] ?? []
					);
					$disallowedAttributes[$disallowedAttribute->getAttribute()] = $disallowedAttribute;
				}
			} catch (InvalidConfigException $e) {
				throw new ShouldNotHappenException(sprintf('%s: %s', $this->formatter->formatIdentifier($attributes), $e->getMessage()));
			}
		}
		return array_values($disallowedAttributes);
	}

}
