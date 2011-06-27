<?php

require_once 'PHPUnit/Extensions/SeleniumTestCase.php';
require_once 'common.php';

class SupermasterTest extends PHPUnit_Extensions_SeleniumTestCase {

	protected function setUp() {
		$this->setBrowserUrl(BROWSER_URL);
	}

	public function testAddSupermaster() {
		Common::doLogin();

		$this->clickAndWait("link=Add supermaster");
		$this->type('master_ip', '127.0.0.1');
		$this->type('ns_name', 'sm.poweradmin.com');
		$this->clickAndWait("submit");
		$this->verifyTextPresent("The supermaster has been added successfully.");
	}

	public function testListSupermasters() {
		Common::doLogin();

		$this->clickAndWait("link=List supermasters");
		$this->verifyTextPresent("sm.poweradmin.com");
	}

	public function testDeleteSupermaster() {
		Common::doLogin();

		$this->clickAndWait("link=List supermasters");
		$this->clickAndWait("css=img[alt=[  Delete supermaster ]]");
		$this->clickAndWait("css=input.button");
		$this->verifyTextPresent("The supermaster has been deleted successfully.");
	}	

} 

?>
