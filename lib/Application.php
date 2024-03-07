<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin;

use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\Application\Service\StatsDisplayService;
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\Domain\SystemMonitoring\MemoryUsage;
use Poweradmin\Domain\SystemMonitoring\Timer;
use Poweradmin\Infrastructure\Configuration\ConfigValidator;
use Poweradmin\Infrastructure\SystemMonitoring\SimpleSizeFormatter;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\PoFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Loader\FilesystemLoader;

class Application
{
    protected Environment $templateRenderer;
    protected LegacyConfiguration $configuration;
    protected ?StatsDisplayService $statsDisplayService = null;

    public function __construct()
    {
        $loader = new FilesystemLoader('templates');
        $this->templateRenderer = new Environment($loader, ['debug' => false]);

        $this->configuration = new LegacyConfiguration();

        if ($this->config('display_stats')) {
            $memoryUsage = new MemoryUsage();
            $timer = new Timer();
            $sizeFormatter = new SimpleSizeFormatter();
            $this->statsDisplayService = new StatsDisplayService($memoryUsage, $timer, $sizeFormatter);
        }

        $config = [
            'iface_rowamount' => $this->configuration->get('iface_rowamount'),
            'syslog_use' => $this->configuration->get('syslog_use'),
            'syslog_ident' => $this->configuration->get('syslog_ident'),
            'syslog_facility' => $this->configuration->get('syslog_facility'),
        ];

        $validator = new ConfigValidator($config);
        $this->showValidationErrors($validator);

        $iface_lang = $this->config('iface_lang');
        if (isset($_SESSION["userlang"])) {
            $iface_lang = $_SESSION["userlang"];
        }

        $translator = new Translator($iface_lang);
        $translator->addLoader('po', new PoFileLoader());
        $translator->addResource('po', $this->getLocaleFile($iface_lang), $iface_lang);

        $this->templateRenderer->addExtension(new TranslationExtension($translator));
    }

    public function render($template, $params = []): void
    {
        try {
            echo $this->templateRenderer->render($template, $params);
        } catch (Error $e) {
            die($e->getMessage());
        }
    }

    public function getConfig(): LegacyConfiguration
    {
        return $this->configuration;
    }

    public function config($name): mixed
    {
        return $this->configuration->get($name);
    }

    public function getLocaleFile(string $iface_lang): string
    {
        if (in_array($iface_lang, ['cs_CZ', 'de_DE', 'fr_FR', 'ja_JP', 'nb_NO', 'nl_NL', 'pl_PL', 'ru_RU', 'tr_TR', 'zh_CN'])) {
            return "locale/$iface_lang/LC_MESSAGES/messages.po";
        }
        return "locale/en_EN/LC_MESSAGES/en.po";
    }

    public function showValidationErrors(ConfigValidator $validator): void
    {
        if (!$validator->validate()) {
            $errors = $validator->getErrors();
            foreach ($errors as $error) {
                $error = new ErrorMessage("Invalid configuration: $error");
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            exit(1);
        }
    }

    public function displayStats(): string
    {
        if ($this->statsDisplayService !== null) {
            return $this->statsDisplayService->displayStats();
        }
        return '';
    }
}