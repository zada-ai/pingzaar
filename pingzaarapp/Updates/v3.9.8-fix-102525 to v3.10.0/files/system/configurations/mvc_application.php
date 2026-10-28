<?php
/**
 * Framework Application Config
 * @package MVC Framework
 * @author Titan Systems <mail@titansystems.ph>
 */

get_configs();

define("configuration", [
	"root_controller" => false,
	"root_action" => false,
	"default_controller" => "default",
	"default_action" => "index",
	"error_handler_class" => "MVC_ErrorHandler"
]);

define("autoload", [
	"libraries" => [
		["Datatables", "datatable"],
		["Timezones", "timezones"],
		["PhoneNumber", "phone"],
		["Sanitize", "sanitize"],
		["Spintax", "spintax"],
		["Process", "process"],
		["Whatsapp", "wa"],
		["Titansys", "titansys"],
		["Session", "session"],
		["Smarty", "smarty"],
		["Header", "header"],
		["Guzzle", "guzzle"],
		["Upload", "upload"],
		["Sheets", "sheet"],
		["Cache", "cache"],
		["Zip", "zip"],
		["File", "file"],
		["SCSS", "scss"],
		["Hash", "hash"],
		["Echo", "echo"],
		["Mail", "mail"],
		["Fcm", "fcm"],
		["URI", "url"],
		["Lex", "lex"],
		["Flow", "flowlib"],
		["Ai", "aiprovider"]
	],
	"models" => [
		["Api_Model", "api"],
		["Cron_Model", "cron"],
		["Admin_Model", "admin"],
		["Table_Model", "table"],
		["Social_Model", "social"],
		["Widget_Model", "widget"],
		["System_Model", "system"],
		["Device_Model", "device"],
		["Gateway_Model", "gateway"],
		["Whatsapp_Model", "whatsapp"],
		["Ai_Model", "ai"],
		["Flow_Model", "flow"]
	]
]);