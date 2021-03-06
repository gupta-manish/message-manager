<?php

class Status extends AppModel {
	public $name = 'Status'; // the status of a message
	public $displayField = 'name';
	public $hasMany = array(
		'Message' => array(
			'className' => 'Message',
			'foreignKey' => 'status',
			'dependent' => false,
		)
	);
	
	// hardcoded status values to save db lookups
	
	public static $STATUS_UNKNOWN      = 0;
	public static $STATUS_AVAILABLE    = 1;
	public static $STATUS_ASSIGNED     = 2;
	public static $STATUS_HIDDEN       = 3;
	public static $STATUS_PENDING      = 4;
	public static $STATUS_SENT         = 5;
	public static $STATUS_ERROR        = 6;
	public static $STATUS_SENT_PENDING = 7;
	public static $STATUS_SENT_OK      = 8;
	public static $STATUS_SENT_FAIL    = 9;
	public static $STATUS_SENT_UNKNOWN = 10;

	// utility method: don't use it, use the constants above
	public static function getIdByName($status_name) {
		$status_id = $this->Status->find('first', array('conditions' => array('Status.name' => $status_name)));
		if (empty($status_id)) {
			return 0; // default 0 is "unknown"
		} else {
			return $status_id;
		}
	}

}