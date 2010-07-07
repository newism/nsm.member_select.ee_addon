<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * NSM Example Addon Fieldtype
 *
 * @package NSMMemberSelect
 * @version 0.0.1
 * @author Leevi Graham <http://newism.com.au>
 * @copyright Copyright (c) 2007-2010 Newism
 * @license Commercial - please see LICENSE file included with this distribution
 * @see http://expressionengine.com/public_beta/docs/development/extensions.html
 *
 **/
class Nsm_member_select_ft extends EE_Fieldtype
{
	/**
	 * Field info - Required
	 * 
	 * @access public
	 * @var array
	 */
	public $info = array(
		'name'		=> 'NSM Member Select',
		'version'	=> '0.0.1'
	);

	/**
	 * UI Modes
	 *
	 * @access private
	 * @var array
	 */
	private static $ui_modes = array(
		'select',
		'multi_select',
		'autocomplete'
	);

	/**
	 * Constructor
	 * 
	 * @access public
	 */
	public function __construct()
	{
		parent::EE_Fieldtype();
		if(!isset($this->EE->session->cache[__CLASS__]))
		{
			$this->EE->session->cache[__CLASS__] = array();
			$this->EE->session->cache[__CLASS__]["members"] = array();
			$this->EE->session->cache[__CLASS__]["member_custom_fields"] = array();
		}
	}	

	/**
	 * Display the settings form for each custom field
	 * 
	 * @access public
	 * @param $data mixed The field settings
	 * @return string Override the field custom settings with custom html
	 * 
	 * In this case we add an extra row to the table. Not sure how the table is built
	 */
	public function display_settings($data)
	{
		$this->EE->lang->loadfile('nsm_member_select');

		$data = array_merge(array(
			"field_member_groups" => array(),
			"field_ui_mode" => FALSE,
			"field_size" => 1
		), $data);

		$member_groups_query = $this->EE->member_model->get_member_groups()->result();
		$member_groups = array();
		foreach ($member_groups_query as $group) {
			$member_groups[$group->group_id] = $group->group_title;
		}

		$this->EE->table->add_row(
			form_hidden($this->field_id.'_field_fmt', 'none') .
			form_hidden('field_show_fmt', 'n') .
			lang("Restrict member selection to:", 'field_member_groups'),
			form_multiselect($this->field_id.'_field_settings[field_member_groups][]', $member_groups, $data["field_member_groups"], "id='field_member_groups'")
		);

		$select_opts = array();
		foreach (self::$ui_modes as $key)
			$select_opts[$key] = lang($key);

		$this->EE->table->add_row(
			lang("UI Mode:", 'field_ui_mode'),
			form_dropdown($this->field_id.'_field_settings[field_ui_mode]', $select_opts, $data["field_ui_mode"], "id='field_ui_mode'")
		);

		$this->EE->table->add_row(
			lang("Size", 'field_size')
			. "<br />Determines the multi-select height and number of results returned in the autocomplete",
			form_input($this->field_id.'_field_settings[field_size]', $data["field_size"], "id='field_size'")
		);
	}

	/**
	 * Save the custom field settings
	 * 
	 * @return boolean Valid or not
	 */
	public function save_settings()
	{
		$new_settings = $this->EE->input->post('nsm_member_select_field_settings');
		return $new_settings;
	}

	/**
	 * Display the field in the publish form
	 * 
	 * @access public
	 * @param $data String Contains the current field data. Blank for new members.
	 * @return String The custom field HTML
	 */
	public function display_field($data = FALSE)
	{
		if(!is_array($data))
			$data = explode("|", $data);

		switch ($this->settings['field_ui_mode']) {
			case "select":
				return $this->_uiSelect($this->field_id, $this->settings['field_member_groups'], $data);
				break;

			case "multi_select":
				return $this->_uiSelect($this->field_id, $this->settings['field_member_groups'], $data, TRUE, $this->settings['field_size']);
				break;
			
			case "auto_complete":
				return "Autocomplete coming soon!";
				break;
		}
	}

	/**
	 * Publish form validation
	 * 
	 * @param $data array Contains the submitted field data.
	 * @return mixed TRUE or an error message
	 */
	public function validate($data)
	{
		return TRUE;
	}

	/**
	 * Pre-process the data and return a string
	 *
	 * @access public
	 * @param array data The selected member groups
	 * @return string Concatenated string f member groups
	 */
	public function save($data){
		$this->EE->load->helper('custom_field');
		return encode_multi_field($data);
	}

	/**
	 * Replaces the custom field tag
	 * 
	 * @access public
	 * @param $data string Contains the field data (or prepped data, if using pre_process)
	 * @param $params array Contains field parameters (if any)
	 * @param $tagdata mixed Contains data between tag (for tag pairs) FALSE for single tags
	 * @return string The HTML replacing the tag
	 * 
	 */
	public function replace_tag($data, $params = FALSE, $tagdata = FALSE)
	{
		$this->EE->load->helper('custom_field');
		$members = decode_multi_field($data);
		
		$params = array_merge(array(
			"backspace" => FALSE,
			"glue" => ", ",
			"multi_field" => FALSE,
			"multi_field_glue" => ", ",
			"value" => "member_id",
			"prefix" => "ms:"
		), (array) $params);

		$members = $this->_getMemberData($members, $params);

		return ($tagdata) ? $this->_parseMulti($members, $params, $tagdata) : $this->_parseSingle($members, $params);
	}

