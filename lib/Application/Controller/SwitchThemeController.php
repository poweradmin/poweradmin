<?php

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;

include_once(__DIR__ . '/../../../inc/config-defaults.inc.php');
@include_once(__DIR__ . '/../../../inc/config.inc.php');

class SwitchThemeController extends BaseController
{
    private const DEFAULT_THEME = 'ignite';

    private array $get;
    private array $server;

    public function __construct(array $request)
    {
        // parent::__construct($request); FIXME: this doesn't work

        $this->get = $_GET;
        $this->server = $_SERVER;
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
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie("theme", $selectedTheme, [
            'secure' => $secure,
            'httponly' => true,
        ]);
    }

    private function redirectToPreviousPage(): void
    {
        $previousScriptName = 'index.php';

        if (isset($this->server['HTTP_REFERER'])) {
            $previousScriptUrlParts = parse_url($this->server['HTTP_REFERER']);
            parse_str($previousScriptUrlParts['query'], $previousScriptUrlQueryParts);
            $previousScriptName = $previousScriptUrlQueryParts['page'] === 'switch_theme' ? 'index.php' : 'index.php?page=' . $previousScriptUrlQueryParts['page'];
        }

        $previousScriptName = htmlspecialchars($previousScriptName, ENT_QUOTES, 'UTF-8');

        $this->redirect($previousScriptName);
    }
}
