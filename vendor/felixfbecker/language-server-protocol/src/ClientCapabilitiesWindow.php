<?php

namespace LanguageServerProtocol;

class ClientCapabilitiesWindow
{

    /**
     * Whether client supports handling progress notifications. If set
     * servers are allowed to report in `workDoneProgress` property in the
     * request specific server capabilities.
     *
     * @since 3.15.0
     *
     * @var boolean|null
     */
    public $workDoneProgress;

    /**
     * Capabilities specific to the showMessage request
     *
     * @since 3.16.0
     *
     * @var ShowMessageRequestClientCapabilities|null
     */
    public $showMessage;

    /**
     * Client capabilities for the show document request.
     *
     * @since 3.16.0
     *
     * @var ShowDocumentClientCapabilities|null
     */
    public $showDocument;


    public function __construct(
        ?bool $workDoneProgress = null,
        ?\LanguageServerProtocol\ShowMessageRequestClientCapabilities $showMessage = null,
        ?\LanguageServerProtocol\ShowDocumentClientCapabilities $showDocument = null
    ) {
        $this->workDoneProgress = $workDoneProgress;
        $this->showMessage = $showMessage;
        $this->showDocument = $showDocument;
    }
}
