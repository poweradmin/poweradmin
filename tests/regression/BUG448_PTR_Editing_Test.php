<?php

require_once 'PHPUnit/Extensions/SeleniumTestCase.php';
require_once 'tests/functional/common.php';

class BUG448_PTR_Editing_Test extends PHPUnit_Extensions_SeleniumTestCase {

	protected function setUp() {
		$this->setBrowserUrl(BROWSER_URL);
	}

	public function testPTREditing() {
		Common::doLogin();
		Common::doAddMasterZone('poweradmin.com');

		$this->clickAndWait("link=List zones");	
		$this->clickAndWait("css=img[alt=[ View zone poweradmin.com ]]");
		$this->type('name', '1.0.168.192.in-addr.arpa');
		$this->select('type', 'label=PTR');
		$this->type('content', 'poweradmin.com');
		$this->clickAndWait("//input[@name='commit' and @value='Add record']");
		$this->verifyTextPresent("The record was successfully added.");

		$this->clickAndWait("link=List zones");
		$this->clickAndWait("css=img[alt=[ View zone poweradmin.com ]]");
		$this->verifyValue("record[2][name]", "1.0.168.192.in-addr.arpa");

		Common::doRemoveZone('poweradmin.com');
	}	
} 

?>
