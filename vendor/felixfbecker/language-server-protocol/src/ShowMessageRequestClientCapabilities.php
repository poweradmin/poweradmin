<?php

namespace LanguageServerProtocol;

class ShowMessageRequestClientCapabilities
{

    /**
     * Capabilities specific to the `MessageActionItem` type.
     *
     * @var ShowMessageRequestClientCapabilitiesMessageActionItem|null
     */
    public $messageActionItem;


    public function __construct(?\LanguageServerProtocol\ShowMessageRequestClientCapabilitiesMessageActionItem $messageActionItem = null)
    {
        $this->messageActionItem = $messageActionItem;
    }
}
