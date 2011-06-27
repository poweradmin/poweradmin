<?php

require_once 'PHPUnit/Extensions/SeleniumTestCase.php';

class Common extends PHPUnit_Extensions_SeleniumTestCase {

	public function doLogin($password='admin') {
		$this->open(SERVER_PATH);
		$this->type('username', 'admin');
		$this->type('password', $password);
		$this->click('authenticate');
		$this->waitForPageToLoad("30000");
	}

	public function doChangePassword($old, $new) {
		$this->click("link=Change password");
		$this->waitForPageToLoad("30000");
		$this->type('currentpass', $old);
		$this->type('newpass', $new);
		$this->type('newpass2', $new);
		$this->clickAndWait("submit");
	}
}

?>
