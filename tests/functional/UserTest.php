<?php

require_once 'PHPUnit/Extensions/SeleniumTestCase.php';
require_once 'common.php';

class UserTest extends PHPUnit_Extensions_SeleniumTestCase {

	protected function setUp() {
		$this->setBrowserUrl(BROWSER_URL);
	}

	public function te2stLogin() {
		Common::doLogin();

		$this->verifyTextPresent("Welcome Administrator");
	} 

	public function te1stLogout() {
		Common::doLogin();

		$this->click("link=Logout");
		$this->waitForPageToLoad("30000");
		$this->verifyTextPresent("You have logged out.");
	}	

	public function testChangePassword() {
		Common::doLogin();
		Common::doChangePassword('admin', 'nimda');

		$this->verifyTextPresent("Password has been changed, please login.");

		// restore default password
		// FIXME: use database fixtures
		Common::doLogin('nimda');
		Common::doChangePassword('nimda', 'admin');
	}

} 

?>