	/**
	 * Get the member data from the DB
	 * 
	 * @access private
	 * @param $members array The entry ids
	 * @param $params array the tag params
	 * @return array Member DB data
	 */
	private function _getMemberData($members, $params)
	{
		if(!$members) return array();

		$required_members = $members; 
		foreach($required_members as $k => $v)
		{
			if(array_key_exists($v, $this->EE->session->cache[__CLASS__]))
			{
				unset($required_members[$k]);
			}
		}

		if(!empty($required_members))
		{
			$this->EE->db->from("exp_members")
						->join("exp_member_data", 'exp_members.member_id = exp_member_data.member_id')
						->where_in("exp_members.member_id", $required_members);

			$query = $this->EE->db->get();
			foreach ($query->result_array() as $member)
			{
				$this->EE->session->cache[__CLASS__]["member_data"][$member["member_id"]] = $member;
			}
		}

		$ret = array();
		foreach ($members as $member_id)
		{
			if(!isset($this->EE->session->cache[__CLASS__]["member_data"][$member_id]))
				continue;

			$ret[] = $this->EE->session->cache[__CLASS__]["member_data"][$member_id];
		}
		return $ret;
	}

	/**
	 * Parse a single tag
	 * 
	 * @access private
	 * @param $members array The entry ids
	 * @param $params array the tag params
	 * @return string The entry ids concatenated with glue
	 */
	private function _parseSingle($members, $params)
	{
		$ret = array();
		foreach ($members as $member_id => $member_data)
		{
			if(self::_isTrue($params["multi_field"]))
			{
				$member_data[$params["value"]] = implode($params["multi_field_glue"], decode_multi_field($member_data[$params["value"]]));
			}
			$ret[] = $member_data[$params["value"]];
		}
		return implode($params["glue"], $ret);
	}
	/**
	 * Parse a tag pair
	 * 
	 * @access private
	 * @param $members array The entry ids
	 * @param $params array the tag params
	 * @param $tagdata string The data between the tag pair
	 * @return string The entry ids concatenated with glue
	 */
	private function _parseMulti($members, $params, $tagdata)
	{
		$chunk = '';
		foreach($members as $count => $member)
		{
			$vars['count'] = $count + 1;
			foreach ($member as $key => $value)
			{
				$vars[$params["prefix"] . $key] = $value;
			}
			$tmp = $this->EE->functions->prep_conditionals($tagdata, $vars);
			$chunk .= $this->EE->functions->var_swap($tmp, $vars);
		}
		if ($params['backspace'])
		{
			$chunk = substr($chunk, 0, - $params['backspace']);
		}
		return $chunk;
	}

	/**
	 * Create a select UI
	 * 
	 * @param $field_id string The field id
	 * @param $groups array Member groups we are selecting members from
	 * @param $selected_members array Previous selected members
	 * @param $multiple boolean Does this select accept multiple selections
	 * @param $size int Size of the select box
	 * @return str HTML for UI
	 */
	private function _uiSelect($field_id, array $groups, array $selected_members = array(), $multiple = FALSE, $size = 1)
	{
		if(empty($groups))
			return lang("No groups have been selected in the field settings");

		$member_query = $this->EE->db->query("SELECT
			exp_members.member_id AS member_id,
			exp_members.screen_name AS screen_name,
			exp_member_groups.group_title AS group_title, 
			exp_member_groups.group_id AS group_id
		FROM
			exp_members
		INNER JOIN
			exp_member_groups
		ON
			exp_members.group_id = exp_member_groups.group_id
		WHERE 
			exp_member_groups.group_id IN (" . implode(",",$groups) . ") 
		ORDER BY 
			exp_member_groups.group_id ASC , exp_members.screen_name ASC ");

		if($member_query->num_rows == 0)
			return lang('No members were found for this field');

		$attrs = ($multiple) ? 'multiple="multiple" size="'.$size.'"' : '';

		$r = "\n<select id='field_id_" . $this->field_id . "' name='field_id_" . $this->field_id . "[]'". $attrs ." >";

		if(!$multiple)
			$r .= "<option value=''>".lang('Do not associate a member with this entry')."</option>";

		$group = null;
		foreach ($member_query->result_array() as $member)
		{
			if($group != $member['group_title'])
			{
				// if this is not the first group
				if($group != null)
				{
					// close the optgroup
					$r .= "\n\t</optgroup>";
				}
				// set the current group
				$group = $member['group_title'];
				// open another opt group
				$r .= "\n\t<optgroup label='" . $member['group_title'] ."'>";
			}

			$selected = (in_array($member['member_id'], $selected_members)) ? " selected='selected' " : "";
			$r .= "\n\t\t<option value='" . $member['member_id'] . "'" . $selected . " > " . $member['screen_name'] . "</option>";
		}
		$r .= "</select>";
		return $r;
	}

	/**
	 * Checks if a param value is true
	 * 
	 * @access private
	 * @param $value mixed The param value
	 * @return boolean
	 */

	private static function _isTrue($value){
		return in_array($value, array("yes", "y", "1", TRUE, "true", "TRUE"));
	}

}
//END CLASS