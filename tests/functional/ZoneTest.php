<?php

require_once 'PHPUnit/Extensions/SeleniumTestCase.php';
require_once 'common.php';

class ZoneTest extends PHPUnit_Extensions_SeleniumTestCase {

	protected function setUp() {
		$this->setBrowserUrl(BROWSER_URL);
	}

	public function testAddMasterZone() {
		Common::doLogin();
		Common::doAddMasterZone('poweradmin.com');
		$this->verifyTextPresent("poweradmin.com - Zone has been added successfully.");
	}	

	public function testRemoveZone() {
		Common::doLogin();
		Common::doRemoveZone('poweradmin.com');
		$this->verifyTextPresent("Zone has been deleted successfully.");
	}	

} 

?>
