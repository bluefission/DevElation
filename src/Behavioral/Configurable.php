<?php
namespace BlueFission\Behavioral;

use BlueFission\DevValue;
use BlueFission\DevArray;
use BlueFission\Behavioral\Behaviors\Behavior;
use BlueFission\Behavioral\Behaviors\Event;
use BlueFission\Behavioral\Behaviors\State;
use BlueFission\Behavioral\Behaviors\Action;

class Configurable extends Scheme implements IConfigurable {
	protected $_config;
	protected $_status;
	
	public function __construct( )
	{
		parent::__construct( );
		if (!isset($this->_config))
			$this->_config = array();
		
		if (!isset($this->_status))
			$this->_status = array();

		$this->dispatch( State::NORMAL );
	}
	
	public function config( $config = null, $value = null )
	{
		if (DevValue::isEmpty($config))
			return $this->_config;
		elseif (is_string($config))
		{
			if (DevValue::isEmpty ($value))
				return isset($this->_config[$config]) ? $this->_config[$config] : null;
						
			if ( ( array_key_exists($config, $this->_config) || $this->is(State::DRAFT) ) && !$this->is(State::READONLY)) {
				$this->_config[$config] = $value; 
			}
		}
		elseif (is_array($config) && !$this->is(State::READONLY))
		{
			$this->perform( State::BUSY );
			if ( $this->is(State::DRAFT) ) {
				foreach ( $config as $a=>$b ) {
					$this->_config[$a] = $config[$a];
				}
			} else {
				foreach ( $this->_config as $a=>$b ) {
					if ( isset($config[$a] )) $this->_config[$a] = $config[$a];
				}
			}
			$this->halt( State::BUSY );
		}
	}
	
	public function status($message = null)
	{
		if (DevValue::isNull($message))
		{
			$message = end($this->_status);
			return $message;
		}
		$this->_status[] = $message;

		$this->perform( Event::MESSAGE );
	}

	public function field( $field, $value = null )
	{
		if ( array_key_exists($field, $this->_data) || $this->is( State::DRAFT ) )
		{	
			$value = parent::field($field, $value);
			return $value;
		}
		else
		{
			return false;
		}
	}

	public function assign( $data )
	{
		if ( is_object( $data ) || DevArray::isAssoc( $data ) ) {
			$this->perform( State::BUSY );
			foreach ( $data as $a=>$b ) {
				$this->field($a, $b);
			}
			$this->halt( State::BUSY );
			$this->dispatch( Event::CHANGE );
		}
		else
			throw new \InvalidArgumentException( "Can't import from variable type " . gettype($data) );
	}

	protected function init()
	{
		parent::init();
		$this->behavior( new Event( Event::MESSAGE ) );
	}
	/*
	*/
}