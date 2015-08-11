<?php

class TimeHelper
{
    private $timezone_name = 'Europe/Berlin';
    public $format = 'Y-m-d H:i:s';
    private $regex_parts = array( // Taken from Perl's Regexp::Common::time ('yr4-mo2-dy2 hr2:mi2:sc2')
        '\d{4}', // Y
        '-',
        '(?:(?=[01])(?:0[1-9]|1[012]))', // m
        '-',
        '(?:(?=[0123])(?:0[1-9]|[12]\d|3[01]))', // d
        ' ',
        '(?:(?=[012])(?:[01]\d|2[0123]))', // h
        ':',
        '(?:[0-5]\d)', // i
        ':',
        '(?:(?=[0-6])(?:[0-5]\d|6[01]))', // s
    );

    private $timezone;
    private $regex;

    public function __construct()
    {
        $this->timezone = new DateTimeZone($this->timezone_name);
        $this->regex = implode($this->regex_parts);
    }

    public function now()
    {
        return new DateTimeImmutable('now', $this->timezone);
    }

    /**
     * @param string $interval A DateInterval format string, i.e. 'P1W' (one week).
     * @return DateTimeImmutable A datetime in the past, subtracting $interval from now.
     */
    public function now_minus($interval)
    {
        return $this->now()->sub(new DateInterval($interval));
    }

    public function beginning_of_time()
    {
        return $this->now()->setTimestamp(0);
    }

    public function regex()
    {
        return $this->regex;
    }
}
