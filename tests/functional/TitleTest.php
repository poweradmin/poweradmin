<?php

require_once 'PHPUnit/Extensions/SeleniumTestCase.php';

class TitleTest extends PHPUnit_Extensions_SeleniumTestCase {

	protected function setUp() {
		$this->setBrowser('*firefox');
		$this->setBrowserUrl('http://127.0.0.1/');
	}

	public function testLogin() {
		$this->open("poweradmin/");
		$this->assertTitle('Poweradmin');
	} 
} 

?>
