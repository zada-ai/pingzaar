<?php
/**
 * Script Installer
 * @author Titan Systems
 */

class Install_Controller extends MVC_Controller
{
	public function index()
	{
		define("install", true);
		
		$this->smarty->assign("data", [
			"timezones" => $this->timezones->generate(),
            "countries" => \CountryCodes::get("alpha2", "country")
		]);

		$this->smarty->display("_install/default.tpl");
	}

	public function request()
	{
		$this->header->allow();

		$request = $this->sanitize->array($_POST);

		if(!isset($request["site_name"], $request["protocol"], $request["site_desc"], $request["purchase_code"], $request["dbhost"], $request["dbport"], $request["dbname"], $request["dbuser"], $request["dbpass"], $request["name"], $request["email"], $request["password"], $request["timezone"], $request["country"]))
			response(500, "Some fields are empty!");

		if(!$this->sanitize->isEmail($request["email"]))
			response(500, "Invalid email address!");

		if(!$this->sanitize->length($request["name"]))
			response(500, "Name is too short!");

		if(!$this->sanitize->length($request["password"], 5))
			response(500, "Password is too short!");

		if(!in_array($request["timezone"], $this->timezones->generate()))
        	response(500, "Invalid Request!");

        if(!array_key_exists($request["country"], \CountryCodes::get("alpha2", "country")))
        	response(500, "Invalid Request!");

		try {
			new Thamaraiselvam\MysqlImport\Import("db.sql", $request["dbuser"], $request["dbpass"], $request["dbname"], $request["dbhost"], $request["dbport"]);
		} catch(Exception $e){
			response(500, "Invalid Database Credentials!");
		}

		try {
			$systoken = hash("sha256", password_hash(uniqid(time(), true), PASSWORD_DEFAULT));

			$env = <<<ENV
dbhost<=>{$request["dbhost"]}
dbport<=>{$request["dbport"]}
dbname<=>{$request["dbname"]}
dbuser<=>{$request["dbuser"]}
dbpass<=>{$request["dbpass"]}
systoken<=>{$systoken}
installed<=>true
ENV;

			$this->file->put("system/configurations/cc_env.inc", $env);
		} catch(Exception $e){
			response(500, "Invalid Database Credentials!");
		}

		$filtered = [
			"role" => 1,
			"email" => $this->sanitize->email($request["email"]),
			"password" => password_hash($request["password"], PASSWORD_DEFAULT),
			"credits" => 0,
			"earnings" => 0,
			"name" => $request["name"],
			"country" => $request["country"],
			"language" => 1,
			"theme_color" => "light",
			"providers" => false,
			"alertsound" => 1,
			"suspended" => 0,
			"timezone" => $request["timezone"],
			"formatting" => false,
			"confirmed" => 1,
			"partner" => 1
		];

		$qKeys = [];
		$qValues = [];

		foreach($filtered as $key => $value):
			$qKeys[] = $key;
			$qValues[] = is_int($value) ? $value : (is_bool($value) ? "\"\"" : "\"{$value}\"");
		endforeach;

		$qKeys = implode(",", $qKeys);
		$qValues = implode(",", $qValues);

		try {
			$query = <<<SQL
INSERT INTO users ({$qKeys}) VALUES ({$qValues});
UPDATE settings SET value = "{$request["site_name"]}" WHERE name = "site_name";
UPDATE settings SET value = "{$request["site_desc"]}" WHERE name = "site_desc";
UPDATE settings SET value = "{$request["purchase_code"]}" WHERE name = "purchase_code";
UPDATE settings SET value = "{$request["protocol"]}" WHERE name = "protocol";
SQL;

			if($this->file->put("populate.sql", $query)):
				new Thamaraiselvam\MysqlImport\Import("populate.sql", $request["dbuser"], $request["dbpass"], $request["dbname"], $request["dbhost"], $request["dbport"]);
			else:
				response(500, "Unable to populate the database!");
			endif;
		} catch(Exception $e){
			response(500, "Something went wrong!");
		}

		// Compile theme CSS files with default settings
		try {
			// Construct site URL for background gradient (following mvc_functions.php pattern)
			$serverName = isset($_SERVER["SERVER_NAME"]) ? $_SERVER["SERVER_NAME"] : php_uname("n");
			$protocol = ($request["protocol"] < 2 ? "http://" : "https://");
			$port = (isset($_SERVER["SERVER_PORT"]) && !in_array($_SERVER["SERVER_PORT"], [80, 443])) ? ":{$_SERVER["SERVER_PORT"]}" : "";
			$subdir = (isset($_SERVER["SCRIPT_NAME"]) && !empty(explode("/", dirname($_SERVER["SCRIPT_NAME"]))[1])) ? dirname($_SERVER["SCRIPT_NAME"]) : "";
			$fullSiteUrl = $protocol . $serverName . $port . $subdir;
			$bgUrl = "../../../../uploads/theme/default-bg.jpg";

			// Verify SCSS source files exist
			$requiredScss = [
				"templates/_scss/dashboard.scss",
				"templates/_scss/dashboard.rtl.scss",
				"templates/_scss/default.scss",
				"templates/_scss/default.rtl.scss"
			];

			foreach($requiredScss as $scssFile):
				if(!file_exists($scssFile)):
					throw new Exception("Required SCSS file not found: {$scssFile}");
				endif;
			endforeach;

			// Ensure output directories exist and are writable
			$outputDirs = [
				"templates/dashboard/assets/css",
				"templates/default/assets/css",
				"templates/_assets/js",
				"templates/_assets/css"
			];

			foreach($outputDirs as $dir):
				if(!is_dir($dir)):
					throw new Exception("Output directory not found: {$dir}");
				endif;
				if(!is_writable($dir)):
					throw new Exception("Output directory not writable: {$dir}");
				endif;
			endforeach;

			// Initialize SCSS compiler with default theme colors
			$this->scss->setVariables([
				"theme" => "#333333",           // Default theme_background (SCSS will convert to RGB)
				"themeText" => "#ffffff",       // Default theme_highlight
				"themeSpinner" => "#333333"     // Default theme_spinner
			]);

			$this->scss->setFormatter("ScssPhp\ScssPhp\Formatter\Crunched");
			$this->scss->setImportPaths("templates/_scss/");

			// Compile dashboard LTR styles (SCSS will auto-convert hex to RGB in rgba())
			$this->file->put("templates/dashboard/assets/css/style.min.css",
				$this->scss->compile("
					.navbar-dark.navbar-vibrant {
						background-image: linear-gradient(to bottom right,rgba(#333333,.4),rgba(#333333,.9)), url({$bgUrl}) !important;
					}
					@import 'dashboard.scss';
				")
			);

			// Compile public site LTR styles
			$this->file->put("templates/default/assets/css/style.min.css",
				$this->scss->compile("@import 'default.scss';")
			);

			// Compile dashboard RTL styles (SCSS will auto-convert hex to RGB in rgba())
			$this->file->put("templates/dashboard/assets/css/style.rtl.min.css",
				$this->scss->compile("
					.navbar-dark.navbar-vibrant {
						background-image: linear-gradient(to bottom right,rgba(#333333,.4),rgba(#333333,.9)), url({$bgUrl}) !important;
					}
					@import 'dashboard.rtl.scss';
				")
			);

			// Compile public site RTL styles
			$this->file->put("templates/default/assets/css/style.rtl.min.css",
				$this->scss->compile("@import 'default.rtl.scss';")
			);

			// Initialize custom JavaScript files
			$customScript = <<<JAVASCRIPT
window._customSystem = {
	hookOnload: () => {},
	hookOnloaded: () => {}
}
JAVASCRIPT;

			$this->file->put("templates/_assets/js/custom.run.js", $customScript);
			$this->file->put("templates/_assets/js/custom.js", "");
			$this->file->put("templates/_assets/css/custom.css", "");

		} catch(Exception $e){
			// Theme compilation failed, but installation can continue
			// CSS files will be regenerated on first admin theme save
		}

		rmrf("templates/_install");
		$this->file->delete("db.sql");
		$this->file->delete("populate.sql");
		$this->file->delete("system/controllers/install.php");

		response(200);
	}
}