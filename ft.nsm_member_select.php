<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * NSM Member Select Fieldtype
 *
 * @package			NsmMemberSelect
 * @version			0.0.1
 * @author			Leevi Graham <http://leevigraham.com>
 * @copyright 		Copyright (c) 2007-2010 Newism <http://newism.com.au>
 * @license 		Commercial - please see LICENSE file included with this distribution
 * @link			http://expressionengine-addons.com/nsm-member-select
 * @see				http://expressionengine.com/public_beta/docs/development/fieldtypes.html
 */

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
		'version'	=> '1.0.0'
	);

	/**
	 * The fieldtype global settings array
	 * 
	 * @access public
	 * @var array
	 */
	public $settings = array();

	/**
	 * The field type - used for form field prefixes. Must be unique and match the class name. Set in the constructor
	 * 
	 * @access private
	 * @var string
	 */
	public $field_type = '';

	/**
	 * UI Modes
	 *
	 * @access private
	 * @var array
	 */
	private $_uiModes = array(
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

		$this->addon_id = strtolower(substr(__CLASS__, 0, -3));

		if(!isset($this->EE->session->cache[$this->addon_id])) {
			$this->EE->session->cache[$this->addon_id]["members"] = array();
		}

	}	



	//----------------------------------------
	// DISPLAY FIELD / CELL / VARIABLE TAG
	//----------------------------------------

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
	public function replace_tag($data, $params = FALSE, $tagdata = FALSE) {

		$data = $this->_prepData($data);

		// Load the members from the data and turn them into an array
		$this->EE->load->helper('custom_field');
		$members = decode_multi_field($data);

		// Build the default params
		$params = array_merge(array(
			"prefix" => "nsm_ms:", // the nested tag prefix
			"backspace" => FALSE, // Remove last n characters
			"value" => false, // The member field
			"glue" => ", ", // glue for value
			"glue_last" => false, // last glue value. overrides glue param
			"multi_field" => FALSE, // is the value we're trying to return a multi select field?
			"multi_field_glue" => ", ", // the glue for the multi select field
			"multi_field_glue_last" => false // the glue for the multi select field
		), (array) $params);

		// If there is no tag pair and no value
		// assume the user just wants the raw data returned
		if($tagdata == false && $params['value'] == false) {
			return $data;
		}

		// Grab the member data
		$members = $this->_getMemberData($members, $params);

		// Tag pair?
		return ($tagdata) 
					// Return pair
					? $this->_parseMulti($members, $params, $tagdata) 
					// return single
					: $this->_parseSingle($members, $params);
	}

	/**
	 * Get the member data from the DB
	 * The members will probably already be cached thanks to the extension but this is a double check
	 * 
	 * @access private
	 * @param $members array The entry ids
	 * @param $params array the tag params
	 * @return array Member DB data
	 */
	private function _getMemberData($members, $params)
	{
		if(!$members) {
 			return array();
		}

		$required_members = $members; 

		// Loop over the required members and test if they have already been loaded
		foreach($required_members as $k => $v) {
			if(array_key_exists($v, $this->EE->session->cache[$this->addon_id]["members"])) {
				unset($required_members[$k]);
			}
		}

		// I just need somebody to load
		if(!empty($required_members)) {

			$this->EE->db->from("exp_members")
						->join("exp_member_data", 'exp_members.member_id = exp_member_data.member_id')
						->where_in("exp_members.member_id", $required_members);

			$query = $this->EE->db->get();

			foreach ($query->result_array() as $member) {
				$this->EE->session->cache[$this->addon_id]["members"][$member["member_id"]] = $member;
			}
		}

		// Get all the members we need to load and return the data
		$ret = array();
		foreach ($members as $member_id) {
			if(!isset($this->EE->session->cache[$this->addon_id]["members"][$member_id])) {
				continue;
			}
			$ret[] = $this->EE->session->cache[$this->addon_id]["members"][$member_id];
		}
		return $ret;
	}

	/**
	 * Checks if a param value is true
	 * 
	 * @access private
	 * @param $value mixed The param value
	 * @return boolean
	 */
	private static function _isTrue($value) {
		return in_array($value, array("yes", "y", "1", TRUE, "true", "TRUE"));
	}
	
	/**
	 * Glue an array together
	 * I'm sure this could be faster with an array_walk but I'm tired
	 */
	private function _glueValues($values, $glue, $glue_last = false) {

		// Nothing special about the last element, implode
		// 1 element, no need to glue that.
		if(! $glue_last || count($values) === 1) {
			return implode($values, $glue);
		}

		$last = array_pop($values);
		return implode($values, $glue) . $glue_last . $last;
	}

	/**
	 * Parse a single tag
	 * 
	 * @access private
	 * @param $members array The entry ids
	 * @param $params array the tag params
	 * @return string The entry ids concatenated with glue
	 */
	private function _parseSingle($members, $params) {

		if(empty($members)) {
			return false;
		}

		$values = array();
		foreach ($members as $member_id => $member_data) {
			// It's possible that the member data we're trying to get is a pipe delimited array
			// In that case we need to split the value and glue it back together
			if($this->_isTrue($params["multi_field"])) {
				// decode the multi field into an array
				$multi_field_values = decode_multi_field($member_data[$params["value"]]);
				// glue it back together
				$member_data[$params["value"]] = $this->_glueValues($multi_field_values, $params["multi_field_glue"], $params["multi_field_glue_last"]);
			}
			// Build up the values we want to glue together
			$values[] = $member_data[$params["value"]];
		}
		return $this->_glueValues($values, $params["glue"], $params["glue_last"]);
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
	private function _parseMulti($members, $params, $tagdata) {
		// No members - not sure how this could happen?
		if(empty($members)) {
			return false;
		}

		$total_results = count($members);
		$data = false;
		$count = 0;

		// Loop over our members and create a data array to parse in the templates
		foreach($members as $member) {

			++$count;

			$vars = array();
			$vars[$params["prefix"].'total_results'] = $total_results;
			$vars[$params["prefix"].'count'] = $count;
			$vars[$params["prefix"].'first'] = ($count == 1);
			$vars[$params["prefix"].'last'] = ($count == count($members));
			$vars[$params["prefix"].'glue'] = false;

			// Loop over all our member variables
			foreach ($member as $key => $value) {
				$vars[$params["prefix"] . $key] = $value;
			}

			// figure out the glue tag
			// Any item less than the total (last) set the glue
			if($count < $total_results) {
				$vars[$params["prefix"].'glue'] = $params["glue"];
			}
			// Second last item change the glue if needed
			if($count == $total_results - 1) {
				$vars[$params["prefix"].'glue'] = ($params["glue_last"]) ? $params["glue_last"] : $params["glue"];
			}

			$data[] = $vars;
		}

		$chunk = $this->EE->TMPL->parse_variables($tagdata, $data);

		// backspace the fuck out of it...
		if ($params['backspace']) {
			$chunk = substr($chunk, 0, - $params['backspace']);
		}

		return $chunk;
	}


	//----------------------------------------
	// INSTALL FIELDTYPE
	//----------------------------------------

	/**
	 * Install the fieldtype
	 *
	 * @return array The default settings for the fieldtype
	 */
	public function install()
	{
		return array(
			"setting_1" => false,
			"setting_2" => false
		);
	}



	//----------------------------------------
	// DISPLAY FIELD / CELL / VARIABLE
	//----------------------------------------

	/**
	 * Takes db / post data and parses it so we have the same info to work with every time
	 *
	 * @access private 
	 * @param $data mixed The data we need to prep
	 * @return array The new array of data
	 */
	private function _prepData($data)
	{
		
		$default_data = array();

		if(empty($data))
		{
			$data = array();
		}
		elseif(is_string($data))
		{
			// Before the $data string is passed to this method the following is applied:
			// $str = htmlspecialchars($str);
			// $str = str_replace(array("'", '"'), array("&#39;", "&quot;"), $str);
			// $data = htmlspecialchars_decode($data, ENT_QUOTES);
			$data = explode('|', $data);//$this->_unserialize($data, true);
		}
		return $this->_mergeRecursive($default_data, $data);
	}
	
	/**
	 * Display the field in the publish form
	 * 
	 * @access public
	 * @param $data String Contains the current field data. Blank for new entries.
	 * @param $input_name String the input name prefix
	 * @param $field_id String The field id - Low variables
	 * @return String The custom field HTML
	 */
	public function display_field($data, $input_name = false, $field_id = false)
	{
		if(!$field_id)
			$field_id = $this->field_name;
			
		if(!$input_name)
			$input_name = $this->field_name;

		//$this->_loadResources();

		$data = $this->_prepData($data);

		switch ($this->settings['field_ui_mode']) {
			case "select":
				return $this->_uiSelect($input_name, $this->settings['field_member_groups'], $data);
				break;
	
			case "multi_select":
				return $this->_uiSelect($input_name, $this->settings['field_member_groups'], $data, TRUE, $this->settings['field_size']);
				break;
		
			case "auto_complete":
				return "Autocomplete coming soon!";
				break;
		}
	}

	/**
	 * Create a select UI
	 * 
	 * @param $input_prefix string The input prefix
	 * @param $groups array Member groups we are selecting members from
	 * @param $selected_members array Previous selected members
	 * @param $multiple boolean Does this select accept multiple selections
	 * @param $size int Size of the select box
	 * @return str HTML for UI
	 */
	private function _uiSelect($input_prefix, array $groups, array $selected_members = array(), $multiple = FALSE, $size = 1)
	{
		if(empty($groups))
			return lang("No groups have been selected in the field settings");

		$member_query = $this->EE->db->query("
			SELECT
				exp_members.member_id AS member_id,
				exp_members.screen_name AS screen_name,
				exp_member_groups.group_title AS group_title, 
				exp_member_groups.group_id AS group_id
			FROM exp_members
			INNER JOIN exp_member_groups
			ON exp_members.group_id = exp_member_groups.group_id
			WHERE exp_member_groups.group_id IN (" . implode(",",$groups) . ") 
			ORDER BY exp_member_groups.group_id ASC , exp_members.screen_name ASC
		");

		if($member_query->num_rows == 0) {
			return lang('No members were found for this field');
		}

		$attrs = ($multiple) ? 'multiple="multiple" size="'.$size.'"' : '';

		$r = "\n<select name='" . $input_prefix . "[]'". $attrs ." >";

		if(!$multiple) {
			$r .= "<option value=''>".lang('Do not associate a member with this entry')."</option>";
		}

		$group = null;
		foreach ($member_query->result_array() as $member) {
			if($group != $member['group_title']) {
				// if this is not the first group
				if($group != null) {
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
	 * Displays the cell - MATRIX COMPATIBILITY
	 * 
	 * @access public
	 * @param $data The cell data
	 * @return string The cell HTML
	 */
	public function display_cell($data)
	{
		return $this->display_field($data, $this->cell_name);
	}

	/**
	 * Displays the Low Variable field
	 * 
	 * @access public
	 * @param $var_data The variable data
	 * @return string The cell HTML
	 * @see http://loweblog.com/software/low-variables/docs/fieldtype-bridge/
	 */
	public function display_var_field($var_data)
	{
		return "Variable content";
	}



	//----------------------------------------
	// DISPLAY FIELD / CELL / VARIABLE SETTINGS
	//----------------------------------------

	/**
	 * Display a global settings page. The current available global settings are in $this->settings.
	 *
	 * @access public
	 * @return string The global settings form HTML
	 */
	public function display_global_settings()
	{
		return "Global settings";
	}
	
	/**
	 * Default settngs
	 * 
	 * @access public
	 * @param $settings array The field / cell settings
	 * @return array Labels and form inputs
	 */
	private function _defaultFieldSettings()
	{
		return array(
			"field_member_groups" => array(),
			"field_ui_mode" => FALSE,
			"field_size" => 1
		);
	}

	/**
	 * Display the settings form for each custom field
	 * 
	 * @access public
	 * @param $settings mixed Not sure what this data is yet :S
	 * @param $field_name mixed The field name="" prefix
	 * @return array Labels and fields
	 */
	private function _displayFieldSettings($settings, $field_name = false)
	{
		$r = array();

		if(!$field_name)
			$field_name = __CLASS__;

		$this->EE->lang->loadfile('nsm_member_select');
		// $this->_loadResources();

		// get member groups
		$member_groups_query = $this->EE->member_model->get_member_groups()->result();
		$member_groups = array();
		foreach ($member_groups_query as $group) {
			$member_groups[$group->group_id] = $group->group_title;
		}

		$r[] = array(
			lang("Restrict member selection to:", 'field_member_groups'),
			form_multiselect($field_name.'[field_member_groups][]', $member_groups, $settings["field_member_groups"], "id='field_member_groups'")
		);

		$select_opts = array();
		foreach ($this->_uiModes as $key) {
			$select_opts[$key] = lang($key);
		}

		$r[] = array(
			lang("UI Mode:", 'field_ui_mode'),
			form_dropdown($field_name.'[field_ui_mode]', $select_opts, $settings["field_ui_mode"], "id='field_ui_mode'")
		);

		$r[] = array(
			lang("Size", 'field_size')
			. "<br />Determines the multi-select height and number of results returned in the autocomplete",
			form_input($field_name.'[field_size]', $settings["field_size"], "id='field_size'")
		);

		return $r;
	}

	/**
	 * Display the settings form for each custom field
	 * 
	 * @access public
	 * @param $field_settings array The field settings
	 */
	public function display_settings($field_settings)
	{
		$field_settings = $this->_mergeRecursive($this->_defaultFieldSettings(), $field_settings);
		$rows = $this->_displayFieldSettings($field_settings);

		// add the rows
		foreach ($rows as $row)
			$this->EE->table->add_row($row[0], $row[1]);
	}

	/**
	 * Display Cell Settings - MATRIX
	 * 
	 * @access public
	 * @param $cell_settings array The cell settings
	 * @return array Label and form inputs
	 */
	public function display_cell_settings($cell_settings)
	{
		// print_r($cell_settings);
		$cell_settings = $this->_mergeRecursive($this->_defaultFieldSettings(), $cell_settings);
		return $this->_displayFieldSettings($cell_settings, $this->addon_id);
	}

	/**
	 * Display Variable Settings - Low Variables
	 * 
	 * @access public
	 * @param $var_settings array The variable settings
	 * @return array Label and form inputs
	 */
	public function display_var_settings($var_settings)
	{
		$var_settings = $this->_mergeRecursive($this->_defaultFieldSettings(), $var_settings);
		return $this->_displayFieldSettings($var_settings);
	}


	//----------------------------------------
	// SAVE FIELD / CELL / VARIABLE SETTINGS
	//----------------------------------------

	/**
	 * Save the custom field settings
	 * 
	 * @param $data array The submitted post data.
	 * @return array Field settings
	 */
	public function save_settings($data)
	{
		return $field_settings = $this->EE->input->post(__CLASS__);
	}

	/**
	 * Process the cell settings before saving - MATRIX
	 * 
	 * @access public
	 * @param $cell_settings array The settings for the cell
	 * @return array The new settings
	 */
	public function save_cell_settings($cell_settings)
	{
		return $cell_settings = $cell_settings[$this->addon_id];
	}

	/**
	 * Save variable settings = LOW Variables
	 * 
	 * @access public
	 * @param $var_settings The variable settings
	 * @see http://loweblog.com/software/low-variables/docs/fieldtype-bridge/
	 */
	public function save_var_settings($var_settings)
	{
		return $this->EE->input->post(__CLASS__);
	}

	//----------------------------------------
	// SAVE FIELD / CELL / VARIABLE
	//----------------------------------------

	/**
	 * Publish form validation
	 * 
	 * @access public
	 * @param $data array Contains the submitted field data.
	 * @return mixed TRUE or an error message
	 */
	public function validate($data)
	{
		return TRUE;
	}

	/**
	 * Saves the field
	 */
	public function save($data)
	{
		if(empty($data)){
			$data = false;
		}elseif(is_array($data)){
			$data = implode('|', $data);
		}
		return $data;
	}

	/**
	 * Save cell data
	 */
	public function save_cell($data)
	{
		return $this->save($data);
	}


	//----------------------------------------
	// PRIVATE HELPER METHODS
	//----------------------------------------

	/**
	 * Takes a value and adds slashes. Array values are parsed recursively.
	 *
	 * @access public
	 * @param mixed string or array
	 * @return mixed the slashed value
	 */
	private function _addSlashesDeep($data)
	{
		if(is_array($data))
			foreach ($data as &$value)
				$value = $this->_addSlashesDeep($value);
		else
			return addslashes($data);

		return $data;
	}

	/**
	 * Serializes a value, encoding if necessary
	 *
	 * @access public
	 * @param mixed string or array
	 * @param $encode boolean Encode the serialized value
	 * @return mixed the serialized value
	 */
	private function _serialize($data, $encode = true)
	{
		if($this->EE->config->item('auto_convert_high_ascii') == 'y')
			$data = $this->_asciiToEntitiesDeep($data);

		$data = serialize($this->_addSlashesDeep($data));
		return ($encode) ? base64_encode($data) : $data;
	}

	/**
	 * Un-Serializes a value, encoding if necessary
	 *
	 * @access public
	 * @param mixed string or array
	 * @param $decode boolean Decode the serialized value
	 * @return mixed the serialized value
	 */
	private function _unserialize($data, $convert = false, $decode = true, $convert_all = false)
	{
		$data = ($decode) ? base64_decode($data) : $data;
		$data = strip_slashes(unserialize($data));

		return ($convert && $this->EE->config->item('auto_convert_high_ascii') == 'y')
			? $this->_entitiesToAsciiDeep($data, $convert_all)
			: $data;
	}

	/**
	 * Takes a value and converts ascii characters to entities
	 *
	 * @access private
	 * @param $data mixed The data to convert
	 * @return mixed The converted data
	 */
	function _asciiToEntitiesDeep($data)
	{
		$this->EE->load->helper('text');

		if (is_array($data))
			foreach ($data as &$value)
				$value = $this->_asciiToEntitiesDeep($value);
		else
			$data = ascii_to_entities($data);

		return $data;
	}

	/**
	 * Takes a value and converts entities to ascii characters
	 *
	 * @access private
	 * @param $data mixed The data to convert
	 * @return mixed The converted data
	 */
	function _entitiesToAsciiDeep($data, $convert_all = false)
	{
		$this->EE->load->helper('text');
		if (is_array($data))
			foreach ($data as &$value)
				$value = $this->_entitiesToAsciiDeep($value, $convert_all);
		else
			$data = entities_to_ascii($data, $convert_all);

		return $data;
	}

	/**
	 * Merges any number of arrays / parameters recursively, replacing 
	 * entries with string keys with values from latter arrays. 
	 * If the entry or the next value to be assigned is an array, then it 
	 * automagically treats both arguments as an array.
	 * Numeric entries are appended, not replaced, but only if they are 
	 * unique
	 *
	 * PHP's array_mergeRecursive does indeed merge arrays, but it converts
	 * values with duplicate keys to arrays rather than overwriting the value 
	 * in the first array with the duplicate value in the second array, as 
	 * array_merge does. e.g., with array_mergeRecursive, this happens 
	 * (documented behavior):
	 * array_mergeRecursive(array('key' => 'org value'), array('key' => 'new value'));
	 *     returns: array('key' => array('org value', 'new value'));
	 * 
	 * calling: result = array_mergeRecursive_distinct(a1, a2, ... aN)
	 *
	 * @author <mark dot roduner at gmail dot com>
	 * @link http://www.php.net/manual/en/function.array-merge-recursive.php#96201
	 * @access private
	 * @param $array1, [$array2, $array3, ...]
	 * @return array Resulting array, once all have been merged
	 */
	 private function _mergeRecursive () {
		$arrays = func_get_args();
		$base = array_shift($arrays);
		if(!is_array($base)) $base = empty($base) ? array() : array($base);
	
		foreach($arrays as $append) {
	
			if(!is_array($append)) $append = array($append);
	
			foreach($append as $key => $value) {
				if(!array_key_exists($key, $base) and !is_numeric($key)) {
					$base[$key] = $append[$key];
					continue;
				}
				if(is_array($value) /*or is_array($base[$key]) */) {
					$base[$key] = $this->_mergeRecursive($base[$key], $append[$key]);
				} else if(is_numeric($key)) {
					if(!in_array($value, $base)) $base[] = $value;
				} else {
					$base[$key] = $value;
				}
			}
		}
	
		return $base;
	}

	/**
	 * Get the current themes URL from the theme folder + / + the addon id
	 * 
	 * @access private
	 * @return string The theme URL
	 */

	private function _getThemeUrl()
	{
		$EE =& get_instance();
		if(!isset($EE->session->cache[$this->addon_id]['theme_url']))
		{
			$theme_url = $EE->config->item('theme_folder_url');
			if (substr($theme_url, -1) != '/') $theme_url .= '/';
			$theme_url .= "third_party/" . $this->addon_id;
			$EE->session->cache[$this->addon_id]['theme_url'] = $theme_url;
		}
		return $EE->session->cache[$this->addon_id]['theme_url'];
	}
	
	/**
	 * Load CSS and JS resources for the fieldtype
	 */
	private function _loadResources()
	{
		if(!isset($this->EE->cache[__CLASS__]['resources_loaded']))
		{
			$theme_url = $this->_getThemeUrl();
			$this->EE->cp->add_to_head("<link rel='stylesheet' href='{$theme_url}/styles/admin.css' type='text/css' media='screen' charset='utf-8' />");
			$this->EE->cp->add_to_foot("<script src='{$theme_url}/scripts/admin.js' type='text/javascript' charset='utf-8'></script>");
			$this->EE->cache[__CLASS__]['resources_loaded'] = true;
		}
	}

}
//END CLASS