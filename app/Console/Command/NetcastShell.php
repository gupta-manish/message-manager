<?php

/*-----------------------------------------------------------------
 * Netcast shell commands:
 *
 * netcast gateway_list
 * netcast gateway_test [source-name/id]
 * netcast get_incoming [source-name/id]
 * netcast send_sms [source-name/id]
 * netcast get_sms_status [source-name/id]
 *
 * Issue with: "cd app & Console/cake netcast gateway_test netcast-gateway"
 * Note that Cake's shells outputs helpful usage info!
 *
 *----------------------------------------------------------------*/
class NetcastShell extends AppShell {
	public $uses = array('MessageSource', 'Message', 'Status', 'Action', 'ActionType');
	
	public static $RETRY_LIMIT = 3;
	public static $NETCAST_MASK = 'FixMyBrgy';  // [sic] note: that's ...Brgy not ...Bgy

	/* suppress output/header message */
	public function startup() {
		$this->out("Running netcast shell for Message Manager", 2, Shell::VERBOSE);
	}
	
	public function getOptionParser() {
		$source_id_arg_def = array(
			'source_id' => array(
				'help' => __('The id or name of the message souce (gateway)'),
				'required' => true
			)
		);
	    $parser = parent::getOptionParser();
		$parser->addSubcommand('gateway_list', array('help' => __('List available sources.')));
		$parser->addSubcommand('gateway_test', array(
			'help' => __('Test the connection to the gateway (like pinging).'),
			'parser' => array('arguments' => $source_id_arg_def)
		));
		$parser->addSubcommand('get_incoming', array(
			'help' => __('Pull down incoming messages from the gateway and load them into the database.'),
			'parser' => array(
			'options' => array(
					'allow-dups' => array(
						'help' => __('Save messages even if they already seem to be in the database (defaults to false, ' .
									'so duplicate messages will be skipped).'), 
						'boolean' => true,
						'short' => 'a',
						'default' => false
					),
					'command' => array(
						'help' => __('Command to use (Netcast allows retrieval from two different mechanisms).'), 
						'short' => 'c',
						'default' => 'getsmart',
						'choices' => array('getsmart', 'getincoming')
					),
				),
				'arguments' => $source_id_arg_def
			)
		));
		$parser->addSubcommand('send_sms', array(
			'help' => __('Send queued outbound messages to the SMS gateway.'),
			'parser' => array(
				'options' => array(
					'force' => array(
						'help' => __('Force send even if message has failed to get through more than %s times', NetCastShell::$RETRY_LIMIT), 
						'boolean' => true,
						'short' => 'f',
						'default' => false
					),
					'dry-run' => array(
						'help' => __('Don\'t send any messages, or update the database: just see what would be sent', NetCastShell::$RETRY_LIMIT), 
						'boolean' => true,
						'short' => 'd',
						'default' => false
					),
				),
				'arguments' => $source_id_arg_def
			)
		));
		$parser->addSubcommand('get_sms_status', array(
			'help' => __('Update status of messages sent to the SMS gateway.'),
			'parser' => array(
				'options' => array(
					'force' => array(
						'help' => __('Force update even if get status has failed more than %s times', NetCastShell::$RETRY_LIMIT), 
						'boolean' => true,
						'short' => 'f',
						'default' => false
					),
					'dry-run' => array(
						'help' => __('Don\'t update the database or contact the gateway: just see what messages would be checked', NetCastShell::$RETRY_LIMIT), 
						'boolean' => true,
						'short' => 'd',
						'default' => false
					),
				),
				'arguments' => $source_id_arg_def
			)
		));
		
	    return $parser;
	}
	
	public function gateway_test() {
		$source = $this->get_message_source($this->args[0]);
		$ms = $source['MessageSource'];
		$this->out(__("Testing connection to message source \"%s\"", $ms['name']), 1, Shell::VERBOSE);
		$this->check_url($ms);
		$netcast = $this->get_netcast_connection($ms);
		$ret_val = $this->call_netcast_function($netcast, "GETCONNECT", array($ms['remote_id']));
		$ret_val = MessageSource::decode_netcast_retval($ret_val);
		$this->out($ret_val, 1, Shell::QUIET);
	}

