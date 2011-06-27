<?php

require_once 'PHPUnit/Extensions/SeleniumTestCase.php';

class Common extends PHPUnit_Extensions_SeleniumTestCase {

	public function doLogin() {
		$this->open(SERVER_PATH);
		$this->type('username', 'admin');
		$this->type('password', 'admin');
		$this->click('authenticate');
		$this->waitForPageToLoad("30000");
	}

}

?>
