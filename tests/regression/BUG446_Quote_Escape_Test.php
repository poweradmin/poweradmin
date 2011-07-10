<?php

require_once 'PHPUnit/Extensions/SeleniumTestCase.php';
require_once 'tests/functional/common.php';

class BUG446_Quote_Escape_Test extends PHPUnit_Extensions_SeleniumTestCase {

	protected function setUp() {
		$this->setBrowserUrl(BROWSER_URL);
	}

	public function testQuoteEscape() {
		Common::doLogin();
		Common::doAddMasterZone('poweradmin.com');

		$this->clickAndWait("link=List zones");	
		$this->clickAndWait("css=img[alt=[ View zone poweradmin.com ]]");
		$this->select('type', 'label=TXT');
		$this->type('content', "var='value'");
		$this->clickAndWait("//input[@name='commit' and @value='Add record']");

		$this->clickAndWait("link=List zones");
		$this->clickAndWait("css=img[alt=[ View zone poweradmin.com ]]");
		$this->verifyValue("record[2][content]", "var='value'");

		Common::doRemoveZone('poweradmin.com');
	}	
} 

?>