	public function gateway_list() {
		$sources = $this->MessageSource->find('all', array('fields' => array('id', 'name', 'url')));
		foreach ($sources as $s) {
			$this->out(sprintf("%4s %24s  %s", "id", "name", "url"), 1, Shell::NORMAL);
			$this->out(sprintf("%4s %24s  %s", "----", "------------------------", "---"), 1, Shell::NORMAL);
			$this->out(sprintf("%4s %24s  %s", $s['MessageSource']['id'], $s['MessageSource']['name'], $s['MessageSource']['url']), 1, Shell::QUIET);
		}
		$this->out(__("Done"), 1, Shell::VERBOSE);
	}

	public function get_incoming() {
		$source = $this->get_message_source($this->args[0]);
		$ms = $source['MessageSource'];
		$command = strtoupper($this->params['command']);
		$this->out(__("Getting incoming messages from message source \"%s\" with %s", $ms['name'], $command), 1, Shell::VERBOSE);
		$this->check_url($ms);
		$netcast = $this->get_netcast_connection($ms);
		$ret_val = $this->call_netcast_function($netcast, $command, array($ms['remote_id']));
		$msgs_received = count($ret_val);
		$msgs_skipped = 0;
		$msgs_saved = 0;
		$msgs_failed = 0;
		if (is_array($ret_val)) {
			$this->out(__("Received incoming messages: %s", $msgs_received), 1, Shell::VERBOSE);
			foreach ($ret_val as $msg) {
				$this->out(__("Processing message: [%s] %s", $msg['min'], $msg['msg']), 1, Shell::VERBOSE);
				// checking for existing messages is a wee bit tricky cos the tag might need to be removed *sigh*
				$conditions = array('Message.from_address' => $msg['min']);
				$tag_data = Message::separate_out_tags($msg['msg']);
				foreach ($tag_data as $key => $value) {
					$conditions["Message.$key"] = $value;
				}
				$existing_msg = $this->Message->find('first', array('conditions' => $conditions, 'fields' => array('id')));
				if (! ($this->params['allow-dups'] || empty($existing_msg))) {
					$msgs_skipped++;
					$this->out(__(" * Skipping (message already exists in database with id=%s)", $existing_msg['Message']['id']), 1, Shell::VERBOSE);
				} else {
					$this->Message->create();
					$this->Message->set('from_address', $msg['min']);
					$this->Message->set('message', $msg['msg']);
					$this->Message->set('source_id', $ms['id']);
					$this->Message->set('is_outbound', 0);
					$this->Message->set('status', Status::$STATUS_AVAILABLE);
					if ($this->Message->save()) {
						$msgs_saved++;
						$this->out(__(" * Saved OK"), 1, Shell::VERBOSE);
					} else {
						$msgs_failed++;
						$this->out(__(" * Saved FAILED"), 1, Shell::NORMAL);
					}
				}
			}
			$this->out(__("Incoming messages received: %s, saved: %s, skipped: %s, failed: %s", 
				$msgs_received, $msgs_saved, $msgs_skipped, $msgs_failed), 1, Shell::NORMAL);
			$this->out(__("Done"), 1, Shell::VERBOSE);
		} else {
			$ret_val = MessageSource::decode_netcast_retval($ret_val);
			$this->error("GETINCOMING fail", __("Gateway did not respond with a list: %s", $ret_val));
		}
	}
	
