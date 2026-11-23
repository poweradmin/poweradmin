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

namespace TestHelpers;

/**
 * Serves one canned HTTP response from a child PHP process on a free local port.
 */
trait StubHttpServer
{
    /**
     * Serves one canned response from a child PHP process and returns [process, port].
     *
     * @return array{0: resource, 1: int}
     */
    protected function startStubServer(string $response, int $stallSeconds = 0): array
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            $this->markTestSkipped('Cannot open a local socket: ' . $errstr);
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $code = '$s = stream_socket_server("tcp://127.0.0.1:" . $argv[1]);'
            . 'echo "ready\n";'
            . '$c = stream_socket_accept($s, 10);'
            . 'fread($c, 8192);'
            . 'fwrite($c, base64_decode($argv[2]));'
            . 'sleep((int) $argv[3]);';
        $process = proc_open(
            [PHP_BINARY, '-r', $code, (string) $port, base64_encode($response), (string) $stallSeconds],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $this->markTestSkipped('Cannot start the stub server');
        }
        $ready = fgets($pipes[1]);
        if ($ready !== "ready\n") {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $this->fail('Stub server did not start: ' . var_export($ready, true));
        }

        return [$process, $port];
    }

    /**
     * Listens on a free local port without accepting, so a connection that reaches it stays
     * queued and stream_socket_accept($server, 0) reveals it.
     *
     * @return array{0: resource, 1: int}
     */
    protected function listenOnFreePort(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped('Cannot open a local socket: ' . $errstr);
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

        return [$server, $port];
    }
}
