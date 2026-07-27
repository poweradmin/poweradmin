<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Application\Http;

use Closure;
use Poweradmin\Application\Controller\NotFoundController;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Throwable;

/**
 * Shapes an unhandled throwable into an HTTP response for the front controller.
 *
 * This is the last line of defence in index.php, so handle() must never throw:
 * the two fallible operations it performs, reading configuration and rendering
 * the 404 page, are individually guarded.
 */
final class BootstrapErrorResponder
{
    /**
     * @param ConfigurationInterface $config Configuration, which may itself be
     *                                       the thing that failed to load
     * @param Closure|null $notFoundRenderer Renders the HTML 404 body; defaults
     *                                       to NotFoundController
     */
    public function __construct(
        private readonly ConfigurationInterface $config,
        private readonly ?Closure $notFoundRenderer = null,
    ) {
    }

    public function handle(Throwable $e): void
    {
        $this->log($e);

        if (RequestContext::expectsJsonOnError()) {
            $this->respondJson($e);
            return;
        }

        $this->respondHtml($e);
    }

    /**
     * 404 and 405 are routine routing outcomes rather than defects, so only a
     * genuine failure is worth a stack trace at error level.
     */
    private function log(Throwable $e): void
    {
        error_log($e->getMessage());

        if ($e->getCode() === 404 || $e->getCode() === 405) {
            return;
        }

        error_log($e->getTraceAsString());
    }

    private function respondJson(Throwable $e): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }

        // v2 wraps errors as {success:false,data,message}; v1 keeps its {error:true} contract.
        $isV2Api = RequestContext::isV2ApiRequest();

        if ($e->getCode() === 404) {
            $this->sendStatus(404);
            echo json_encode($isV2Api
                ? ['success' => false, 'data' => null, 'message' => 'Endpoint not found']
                : ['error' => true, 'message' => 'Endpoint not found']);
            return;
        }

        if ($e->getCode() === 405) {
            $this->sendStatus(405);
            echo json_encode($isV2Api
                ? ['success' => false, 'data' => null, 'message' => 'Method not allowed']
                : ['error' => true, 'message' => 'Method not allowed']);
            return;
        }

        $this->sendStatus(500);

        $debug = $this->displayErrors() ? [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString()),
        ] : null;
        $message = $debug === null ? 'Internal server error' : $e->getMessage();

        if ($isV2Api) {
            echo json_encode([
                'success' => false,
                'data' => $debug,
                'message' => $message,
            ]);
            return;
        }

        echo json_encode([
            'error' => true,
            'message' => $message,
            'file' => $debug['file'] ?? null,
            'line' => $debug['line'] ?? null,
            'trace' => $debug['trace'] ?? null
        ]);
    }

    private function respondHtml(Throwable $e): void
    {
        if ($e->getCode() === 404) {
            $this->sendStatus(404);
            $this->renderNotFound();
            return;
        }

        // A rejected method is a routine client error, not a server failure
        $this->sendStatus($e->getCode() === 405 ? 405 : 500);

        if ($this->displayErrors()) {
            $this->renderDebugPage($e);
            return;
        }

        echo 'An error occurred while processing the request.';
    }

    private function renderNotFound(): void
    {
        try {
            ($this->notFoundRenderer ?? static function (): void {
                (new NotFoundController([]))->run();
            })();
        } catch (Throwable) {
            echo 'Page not found.';
        }
    }

    private function renderDebugPage(Throwable $e): void
    {
        echo '<pre>';
        echo 'Error: ' . htmlspecialchars($e->getMessage()) . "\n";
        echo 'File: ' . htmlspecialchars($e->getFile()) . "\n";
        echo 'Line: ' . $e->getLine() . "\n";
        echo 'Trace: ' . "\n" . htmlspecialchars($e->getTraceAsString());
        echo '</pre>';
    }

    /**
     * A throwable raised after the page header was flushed cannot change the status,
     * and header() would only append warnings. Skip those, still emit the body.
     */
    private function sendStatus(int $code): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($code);
    }

    /**
     * Reading configuration can itself fail: when the throwable being shaped came
     * out of ConfigurationManager::initialize(), get() retries the failed
     * initialization and rethrows. Fall back to hiding internals rather than
     * turning the shaped error back into the fatal this class exists to prevent.
     */
    private function displayErrors(): bool
    {
        try {
            return (bool) $this->config->get('misc', 'display_errors', false);
        } catch (Throwable) {
            return false;
        }
    }
}
