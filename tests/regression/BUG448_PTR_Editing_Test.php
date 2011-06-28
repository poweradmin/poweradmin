<?php

require_once 'PHPUnit/Extensions/SeleniumTestCase.php';
//require_once 'common.php';

class BUG448_PTR_Editing_Test extends PHPUnit_Extensions_SeleniumTestCase {

	protected function setUp() {
		$this->setBrowserUrl(BROWSER_URL);
	}

	public function testFindZone() {
//		Common::doLogin();
	}	

} 

?>
