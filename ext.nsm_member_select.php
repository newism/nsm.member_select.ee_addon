<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * NSM Simple Commerce Utilities Addon Extension
 *
 * @package NSMMemberSelect
 * @version 0.0.1
 * @author Leevi Graham <http://newism.com.au>
 * @copyright Copyright (c) 2007-2009 Newism
 * @license Commercial - please see LICENSE file included with this distribution
 * @see http://expressionengine.com/public_beta/docs/development/extensions.html
 *
 **/
class Nsm_member_select_ext
{
	var $settings			= array();
	var $name				= 'NSM Member Select';
	var $version			= '0.0.1';
	var $description		= 'Extension for NSM Members Select';
	var $settings_exist		= 'n';
	var $docs_url			= '';

	var $hooks = array('channel_entries_query_result');

	var $default_site_settings = array();
	var $default_channel_settings = array();
	var $default_member_group_settings = array();

	// ====================================
	// = Delegate & Constructor Functions =
	// ====================================

	/**
	 * PHP5 constructor function.
	 * @since		Version 0.0.0
	 * @access		public
	 * @param		array	$settings	an array of settings used to construct a new instance of this class.
	 * @return		void
	 **/
	function __construct($settings=FALSE)
	{
		// define a constant for the current site_id rather than calling $PREFS->ini() all the time
		if (defined('SITE_ID') == FALSE) {
			define('SITE_ID', get_instance()->config->item('site_id'));
		}

		$this->addon_id = strtolower(substr(__CLASS__, 0, -4));
		if(!isset($this->EE->session->cache[$this->addon_id])) {
			$this->EE->session->cache[$this->addon_id]["members"] = array();
		}
	}

	function activate_extension(){$this->_createHooks();}
	function disable_extension(){$this->_deleteHooks();}
	function update_extension(){}
	function settings_form(){}

	// ===============================
	// = Hook Functions =
	// ===============================

	/**
	 * Load member data into cache for performance
	 * 
	 * @access public
	 * @param $Channel The current Channel object including all data relating to categories and custom fields
	 * @param $query_result array Entries for the current tag
	 * @return array The modified query result
	 * @see http://expressionengine.com/public_beta/docs/development/extension_hooks/module/channel/index.html
	 */
	public function channel_entries_query_result($Channel, $query_result){

		$EE =& get_instance();
		
		if($EE->extensions->last_call != FALSE)
			$query_result = $this->EE->extensions->last_call;

		// Does the disable param include "nsm_member_select_preload"
		// disable="nsm_member_select_preload"
		if(strpos("nsm_member_select_preload", $EE->TMPL->fetch_param("disable")) === false) {
			
			// If the pfields are not yet ready return the result to prevent errors
			if(!isset($Channel->pfields[1])){
				return $query_result;
			}
			// Find all the member_select fields for this channel
			$fields = array();
			foreach($Channel->pfields[1] as $field_id => $field_type) {
				if($field_type == $this->addon_id) {
					$fields[] = 'field_id_' . $field_id;
				}
			}
			// If no matching fields are found return the result to prevent errors
			if(!count($fields)){
				return $query_result;
			}
			
			// Build an array of member ids we need to fetch
			$member_ids = array();
			foreach ($query_result as $entry) {
				foreach ($fields as $field) {
					if($entry[$field] != false) {
						$member_ids = array_merge($member_ids, explode("|", $entry[$field]));
					}
				}
			}
			// Are there members?
			if(!empty($member_ids)) {

				// Get their details
				$member_query = $EE->db
					->select("*, LCASE(`exp_member_groups`.`group_title`) AS `group_name`")
					->from("exp_members")
					->join("exp_member_data", 'exp_members.member_id = exp_member_data.member_id')
					->join("exp_member_groups", 'exp_members.group_id = exp_member_groups.group_id')
					->where_in('exp_members.member_id', array_unique($member_ids))
					->get();
				
				// Loop over the member and add them to the cache
				foreach ($member_query->result_array() as $member) {
					$EE->session->cache[$this->addon_id]["members"][$member["member_id"]] = $member;
				}
			}
		}

		// Return the entry query unaffected
		// the tag parsing will take care of the rest
		return $query_result;
	}


	// ===============================
	// = Class and Private Functions =
	// ===============================

	/**
	 * Sets up and subscribes to the hooks specified by the $hooks array.
	 * @since		Version 0.0.0
	 * @access		private
	 * @param		array	$hooks	a flat array containing the names of any hooks that this extension subscribes to. By default, this parameter is set to FALSE.
	 * @return		void
	 * @see 		http://codeigniter.com/user_guide/general/hooks.html
	 **/
	private function _createHooks($hooks = FALSE)
	{
		$EE =& get_instance();

		if (!$hooks)
			$hooks = $this->hooks;

		$hook_template = array(
			'class'    => __CLASS__,
			'settings' => FALSE,
			'version'  => $this->version,
		);

		foreach ($hooks as $key => $hook)
		{
			if (is_array($hook))
			{
				$data['hook'] = $key;
				$data['method'] = (isset($hook['method']) === TRUE) ? $hook['method'] : $key;
				$data = array_merge($data, $hook);
			}
			else
			{
				$data['hook'] = $data['method'] = $hook;
			}

			$hook = array_merge($hook_template, $data);
			$hook['settings'] = serialize($hook['settings']);
			$EE->db->insert('exp_extensions', $hook);
		}
	}

	/**
	 * Removes all subscribed hooks for the current extension.
	 * 
	 * @since		Version 0.0.0
	 * @access		private
	 * @return		void
	 * @see 		http://codeigniter.com/user_guide/general/hooks.html
	 **/
	private function _deleteHooks()
	{
		$EE =& get_instance();
		$EE->db->where('class', __CLASS__);
		$EE->db->delete('exp_extensions'); 
	}
}