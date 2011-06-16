<?php

require_once 'inc/record.inc.php';

class SerialTest extends PHPUnit_Framework_TestCase
{
	public function testGetNextDate() {
		$this->assertEquals(get_next_date(20110526), 20110527);
		$this->assertEquals(get_next_date(20101231), 20110101);
		$this->assertEquals(get_next_date(20110228), 20110301);
	}
	
	public function testGetNextSerial() {
		$this->assertEquals(get_next_serial(0, 20110526), 0);
		$this->assertEquals(get_next_serial(2011052600, 20110526), 2011052601);
		$this->assertEquals(get_next_serial(2011052501, 20110526), 2011052600);
		$this->assertEquals(get_next_serial(2011052705, 20110526), 2011052706);

		$this->assertEquals(get_next_serial(2011052699, 20110526), 2011052700);
		$this->assertEquals(get_next_serial(2011052999, 20110528), 2011053000);
		$this->assertEquals(get_next_serial(2011053199, 20110528), 2011060100);
		$this->assertEquals(get_next_serial(2011123199, 20110528), 2012010100);
	}
}

?>
