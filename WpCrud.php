<?php

require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
require_once __DIR__."/src/Template.php";
require_once __DIR__."/src/WpCrudFieldSpec.php";

use wpcrud\Template;
use wpcrud\WpCrudFieldSpec;

/**
 * Generic CRUD interface for Wordpress.
 * Implemented using following this example:
 *
 * http://mac-blog.org.ua/wordpress-custom-database-table-example-full/
 *
 * Implementing classes should implement these functions:
 *
 * - getFieldValue
 * - setFieldValue
 * - createItem
 * - getItem
 * - saveItem
 * - deleteItem
 * - getAllItems
 */
abstract class WpCrud extends WP_List_Table {

	private static $scriptsEnqueued;

	private $typeName;
	private $typeId;
	private $fields=array();
	private $listFields;
	private $editFields;
	private $submenuSlug;
	private $description;

	/**
	 * Constructor.
	 */
	public final function __construct() {
		$this->setTypeName(get_called_class());

		parent::__construct(array(
			"screen"=>$this->typeId
		));

		$this->init();
	}

	/**
	 * Initialize fields.
	 * Override in subclass.
	 */
	protected function init() {}

	/**
	 * Set description.
	 */
	public function setDescription($description) {
		$this->description=$description;
	}

	/**
	 * Set submenu slug.
	 */
	protected function setSubmenuSlug($slug) {
		$this->submenuSlug=$slug;
	}

	/**
	 * Set the name of the type being managed.
	 */
	protected function setTypeName($name) {
		$this->typeName=$name;
		$this->typeId=strtolower(str_replace(" ", "", $name));
	}

	/**
	 * Add a field to be managed. This function returns a 
	 * WpCrudFieldSpec object, it is intended to be used something
	 * like this in init function:
	 *
	 *     $this->addField("myfield")->label("My Field")->...
	 */
	protected function addField($field) {
		$this->fields[$field]=new WpCrudFieldSpec($field);

		return $this->fields[$field];
	}

	/**
	 * Which fields should be editable?
	 */
	public function setEditFields($fieldNames) {
		$this->editFields=$fieldNames;
	}

	/**
	 * Which fields should be listable?
	 */
	public function setListFields($fieldNames) {
		$this->listFields=$fieldNames;
	}

	/**
	 * Get field spec.
	 * Internal.
	 */
	private function getFieldSpec($field) {
		if (!isset($this->fields[$field]))
			$this->addField($field);

		return $this->fields[$field];
	}

	/**
	 * Get edit fields.
	 */
	private function getEditFields() {
		if ($this->editFields)
			return $this->editFields;

		return array_keys($this->fields);
	}

	/**
	 * Get list fields.
	 */
	private function getListFields() {
		if ($this->listFields)
			return $this->listFields;

		return array_keys($this->fields);
	}

	/**
	 * Get columns.
	 * Internal.
	 */
	public function get_columns() {
		$a=array();
		$a["cb"]='<input type="checkbox" />';

		foreach ($this->getListFields() as $field) {
			$fieldspec=$this->getFieldSpec($field);
			$a[$field]=$fieldspec->label;
		}

		return $a;
	}

	/**
	 * Get checkbox column.
	 * Internal.
	 */
	public function column_cb($item) {
		return sprintf(
			'<input type="checkbox" name="_bulkid[]" value="%s" />', $item->id
		);
	}

	/**
	 * Column value.
	 * This function returns the value shown when listing stuff.
	 */
	public function column_default($item, $column_name) {
		$listFields=$this->getListFields();

		if ($column_name==$listFields[0]) {
			$actions = array(
				'edit' => sprintf('<a href="?page=%s_form&id=%s">%s</a>', $this->typeId, $item->id, __('Edit', $this->typeId)),
				'delete' => sprintf('<a href="?page=%s&action=delete&id=%s" onclick="return confirm(\'Are you sure? This operation cannot be undone!\');">%s</a>', $_REQUEST['page'], $item->id, __('Delete', $this->typeId)),
			);

			return sprintf('%s %s',
				$item->$column_name,
				$this->row_actions($actions)
			);
		}

		$fieldspec=$this->getFieldSpec($column_name);

		if ($fieldspec->type=="select") {
			return $fieldspec->options[$item->$column_name];
		}

		if ($fieldspec->type="timestamp") {
			$v=$item->$column_name;
			if (!$v)
				return "";

			return date('Y-m-d H:i',$v);
		}

		return $item->$column_name;
	}

