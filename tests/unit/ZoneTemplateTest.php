<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneTemplate;

class ZoneTemplateTest extends TestCase
{
    public function testReplaceWithTemplatePlaceholders()
    {
        $domain = 'example.com';
        $record = [
            'name' => 'www.example.com',
            'content' => '100.100.100.100'
        ];

        $expected = ['www.[ZONE]', '100.100.100.100'];
        $result = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        $this->assertEquals($expected, $result);
    }

    public function testReplaceWithTemplatePlaceholdersNoMatch()
    {
        $domain = 'example.com';
        $record = [
            'name' => 'otherdomain.com',
            'content' => '300.300.300.300'
        ];

        $expected = ['otherdomain.com', '300.300.300.300'];
        $result = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        $this->assertEquals($expected, $result);
    }

    public function testReplaceWithTemplatePlaceholdersEmptyDomain()
    {
        $domain = '';
        $record = [
            'name' => 'example.com',
            'content' => '400.400.400.400'
        ];

        $expected = ['example.com', '400.400.400.400'];
        $result = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        $this->assertEquals($expected, $result);
    }

    public function testReplaceWithTemplatePlaceholdersEmptyRecord()
    {
        $domain = 'example.com';
        $record = [
            'name' => '',
            'content' => ''
        ];

        $expected = ['', ''];
        $result = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        $this->assertEquals($expected, $result);
    }

    public function testReplaceWithTemplatePlaceholdersSOARecord()
    {
        $domain = 'example.com';
        $record = [
            'name' => 'example.com',
            'content' => 'ns1.example.com hostmaster.example.com 2023101001 3600 1800 1209600 3600',
            'type' => 'SOA'
        ];
        $options = [
            'NS1' => 'ns1.example.com',
            'HOSTMASTER' => 'hostmaster.example.com'
        ];

        $expected = ['[ZONE]', '[NS1] [HOSTMASTER] [SERIAL] 3600 1800 1209600 3600'];
        $result = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record, $options);

        $this->assertEquals($expected, $result);
    }

    public function testReplaceWithTemplatePlaceholdersSOAWithoutOptions()
    {
        $domain = 'example.com';
        $record = [
            'name' => 'example.com',
            'content' => 'ns1.example.com hostmaster.example.com 2023010101 3600 1800 1209600 3600',
            'type' => 'SOA'
        ];
        $options = [];

        $expected = [
            '[ZONE]',
            'ns1.example.com hostmaster.example.com [SERIAL] 3600 1800 1209600 3600'
        ];

        $this->assertEquals($expected, ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record, $options));
    }

    public function testReplaceWithTemplatePlaceholdersSOAWithPartialOptions()
    {
        $domain = 'example.com';
        $record = [
            'name' => 'example.com',
            'content' => 'ns1.example.com hostmaster.example.com 2023010101 3600 1800 1209600 3600',
            'type' => 'SOA'
        ];
        $options = [
            'NS1' => 'ns1.example.com'
        ];

        $expected = [
            '[ZONE]',
            '[NS1] hostmaster.example.com [SERIAL] 3600 1800 1209600 3600'
        ];

        $this->assertEquals($expected, ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record, $options));
    }

    public function testReplaceWithTemplatePlaceholdersSOAWithDifferentDomain()
    {
        $domain = 'anotherdomain.com';
        $record = [
            'name' => 'example.com',
            'content' => 'ns1.sample.com hostmaster.sample.com 2023010101 3600 1800 1209600 3600',
            'type' => 'SOA'
        ];
        $options = [
            'NS1' => 'ns1.example.com',
            'HOSTMASTER' => 'hostmaster.example.com'
        ];

        $expected = [
            'example.com',
            'ns1.sample.com hostmaster.sample.com [SERIAL] 3600 1800 1209600 3600'
        ];

        $this->assertEquals($expected, ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record, $options));
    }
}
