<?php declare(strict_types=1);

namespace Amp\Process;

const BIN_DIR = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'bin';
const IS_WINDOWS = \PHP_OS_FAMILY === 'Windows';

if (!\function_exists(__NAMESPACE__ . '\\escapeArgument')) {
    if (IS_WINDOWS) {
        /**
         * Escapes the command argument for safe inclusion into a Windows command string used with proc_open()
         * and the option bypass_shell=true.
         */
        function escapeArgument(string $arg): string
        {
            return '"' . \preg_replace_callback('(\\\\*("|$))', function (array $m): string {
                return \str_repeat('\\', \strlen($m[0])) . $m[0];
            }, $arg) . '"';
        }
    } else {
        /**
         * Escapes the command argument for safe inclusion into a Posix shell command string used with proc_open().
         */
        function escapeArgument(string $arg): string
        {
            return \escapeshellarg($arg);
        }
    }

    /**
     * Returns the name for the signal number if defined.
     * ext-pcntl is required, otherwise null is always returned.
     *
     * @return non-empty-string|null
     */
    function getSignalName(int $signal): ?string
    {
        /** @var array<int, non-empty-string>|null $signalNameMap */
        static $signalNameMap = null;

        if (!\extension_loaded('pcntl')) {
            return null;
        }

        if ($signalNameMap === null) {
            $skippedNames = [
                'SIGBABY', // PHP inside-joke
                'SIGIOT', // Alias for SIGABRT
            ];

            /** @var array<non-empty-string, int> $constantMap */
            $constantMap = \array_filter(
                (new \ReflectionExtension('pcntl'))->getConstants(),
                fn (string $name) => \preg_match('[^SIG[A-Z0-9]+$]', $name) && !\in_array($name, $skippedNames, true),
                \ARRAY_FILTER_USE_KEY,
            );

            $signalNameMap = \array_flip($constantMap);
            \ksort($signalNameMap);
        }

        return $signalNameMap[$signal] ?? null;
    }
}
