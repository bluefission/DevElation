<?php
namespace BlueFission;

@include_once('Loader.php');
$loader = Loader::instance();
$loader->load('com.bluefission.develation.functions.common');
$loader->load('com.bluefission.develation.DevObject');

class Service extends \DevObject {

	protected $_registrations;
	protected $_routes;

	const LOCAL_LEVEL = 1;
	const SCOPE_LEVEL = 2;
	
	protected $_data = array(
		'name'=>'',
		'arguments'=>'',
		'instance'=>'',
		'type'=>'',
		'scope'=>'',
	);

	public function __construct() 
	{
		parent::__construct();
		$this->_registrations = array();
		$this->scope = $this;
	}

	public function instance()
	{
		if ( isset( $this->instance ) && $this->instance instanceof $this->type ) {
			$service = $this->instance;
		} else {
			$reflection_class = new \ReflectionClass($this->type);
			$args = \dev_value_to_array( $this->arguments );
    		$this->instance = $reflection_class->newInstanceArgs( $args );

			foreach ($this->_registrations as $name=>$registrations) {
				usort($registrations, function ($a, $b) {
					if ($a['priority'] == $b['priority']) {
						return 0;
					}
					return ($a['priority'] < $b['priority']) ? -1 : 1;
				});
				foreach ($registrations as $registration) {
					$level = $registration['level'];
					$handler = $registration['handler'];
					$callback = $handler->callback();

					if ( \is_object($callback) ) {
						$callback = $callback->bindTo($this->scope, $this->instance);
					} elseif (\is_string($callback) && (\strpos($callback, '::') !== false)) {
						$function = \explode('::', $callback);
						$callback = array($this->instance, $function[1]);
					} elseif (\is_array($callback) && count( $callback ) == 2) {
						if ( $this->instance instanceof $callback[0] ) {
							$callback[0] = $this->instance;
						}
					} elseif (\is_string($callback)) {
						$callback = array($this->instance, $callback);
					}

					if ( $level == self::SCOPE_LEVEL && $this->scope instanceof Dispatcher && is_callable( array( $this->scope, 'behavior')) )
					{
						$this->scope->behavior($handler->name(), $callback);
					}
					elseif ( $level == self::LOCAL_LEVEL && $this->instance instanceof Dispatcher && is_callable( array( $this->instance, 'behavior')) )
					{
						$this->instance->behavior($handler->name(), $callback);
						$this->instance->behavior($handler->name(), $this->broadcast);
					}
					else
					{
						$this->behavior($handler->name(), $callback);
					}
					/*
					if ( $this->instance instanceof Dispatcher && is_callable( array( $this->instance, 'behavior'))) {	
						$this->instance->behavior($handler->name(), $callback);
					} else {
						$this->behavior($handler->name(), $callback);
					}
					*/
				}
			}
		}
		return $this->instance;
	}

	public function broadcast( $behavior )
	{
		$this->dispatch( $behavior );
	}

	public function call( $call, $args )
	{
		if ( is_callable(array($this->instance, $call) ) )
		{
			$return = call_user_func_array( array($this->instance, $call), $args );
			return false;
		}
	}

	public function register( $name, $handler, $level = self::LOCAL_LEVEL, $priority = 0 )
	{
		$registration = array('handler'=>$handler, 'level'=>$level, 'priority'=>$priority);
		$this->_registrations[$name][] = $registration;
	}
}