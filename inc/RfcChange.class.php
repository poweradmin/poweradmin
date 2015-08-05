<?php

class RfcChange
{
    private $zone;
    private $serial;
    private $prior;
    private $after;

    /**
     * RfcChange constructor.
     * @param int $zone The id of the zone / domain
     * @param string $serial The serial based on which the change is valid.
     * @param Record $prior The record before the change.
     * @param Record $after The record after the change.
     */
    public function __construct($zone, $serial, $prior, $after)
    {
        $this->zone = $zone;
        $this->serial = $serial;
        $this->prior = $prior;
        $this->after = $after;
    }
}
