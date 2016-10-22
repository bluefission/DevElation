<?php
namespace BlueFission\Services;

use BlueFission\Behavioral\Programmable;
use BlueFission\Utils\Util;
use BlueFission\Behavioral\Scheme;
use BlueFission\Behavioral\Dispatcher;
use BlueFission\Collections\Collection;
use BlueFission\DevValue;
use BlueFission\DevArray;
use BlueFission\Behavioral\Behaviors\Behavior;
use BlueFission\Behavioral\Behaviors\Event;
use BlueFission\Behavioral\Behaviors\Handler;
use Exception;

class Application extends Programmable {
	static $_instance;

	private $_broadcasted_events = array();
	private $_broadcast_chain = array();
	private $_last_args = null;
	private $_depth = 0;

	protected $_config = array(
		'template'=>'',
		'storage'=>'',
		'name'=>'Application',
	);

	protected $_parameters = array(
		'_method',
		'service',
		'behavior',
		'data',
	);

	private $_context;
	private $_connection;
	private $_storage;
	private $_agent;
	private $_services;
	private $_routes = array();
	private $_arguments = array();

	public function __construct() 
	{
		if ( self::$_instance != null )
			return self::$_instance;

		parent::__construct();
		$this->_services = new Collection();
		$this->_broadcasted_events[$this->name()] = array();

		self::$_instance = $this;
	}

	static function instance()
	{
		if (!isset(self::$_instance)) {
			$c = get_class();

			self::$_instance = new $c;
		}

		return self::$_instance;
	}

	public function params( $params ) {
		$this->_parameters = DevArray::toArray($params);
	
		return $this;
	}

