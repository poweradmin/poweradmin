<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed;

use Spaze\PHPStan\Rules\Disallowed\Allowed\AllowedConfig;

class DisallowedCall implements DisallowedWithParams
{

	private string $call;

	/** @var list<string> */
	private array $excludes;

	/** @var list<string> */
	private array $definedIn;

	private ?string $message;

	private AllowedConfig $allowedConfig;

	private ?string $errorIdentifier;

	/** @var list<string> */
	private array $errorTip;


	/**
	 * @param string $call
	 * @param list<string> $excludes
	 * @param list<string> $definedIn
	 * @param string|null $message
	 * @param AllowedConfig $allowedConfig
	 * @param string|null $errorIdentifier
	 * @param string|list<string> $errorTip
	 */
	public function __construct(
		string $call,
		array $excludes,
		array $definedIn,
		?string $message,
		AllowedConfig $allowedConfig,
		?string $errorIdentifier,
		$errorTip
	) {
		$this->call = $call;
		$this->excludes = $excludes;
		$this->definedIn = $definedIn;
		$this->message = $message;
		$this->allowedConfig = $allowedConfig;
		$this->errorIdentifier = $errorIdentifier;
		$this->errorTip = (array)$errorTip;
	}


	public function getCall(): string
	{
		return $this->call;
	}


	/**
	 * @return list<string>
	 */
	public function getExcludes(): array
	{
		return $this->excludes;
	}


	/**
	 * @return list<string>
	 */
	public function getDefinedIn(): array
	{
		return $this->definedIn;
	}


	public function getMessage(): ?string
	{
		return $this->message;
	}


	/** @inheritDoc */
	public function getAllowIn(): array
	{
		return $this->allowedConfig->getAllowIn();
	}


	/** @inheritDoc */
	public function getAllowExceptIn(): array
	{
		return $this->allowedConfig->getAllowExceptIn();
	}


	public function getAllowInCalls(): array
	{
		return $this->allowedConfig->getAllowInCalls();
	}


	public function getAllowExceptInCalls(): array
	{
		return $this->allowedConfig->getAllowExceptInCalls();
	}


	public function getAllowInInstanceOf(): array
	{
		return $this->allowedConfig->getAllowInInstanceOf();
	}


	public function getAllowExceptInInstanceOf(): array
	{
		return $this->allowedConfig->getAllowExceptInInstanceOf();
	}


	public function getAllowParamsInAllowed(): array
	{
		return $this->allowedConfig->getAllowParamsInAllowed();
	}


	public function getAllowParamsAnywhere(): array
	{
		return $this->allowedConfig->getAllowParamsAnywhere();
	}


	public function getAllowExceptParamsInAllowed(): array
	{
		return $this->allowedConfig->getAllowExceptParamsInAllowed();
	}


	public function getAllowExceptParams(): array
	{
		return $this->allowedConfig->getAllowExceptParams();
	}


	public function getAllowInCallsWithAttributes(): array
	{
		return $this->allowedConfig->getAllowInCallsWithAttributes();
	}


	public function getAllowExceptInCallsWithAttributes(): array
	{
		return $this->allowedConfig->getAllowExceptInCallsWithAttributes();
	}


	public function getAllowInClassWithAttributes(): array
	{
		return $this->allowedConfig->getAllowInClassWithAttributes();
	}


	public function getAllowExceptInClassWithAttributes(): array
	{
		return $this->allowedConfig->getAllowExceptInClassWithAttributes();
	}


	public function getAllowInClassWithMethodAttributes(): array
	{
		return $this->allowedConfig->getAllowInClassWithMethodAttributes();
	}


	public function getAllowExceptInClassWithMethodAttributes(): array
	{
		return $this->allowedConfig->getAllowExceptInClassWithMethodAttributes();
	}


	public function getErrorIdentifier(): ?string
	{
		return $this->errorIdentifier;
	}


	/**
	 * @return list<string>
	 */
	public function getErrorTip(): array
	{
		return $this->errorTip;
	}


	public function getKey(): string
	{
		// The key consists of "initial" config values that would be overwritten with more specific details in a custom config.
		// `allowIn` & `allowParams*` & few others aren't included because these are set by the user in their config, not in the bundled files.
		return serialize([$this->getCall(), $this->getAllowExceptParams()]);
	}

}