	/*
	 * Netcast's API:
	 * SENDSMS Description: Send an SMS 
	 * message Parameters: 
	 *    Destination mobile no., your message, and your Netcast ID 
	 *    Optional: Custom sender mask
	 */
	public function send_sms() {
		if ($this->params['dry-run']) {
			$this->print_dry_run_notice();
		}
		$source = $this->get_message_source($this->args[0]);
		$ms = $source['MessageSource'];
		$this->out(__("Sending outgoing messages to message source \"%s\"", $ms['name']), 1, Shell::VERBOSE);
		$this->check_url($ms);
		$conditions = array(
			'Message.status' => Status::$STATUS_PENDING,
			'Message.is_outbound' => 1,
			array("NOT" => array(
			        "Message.to_address" => null
			    )
			)
		);
		$out_msgs = $this->Message->find('all', array('conditions' => $conditions));
		$msgs_queued = count($out_msgs);
		$msgs_sent = 0;
		$msgs_failed = 0;
		$msgs_skipped = 0;
		$msgs_sent_unsaved = 0;
		$last_err_msg = "";
		$this->out(__("Messages queued to be sent: %s", $msgs_queued), 1, Shell::VERBOSE);
		if (!empty($out_msgs)) {
			$netcast_mask = NetCastShell::$NETCAST_MASK;
			if (! $this->params['dry-run']) {
				$netcast = $this->get_netcast_connection($ms);
			}
			foreach ($out_msgs as $msg) {
				$qty_retries = $msg['Message']['send_fail_count']+0;
				if (!$this->params['force'] && $qty_retries >= NetCastShell::$RETRY_LIMIT) {
					$msgs_skipped++;
					$this->out(__(" * Skipping message id=%s (%s attempts, cutoff is %s)", 
						$msg['Message']['id'], $qty_retries, NetCastShell::$RETRY_LIMIT), 1, Shell::VERBOSE);
				} else {
					$retry_no = $qty_retries==0? __('this will be first attempt'):__('this will be no. %s', $qty_retries+1);
					$this->out(__("  * Sending message id=%s", $msg['Message']['id']), 1, Shell::VERBOSE);
					$this->out(__("                    to=%s", $msg['Message']['to_address']), 1, Shell::VERBOSE);
					$this->out(__("                    retries=%s (%s)", $qty_retries, $retry_no), 1, Shell::VERBOSE);
					if ($this->params['dry-run']) {
						$msgs_sent++;
						continue;
					}
					$ret_val = $this->call_netcast_function($netcast, "SENDSMS", array(
						$msg['Message']['to_address'], $msg['Message']['message'], $ms['remote_id'], $netcast_mask
					));
					$this->Message->id = $msg['Message']['id'];
					$transaction_id = $ret_val;
					if (preg_match('/^\d+$/', $ret_val)) { // sent OK 'cos we got a numeric transaction id back
						$this->Message->set('status', Status::$STATUS_SENT);
						$this->Message->set('external_id', $transaction_id);
						$this->Message->set('send_fail_count', 0); // reset so we can re-use for get-status
						if ($this->Message->save()) {
							$msgs_sent++;
							$this->out(__("   Sent and updated OK"), 1, Shell::VERBOSE);
							$this->log_action($msg['Message']['id'], __("sent to gateway %s", $ms['name']), $transaction_id);
						} else {
							$msgs_sent_unsaved++;
							$this->out(__("   Sent but update failed"), 1, Shell::NORMAL);
							$this->log_action($msg['Message']['id'], __("sent to gateway %s but status update failed", $ms['name']), $transaction_id);
						}
					} else {
						$msgs_failed++;
						$ret_val = MessageSource::decode_netcast_retval($ret_val);
						$last_err_msg = __("Gateway did not return a transaction id: %s", $ret_val); 
						$this->Message->set('send_fail_count', $msg['Message']['send_fail_count']+1);
						$this->Message->set('send_failed_at', date('Y-m-d H:i:s', time()));
						$this->Message->set('send_fail_reason', $ret_val);
						if ($this->Message->save()) {
							$this->log_action($msg['Message']['id'], __("failed to send to gateway %s", $ms['name']));
						} else {
							$this->out(__("   failed to save (database error)"), 1, Shell::NORMAL);
						}
						$this->out($last_err_msg, 1, Shell::NORMAL);
					}
				}
			}
		} 
		$this->out("", 2, Shell::VERBOSE);
		$this->out(__("Outgoing messages in queue: %s, sent: %s, failed: %s, sent-but-not-updated: %s, skipped: %s", 
			$msgs_queued, $msgs_sent, $msgs_failed, $msgs_sent_unsaved, $msgs_skipped), 1, Shell::NORMAL);
		if ($this->params['dry-run']) {
			$this->print_dry_run_notice();
		}
		if ($msgs_failed > 0) {
			$this->error("SENDSMS fail", __("Messages failed: %s, last message was: %s", $msgs_failed, $last_err_msg));
		}
		$this->out(__("Done"), 1, Shell::VERBOSE);
	}
	