	/**
	 * Render the page.
	 */
	public function list_handler() {
		$template=new Template(__DIR__."/tpl/itemlist.php");

		if (isset($_REQUEST["action"]) && $_REQUEST["action"]=="delete") {
			$item=$this->getItem($_REQUEST["id"]);

			if ($item) {
				$this->deleteItem($item);
				$template->set("message","Item deleted.");
			}
		}

		if ($this->current_action()=="delete" && !empty($_REQUEST["_bulkid"])) {
			$numitems=0;

			foreach ($_REQUEST["_bulkid"] as $id) {
				$item=$this->getItem($id);

				if ($item) {
					$item->delete();
					$numitems++;
				}
			}

			$template->set("message",$numitems." item(s) deleted.");
		}

		$this->items=$this->getAllItems();

		$template->set("description",$this->description);
		$template->set("title",$this->typeName);
		$template->set("typeId",$this->typeId);
		$template->set("listTable",$this);
		$template->set("addlink",get_admin_url(get_current_blog_id(), 'admin.php?page='.$this->typeId.'_form'));
		$template->show();
	}

	/**
	 * Form handler.
	 * Internal.
	 */
	public function form_handler() {
		wp_enqueue_script("jquery-datetimepicker");
		wp_enqueue_style("jquery-datetimepicker");

		$template=new Template(__DIR__."/tpl/itemformpage.php");

		if (wp_verify_nonce($_REQUEST["nonce"],basename(__FILE__))) {
			if ($_REQUEST["id"])
				$item=$this->getItem($_REQUEST["id"]);

			else
				$item=$this->createItem();

			foreach ($this->getEditFields() as $field) {
				$fieldspec=$this->getFieldSpec($field);
				$v=$_REQUEST[$field];

				// post process field.
				switch ($fieldspec->type) {
					case "timestamp":
						if ($v) {
							$oldTz=date_default_timezone_get();
							date_default_timezone_set(get_option('timezone_string'));
							$v=strtotime($v);
							date_default_timezone_set($oldTz);
						}

						else {
							$v=0;
						}

						break;
				}

				$this->setFieldValue($item,$field,$v);
			}

			$message=$this->validateItem($item);

			if ($message) {
				$template->set("notice",$message);
			}

			else {
				$this->saveItem($item);
				$template->set("message",$this->typeName." saved.");
			}
		}

		else if (isset($_REQUEST["id"]))
			$item=$this->getItem($_REQUEST["id"]);

		else
			$item=$this->createItem();

		add_meta_box($this->typeId."_meta_box",$this->typeName,array($this,"meta_box_handler"),$this->typeId, 'normal_'.$this->typeId, 'default');

		$template->set("title",$this->typeName);
		$template->set("nonce",wp_create_nonce(basename(__FILE__)));
		$template->set("backlink",get_admin_url(get_current_blog_id(), 'admin.php?page='.strtolower($this->typeName)));
		$template->set("metabox",$this->typeId);
		$template->set("metaboxContext","normal_".$this->typeId);
		$template->set("item",$item);
		$template->show();
	}

	/**
	 * Meta box handler.
	 * Internal.
	 */
	public function meta_box_handler($item) {
		$template=new Template(__DIR__."/tpl/itemformbox.php");

		$fields=array();

		foreach ($this->getEditFields() as $field) {
			$fieldspec=$this->getFieldSpec($field);
			$v=$this->getFieldValue($item,$field);

			// pre process fields.
			switch ($fieldspec->type) {
				case "timestamp":
					if ($v) {
						$oldTz=date_default_timezone_get();
						date_default_timezone_set(get_option('timezone_string'));
						$v=date("Y-m-d H:i",$v);
						date_default_timezone_set($oldTz);
					}

					else {
						$v="";
					}

					break;
			}

			$fields[]=array(
				"spec"=>$fieldspec,
				"field"=>$fieldspec->field,
				"label"=>$fieldspec->label,
				"description"=>$fieldspec->description,
				"value"=>$v,
			);
		}

		$template->set("fields",$fields);
		$template->show();
	}

