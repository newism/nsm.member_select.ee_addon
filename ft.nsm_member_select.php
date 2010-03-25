<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * NSM Example Addon Fieldtype
 *
 * @package NSMMemberSelect
 * @version 0.0.1
 * @author Leevi Graham <http://newism.com.au>
 * @copyright Copyright (c) 2007-2009 Newism
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
	 * 
	 * Calls the parent constructor
	 */
	public function __construct()
	{
		parent::EE_Fieldtype();
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
	 * @param $data String Contains the current field data. Blank for new entries.
	 * @return String The custom field HTML
	 */
	public function display_field($data = FALSE)
	{
		$data = (empty($data)) ? array() : explode("|", $data);

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
	 * Pre-process the data and return a string
	 *
	 * @access public
	 * @param array data The selected member groups
	 * @return string Concatenated string f member groups
	 */
	public function save($data){
		return implode("|", $data);
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
		$params = array_merge(array(
			"divider" => "|"
		), $params);

		return str_replace("|", $params["divider"], $data);
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

}
//END CLASS