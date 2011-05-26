<?php

require_once 'inc/record.inc.php';

class RecordTest extends PHPUnit_Framework_TestCase
{
	public function testGetNextSerial() {
		$this->assertEquals(get_next_serial(0), 0);
		$this->assertEquals(get_next_serial(2011052600, 20110526), 2011052601);
		$this->assertEquals(get_next_serial(2011052699, 20110526), 2011052699);
	}
}

?>