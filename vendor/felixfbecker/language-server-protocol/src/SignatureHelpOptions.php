<?php

namespace LanguageServerProtocol;

use JsonSerializable;

/**
 * Signature help options.
 */
class SignatureHelpOptions implements JsonSerializable
{
    /**
     * The characters that trigger signature help automatically.
     *
     * @var string[]|null
     */
    public $triggerCharacters;

    /**
     * @param string[]|null $triggerCharacters
     */
    public function __construct(?array $triggerCharacters = null)
    {
        $this->triggerCharacters = $triggerCharacters;
    }

    /**
     * This is needed because VSCode Does not like nulls
     * meaning if a null is sent then this will not compute
     *
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return array_filter(get_object_vars($this));
    }
}
