<?php

require_once 'PHPUnit/Extensions/SeleniumTestCase.php';

class LoginTest extends PHPUnit_Extensions_SeleniumTestCase {

	protected function setUp() {
		$this->setBrowserUrl(BROWSER_URL);
	}

	public function testLogin() {
		$this->open(SERVER_PATH);
		$this->type('username', 'admin');
		$this->type('password', 'admin');
		$this->click('authenticate');
		$this->waitForPageToLoad("30000");
		$this->verifyTextPresent("Welcome Administrator");
	} 
} 

?>
