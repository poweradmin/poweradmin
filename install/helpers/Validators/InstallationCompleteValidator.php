<?php

namespace PoweradminInstall\Validators;

use Symfony\Component\HttpFoundation\Request;

class InstallationCompleteValidator implements StepValidatorInterface
{

    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function validate(Request $request): array
    {
        return [];
    }
}