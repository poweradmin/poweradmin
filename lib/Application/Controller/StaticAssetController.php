<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

/**
 * Static Asset Controller
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Class StaticAssetController
 *
 * This controller serves static assets like CSS, JS, images, and fonts.
 */
class StaticAssetController extends BaseController
{
    /**
     * Constructor for StaticAssetController
     *
     * @param array $request The request data
     */
    public function __construct(array $request)
    {
        // Disable authentication for static assets
        parent::__construct($request, false);
    }

    /**
     * Serve static assets
     */
    public function run(): void
    {
        $path = $this->pathParameters['path'] ?? '';

        // Security: prevent path traversal attacks
        if (strpos($path, '..') !== false || strpos($path, '\0') !== false) {
            $this->sendNotFound();
            return;
        }

        // Build the absolute file path
        $rootPath = dirname(dirname(dirname(__DIR__)));
        $filePath = $rootPath . '/' . $path;

        // Check if file exists and is readable
        if (!file_exists($filePath) || !is_readable($filePath)) {
            $this->sendNotFound();
            return;
        }

        // Security: ensure the file is within the allowed directories
        $realPath = realpath($filePath);
        $allowedPaths = [
            realpath($rootPath . '/templates'),
            realpath($rootPath . '/vendor/twbs')
        ];

        $isAllowed = false;
        foreach ($allowedPaths as $allowedPath) {
            if ($allowedPath && strpos($realPath, $allowedPath) === 0) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            $this->sendNotFound();
            return;
        }

        // Determine MIME type
        $mimeType = $this->getMimeType($path);

        // Create and send binary file response
        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', $mimeType);

        // Set cache headers for better performance
        $response->setMaxAge(3600); // 1 hour
        $response->setPublic();

        $response->send();
        exit;
    }

    /**
     * Send 404 Not Found response
     */
    private function sendNotFound(): void
    {
        $response = new Response();
        $response->setStatusCode(404);
        $response->headers->set('Content-Type', 'text/plain');
        $response->setContent('Not Found');
        $response->send();
        exit;
    }

    /**
     * Get MIME type for file extension
     *
     * @param string $path File path
     * @return string MIME type
     */
    private function getMimeType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            default => 'application/octet-stream'
        };
    }
}
