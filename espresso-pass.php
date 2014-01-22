<?php

/*
  Plugin Name: Event Espresso - Pass Generator
  Plugin URI: http://eventespresso.com/
  Description: Multi Events Registration addon for Event Espresso.

  Version: 0.5.b

  Author: Sidney Harrell
  Author URI: http://www.eventespresso.com

  Copyright (c) 2011 Event Espresso  All Rights Reserved.

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation; either version 2 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA

 */

class EE_PASS_GENERATOR {

	// instance of the VLM_DSCNT object
	private static $_instance = NULL;
	private $_payment_complete = false;
	private $_groupon_holder = NULL;
	private $_nonce_field = NULL;

	/**
	 * 		@singleton method used to instantiate class object
	 * 		@access public
	 * 		@return class instance
	 */
	public function &instance() {
		// check if class object is instantiated
		if (self::$_instance === NULL or !is_object(self::$_instance) or !is_a(self::$_instance, __CLASS__)) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * 		private constructor to prevent direct creation
	 * 		@Constructor
	 * 		@access private
	 * 		@return void
	 */
	private function __construct() {
		if (!defined('EVENT_ESPRESSO_VERSION') || !defined('EVENTS_GROUPON_CODES_TABLE')) {
			return;
		}
		add_shortcode('ESPRESSO_GENERATE_PASS', array(&$this, 'espresso_generate_pass_shortcode'));
		add_filter('filter_hook_espresso_update_attendee_payment_data_in_db', array(&$this, 'espresso_trigger_pass_generation_form'));
		add_filter('filter_hook_espresso_prepare_event_link', array(&$this, 'espresso_pass_generation_final'));
	}

	function espresso_generate_pass_shortcode($atts) {
		extract(shortcode_atts(array('number' => 0, 'email_id' => 0, 'event_ids' => ''), $atts));
		global $wpdb;
		$return_string = '';
		if (!empty($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], $this->_nonce_field) && !empty($_SESSION[$this->_nonce_field])) {
			for ($i = 0; $i < $number; $i++) {
				$groupon_code = $groupon_codes[$i] = uniqid();
				$wpdb->insert(EVENTS_GROUPON_CODES_TABLE, array('groupon_code' => $groupon_code,
						'groupon_holder' => $this->_groupon_holder,
						'event_id' => $_REQUEST['pass_events'][$i]), array('%s',
						'%s'));
			}
			$_SESSION[$this->_nonce_field] = false;
			if (empty($email_id)) {
				$email_subject = "Event Passes";
				$email_body = "Thank you for purchasing $number passes. The following are your codes for redeeming your passes:";
			} else {
				$email_data = espresso_email_message($email_id);
				$email_body = $email_data['email_text'];
				$email_subject = $email_data['email_subject'];
			}
			foreach ($groupon_codes as $groupon_code) {
				$email_body .= "<br>" . $groupon_code;
			}
			event_espresso_send_email(array('send_to' => $this->_groupon_holder, 'email_subject' => $email_subject, 'email_body' => $email_body));
		} elseif ($this->_payment_complete) {
			$sql = "SELECT id, event_name FROM " . EVENTS_DETAIL_TABLE . " WHERE end_date >= '" . date('Y-m-d') . "'";
			if (!empty($event_ids)) {
				$event_ids = explode(',', $event_ids);
				$sql .= " AND id IN (";
				foreach ($event_ids as $event_id) {
					$sql .= "%d,";
				}
				$sql = rtrim($sql, ',');
				$sql .= ")";
				$sql = $wpdb->prepare($sql, $event_ids);
			}
			$events = $wpdb->get_results($sql, ARRAY_A);
			$values = array();
			foreach ($events as $event) {
				$values[] = array('id' => $event['id'], 'text' => $event['event_name']);
			}
			$return_string = "<form action='" . $_SERVER['REQUEST_URI'] . "' method='post'>";
			$return_string .= 'Select which events you wish your passes to apply to:';
			for ($i = 0; $i < $number; $i++) {
				$return_string .= '<br>';
				$return_string .= select_input('pass_events[]', $values);
			}
			$return_string .= wp_nonce_field($this->_nonce_field, '_wpnonce', true, false);
			$return_string .= '<br><input type="submit" value="Generate Passes"></form>';
			$_SESSION[$this->_nonce_field] = true;
		}

		return $return_string;
	}

	function espresso_trigger_pass_generation_form($payment_data) {
		if ($payment_data['payment_status'] == "Completed") {
			$this->_payment_complete = true;
			$this->_groupon_holder = $payment_data['email'];
			$this->_nonce_field = 'espresso-pass-' . $payment_data['registration_id'];
			global $wpdb;
			$sql = "SELECT ed.event_desc FROM " . EVENTS_DETAIL_TABLE . " ed JOIN " . EVENTS_ATTENDEE_TABLE . " ea ON ea.event_id=ed.id WHERE ea.attendee_session=%s";
			$events = $wpdb->get_results($wpdb->prepare($sql, $payment_data['attendee_session']));
			foreach ($events as $event) {
				echo do_shortcode(stripslashes_deep($event->event_desc));
			}
		}
		return $payment_data;
	}

	function espresso_pass_generation_final($payment_data) {
		$this->_nonce_field = 'espresso-pass-' . $payment_data['registration_id'];
		if (!empty($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], $this->_nonce_field) && !empty($_SESSION[$this->_nonce_field])) {
			$this->_groupon_holder = $payment_data['email'];
			global $wpdb;
			$sql = "SELECT ed.event_desc FROM " . EVENTS_DETAIL_TABLE . " ed JOIN " . EVENTS_ATTENDEE_TABLE . " ea ON ea.event_id=ed.id WHERE ea.attendee_session=%s";
			$events = $wpdb->get_results($wpdb->prepare($sql, $payment_data['attendee_session']));
			foreach ($events as $event) {
				echo do_shortcode(stripslashes_deep($event->event_desc));
			}
		}
		return $payment_data;
	}

}

function espresso_run_pass_generator() {
	// instantiate !!!
	$EE_PASS_GENERATOR = EE_PASS_GENERATOR::instance();
}

add_action('plugins_loaded', 'espresso_run_pass_generator', 100);