	public function args() {
		global $argv, $argc;

		if ( $argc > 1 ) {
			$this->_arguments[$this->_parameters[0]] = 'console';
			for ( $i = 1; $i <= $argc-1; $i++) {
				$this->_arguments[$this->_parameters[$i]] = $argv[$i];
			}
		} elseif ( count( $_GET ) > 0 || count( $_POST ) > 0 ) {
			$args = $this->_parameters;
			foreach ( $args as $arg ) {
				$this->_arguments[$arg] = Util::value($arg);
			}
		} else {
			$request_parts = explode( '/', $_SERVER['REQUEST_URI'] );
			$parts = array_reverse($request_parts);
		}

		$this->_arguments[$this->_parameters[0]] = (isset($this->_arguments[$this->_parameters[0]])) ? $this->_arguments[$this->_parameters[0]] : strtolower( isset($_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' );
		$this->_arguments[$this->_parameters[1]] = (isset($this->_arguments[$this->_parameters[1]])) ? $this->_arguments[$this->_parameters[1]] : $this->name();

		$this->_arguments[$this->_parameters[2]] = (isset($this->_arguments[$this->_parameters[2]])) ? $this->_arguments[$this->_parameters[2]] : $this->_arguments[$this->_parameters[0]];

		return $this;
	}

	public function run() {
		$args = array_slice($this->_arguments, 1);

		$behavior = $args['behavior'];
		if ( $args['service'] == $this->name() ) {
			$data = $args['data'];
			
			$this->boost($behavior, $data);
		} else {
			if (\is_string($behavior))
				$behavior = new Behavior($behavior);

			$behavior->_context = $args;
			$behavior->_target = $this;
			$args['behavior'] = $behavior;

			call_user_func_array(array($this, 'message'), $args);
		}

		return $this;
	}

	public function boost( $behavior, $args = null ) {
		if (\is_string($behavior)) {
			$behavior = new Behavior($behavior);
		}
		
		$behavior->_context = $args ? $args : $behavior->_context;
		$behavior->_target = $behavior->_target ? $behavior->_target : $this;

		call_user_func_array(array($this, 'broadcast'), array($behavior));
	}

	public function serve( $service, $behavior, $args ) {
		$this->service($service)->perform($behavior, $args);
	}

	public function execute( $behavior, $args = null )
	{
		$this->_last_args = null;
		if ( \is_string($behavior) )
			$behavior = new Behavior( $behavior );

		if ( $behavior instanceof Behavior ) {
			$this->_broadcasted_events[$this->name()] = array($behavior->name());

			$behavior->_context = $args;	
		
			$this->perform($behavior);
		}

		return $this;
	}
	
	public function name( $newname = null )
	{
		return $this->config('name', $newname);
	}

	// Creates a property of the application that is a programmable object
	public function component( $name, $data = null, $configuration = null )
	{	
		$object = null;
		if ( DevValue::isNull($this->$name)) {
			$object = new Programmable();
			$object->config( $configuration );
			if (DevValue::isNotNull($data)) {
				$object->assign( $data );
			}
		}

		return $this->field( $name, $object );
	}

	// Creates a delegate service for the application and registers it
	public function delegate( $name, $reference = null, $args = null )
	{
		$params = func_get_args();
		$args = array_slice( $params, 2 );

		$service = new Service();
		$service->parent($this);
		if ( \is_object($reference) ) {
			$service->instance = $reference;
			$service->type = \get_class($reference);
			$service->scope = $reference;
		} elseif (DevValue::isNotNull($reference) ) {
			$service->type = $reference;	
			$service->scope = $this;
		} else {
			// If type isn't given, creates a programmable object property
			$component = $this->component( $name );
			$component->_parent = $this;
			$service->instance = $component;
			$service->type = \get_class($component);
			$service->scope = $component;
		}
		
		$service->name = $name;
		// $service->scope = $this;
		$service->arguments = $args;

		$this->_services->add( $service, $name );

		return $this;
	}

	// Registers a behavior and a function under a given service, automatically routes it
	public function register( $serviceName, $behavior, $callable, $level = Service::LOCAL_LEVEL, $priority = 0 )
	{
		if (\is_string($behavior))
			$behavior = new Behavior($behavior, $priority);

		if ( $serviceName == $this->name() ) {
			$function_name = uniqid($behavior->name().'_');
			$this->learn($function_name, $callable, $behavior);
			// return $this;
		} elseif ( !$this->_services->has( $serviceName ) ) {
			$this->delegate($serviceName);
		} 

		if ( $serviceName != $this->name() ) {
			$handler = new Handler($behavior, $callable);

			$this->_services[$serviceName]->register($behavior->name(), $handler, $level);
		}

		$this->route($this->name(), $serviceName, $behavior);

		return $this;
	}

	// Configures given behaviors to be routed to given sub-services
	public function route( $senderName, $recipientName, $behavior, $callback = null )
	{
		if (\is_string($behavior))
			$behavior = new Behavior($behavior);

		$handlers = $this->_handlers->get($behavior->name());
		$new_broadcast = true;
		$broadcaster = array($this, 'broadcast');
		foreach ($handlers as $handler) {
			if ($handler->callback() == $broadcaster && $handler->name() == $behavior->name()) {
				$new_broadcast = false;
			}
		}

		if ( $this->name() == $senderName )
		{
			if ($new_broadcast) {
				$this->behavior($behavior, $broadcaster);
			} 
		}
		elseif ( !$this->_services->has( $senderName ) )
		{
			throw new Exception("The service {$senderName} is not registered", 1);
		} elseif ($callback) {
			// echo $senderName ." | ". $behavior . "\n";
			$this->register($senderName, $behavior, array($this, 'boost'));
		}

		if ( !$this->_services->has( $recipientName ) && $this->name() != $recipientName )
		{
			throw new Exception("The service {$recipientName} is not registered", 1);
		}

		$this->_routes[$behavior->name()][$senderName][] = array('recipient'=>$recipientName, 'callback'=>$callback);

		return $this;
	}

	public function service( $serviceName, $call = null )
	{
		if ( !$this->_services->has( $serviceName ) )
			throw new Exception("The service {$serviceName} is not registered", 1);
			
		$service = $this->_services[$serviceName]->instance();
		if ( $call )
		{
			$params = func_get_args();
			$args = array_slice( $params, 2 );

			$response = $this->_services[$serviceName]->call( $call, $args );
			// $response = $service->call( $call, $args );

			// $service->perform(new Event('OnComplete'), $response);
			$this->_services[$serviceName]->dispatch( Event::COMPLETE, $response);
		}

		return $service;
	}

	public function broadcast( $behavior, $args = null )
	{
		if (empty($this->_broadcast_chain)) $this->_broadcast_chain = array("Base");

		if ( !($behavior instanceof Behavior) )
		{
			throw new Exception("Invalid Behavior");
		}

		$behavior->_context = $args ? $args : $behavior->_context;

		// if ( $this->_depth == 0 ) {
			$this->_last_args = $behavior->_context ? $behavior->_context : $this->_last_args;
		// }

		// echo "\nrunning ".$behavior->name()." from ".$behavior->_target->name(). "\n";
		// var_dump($this->_routes);

		$this->_depth++;
		foreach ( $this->_routes as $behaviorName=>$senders )
		{
			if ( $behavior->name() == $behaviorName )
			{
				foreach ( $senders as $senderName=>$recipients )
				{
					if (!isset($this->_broadcasted_events[$senderName])) $this->_broadcasted_events[$senderName] = array();

					if (in_array($behavior->name(), $this->_broadcasted_events[$senderName])) {
						continue;
					}

					foreach ( $recipients as $recipient )
					{
						if ( $behavior->_target->name() == $senderName || 
							( isset($this->_broadcast_chain[$this->_depth-1]) && $this->_broadcast_chain[$this->_depth-1] == $behavior->_target->name()))
						{
							$name = $recipient['callback'] ? $recipient['callback'] : $behavior->name();

							$this->_broadcast_chain[$this->_depth] = $senderName;
							
							$this->_broadcasted_events[$senderName][] = $name;
							// echo "{$recipient['recipient']} - $name\n";
							$this->message( $recipient['recipient'], $behavior, $this->_last_args, $recipient['callback'] );
						}
					}
				}
			}
		}

		$this->_depth--;

		if ( $this->_depth == 0 ) {
			$this->_broadcasted_events = array();
			$this->_broadcast_chain = array();
			$this->_last_args = null;
		}
	}

	private function message( $recipientName, $behavior, $arguments = null, $callback = null )
	{
		if ( $this->name() == $recipientName )
		{
			$recipient = $this;
			$behavior->_context = $arguments;
		} 
		else
		{
			$recipient = $this->_services[$recipientName];
		}

		if (DevValue::isNotNull($callback) && \is_string($callback)) {
			$behavior = new Behavior($callback);
		}

		if ( $recipient instanceof Application ) {
			$recipient->execute($behavior, $arguments);
		} elseif ( $recipient instanceof Service ) {
			// echo "yo, I'm ".$recipient->name()." doing $behavior\n";
			$recipient->message($behavior, $arguments);
		} elseif ( $recipient instanceof Scheme ) {
			$recipient->perform($behavior, $arguments);
		} elseif ( $recipient instanceof Dispatcher ) {
			$recipient->dispatch($behavior, $arguments);
		} else {
			// var_dump($recipientName);
			call_user_func_array(array($recipient, $behavior->name()), array($arguments));
		}
	}
}