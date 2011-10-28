<?php

require_once 'PHPUnit/Extensions/SeleniumTestCase.php';
require_once 'common.php';

class SearchTest extends PHPUnit_Extensions_SeleniumTestCase {

	protected function setUp() {
		$this->setBrowserUrl(BROWSER_URL);
	}

	public function testFindZone() {
		Common::doLogin();

		Common::doAddMasterZone('poweradmin.com');

		$this->clickAndWait("link=Search zones and records");
		$this->type('css=td > input[name=query]', 'poweradmin.com');
		$this->clickAndWait('submit');
		$this->verifyTextPresent('master');

		Common::doRemoveZone('poweradmin.com');
	}	

	public function testFindZoneWithUnderscorePattern() {
		Common::doLogin();

		Common::doAddMasterZone('poweradmin.com');
		Common::doAddMasterZone('poteradmin.com');

		$this->clickAndWait("link=Search zones and records");
		$this->type('css=td > input[name=query]', 'po_eradmin.com');
		$this->clickAndWait('submit');
		$this->verifyTextPresent('poweradmin.com');
		$this->verifyTextPresent('poteradmin.com');

		Common::doRemoveZone('poteradmin.com');
		Common::doRemoveZone('poweradmin.com');
	}	

	public function testFindZoneWithPercentPattern() {
		Common::doLogin();

		Common::doAddMasterZone('poweradmin.com');
		Common::doAddMasterZone('poteradmin.com');

		$this->clickAndWait("link=Search zones and records");
		$this->type('css=td > input[name=query]', 'po%');
		$this->clickAndWait('submit');
		$this->verifyTextPresent('poweradmin.com');
		$this->verifyTextPresent('poteradmin.com');

		Common::doRemoveZone('poteradmin.com');
		Common::doRemoveZone('poweradmin.com');
	}
} 

?>
