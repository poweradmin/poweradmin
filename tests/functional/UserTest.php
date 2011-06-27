<?php

require_once 'PHPUnit/Extensions/SeleniumTestCase.php';
require_once 'common.php';

class UserTest extends PHPUnit_Extensions_SeleniumTestCase {

	protected function setUp() {
		$this->setBrowserUrl(BROWSER_URL);
	}

	public function testLogin() {
		Common::doLogin();

		$this->verifyTextPresent("Welcome Administrator");
	} 

	public function testLogout() {
		Common::doLogin();

		$this->click("link=Logout");
		$this->waitForPageToLoad("30000");
		$this->verifyTextPresent("You have logged out.");
	}	

} 

?>
