<?php

require_once 'PHPUnit/Extensions/SeleniumTestCase.php';
require_once 'common.php';

class ZoneTest extends PHPUnit_Extensions_SeleniumTestCase {

	protected function setUp() {
		$this->setBrowserUrl(BROWSER_URL);
	}

	public function testAddMasterZone() {
		Common::doLogin();

		$this->clickAndWait("link=Add master zone");
		$this->type('domain_1', 'poweradmin.com');
		$this->clickAndWait("submit");
		$this->verifyTextPresent("poweradmin.com - Zone has been added successfully.");
	}	

	public function testDeleteZone() {
		Common::doLogin();

		$this->open('/poweradmin/list_zones.php');
		$this->clickAndWait("css=img[alt=[ Delete zone poweradmin.com ]]");
		$this->clickAndWait("css=input.button");
		$this->verifyTextPresent("Zone has been deleted successfully.");
	}	

} 

?>
