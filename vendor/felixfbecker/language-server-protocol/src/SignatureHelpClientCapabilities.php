<?php

namespace LanguageServerProtocol;

use JsonSerializable;

class SignatureHelpClientCapabilities implements JsonSerializable
{
    /**
     * Whether signature help supports dynamic registration.
     *
     * @var bool|null
     */
    public $dynamicRegistration;

    /**
     * The client supports the following `SignatureInformation`
     * specific properties.
     *
     * @var SignatureHelpClientCapabilitiesSignatureInformation|null
     */
    public $signatureInformation;

    /**
     * The client supports to send additional context information for a
     * `textDocument/signatureHelp` request. A client that opts into
     * contextSupport will also support the `retriggerCharacters` on
     * `SignatureHelpOptions`.
     *
     * @since 3.15.0
     *
     * @var bool|null
     */
    public $contextSupport;

    public function __construct(
        ?bool $dynamicRegistration = null,
        ?\LanguageServerProtocol\SignatureHelpClientCapabilitiesSignatureInformation $signatureInformation = null,
        ?bool $contextSupport = null
    ) {
        $this->dynamicRegistration = $dynamicRegistration;
        $this->signatureInformation = $signatureInformation;
        $this->contextSupport = $contextSupport;
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
        return array_filter(get_object_vars($this), function ($v) {
            return $v !== null;
        });
    }
}
