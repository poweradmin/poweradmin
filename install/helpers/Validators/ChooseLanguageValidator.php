<?php

namespace PoweradminInstall\Validators;

use Poweradmin\Application\Service\CsrfTokenService;
use PoweradminInstall\InstallationSteps;
use PoweradminInstall\LocaleHandler;
use Symfony\Component\Validator\Constraints as Assert;

class ChooseLanguageValidator extends AbstractStepValidator
{

    public function validate(): array
    {
        $constraints = new Assert\Collection([
            'install_token' => [
                new Assert\NotBlank(),
                new Assert\Length(['min' => CsrfTokenService::TOKEN_LENGTH, 'max' => CsrfTokenService::TOKEN_LENGTH]),
            ],
            'submit' => [
                new Assert\NotBlank(),
            ],
            'step' => [
                new Assert\NotBlank(),
                new Assert\EqualTo([
                    'value' => InstallationSteps::STEP_GETTING_READY,
                    'message' => 'The step must be equal to ' . InstallationSteps::STEP_GETTING_READY
                ])
            ],
            'language' => [
                new Assert\NotBlank(),
                new Assert\Choice(['choices' => LocaleHandler::getAvailableLanguages()]),
            ]
        ]);

        $input = $this->request->request->all();
        $violations = $this->validator->validate($input, $constraints);

        return ValidationErrorHelper::formatErrors($violations);
    }
}
