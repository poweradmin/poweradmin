<?php

namespace PoweradminInstall\Validators;

use Symfony\Component\HttpFoundation\Request;

class GettingReadyValidator implements StepValidatorInterface
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