	public function get_sms_status() {
		if ($this->params['dry-run']) {
			$this->print_dry_run_notice();
		}
		$status_lookup = $this->Status->find('list');
		$source = $this->get_message_source($this->args[0]);
		$ms = $source['MessageSource'];
		$this->out(__("Getting SMS statuses for message source \"%s\"", $ms['name']), 1, Shell::VERBOSE);
		$this->check_url($ms);
		$conditions = array(
			'Message.status' => array( Status::$STATUS_SENT, Status::$STATUS_SENT_PENDING ),
			'Message.is_outbound' => 1,
			array("NOT" => array("Message.external_id" => null)),
			array("NOT" => array("Message.to_address" => null))
		);
		$statusless_msgs = $this->Message->find('all', array('conditions' => $conditions));
		$msgs_unknown = count($statusless_msgs);
		$msgs_checked = 0;
		$msgs_updated = 0;
		$msgs_failed = 0;
		$msgs_skipped = 0;
		$last_err_msg = "";
		$this->out(__("Messages with unknown status: %s", $msgs_unknown), 1, Shell::VERBOSE);
		if (!empty($statusless_msgs)) {
			if (! $this->params['dry-run']) {
				$netcast = $this->get_netcast_connection($ms);
			}
			foreach ($statusless_msgs as $msg) {
				$qty_retries = $msg['Message']['send_fail_count']+0;
				if (!$this->params['force'] && $qty_retries >= NetCastShell::$RETRY_LIMIT) {
					$msgs_skipped++;
					$this->out(__(" * Skipping message id=%s (%s attempts, cutoff is %s)", 
						$msg['Message']['id'], $qty_retries, NetCastShell::$RETRY_LIMIT), 1, Shell::VERBOSE);
				} else {
					$retry_no = $qty_retries==0? __('this will be first attempt'):__('this will be no. %s', $qty_retries+1);
					$this->out(__("  * getting status for id: %s", $msg['Message']['id']), 1, Shell::VERBOSE);
					$this->out(__("            transaction_id: %s", $msg['Message']['external_id']), 1, Shell::VERBOSE);
					$this->out(__("            to: %s", $msg['Message']['to_address']), 1, Shell::VERBOSE);
					$this->out(__("            retries: %s (%s)", $qty_retries, $retry_no), 1, Shell::VERBOSE);
					$this->out(__("            %s status: %s", 
						($this->params['dry-run']? 'current':'old'), $msg['Status']['name']), 1, Shell::VERBOSE);
					if ($this->params['dry-run']) {
						continue;
					}
					$ret_val = $this->call_netcast_function($netcast, "GETMSGSTATUS", array(
						$msg['Message']['external_id'], $ms['remote_id'],
					));
					$new_status = null;
					$is_success = true; // let's be optimistic 
					switch (strtoupper($ret_val)) {
						case 'RETGMS01':
							$new_status = Status::$STATUS_SENT_PENDING;
							break;
						case 'RETGMS02':
							$new_status = Status::$STATUS_SENT_OK;
							break;
						case 'RETGMS03':
							$new_status = Status::$STATUS_SENT_FAIL;
							break;
						case 'RETGMS04': // no such transaction id
							$new_status = Status::$STATUS_SENT_UNKNOWN;
							$is_success = false;
							break;
						default:
							$is_success = false;
					}
					if ($new_status) {
						$this->out(__("            new status: %s", strtoupper($status_lookup[$new_status])), 1, Shell::VERBOSE);
					}
					$this->Message->id = $msg['Message']['id'];
					if ($new_status && $new_status != $msg['Message']['status']) {
						$msgs_updated++;
						$this->Message->set('status', $new_status);
					} elseif (! $is_success){
						$msgs_failed++;
						$ret_val = MessageSource::decode_netcast_retval($ret_val);
						$last_err_msg = __("Gateway error (message id=%s/%s): %s", 
							$msg['Message']['id'], $msg['Message']['external_id'], $ret_val);
						$this->Message->set('send_fail_count', $msg['Message']['send_fail_count']+1);
						$this->Message->set('send_failed_at', date('Y-m-d H:i:s', time()));
						$this->Message->set('send_fail_reason', "[GETMSGSTATUS]: $ret_val");
						$this->out($last_err_msg, 1, Shell::NORMAL);
					} else {
						$this->out(__("    record unchanged (nothing to update)"), 1, Shell::VERBOSE);
						continue;
					}
					if ($this->Message->save()) {
						$this->out(__("    record updated OK"), 1, Shell::VERBOSE);
					} else {
						$this->error("Save failed", __("Message id=%s failed", $msg['Message']['id']));
					}
				}
			}
		} 
		$this->out("", 2, Shell::VERBOSE);
		$this->out(__("Messages to check: %s, checked: %s, updated: %s, failed: %s, skipped: %s", 
			$msgs_unknown, $msgs_checked, $msgs_updated, $msgs_failed, $msgs_skipped), 1, Shell::NORMAL);
		if ($this->params['dry-run']) {
			$this->print_dry_run_notice();
		}
		if ($msgs_failed > 0) {
			$this->error("GETMSGSTATUS fail", __("Messages failed: %s, last message was: %s", $msgs_failed, $last_err_msg));
		}
		$this->out(__("Done"), 1, Shell::VERBOSE);
	}
	
	
	private function print_dry_run_notice() {
		$this->out("\n", 1, Shell::QUIET);
		$this->out(__("***---------------------------------------------------------***"), 1, Shell::QUIET);
		$this->out(__("*** this is a DRY RUN: no messages sent or records updated! ***"), 1, Shell::QUIET);
		$this->out(__("***---------------------------------------------------------***"), 2, Shell::QUIET);
	}

