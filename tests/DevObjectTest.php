<?php
namespace BlueFission\Tests;

use BlueFission\DevObject;
 
class DevObjectTest extends \PHPUnit_Framework_TestCase {
 
 	static $classname = 'BlueFission\DevObject';
	
	public function setup()
	{
		$this->object = new static::$classname();
	}

	public function testEvaluatesAsStringUsingType()
	{
		$this->assertEquals(static::$classname, "".$this->object."");
	}

	public function testThrowsErrorOnUndefinedAccess()
	{
		// var_dump($this->object->testValue);
	}

	public function testAddsAndClearsUndefinedFields()
	{
		$this->object->testValue = true;
		$this->assertTrue($this->object->testValue);

		$this->object->clear();
		$this->assertEquals(null, $this->object->testValue);
	}
}