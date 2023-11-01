<?php

namespace Poweradmin\Infrastructure\Logger;

class SyslogLogger implements LoggerInterface
{
    private string $ident;
    private int $facility;

    public function __construct(string $ident = 'poweradmin', int $facility = LOG_USER)
    {
        $this->ident = $ident;
        $this->facility = $facility;

        openlog($this->ident, LOG_PERROR, $this->facility);
    }

    public function info(string $message): void
    {
        syslog(LOG_INFO, $message);
    }

    public function warn(string $message): void
    {
        syslog(LOG_WARNING, $message);
    }

    public function error(string $message): void
    {
        syslog(LOG_ERR, $message);
    }

    public function notice(string $message): void
    {
        syslog(LOG_NOTICE, $message);
    }

    public function __destruct()
    {
        closelog();
    }
}
