<?php declare(strict_types=1);

namespace Amp\Process;

const BIN_DIR = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'bin';
const IS_WINDOWS = \PHP_OS_FAMILY === 'Windows';

if (!\function_exists(__NAMESPACE__ . '\\escapeArgument')) {
    if (IS_WINDOWS) {
        /**
         * Escapes the command argument for safe inclusion into a Windows command string.
         */
        function escapeArgument(string $arg): string
        {
            return '"' . \preg_replace_callback('(\\\\*("|$))', function (array $m): string {
                return \str_repeat('\\', \strlen($m[0])) . $m[0];
            }, $arg) . '"';
        }
    } else {
        /**
         * Escapes the command argument for safe inclusion into a Posix shell command string.
         */
        function escapeArgument(string $arg): string
        {
            return \escapeshellarg($arg);
        }
    }
}
