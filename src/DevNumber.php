<?php
namespace BlueFission;

class DevNumber extends DevValue implements IDevValue {
	protected $_type = "double";

	public function __construct( $value = null ) {
		$this->_data = $value;
		if ( $this->_type ) {
			$clone = $this->_data;
			settype($clone, $this->_type);
			$remainder = $clone % 1;
			$this->_type = $remainder ? $this->_type : "int";
			settype($this->_data, $this->_type);
		}
	}

	public function _isValid($allow_zero = true) {
		$number = $this->_data;
		return (is_numeric($number) && ((DevValue::isNotEmpty($number) && $number != 0) || $allow_zero));
	}

	// return the ratio between two values
	public function _percentage($part = 0, $percent = false) {
		$whole = $this->_data;
		if (!DevNumber::isValid($part)) $part = 0;
		if (!DevNumber::isValid($whole)) $whole = 1;
		
		$ratio = $whole/($part * 100);
		
		return $ratio*(($percent) ? 100 : 1);
	}
}