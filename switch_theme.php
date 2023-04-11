<?php

use Poweradmin\BaseController;

require_once __DIR__ . '/vendor/autoload.php';
include_once 'inc/config-me.inc.php';
@include_once('inc/config.inc.php');

require_once 'inc/messages.inc.php';

class SwitchThemeController extends BaseController
{
    private const DEFAULT_THEME = 'ignite';

    private array $get;
    private array $server;

    public function __construct(array $get, array $server)
    {
        $this->get = $get;
        $this->server = $server;
    }

    public function run(): void
    {
        $this->checkTheme();
        $selectedTheme = $this->getSelectedTheme();
        $this->setThemeCookie($selectedTheme);
        $this->redirectToPreviousPage();
    }

    public function checkTheme(): void
    {
        $this->checkCondition(!isset($this->get['theme']), _('No theme selected.'));
    }

    private function getSelectedTheme(): string
    {
        $theme = htmlspecialchars($this->get['theme']);
        return $theme === 'spark' ? 'spark' : self::DEFAULT_THEME;
    }

    private function setThemeCookie(string $selectedTheme): void
    {
        setcookie("theme", $selectedTheme, ['httponly' => true]);
    }

    private function redirectToPreviousPage(): void
    {
        if (isset($this->server['HTTP_REFERER'])) {
            $previousScriptUrl = $this->server['HTTP_REFERER'];
            $previousScriptName = basename(parse_url($previousScriptUrl, PHP_URL_PATH));
            $this->redirect($previousScriptName);
        } else {
            $this->redirect('index.php');
        }
    }
}

$controller = new SwitchThemeController($_GET, $_SERVER);
$controller->run();
