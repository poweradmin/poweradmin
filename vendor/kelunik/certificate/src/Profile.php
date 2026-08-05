<?php declare(strict_types=1);

namespace Kelunik\Certificate;

class Profile
{
    private $commonName;
    private $organizationName;
    private $country;

    public function __construct($commonName, $organizationName, $country)
    {
        $this->commonName = $commonName;
        $this->organizationName = $organizationName;
        $this->country = $country;
    }

    public function getCommonName()
    {
        return $this->commonName;
    }

    public function getOrganizationName()
    {
        return $this->organizationName;
    }

    public function getCountry()
    {
        return $this->country;
    }
}
