<?php

namespace BlueFission\Data\Queues;

class Queue implements IQueue {
		
	private static $_stack;

	private function __construct() {}
	
	private function __clone() {}

	private static function instance() {
		if(!self::$_stack) self::init();
		return self::$_stack;
	}
	
	private static function init() {
		$stack = new \ArrayObject;
		self::$_stack = $stack;
	}
	
	public static function is_empty($queue) {
		$stack = self::instance();
		$count = isset($stack[$queue]) && count($stack[$queue]);
		return $count ? false : true;
	}

	public static function dequeue($queue, $after_id=false, $till_id=false) {
		$stack = self::instance();

		if($after_id === false && $till_id === false) {
			$item = array_shift( $stack[$queue] );
			return $item;
		} elseif($after_id !== false && $till_id === false) {
			$till_id = count($stack[$queue])-1;
		}
		$items = array_slice ( $stack[$queue], $after_id, $till_id, true );
		return $items;
	}
	
	public static function enqueue($queue, $item) {
		$stack = self::instance();
		$stack[$queue][] = $item;
	}	
}