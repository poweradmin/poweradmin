<?php

use Poweradmin\BaseController;

require_once __DIR__ . '/vendor/autoload.php';

include_once('inc/config-defaults.inc.php');
@include_once('inc/config.inc.php');

class SwitchThemeController extends BaseController
{
    private const DEFAULT_THEME = 'ignite';

    private array $get;
    private array $server;

    public function __construct(array $get, array $server)
    {
        // parent::__construct(); FIXME: this doesn't work

        $this->get = $get;
        $this->server = $server;
    }

    public function run(): void
    {
        $selectedTheme = $this->getSelectedTheme();
        $this->setThemeCookie($selectedTheme);
        $this->redirectToPreviousPage();
    }

    private function getSelectedTheme(): string
    {
        $theme = htmlspecialchars($this->get['theme']);
        return $theme === 'spark' ? 'spark' : self::DEFAULT_THEME;
    }

    private function setThemeCookie(string $selectedTheme): void
    {
        setcookie("theme", $selectedTheme, ['HttpOnly' => true]);
    }

    private function redirectToPreviousPage(): void
    {
        $previousScriptName = 'index.php';

        if (isset($this->server['HTTP_REFERER'])) {
            $previousScriptUrl = $this->server['HTTP_REFERER'];
            $parsedScriptName = basename(parse_url($previousScriptUrl, PHP_URL_PATH));
            $previousScriptName = $parsedScriptName !== '' ? $parsedScriptName : $previousScriptName;
        }

        $this->redirect($previousScriptName);
    }
}

$controller = new SwitchThemeController($_GET, $_SERVER);
$controller->run();
