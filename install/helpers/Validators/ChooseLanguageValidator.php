<?php

namespace PoweradminInstall\Validators;

use PoweradminInstall\InstallationSteps;
use PoweradminInstall\LocaleHandler;
use Symfony\Component\Validator\Constraints as Assert;

class ChooseLanguageValidator extends BaseValidator
{
    public function validate(): array
    {
        // For GET requests (like "Check Again"), we don't need to validate the same constraints
        if ($this->request->isMethod('GET')) {
            // Only validate language for GET requests
            $constraints = new Assert\Collection([
                'language' => [
                    new Assert\NotBlank(),
                    new Assert\Choice(['choices' => LocaleHandler::getAvailableLanguages()]),
                ],
                'step' => [
                    new Assert\NotBlank(),
                ],
            ]);

            $input = $this->request->query->all();
            $violations = $this->validator->validate($input, $constraints);
            return ValidationErrorHelper::formatErrors($violations);
        }

        // For POST requests, use the full validation
        $constraints = new Assert\Collection(array_merge(
            $this->getBaseConstraints(),
            [
                'step' => [
                    new Assert\NotBlank(),
                    new Assert\EqualTo([
                        'value' => InstallationSteps::STEP_CHECK_REQUIREMENTS,
                        'message' => 'The step must be equal to ' . InstallationSteps::STEP_CHECK_REQUIREMENTS
                    ])
                ],
            ]
        ));

        $input = $this->request->request->all();
        $violations = $this->validator->validate($input, $constraints);

        return ValidationErrorHelper::formatErrors($violations);
    }
}