	private function get_message_source($id_or_name) {
		$source = null;
		if (preg_match('/^\d+$/', $id_or_name)) {
		 	$source = $this->MessageSource->findById($id_or_name);
		} else {
			$source = $this->MessageSource->findByName($id_or_name);
		}
		if (empty($source)) {
			$this->error("No such source", __("Could not find a message source that matched the id (or name) that you provided"));
		}
		return $source;
	}
	
	private function get_netcast_connection($ms) {
		require_once("nusoap/nusoap.php");
		return new SoapClient($ms['url']);
	}
	
	private function call_netcast_function($conn, $function_name, $param_array) {
		return $conn->__soapCall($function_name, $param_array); 
	}
	
	private function check_url($message_source) {
		$url = $message_source['url'];
		if (empty($url)) {
			$this->error("Missing URL", 'No test was run: you need to add a URL to the Message Source');
		} elseif (! preg_match('/^https?:\/\//', $url)) {
			$this->error("Missing protocol", 'No test was run: URL must start with protocol (http or https');
		}		
	}
	
	private function log_action($id, $note, $transaction_id=null) {
		$action = new Action;
		$params = array(
			'type_id' =>  ActionType::$ACTION_GATEWAY,
			'message_id' => $this->Message->id,
			'note' => $note,
			'item_id' => $transaction_id
		);
		$action->create($params);
		$action->save();
	}
	
}