	/**
	 * Return array of bulk actions if any.
	 */
	protected function get_bulk_actions() {
		$actions = array(
		    'delete' => 'Delete'
		);
		return $actions;
	}

	/**
	 * Validate item, return error message if
	 * not valid.
	 * Override in sub-class.
	 */
	protected function validateItem($item) {
	}

	/**
	 * Create a new item.
	 * Implement in sub-class.
	 */
	protected function createItem() {
		return new stdClass;
	}

	/**
	 * Get specified value from an item.
	 * Implement in sub-class.
	 */
	protected function getFieldValue($item, $field) {
		if (is_array($item))
			return $item[$field];

		else if (is_object($item))
			return $item->$field;

		else
			throw new Exception("Expected item to be an object or an array");
	}

	/**
	 * Set field value.
	 * Implement in sub-class.
	 */
	protected function setFieldValue(&$item, $field, $value) {
		if (is_array($item))
			return $item[$field]=$value;

		else if (is_object($item))
			return $item->$field=$value;

		else
			throw new Exception("Expected item to be an object or an array");
	}

	/**
	 * Save item.
	 * Implement in sub-class.
	 */
	protected abstract function saveItem($item);

	/**
	 * Delete item.
	 * Implement in sub-class.
	 */
	protected abstract function deleteItem($item);

	/**
	 * Get item by id.
	 * Implement in sub-class.
	 */
	protected abstract function getItem($id);

	/**
	 * Get all items for list.
	 * Implement in sub-class.
	 */
	protected abstract function getAllItems();

	/**
	 * Serve frontend resource.
	 */
	public static function res() {
		switch ($_REQUEST["res"]) {
			case "jquery.datetimepicker.js":
				header('Content-Type: application/javascript');
				readfile(__DIR__."/res/jquery.datetimepicker.js");
				exit;
				break;

			case "jquery.datetimepicker.css":
				header("Content-Type: text/css");
				readfile(__DIR__."/res/jquery.datetimepicker.css");
				exit;
				break;
		}
	}

	/**
	 * Stuff.
	 */
	public static function admin_enqueue_scripts() {
		if (WpCrud::$scriptsEnqueued)
			return;

		WpCrud::$scriptsEnqueued=TRUE;

		wp_register_script("jquery-datetimepicker",admin_url('admin-ajax.php')."?action=wpcrud_res&res=jquery.datetimepicker.js");
		wp_register_style("jquery-datetimepicker",admin_url('admin-ajax.php')."?action=wpcrud_res&res=jquery.datetimepicker.css");
	}

	/**
	 * Main entry point.
	 */
	public static function admin_menu() {
		$instance=new static();

		if ($instance->submenuSlug)
			$screenId=add_submenu_page(
				$instance->submenuSlug,
				"Manage ".$instance->typeName,
				"Manage ".$instance->typeName,
				"manage_options",
				strtolower($instance->typeName),
				array($instance,"list_handler")
			);

		else
			$screenId=add_menu_page(
				$instance->typeName,
				$instance->typeName,
				"manage_options",
				strtolower($instance->typeName),
				array($instance,"list_handler")
			);

	    add_submenu_page(NULL, "Edit ".$instance->typeName, "Edit ".$instance->typeName, 'activate_plugins', $instance->typeId.'_form', array($instance,"form_handler"));
	}

	/**
	 * Setup.
	 */
	public static function setup() {
		add_action("admin_menu",get_called_class()."::admin_menu");
		add_action("admin_enqueue_scripts","WpCrud::admin_enqueue_scripts");
		add_action("wp_ajax_wpcrud_res","WpCrud::res");
	}
}