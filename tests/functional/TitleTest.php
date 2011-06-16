<?php

require_once 'PHPUnit/Extensions/SeleniumTestCase.php';

class TitleTest extends PHPUnit_Extensions_SeleniumTestCase {

	protected function setUp() {
		$this->setBrowserUrl(BROWSER_URL);
	}

	public function testTitle() {
		$this->open(SERVER_PATH);
		$this->assertTitle('Poweradmin');
	} 
} 

?>
