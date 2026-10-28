<?php
/**
 * @controller Requests
 * @desc Handles all ajax requests from front-end
 */

class Requests_Controller extends MVC_Controller
{
	public function index()
	{	
		$this->header->allow(site_url);

		$type = $this->sanitize->string($this->url->segment(4));
		$request = $this->sanitize->array($_REQUEST);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        $this->cache->container("system.plugins");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getPlugins());
        endif;

        set_plugins($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

		switch($type):
			case "login":
				if($this->session->has("logged"))
	            	response(302);

				if(!isset($request["email"], $request["password"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isEmail($request["email"]))
					response(500, __("response_invalid_emailpass"));

				if(!$this->sanitize->length($request["password"], 5))
					response(500, __("response_invalid_emailpass"));

				if(system_recaptcha < 2):
					if(empty(system_recaptcha_key) || empty(system_recaptcha_secret))
	            		response(500, __("recaptcha_add_keys"));

					if(!isset($request["g-recaptcha-response"]))
						response(500, __("response_invalid"));
				endif;
				
				if(system_recaptcha < 2):
					try {
		            	$recaptcha = json_decode($this->guzzle->get("https://www.recaptcha.net/recaptcha/api/siteverify?secret=" . system_recaptcha_secret . "&response={$request["g-recaptcha-response"]}", [
			                "http_errors" => false
			            ])->getBody()->getContents());
		            } catch(Exception $e){
		            	response(500, __("response_went_wrong"));
		            }
		        endif;

				$filtered = [
					"email" => $this->sanitize->email($request["email"]),
					"password" => $request["password"]
				];

				if(system_recaptcha < 2 && !$recaptcha->success)
					response(500, __("response_solve_captcha"));

				if($this->system->checkEmail($filtered["email"]) > 0):
					$raw = $this->system->getPassword($filtered["email"]);

					if($raw["suspended"] > 0)
						response(500, __("response_suspended"));

					$userAccount = $this->system->getUser($raw["id"]);

					if(!in_array("register_confirm", explode(",", system_mailing_triggers))):
						$userAccount["confirmed"] = 1;
					endif;

					if($userAccount["confirmed"] < 2):
						$this->cache->container("register.confirm", true);
						
						if($this->cache->has($filtered["email"])):
							$this->cache->delete($filtered["email"]);	

							$this->session->delete("language");
							$this->session->set("logged", $userAccount);

							response(301, __("response_loggedin_success"));
						else:
							$this->cache->container("forgot.confirm", true);

							// Check temporary password first
							$tempPasswordValid = false;
							if($this->cache->has($filtered["password"])) {
								// SECURITY FIX: Verify the temporary password belongs to this email
								$storedEmail = $this->cache->get($filtered["password"]);
								if($storedEmail === $filtered["email"]) {
									$tempPasswordValid = true;
									$this->cache->delete($filtered["password"]); // Delete after use
									// Log successful temporary password usage
									$this->file->put("system/storage/temporary/security.log", "Temporary password used successfully for email: {$filtered["email"]} at " . date("m/d/Y g:i A") . "\n", FILE_APPEND);
								} else {
									// Log potential security abuse - temporary password used with wrong email
									$this->file->put("system/storage/temporary/security.log", "SECURITY ALERT: Temporary password from {$storedEmail} attempted with different email: {$filtered["email"]} at " . date("m/d/Y g:i A") . "\n", FILE_APPEND);
								}
							}

							if($tempPasswordValid || password_verify($filtered["password"], $raw["password"])):
								$this->cache->container("register.confirm", true);
								$this->cache->delete($filtered["email"]);

								$this->session->delete("language");
								$this->session->set("logged", $userAccount);

								response(301, __("response_loggedin_success"));
							else:
								response(500, __("response_invalid_emailpass"));
							endif;
						endif;
					else:
        				$this->cache->container("register.confirm", true);

        				$confirmHash = $this->hash->encode(rand(0, time()), system_token);
        				$confirmLink = site_url("dashboard/auth/register", true) . "/{$confirmHash}";

        				$this->cache->set($confirmHash, $userAccount["id"], 600);

        				$mailContent = <<<HTML
						<p>{$GLOBALS["__"]("requests_loginnotverified_emaildesc1")}</p>
						<p>{$GLOBALS["__"]("requests_loginnotverified_emaildesc2")}</p> 
						<p><a href="{$confirmLink}">{$confirmLink}</a></p>
						<p>{$GLOBALS["__"]("requests_loginnotverified_emaildesc3")}</p>
						HTML;

        				if($this->mail->send([
							"title" => system_site_name,
							"data" => [
								"subject" => mail_title(___(__("requests_loginnotverified_emailtitle"), [system_site_name])),
								"content" => $mailContent
							]
						], $filtered["email"], "_mail/default.tpl", $this->smarty)):
        					response(200, ___(__("requests_loginnotverified_response"), ["<strong>{$filtered["email"]}</strong>"]));
            			else:
            				response(500, __("requests_loginnotverified_responseunable"));
            			endif;
					endif;
				else:
					response(500, __("response_invalid_emailpass"));
				endif;

				break;
			case "forgot":
				if($this->session->has("logged"))
	            	response(301, __("response_session_true"));

				if(!isset($request["email"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isEmail($request["email"]))
					response(500, __("response_invalid_email"));

				if(system_recaptcha < 2):
					if(empty(system_recaptcha_key) || empty(system_recaptcha_secret))
	            		response(500, __("recaptcha_add_keys"));

					if(!isset($request["g-recaptcha-response"]))
						response(500, __("response_invalid"));
				endif;

				$filtered = [
					"email" => $this->sanitize->email($request["email"]),
				];

				if(system_recaptcha < 2):
				 	try {
		            	$recaptcha = json_decode($this->guzzle->get("https://www.recaptcha.net/recaptcha/api/siteverify?secret=" . system_recaptcha_secret . "&response={$request["g-recaptcha-response"]}", [
			                "http_errors" => false
			            ])->getBody()->getContents());
		            } catch(Exception $e){
		            	response(500, __("response_went_wrong"));
		            }
		        endif;

		        if(system_recaptcha < 2 && !$recaptcha->success)
					response(500, __("response_solve_captcha"));

				if($this->system->checkEmail($filtered["email"]) > 0):
					$this->cache->container("forgot.confirm", true);

					$temporaryPass = $this->hash->encode(rand(0, time()), system_token);

					$this->cache->set($temporaryPass, $filtered["email"], 600);

					// Log password reset request for security auditing
					$this->file->put("system/storage/temporary/security.log", "Password reset requested for email: {$filtered["email"]} at " . date("m/d/Y g:i A") . "\n", FILE_APPEND);
					
					if($this->mail->send([
						"title" => system_site_name,
						"data" => [
							"subject" => mail_title(__("response_retrieval_received")),
							"password" => $temporaryPass
						]
					], $filtered["email"], "_mail/forgot.tpl", $this->smarty)):
						response(200, ___(__("forgot_response_recoverysent"), [$filtered["email"]]));
					else:
						response(500, __("forgot_response_recoveryfail"));
					endif;
				else:
					response(500, __("response_invalid_email"));
				endif;

				break;
			case "register":
				if($this->session->has("logged"))
	            	response(302);

	            if(system_registrations > 1)
	            	response(500, __("response_register_false"));
	            
	            if(!isset($request["name"], $request["email"], $request["timezone"], $request["country"], $request["password"], $request["cpassword"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["name"]))
	            	response(500, __("response_name_short"));

	            if(!$this->sanitize->isEmail($request["email"]))
	            	response(500, __("response_invalid_email"));

	            if(!in_array($request["timezone"], $this->timezones->generate()))
	            	response(500, __("response_invalid"));

	            if(!array_key_exists($request["country"], \CountryCodes::get("alpha2", "country")))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["password"], 5))
	            	response(500, __("response_password_short"));

	            if($request["password"] !== $request["cpassword"])
	            	response(500, __("response_password_notmatch"));

	            if(system_recaptcha < 2):
					if(empty(system_recaptcha_key) || empty(system_recaptcha_secret))
	            		response(500, __("recaptcha_add_keys"));

					if(!isset($request["g-recaptcha-response"]))
						response(500, __("response_invalid"));
				endif;

	            if(system_recaptcha < 2):
		            try {
		            	$recaptcha = json_decode($this->guzzle->get("https://www.recaptcha.net/recaptcha/api/siteverify?secret=" . system_recaptcha_secret . "&response={$request["g-recaptcha-response"]}", [
			                "http_errors" => false
			            ])->getBody()->getContents());
		            } catch(Exception $e){
		            	response(500, __("response_went_wrong"));
		            }
		        endif;

	            if(system_recaptcha < 2 && !$recaptcha->success)
					response(500, __("response_solve_captcha"));

            	$filtered = [
            		"role" => 1,
            		"name" => $request["name"],
            		"email" => $this->sanitize->email($request["email"]),
            		"credits" => 0,
            		"earnings" => 0,
            		"language" => system_default_lang,
					"theme_color" => system_default_scheme,
            		"suspended" => 0,
            		"providers" => false,
            		"alertsound" => 1,
            		"timezone" => strtolower($request["timezone"]),
            		"formatting" => false,
            		"country" => $request["country"],
            		"partner" => 2,
            		"confirmed" => 2,
            		"password" => password_hash($request["password"], PASSWORD_DEFAULT)
            	];

            	if($this->system->checkEmail($filtered["email"]) < 1):
            		$create = $this->system->create("users", $filtered);

            		if($create):
            			if(!empty(system_mailing_address) && in_array("admin_new_user", explode(",", system_mailing_triggers))):
            				$mailingContent = <<<HTML
							<p>Hi there!</p>
							<p>This is to inform you that a new user with email <strong>{$filtered["email"]}</strong> have registered!</p> 
							HTML;

	            			$this->mail->send([
								"title" => system_site_name,
								"data" => [
									"subject" => mail_title("Admin Alert Message from " . system_site_name . "!"),
									"content" => $mailingContent
								]
							], system_mailing_address, "_mail/default.tpl", $this->smarty);
	            		endif;

            			if(in_array("register_confirm", explode(",", system_mailing_triggers))):
            				$this->cache->container("register.confirm", true);

            				$confirmHash = $this->hash->encode(rand(0, time()), system_token);
            				$confirmLink = site_url("dashboard/auth/register", true) . "/{$confirmHash}";

            				$this->cache->set($confirmHash, $create, 600);

            				$mailContent = <<<HTML
							<p>{$GLOBALS["__"]("requests_loginnotverified_emaildesc1")}</p>
							<p>{$GLOBALS["__"]("requests_loginnotverified_emaildesc2")}</p> 
							<p><a href="{$confirmLink}">{$confirmLink}</a></p>
							<p>{$GLOBALS["__"]("requests_loginnotverified_emaildesc3")}</p>
							HTML;

            				if($this->mail->send([
								"title" => system_site_name,
								"data" => [
									"subject" => mail_title(___(__("requests_loginnotverified_emailtitle"), [system_site_name])),
									"content" => $mailContent
								]
							], $filtered["email"], "_mail/default.tpl", $this->smarty)):
            					response(200, ___(__("requests_loginnotverified_response2"), ["<strong>{$filtered["email"]}</strong>"]));
	            			else:
	            				response(500, __("requests_loginnotverified_responseunable"));
	            			endif;
            			else:
            				$this->system->update($create, false, "users", [
            					"confirmed" => 1
            				]);

            				$this->session->set("logged", 
	            				$this->system->getUser($create)
	            			);

	            			$this->cache->container("system.users");
	    					$this->cache->clear();

							response(301, __("response_register_success"));
            			endif;
            		else:
            			response(500, __("response_went_wrong"));
            		endif;
            	else:
            		response(500, __("response_email_unavailable"));
            	endif;

				break;
			case "logout":
				if(isset($request["error"])):
					$this->session->destroy();
					$this->header->redirect(site_url("dashboard"));
				endif;
				
				if(!$this->session->has("logged"))
	            	response(302);

	            if($this->session->destroy())
	            	response(200, __("response_loggedout_success"));
	            else
	            	response(500, __("response_went_wrong"));

				break;
			case "livechat":
				if(!$this->session->has("logged"))
	            	response(302);

	            if(system_livechat < 2):
	            	response(200, false, logged_name);
				else:
					response(202);
				endif;			
	            
				break;
			case "support":
				if(!$this->session->has("logged"))
	            	response(302);

	            if(!super_admin)
					response(500, __("response_no_permission"));

	            response(200, false, "https://titansys.dev");
	            
				break;
			case "regenerate_admintoken":
				if(!$this->session->has("logged"))
					response(302);

				if(!super_admin)
					response(500, __("response_no_permission"));

				$token = hash("sha256", password_hash(uniqid(time(), true), PASSWORD_DEFAULT));

				$this->system->settings("admin_token", $token);

				$this->cache->container("system.settings");
				$this->cache->clear();

	            response(200, "Admin token has been regenerated!", $token);
				
				break;
			case "regenerate_systoken":
				if(!$this->session->has("logged"))
	            	response(302);

	            if(!super_admin)
					response(500, __("response_no_permission"));

				$token = hash("sha256", password_hash(uniqid(time(), true), PASSWORD_DEFAULT));

				$env = explode("\n", $this->file->get("system/configurations/cc_env.inc"));

				$newEnv = "";

				foreach($env as $row):
					$line = explode("<=>", trim($row));

					if(isset($line[0]) && $line[0] == "systoken"):
						$newEnv .= "{$line[0]}<=>{$token}\n";
					elseif(isset($line[0]) && isset($line[1])):
						$newEnv .= "{$line[0]}<=>{$line[1]}\n";
					endif;
				endforeach;

				$this->file->put("system/configurations/cc_env.inc", trim($newEnv));

	            response(200, __("response_token_generated"), $token);
	            
				break;
			case "impersonate":
				if(!$this->session->has("logged"))
	            	response(302);

				if(!impersonate):
					if(!is_admin)
						response(500, __("response_no_permission"));

					if(!permission("manage_users"))
						response(500, __("response_no_permission"));
				endif;

				if(!isset($request["uid"], $request["type"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["uid"]))
					response(500, __("response_invalid"));

				if($request["type"] == "entry"):
					$userAccount = $this->system->getUser($request["uid"]);

					if($userAccount):
						$this->session->set("logged", $userAccount);
						$this->session->set("impersonate", [
							"admin" => logged_id
						]);

						response(200, __("response_adminuserimpersonate_entry"));
					else:
						response(500, __("response_invalid"));
					endif;
				else:
					$impersonateInfo = $this->session->get("impersonate");
					$adminAccount = $this->system->getUser($impersonateInfo["admin"]);

					if($adminAccount):
						$this->session->set("logged", $adminAccount);
						$this->session->delete("impersonate");

						response(200, __("response_adminuserimpersonate_exit"));
					else:
						response(500, __("response_invalid"));
					endif;
				endif;

				break;
			case "trigger":
				if(!$this->session->has("logged"))
	            	response(302);

	            $request = $this->sanitize->array($_POST);

	            if(!isset($request["secret"], $request["type"], $request["url"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->isUrl($request["url"]))
	            	response(500, __("response_invalid"));

	            try {
		            $phoneSample = $this->phone->getExampleNumber(logged_country, Brick\PhoneNumber\PhoneNumberType::MOBILE);
		        } catch(Exception $e){
		            $phoneSample = "+63123456789";
		        }

	            switch($request["type"]):
	            	case "sms":
	            		$this->guzzle->post($request["url"], [
				            "form_params" => [
				            	"secret" => $request["secret"],
				            	"type" => "sms",
				            	"data" => [
			            			"id" => rand(1, 5000), 
							        "rid" => rand(1, 10000),
							        "sim" => rand(1, 2), 
							        "device" => "00000000-0000-0000-d57d-f30cb6a89289", 
							        "phone" => $phoneSample, 
							        "message" => "Hello World!", 
							        "timestamp" => time() 
				            	]
				            ],
				            "allow_redirects" => true,
				            "http_errors" => false
				        ]);

	            		break;
					case "whatsapp":
						$this->guzzle->post($request["url"], [
							"form_params" => [
								"secret" => $request["secret"],
								"type" => "whatsapp",
								"data" => [
									"id" => rand(1, 5000), 
									"wid" => $phoneSample, 
									"phone" => $phoneSample, 
									"message" => "Hello World!", 
									"attachment" => site_url("/uploads/whatsapp/received/1682013836c4ca4238a0b923820dcc509a6f75849b64417e8c8eac5/1.jpg", true), 
									"timestamp" => time() 
								]
							],
							"allow_redirects" => true,
							"http_errors" => false
						]);

						break;
	            	case "ussd":
	            		$this->guzzle->post($request["url"], [
				            "form_params" => [
				            	"secret" => $request["secret"],
				            	"type" => "ussd",
				            	"data" => [
			            			"id" => rand(1, 5000),
							        "sim" => rand(1, 2), 
							        "device" => "00000000-0000-0000-d57d-f30cb6a89289", 
							        "code" => "*143#", 
							        "response" => "Sorry! You are not allowed to use this service.", 
							        "timestamp" => time()  
				            	]
				            ],
				            "allow_redirects" => true,
				            "http_errors" => false
				        ]);

	            		break;
	            	default:
	            		response(500, __("response_invalid"));
	            endswitch;

	            response(200, __("response_trigger_done"));

				break;
			default:
				response(500, __("response_invalid"));
		endswitch;
	}

	public function languages()
	{
		$this->header->allow(site_url);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

        $type = $this->sanitize->string($this->url->segment(4));

        if($type == "change"):
        	$language = $this->sanitize->string($this->url->segment(5));

			if(!$this->sanitize->isInt($language))
				response(500, __("response_invalid"));

	        if($this->system->checkLanguage($language) < 1)
	        	response(500, __("response_invalid"));

			if(logged_id):
				$this->system->update(logged_id, false, "users", [
					"language" => $language
				]);
			endif;

			$this->session->set("language", $language);

			response(200, __("response_lang_changed"));
        else:
        	$languages = $this->system->getLanguages();

        	$languageIndex = 1;
			$languageArray = [];

	        foreach($languages as $language):
	        	if($languageIndex < 4):
	        		$languageArray[] = <<<HTML
					<li>
					    <a href="#" data-mfb-label="{$language["name"]}" class="mfb-component__button--child" system-language="{$language["id"]}">
					        <i class="mfb-component__child-icon flag-icon flag-icon-{$language["iso"]}"></i>
					    </a>
					</li>
					HTML;
				endif;
				
				$languageIndex++;
	        endforeach;

	        if(count($languages) > 3):
	        	$label = __("all_languages");

	        	$languageArray[] = <<<HTML
				<li>
				    <a href="#" data-mfb-label="{$label}" class="mfb-component__button--child bg-dark" system-toggle="languages">
				        <i class="mfb-component__child-icon la la-braille la-lg text-white more-lang"></i>
				    </a>
				</li>
				HTML;
	        endif;
			
	        response(200, false, $languageArray);
        endif;
	}

	public function echo()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
            response(401);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

        $type = $this->sanitize->string($this->url->segment(4));

        switch($type):
        	case "reload":
		        $this->session->set("logged",
					$this->system->getUser(logged_id)
				);

				response(200);

				break;
			case "auth":
				// This is the one endpoint in the app consumed by a third-party
				// library rather than our own JS, and pusher-js reads the HTTP
				// status, not a status field in the body -- it treats ANY 200 as
				// a successful authorisation. So this case sets real status
				// codes instead of using response(), which always returns 200.
				$request = $this->sanitize->array($_POST);

				if(!isset($request["socket_id"], $request["channel_name"]))
					deny(400);

				if(!preg_match('/^[0-9]+\.[0-9]+$/', $request["socket_id"]))
					deny(400);

				// The only channel a session may ever hold is its own. Every
				// other name -- including another user's -- is refused here,
				// which is what makes the guessable md5(id) name harmless.
				if($request["channel_name"] !== "private-user-" . logged_hash)
					deny(403);

				$auth = $this->echo->authorize($request["socket_id"], $request["channel_name"]);

				if(!$auth)
					deny(401);

				die(json_encode(["auth" => $auth]));

				break;
			case "broadcast":
				$id = $this->sanitize->string($this->url->segment(5));

				if(!$this->sanitize->isInt($id))
					response(400);

				$marketing = $this->system->getMarketing($id);

				// The ping carries only an id, so membership is decided here
				// rather than in the browser. A non-recipient learns nothing
				// beyond the fact that some broadcast exists -- a missing row
				// and a row addressed elsewhere return the same 204.
				//
				// Roles are resolved now rather than frozen into the row when
				// the broadcast was created. That keeps marketing.users meaning
				// the same thing for every marketing type, and stops a
				// role-wide broadcast writing one id per member into a TEXT
				// column it could eventually overflow.
				if(!$marketing)
					response(204);

				$recipients = explode(",", $marketing["users"]);
				$audience = explode(",", $marketing["roles"]);

				if(!in_array((string) logged_id, $recipients, true)
					&& !in_array((string) logged_role, $audience, true))
					response(204);

				response(200, false, [
					"title" => $marketing["title"],
					"content" => $marketing["content"],
					"image" => $marketing["image"] && $this->file->exists("uploads/temporary/{$marketing["image"]}.png")
						? site_url("uploads/temporary/{$marketing["image"]}.png", true)
						: false
				]);

				break;
			case "test":
				if(!super_admin)
					response(403, __("response_no_permission"));

				$request = $this->sanitize->array($_POST);

				// A blank credential means the admin left the masked field
				// untouched, so fall back to what is already stored.
				$credentials = [
					"driver" => isset($request["driver"]) ? $request["driver"] : "pusher",
					"app_id" => isset($request["app_id"]) ? $request["app_id"] : "",
					"key" => !empty($request["key"]) ? $request["key"] : (defined("system_realtime_key") ? system_realtime_key : ""),
					"secret" => !empty($request["secret"]) ? $request["secret"] : (defined("system_realtime_secret") ? system_realtime_secret : ""),
					"cluster" => isset($request["cluster"]) ? $request["cluster"] : "",
					"host" => isset($request["host"]) ? $request["host"] : ""
				];

				// This is the only place a request can name the host the server
				// then connects to, so refuse the targets that could never be a
				// realtime server but are prime forgery targets.
				if($credentials["driver"] === "compatible" && !$this->echo->reachable($credentials["host"]))
					response(500, __("response_realtime_testfailed"));

				if($this->echo->using($credentials)->trigger("realtime-connection-test", "test", ["ping" => true]))
					response(200, __("response_realtime_testok"));

				response(500, __("response_realtime_testfailed"));

				break;
			default:
				$config = $this->echo->config();

				if(!$config)
					response(401);

				$config["id"] = logged_id;
				$config["hash"] = logged_hash;

				response(200, false, $config);
		endswitch;
	}

	public function whatsapp()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
			response(401);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

		$request = $this->sanitize->array($_POST);
		$type = $this->sanitize->string($this->url->segment(4));

		switch($type):
			case "link":
				// wa_server is now optional - removed the required check

				$subscription = set_subscription(
					$this->system->checkSubscription(logged_id), 
					$this->system->getSubscription(false, logged_id), 
					$this->system->getSubscription(false, false, true)
				);
	
				if(empty($subscription))
					response(500, __("response_whatsapp_notprem"));
	
				if(limitation($subscription["wa_account_limit"], $this->system->countWaAccounts(logged_id)))
					response(500, __("response_whatsapp_maxaccounts"));
				
				$waServers = $this->system->getWaServers($subscription["pid"]);

				if(empty($waServers))
					response(500, __("requests_whatsapp_notavail"));
	
				// Automatic server selection logic
				$selectedServer = null;
				$currentCount = 0;
				
				// Check if manual server selection was made
				if(isset($request["wa_server"]) && !empty($request["wa_server"])):
					// Manual server selection - validate capacity
					if(!isset($waServers[$request["wa_server"]])):
						response(500, __("response_invalid"));
					endif;
					
					$candidateServer = $waServers[$request["wa_server"]];
					
					// Check server connection
					if($this->wa->check($candidateServer["url"], $candidateServer["port"])):
						// Check server capacity
						$currentCount = $this->wa->total($candidateServer["secret"], $candidateServer["url"], $candidateServer["port"]);
						
						if($currentCount !== false && $currentCount >= $candidateServer["accounts"]):
							response(403, __("requests_whatsapp_notavail"));
						else:
							$selectedServer = $candidateServer;
						endif;
					else:
						response(500, __("response_whatsapp_noconnectserver"));
					endif;
				else:
					// Automatic server selection - find first available server
					foreach($waServers as $serverId => $server):
						// Check server connection
						if($this->wa->check($server["url"], $server["port"])):
							// Check server capacity
							$currentCount = $this->wa->total($server["secret"], $server["url"], $server["port"]);
							
							if($currentCount !== false && $currentCount < $server["accounts"]):
								$selectedServer = $server;
								break;
							endif;
						endif;
					endforeach;
					
					if(!$selectedServer):
						response(503, __("requests_whatsapp_notavail"));
					endif;
				endif;

				// Create QR code with selected server
				$qrResult = $this->wa->create($selectedServer["secret"], logged_id, logged_hash, $selectedServer["id"], $selectedServer["url"], $selectedServer["port"]);

				if($qrResult):
					response(200, false, $qrResult);
				else:
					response(500, __("requests_whatsapp_unablegenqr"));
				endif;

				break;
			case "relink":
				if(!isset($request["unique"]))
					response(500, __("response_invalid"));

				if($this->system->checkWaAccount(logged_id, $request["unique"], "unique") < 1)
					response(500, __("response_invalid"));

				$subscription = set_subscription(
					$this->system->checkSubscription(logged_id), 
					$this->system->getSubscription(false, logged_id), 
					$this->system->getSubscription(false, false, true)
				);
	
				if(empty($subscription))
					response(500, __("response_whatsapp_notprem"));
				
				$waServers = $this->system->getWaServers($subscription["pid"]);

				if(empty($waServers))
					response(500, __("requests_whatsapp_notavail"));
	
				// Get current account details
				$currentAccount = $this->system->getWaAccount(logged_id, $request["unique"], "unique");
				
				if(!$currentAccount)
					response(500, __("response_invalid"));
				
				// Automatic server selection logic for relink
				$selectedServer = null;
				$selectedServerId = null;
				
				if(isset($request["wa_server"]) && !empty($request["wa_server"])):
					// Manual server selection for relink
					if(!isset($waServers[$request["wa_server"]])):
						response(500, __("response_invalid"));
					endif;
					
					$candidateServer = $waServers[$request["wa_server"]];
					$selectedServerId = $request["wa_server"];
					
					// Check server connection
					if($this->wa->check($candidateServer["url"], $candidateServer["port"])):
						// Check server capacity (if different server)
						if($selectedServerId != $currentAccount["wsid"]):
							$currentCount = $this->wa->total($candidateServer["secret"], $candidateServer["url"], $candidateServer["port"]);
							
							if($currentCount !== false && $currentCount >= $candidateServer["accounts"]):
								response(403, __("requests_whatsapp_notavail"));
							endif;
						endif;
						$selectedServer = $candidateServer;
					else:
						response(500, __("response_whatsapp_noconnectserver"));
					endif;
				else:
					// Automatic server selection for relink - prefer current server if available
					if(isset($waServers[$currentAccount["wsid"]])):
						$currentServer = $waServers[$currentAccount["wsid"]];
						if($this->wa->check($currentServer["url"], $currentServer["port"])):
							$selectedServer = $currentServer;
							$selectedServerId = $currentAccount["wsid"];
						endif;
					endif;
					
					// If current server not available, find another
					if(!$selectedServer):
						foreach($waServers as $serverId => $server):
							if($this->wa->check($server["url"], $server["port"])):
								$currentCount = $this->wa->total($server["secret"], $server["url"], $server["port"]);
								
								if($currentCount !== false && $currentCount < $server["accounts"]):
									$selectedServer = $server;
									$selectedServerId = $serverId;
									break;
								endif;
							endif;
						endforeach;
					endif;
					
					if(!$selectedServer):
						response(503, __("requests_whatsapp_notavail"));
					endif;
				endif;

				// Delete from current server
				$oldServer = $this->system->getWaServer($currentAccount["wsid"], "id");
				if($oldServer && $this->wa->check($oldServer["url"], $oldServer["port"])):
					$this->wa->delete($oldServer["secret"], $oldServer["url"], $oldServer["port"], $request["unique"]);
				endif;

				// Create on selected server
				$qrResult = $this->wa->create($selectedServer["secret"], logged_id, logged_hash, $selectedServer["id"], $selectedServer["url"], $selectedServer["port"], $request["unique"]);

				if($qrResult):
					// Update server ID if changed
					$this->system->updateWaAccount(false, $request["unique"], [
						"wsid" => $selectedServerId
					]);

					response(200, false, $qrResult);
				else:
					response(500, __("requests_whatsapp_unablegenqr"));
				endif;

				break;
			default:
				response(500, __("response_invalid"));
		endswitch;
	}

	public function chart()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
            response(401);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

        $type = $this->sanitize->string($this->url->segment(4));

		switch($type):
			case "dashboard.messages":
				$this->cache->container("user." . logged_hash, true);

				if(!$this->cache->has("statistics.messages")):
					$sent = []; 
					$received = [];
					$wa_sent = [];
					$wa_received = [];

					foreach($this->system->getStatisticsSent(logged_id) as $key => $value):
						$sent[] = [
							(int)("{$key}000"),
							count($value)
						];
					endforeach;

					foreach($this->system->getStatisticsReceived(logged_id) as $key => $value):
						$received[] = [
							(int)("{$key}000"),
							count($value)
						];
					endforeach;

					foreach($this->system->getStatisticsSent(logged_id, true) as $key => $value):
						$wa_sent[] = [
							(int)("{$key}000"),
							count($value)
						];
					endforeach;

					foreach($this->system->getStatisticsReceived(logged_id, true) as $key => $value):
						$wa_received[] = [
							(int)("{$key}000"),
							count($value)
						];
					endforeach;

					$series = [
						"sent" => $sent,
						"received" => $received,
						"wa_sent" => $wa_sent,
						"wa_received" => $wa_received
					];

					$this->cache->set("statistics.messages", $series, 3600);
				endif;

				$chart = $this->cache->get("statistics.messages");

				$vars = [
					"series" => [
						[
							"name" => strtoupper(__("response_chart_dashsmssent")),
							"data" => $chart["sent"]
						],
						[
							"name" => strtoupper(__("response_chart_dashsmsreceive")),
							"data" => $chart["received"]
						],
						[
							"name" => strtoupper(__("response_chart_dashwasent")),
							"data" => $chart["wa_sent"]
						],
						[
							"name" => strtoupper(__("response_chart_dashwareceive")),
							"data" => $chart["wa_received"]
						]
					],
					"colors" => [
						"#1ccf13", 
						"#1099da",
						"#fe9431",
						"#e82753"
					]
				];

				break;
			case "dashboard.events":
				$this->cache->container("user." . logged_hash, true);

				if(!$this->cache->has("statistics.events")):
					$webhook = []; 
					$actions = [];

					foreach($this->system->getStatisticsEvents(logged_id, 1) as $key => $value):
						$webhook[] = [
							(int)("{$key}000"),
							count($value)
						];
					endforeach;

					foreach($this->system->getStatisticsEvents(logged_id, 2) as $key => $value):
						$actions[] = [
							(int)("{$key}000"),
							count($value)
						];
					endforeach;

					$series = [
						"webhook" => $webhook,
						"actions" => $actions
					];

					$this->cache->set("statistics.events", $series, 3600);
				endif;

				$chart = $this->cache->get("statistics.events");

				$vars = [
					"series" => [
						[
							"name" => strtoupper(__("response_chart_dashwebhooks")),
							"data" => $chart["webhook"]
						],
						[
							"name" => strtoupper(__("response_chart_dashactions")),
							"data" => $chart["actions"]
						]
					],
					"colors" => [
						"#1ccf13", 
						"#1099da"
					]
				];

				break;
			case "dashboard.utilities":
				$this->cache->container("user." . logged_hash, true);

				if(!$this->cache->has("statistics.utilities")):
					$ussd = [];

					foreach($this->system->getStatisticsUtilities(logged_id, 1) as $key => $value):
						$ussd[] = [
							(int)("{$key}000"),
							count($value)
						];
					endforeach;

					$series = [
						"ussd" => $ussd
					];

					$this->cache->set("statistics.utilities", $series, 3600);
				endif;

				$chart = $this->cache->get("statistics.utilities");

				$vars = [
					"series" => [
						[
							"name" => strtoupper(__("response_chart_dashussd")),
							"data" => $chart["ussd"]
						]
					],
					"colors" => [
						"#1ccf13"
					]
				];

				break;
			case "admin.countries":
				$this->cache->container("system.statistics", true);

				if(!$this->cache->has("visitors.countries")):
					$series = [];
					
					foreach($this->system->getStatisticsVisitors("country") as $key => $value):
						unset($country);

						foreach($value as $vkey => $vvalue){
							$country[] = [
								(int)("{$vkey}000"),
								count($vvalue)
							];
						}

						$series[] = [
							"name" => strtoupper($key),
							"data" => $country
						];
					endforeach;

					$this->cache->set("visitors.countries", $series, 3600);
				endif;

				$vars = [
					"series" => $this->cache->get("visitors.countries")
				];

				break;
			case "admin.browsers":
				$this->cache->container("system.statistics", true);

				if(!$this->cache->has("visitors.browsers")):
					$series = [];
					
					foreach($this->system->getStatisticsVisitors("browser") as $key => $value):
						unset($browser);

						foreach($value as $vkey => $vvalue){
							$browser[] = [
								(int)("{$vkey}000"),
								count($vvalue)
							];
						}

						$series[] = [
							"name" => strtoupper($key),
							"data" => $browser
						];
					endforeach;

					$this->cache->set("visitors.browsers", $series, 3600);
				endif;

				$vars = [
					"series" => $this->cache->get("visitors.browsers")
				];

				break;
			case "admin.os":
				$this->cache->container("system.statistics", true);

				if(!$this->cache->has("visitors.os")):
					$series = [];
					
					foreach($this->system->getStatisticsVisitors("os") as $key => $value):
						unset($os);

						foreach($value as $vkey => $vvalue){
							$os[] = [
								(int)("{$vkey}000"),
								count($vvalue)
							];
						}

						$series[] = [
							"name" => strtoupper($key),
							"data" => $os
						];
					endforeach;

					$this->cache->set("visitors.os", $series, 3600);
				endif;

				$vars = [
					"series" => $this->cache->get("visitors.os")
				];

				break;
			case "admin.messages":
				if(!is_admin)
					response(500, __("response_no_permission"));

				$this->cache->container("system.statistics", true);

				if(!$this->cache->has("messages")):
					$sent = []; 
					$received = [];
					$wa_sent = [];
					$wa_received = [];

					foreach($this->system->getStatisticsSent(false, false, true) as $key => $value):
						$sent[] = [
							(int)("{$key}000"),
							count($value)
						];
					endforeach;

					foreach($this->system->getStatisticsReceived(false, false, true) as $key => $value):
						$received[] = [
							(int)("{$key}000"),
							count($value)
						];
					endforeach;

					foreach($this->system->getStatisticsSent(false, true, true) as $key => $value):
						$wa_sent[] = [
							(int)("{$key}000"),
							count($value)
						];
					endforeach;

					foreach($this->system->getStatisticsReceived(false, true, true) as $key => $value):
						$wa_received[] = [
							(int)("{$key}000"),
							count($value)
						];
					endforeach;

					$series = [
						"sent" => $sent,
						"received" => $received,
						"wa_sent" => $wa_sent,
						"wa_received" => $wa_received
					];

					$this->cache->set("messages", $series, 3600);
				endif;

				$chart = $this->cache->get("messages");

				$vars = [
					"series" => [
						[
							"name" => strtoupper(__("requests_charts_smssent")),
							"data" => $chart["sent"]
						],
						[
							"name" => strtoupper(__("requests_charts_smsreceived")),
							"data" => $chart["received"]
						],
						[
							"name" => strtoupper(__("requests_charts_wasent")),
							"data" => $chart["wa_sent"]
						],
						[
							"name" => strtoupper(__("requests_charts_wareceived")),
							"data" => $chart["wa_received"]
						]
					],
					"colors" => [
						"#1ccf13", 
						"#1099da",
						"#fe9431",
						"#e82753"
					]
				];

				break;
			case "admin.utilities":
				if(!is_admin)
					response(500, __("response_no_permission"));

				$this->cache->container("system.statistics", true);

				if(!$this->cache->has("utilities")):
					$ussd = [];

					foreach($this->system->getStatisticsUtilities(false, 1, true) as $key => $value):
						$ussd[] = [
							(int)("{$key}000"),
							count($value)
						];
					endforeach;

					$series = [
						"ussd" => $ussd
					];

					$this->cache->set("utilities", $series, 3600);
				endif;

				$chart = $this->cache->get("utilities");

				$vars = [
					"series" => [
						[
							"name" => strtoupper(__("requests_charts_ussd")),
							"data" => $chart["ussd"]
						]
					],
					"colors" => [
						"#1ccf13"
					]
				];

				break;
			case "admin.subscriptions":
				if(!permission("manage_transactions"))
					response(500, __("response_no_permission"));

				$this->cache->container("system.statistics", true);

				if(!$this->cache->has("subscriptions")):
					$series = [];
					
					foreach($this->system->getStatisticsEarnings() as $key => $value):
						unset($earnings);

						foreach($value as $vkey => $vvalue){
							$earnings[] = [
								(int)("{$vkey}000"),
								array_sum($vvalue)
							];
						}

						$series[] = [
							"name" => strtoupper($key),
							"data" => $earnings
						];
					endforeach;

					$this->cache->set("subscriptions", $series, 3600);
				endif;

				$vars = [
					"series" => $this->cache->get("subscriptions")
				];

				break;
			case "admin.credits":
				if(!permission("manage_transactions"))
					response(500, __("response_no_permission"));

				$this->cache->container("system.statistics", true);

				if(!$this->cache->has("credits")):
					$credits = []; 

					foreach($this->system->getStatisticsEarnings(2) as $key => $value):
						$credits[] = [
							(int)("{$key}000"),
							array_sum($value)
						];
					endforeach;

					$series = [
						"credits" => $credits
					];

					$this->cache->set("credits", $series, 3600);
				endif;

				$chart = $this->cache->get("credits");

				$vars = [
					"series" => [
						[
							"name" => strtoupper(__("requests_charts_credits")),
							"data" => $chart["credits"]
						]
					],
					"colors" => [
						"#1ccf13"
					]
				];

				break;
			case "admin.commissions":
				if(!permission("manage_transactions"))
					response(500, __("response_no_permission"));
				
				$this->cache->container("system.statistics", true);

				if(!$this->cache->has("commissions")):
					$commissions = []; 

					foreach($this->system->getStatisticsEarnings(3) as $key => $value):
						$commissions[] = [
							(int)("{$key}000"),
							array_sum($value)
						];
					endforeach;

					$series = [
						"commissions" => $commissions
					];

					$this->cache->set("commissions", $series, 3600);
				endif;

				$chart = $this->cache->get("commissions");

				$vars = [
					"series" => [
						[
							"name" => __("requests_charts_commisionstitle"),
							"data" => $chart["commissions"]
						]
					],
					"colors" => [
						"#1ccf13"
					]
				];

				break;
			default:
				response(500, __("response_invalid"));
		endswitch;

		response(200, false, [
			"vars" => $vars
		]);
	}

	public function visitors()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
			response(401);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

        $visitor = new Lablnet\UserInfo();

		$info = [
			"country" => "Unknown",
			"browser" => $visitor->browser()["browser"],
			"os" => $visitor->operatingSystem(),
			"ip" => $visitor->ip() == "::1" ? "localhost" : $visitor->ip(),
			"country" => $this->titansys->getGeoIp($visitor->ip()) ?: "Unknown"
		];

        if($this->system->create("visitors", $info)):
        	$this->cache->container("system.statistics", true);
        	
        	response(200);
        else:
        	response(500);
        endif;
	}

	public function autocomplete()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
            response(401);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

        $request = $this->sanitize->array($_POST);
        $type = $this->sanitize->string($this->url->segment(4));

		switch($type){
			case "contacts":
				$this->cache->container("autocomplete.{$type}." . logged_hash);

				if($this->cache->empty()):
					$contacts = $this->system->getContacts(logged_id);

					if(!empty($contacts)):
						foreach($contacts as $contact):
							$autocomplete[] = [
								"value" => "{$contact["name"]} ({$contact["phone"]})",
								"data" => $contact["phone"]
							];
						endforeach;
					else:
						$autocomplete = [];
					endif;

					$this->cache->setArray($autocomplete);
				endif;

				response(200, false, $this->cache->getAll());

				break;
			case "wa.contacts":
				$this->cache->container("autocomplete.contacts." . logged_hash);

				if($this->cache->empty()):
					$contacts = $this->system->getContacts(logged_id);

					if(!empty($contacts)):
						foreach($contacts as $contact):
							$autocomplete[] = [
								"value" => "{$contact["name"]} ({$contact["phone"]})",
								"data" => $contact["phone"]
							];
						endforeach;
					else:
						$autocomplete = [];
					endif;

					$this->cache->setArray($autocomplete);
				endif;

				$autocomplete = $this->cache->getAll();

				if(isset($request["account"])):
					$account = $this->system->getWaAccount(logged_id, $request["account"], "id");

					if($account):
						$waGroups = $this->system->getWaGroups(logged_id, $account["wid"]);

						if(!empty($waGroups)):
							foreach($waGroups as $group):
								$autocomplete[] = [
									"value" => "{$group["name"]} ({$group["gid"]})",
									"data" => $group["gid"]
								];
							endforeach;
						endif;
					endif;
				endif;

				response(200, false, $autocomplete);

				break;
			default:
				response(500, __("response_invalid"));
		}
	}

	public function resend()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
			response(401);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

		$type = $this->sanitize->string($this->url->segment(4));
		$id = $this->sanitize->string($this->url->segment(5));

		if(!$this->sanitize->isInt($id))
			response(500, __("response_invalid"));

		switch($type):
			case "sms":
				try {
					// Get the original message details
					$sent = $this->system->getSent($id);

					if(!$sent)
						response(500, __("response_invalid"));

					// Verify ownership
					if($sent["uid"] != logged_id)
						response(500, __("response_no_permission"));

					// Only allow resend for device-based messages (mode <= 1)
					if($sent["mode"] > 1)
						response(500, __("response_invalid"));

					// Get device details
					$device = $this->system->getDevice(logged_id, $sent["did"], "did");

					if(!$device)
						response(500, __("requests_resend_devicenotexist"));

					// Create a NEW record with a new ID (Android gateway won't reject it)
					$newId = $this->system->create("sent", [
						"cid" => $sent["cid"],
						"uid" => $sent["uid"],
						"did" => $sent["did"],
						"gateway" => $sent["gateway"],
						"api" => $sent["api"],
						"sim" => $sent["sim"],
						"mode" => $sent["mode"],
						"priority" => $sent["priority"],
						"phone" => $sent["phone"],
						"message" => $sent["message"],
						"status" => 1,  // Pending status
						"status_code" => "",
						"create_date" => date("Y-m-d H:i:s", time())
					]);

					if($newId):
						// Send FCM notification to device
						$this->fcm->sendWithToken($device["fcm_token"], [
					    	"action" => "message_request"
					    ]);
					endif;

					response(200, __("response_resend_queued"));
				} catch(Exception $e){
					response(500, __("requests_resend_devicenotexist"));
				}

				break;
			default:
				response(500, __("response_invalid"));
		endswitch;
	}

	public function payout()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
			response(401);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

		$type = $this->sanitize->string($this->url->segment(4));
		$id = $this->sanitize->string($this->url->segment(5));

		$payout = $this->system->getPayout($id);
		$user = $this->system->getUser($payout["uid"]);

		set_language($user["language"]);

		if(!permission("manage_payouts"))
			response(500, __("response_no_permission"));

		if(!$this->sanitize->isInt($id))
			response(500, __("response_invalid"));

		if($this->system->checkPayout($id) < 1)
			response(500, __("response_invalid"));

		if($this->system->getPartnership(logged_id) > 1)
			response(500, __("response_invalid"));

		switch($type):
			case "confirm":
				$this->mail->send([
					"title" => system_site_name,
					"data" => [
						"subject" => mail_title(__("mail_title_payoutapproved")),
						"payout" => $payout
					]
				], $user["email"], "_mail/payout_paid.tpl", $this->smarty);

				$this->system->delete(false, $id, "payouts");

				response(200, __("response_payout_confirmed"));

				break;
			case "reject":
				$this->system->earnings($user["id"], "increase", $payout["amount"]);

				$this->mail->send([
					"title" => system_site_name,
					"data" => [
						"subject" => mail_title(__("mail_title_payoutrejected")),
						"payout" => $payout
					]
				], $user["email"], "_mail/payout_rejected.tpl", $this->smarty);

				$this->system->delete(false, $id, "payouts");

				response(200, __("response_payout_rejected"));

				break;
			default:
				response(500, __("response_invalid"));
		endswitch;
	}

	public function translate()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
			response(401);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

        $request = $this->sanitize->array($_POST);
		$from = $this->sanitize->string($this->url->segment(4));
		$to = $this->sanitize->string($this->url->segment(5));

		if(!isset($request["message"]))
			response(500, __("response_invalid"));

		if(!$this->sanitize->length($request["message"], 3))
			response(500, __("response_translate_msgshort"));

		if(empty($from) || empty($to))
			response(500, __("response_invalid"));

		try {
			$message = (new \Statickidz\GoogleTranslate)->translate($from, $to, $request["message"]);
		} catch(Exception $e){
			response(500, __("response_went_wrong"));
		}

		response(200, false, $message);
	}

	public function clear()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
			response(401);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

		$type = $this->sanitize->string($this->url->segment(4));

		switch($type):
			case "cache":
				if(!is_admin)
					response(500, __("response_no_permission"));

				try {
					rmrf("system/storage/cache");
					mkdir("system/storage/cache");
				} catch(Exception $e){
					// Ignore
				}

				response(200, __("response_clear_systemcleared"));

				break;
			default:
				response(500, __("response_invalid"));
		endswitch;
	}

	public function remote()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
			response(401);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

        $request = $this->sanitize->array($_POST);
		$type = $this->sanitize->string($this->url->segment(4));

		switch($type):
			case "start_sms":
				if(!isset($request["cid"], $request["did"], $request["name"]))
					response(500, __("response_invalid"));

				if($this->system->update($request["cid"], logged_id, "campaigns", [
					"status" => 1
				])):
					$device = $this->system->getDevice(logged_id, $request["did"], "did");

					if($device):
						$this->fcm->sendWithToken($device["fcm_token"], [
					    	"action" => "campaign_request",
					    	"campaign_id" => (int) $request["cid"],
							"campaign_status" => "start"
					    ]);
					endif;
				endif;

				response(200, __("requests_remote_smscampaignresumed"));

				break;
			case "stop_sms":
				if(!isset($request["cid"], $request["did"], $request["name"]))
					response(500, __("response_invalid"));

				if($this->system->update($request["cid"], logged_id, "campaigns", [
					"status" => 2
				])):
					$device = $this->system->getDevice(logged_id, $request["did"], "did");

					if($device):
						$this->fcm->sendWithToken($device["fcm_token"], [
					    	"action" => "campaign_request",
					    	"campaign_id" => (int) $request["cid"],
							"campaign_status" => "stop"
					    ]);
					endif;
				endif;

				response(200, __("requests_remote_smscampaignpaused"));

				break;
			case "start_chats":
				if(!isset($request["cid"]))
					response(500, __("response_invalid"));

				$waServer = $this->system->getWaServer($request["cid"], "campaign_id");

				if($waServer && $this->wa->check($waServer["url"], $waServer["port"])):
					if($this->system->update($request["cid"], logged_id, "wa_campaigns", [
						"status" => 1
					])):
						$campaign = $this->system->getWaCampaign(logged_id, $request["cid"], "id");

						if($campaign):
							$account = $this->system->getWaAccount(logged_id, $campaign["unique"], "unique");

							if($account):
								$this->wa->start_campaign($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"], logged_hash, $request["cid"]);
							endif;
						endif;
					endif;
				else:
					response(500, __("response_whatsapp_noconnectserver"));
				endif;

				response(200, __("requests_remote_wacampaignresumed"));

				break;
			case "stop_chats":
				if(!isset($request["cid"]))
					response(500, __("response_invalid"));
					
				$waServer = $this->system->getWaServer($request["cid"], "campaign_id");

				if($waServer && $this->wa->check($waServer["url"], $waServer["port"])):
					if($this->system->update($request["cid"], logged_id, "wa_campaigns", [
						"status" => 2
					])):
						$campaign = $this->system->getWaCampaign(logged_id, $request["cid"], "id");

						if($campaign):
							$account = $this->system->getWaAccount(logged_id, $campaign["unique"], "unique");

							if($account):
								$this->wa->stop_campaign($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"], logged_hash, $request["cid"]);
							endif;
						endif;
					endif;
				else:
					response(500, __("response_whatsapp_noconnectserver"));
				endif;

				response(200, __("requests_remote_wacampaignpaused"));

				break;
			default:
				response(500, __("response_invalid"));
		endswitch;
	}

	public function reorder()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
			response(401);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

        $request = $this->sanitize->array($_POST);
		$type = $this->sanitize->string($this->url->segment(4));

		if(!isset($request["rows"]) || !is_array($request["rows"]) || empty($request["rows"]))
			response(500, __("response_invalid"));

		$orderIndex = 1;
		$languages = $this->system->getLanguages();

		foreach($request["rows"] as $row):
			$this->system->update($row, false, "languages", [
				"order" => $orderIndex
			]);

			$orderIndex++;
		endforeach;

		if(!empty($languages)):
			foreach($languages as $language):
				if(!in_array($language["id"], $request["rows"])):
					$this->system->update($language["id"], false, "languages", [
						"order" => $orderIndex
					]);

					$orderIndex++;
				endif;
			endforeach;
		endif;

		response(200, __("response_reorder_languages"));
	}

	public function trash()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
			response(401);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

        $request = $this->sanitize->array($_POST);
		$type = $this->sanitize->string($this->url->segment(4));

		if(!isset($request["rows"]) || !is_array($request["rows"]) || empty($request["rows"]))
			response(500, __("response_invalid"));

		switch($type):
			case "sms.sent":
				foreach($request["rows"] as $row):
					$sent = $this->system->getSent(str_replace("#", "", $row));

					if($sent):
						if($sent["status"] < 3):
							$device = $this->system->getDevice(logged_id, $sent["did"], "did");

							$this->fcm->sendWithToken($device["fcm_token"], [
								"action" => "message_delete",
								"message_id" => str_replace("#", "", $row)
							]);
						endif;
					endif;

					$this->system->delete(logged_id, str_replace("#", "", $row), "sent");
				endforeach;

				break;
			case "sms.received":
				foreach($request["rows"] as $row):
					$received = $this->system->getMessageReceived(logged_id, str_replace("#", "", $row));

					if($this->system->checkDeleted($received["uid"], $received["rid"], $received["did"]) < 1):
						$this->system->create("deleted", [
							"rid" => $received["rid"],
							"uid" => $received["uid"],
							"did" => $received["did"]
						]);
					endif;

					$this->system->delete(logged_id, str_replace("#", "", $row), "received");
				endforeach;

				break;
			case "whatsapp.sent":
				foreach($request["rows"] as $row):
					$sent = $this->system->getWaSent(str_replace("#", "", $row));
					
					try {
						if($sent["priority"] > 1):
							if($this->system->delete(logged_id, str_replace("#", "", $row), "wa_sent")):
								if($sent["status"] < 3):
									$waServer = $this->system->getWaServer($sent["unique"], "unique");

									try {
										if($this->wa->check($waServer["url"], $waServer["port"])):
											$account = $this->system->getWaAccount(logged_id, $sent["unique"], "unique");
											$this->wa->delete_chat($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"], logged_hash, $sent["cid"], str_replace("#", "", $row));
										endif;
									} catch(Exception $e){
										// Ignore
									}
								endif;
							endif;
						else:
							$this->system->delete(logged_id, str_replace("#", "", $row), "wa_sent");
						endif;
					} catch(Exception $e){
						// Ignore
					}

					try {
						$msgDecode = json_decode($sent["message"], true, JSON_THROW_ON_ERROR);
						
						if(!isset($msgDecode["autoreply"])):
							if(isset($msgDecode["image"])):
								$fileUrl = explode("/", $msgDecode["image"]["url"]);
							endif;
		
							if(isset($msgDecode["audio"])):
								$fileUrl = explode("/", $msgDecode["audio"]["url"]);
							endif;
		
							if(isset($msgDecode["video"])):
								$fileUrl = explode("/", $msgDecode["video"]["url"]);
							endif;
		
							if(isset($msgDecode["document"])):
								$fileUrl = explode("/", $msgDecode["document"]["url"]);
							endif;
		
							if(isset($fileUrl)):
								$this->file->delete("uploads/whatsapp/sent/" . logged_id . "/" . end($fileUrl));
							endif;
						endif;
					} catch(Exception $e){
						// Ignore
					}
				endforeach;

				break;
			case "whatsapp.received":
				foreach($request["rows"] as $row):
					$received = $this->system->getWaReceived(str_replace("#", "", $row));

					$this->system->delete(logged_id, str_replace("#", "", $row), "wa_received");

					if($received):
						$fileName = checkFile($received["id"], "uploads/whatsapp/received/{$received["unique"]}");

						if($fileName):
							$this->file->delete("uploads/whatsapp/received/{$received["unique"]}/{$fileName}");
						endif;
					endif;
				endforeach;

				break;
			case "whatsapp.groups":
				foreach($request["rows"] as $row):
					$this->system->delete(logged_id, str_replace("#", "", $row), "wa_groups");
				endforeach;

				$this->cache->container("wa.contacts." . logged_hash);
				$this->cache->clear();

				break;
			case "android.ussd":
				foreach($request["rows"] as $row):
					$ussd = $this->system->getUssd(str_replace("#", "", $row), logged_id);

					if($ussd):
						$device = $this->system->getDevice(logged_id, $ussd["did"], "did");
						
						if($device):
							$this->fcm->sendWithToken($device["fcm_token"], [
								"action" => "ussd_delete",
								"ussd_id" => $ussd["id"]
							]);
						endif;
					endif;
					
					$this->system->delete(logged_id, str_replace("#", "", $row), "ussd");
				endforeach;

				break;
			case "contacts.saved":
				foreach($request["rows"] as $row):
					$this->system->delete(logged_id, str_replace("#", "", $row), "contacts");
				endforeach;

				$this->cache->container("autocomplete.contacts." . logged_hash);
                $this->cache->clear();
                $this->cache->container("contacts." . logged_hash);
                $this->cache->clear();
                $this->cache->container("user." . logged_hash);
                $this->cache->clear();

				break;
			case "contacts.groups":
				foreach($request["rows"] as $row):
					$this->system->delete(logged_id, str_replace("#", "", $row), "groups");
				endforeach;

				break;
			case "contacts.unsubscribed":
				foreach($request["rows"] as $row):
					$this->system->delete(logged_id, str_replace("#", "", $row), "unsubscribed");
				endforeach;

				break;
			case "administration.vouchers":
				foreach($request["rows"] as $row):
					$this->system->delete(false, str_replace("#", "", $row), "vouchers");
				endforeach;

				break;
			case "administration.marketing":
				foreach($request["rows"] as $row):
					$this->system->delete(false, str_replace("#", "", $row), "marketing");
				endforeach;

				break;
			default:
				response(500, __("response_invalid"));
		endswitch;

		response(200, __("response_trash_selectedremoved"));
	}

	public function create()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
            response(302);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        $this->cache->container("system.plugins");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getPlugins());
        endif;

        set_plugins($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

        $type = $this->sanitize->string($this->url->segment(4));
        $request = $this->sanitize->array($_POST, 
        	in_array($type, ["add.ai.plugin", "add.page", "add.mailer", "add.gateway"]) ? ["content"] : []
    	);

        switch($type):
        	case "redeem":
        		if(!isset($request["code"]))
        			response(500, __("response_invalid"));

        		if($this->system->checkVoucher($request["code"]) > 0):
        			$voucher = $this->system->getVoucher($request["code"]);

        			$package = $this->system->getPackage($voucher["package"]);
					$txn = md5(uniqid(time(), true));

    				if($this->system->checkSubscription(logged_id) > 0):
						// User has existing subscription - extend it
						$subscription = $this->system->getSubscription(false, logged_id);
						$currentExpiry = strtotime($subscription["sub_date"]);
						$now = time();
						$startFrom = ($currentExpiry > $now) ? $currentExpiry : $now;
						$newExpiry = date("Y-m-d H:i:s", strtotime("+{$voucher["duration"]} months", $startFrom));

						$transaction = $this->system->create("transactions", [
							"uid" => logged_id,
							"pid" => $package["id"],
							"type" => 1,
							"price" => $package["price"],
							"currency" => system_currency,
							"duration" => $voucher["duration"],
							"provider" => "voucher",
							"txn" => "voucher-{$txn}"
						]);

						$filtered = [
							"pid" => $package["id"],
							"tid" => $transaction,
							"date" => $newExpiry
						];

						$this->system->update($subscription["sid"], logged_id, "subscriptions", $filtered);
					else:
						// Create new subscription with proper expiry date
						$newExpiry = date("Y-m-d H:i:s", strtotime("+{$voucher["duration"]} months"));

						$transaction = $this->system->create("transactions", [
							"uid" => logged_id,
							"pid" => $package["id"],
							"type" => 1,
							"price" => $package["price"],
							"currency" => system_currency,
							"duration" => $voucher["duration"],
							"provider" => "voucher",
							"txn" => "voucher-{$txn}"
						]);

						$filtered = [
							"uid" => logged_id,
							"pid" => $package["id"],
							"tid" => $transaction,
							"date" => $newExpiry
						];

						$this->system->create("subscriptions", $filtered);
					endif;

					$this->mail->send([
						"title" => system_site_name,
						"data" => [
							"subject" => mail_title(__("response_package_purchasedtitle")),
							"package" => $this->system->getPackage($package["id"]),
							"subscription" => $this->system->getSubscription(false, logged_id)
						]
					], logged_email, "_mail/subscribe.tpl", $this->smarty);

					if($this->system->delete(false, $voucher["id"], "vouchers")):
						$this->cache->container("system.users");
						$this->cache->clear();

						if(!empty(system_mailing_address) && in_array("admin_voucher_redeem", explode(",", system_mailing_triggers))):
							$userEmail = logged_email;

							$mailingContent = <<<HTML
							<p>Hi there!</p>
							<p>This is to inform you that a voucher with <strong>{$package["name"]}</strong> package has redeemed by: <strong>{$userEmail}</strong></p> 
							HTML;

			    			$this->mail->send([
								"title" => system_site_name,
								"data" => [
									"subject" => mail_title("Admin Alert Message from " . system_site_name . "!"),
									"content" => $mailingContent
								]
							], system_mailing_address, "_mail/default.tpl", $this->smarty);
			    		endif;

						response(200, __("response_voucher_redeemed"));
					else:
						response(500, __("response_went_wrong"));
					endif;
        		else:
        			response(500, __("invalid_voucher_code"));
        		endif;

        		break;
        	case "add.payout":
        		if(!isset($request["amount"], $request["provider"], $request["address"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->isInt($request["amount"]))
        			response(500, __("response_payout_invalidamount"));

        		if(!in_array($request["provider"], ["paypal", "payoneer"]))
        			response(500, __("response_invalid"));

        		if($request["amount"] < system_partner_minimum)
        			response(500, ___(__("requests_create_payoutwithdrawalamountatleast"), [system_partner_minimum, system_currency]));

        		if($this->system->getEarnings(logged_id) < $request["amount"])
        			response(500, __("response_payout_notenoughbal"));

        		if($this->system->checkPayout(logged_id, "uid") < 1):
    				$filtered = [
    					"uid" => logged_id,
    					"amount" => $request["amount"],
    					"currency" => system_currency,
    					"provider" => $request["provider"],
    					"address" => $request["address"],
    					"create_date" => date("Y-m-d H:i:s", time())
    				];

    				if($this->system->create("payouts", $filtered)):
    					$this->system->earnings(logged_id, "decrease", $request["amount"]);

    					if(!empty(system_mailing_address) && in_array("admin_payout_request", explode(",", system_mailing_triggers))):
							$userEmail = logged_email;

							$mailingContent = <<<HTML
							<p>Hi there!</p>
							<p>This is to inform you that a new payout request has been submitted by: <strong>{$userEmail}</strong></p> 
							HTML;

			    			$this->mail->send([
								"title" => system_site_name,
								"data" => [
									"subject" => mail_title("Admin Alert Message from " . system_site_name . "!"),
									"content" => $mailingContent
								]
							], system_mailing_address, "_mail/default.tpl", $this->smarty);
			    		endif;

    					response(200, __("response_payout_reqreceive"));
    				else:
    					response(500, __("response_went_wrong"));
    				endif;
        		else:
        			response(500, __("response_payout_pendingreq"));
        		endif;

        		break;
        	case "sms.quick":
        		if(!isset($request["mode"], $request["shortener"], $request["phone"], $request["message"]))
        			response(500, __("response_invalid"));

        		if(!$this->file->exists("system/storage/temporary/firebase.json")):
					response(500, __("response_system_sysconfigerr"));
				endif;

				if(!$this->sanitize->isInt($request["mode"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["shortener"]))
					response(500, __("response_invalid"));

				try {
				    $number = $this->phone->parse($request["phone"], logged_country);

				    $number->format(Brick\PhoneNumber\PhoneNumberFormat::INTERNATIONAL);

				    if(!$number->isValidNumber() && $number->getRegionCode() != "BR")
						response(500, __("response_invalid_number"));

					$phone = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);
					$country = $number->getRegionCode();
				} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
					response(500, __("response_invalid_number"));
				}

				if(!$this->sanitize->length($request["message"], system_message_min))
					response(500, __("response_message_short"));

				if(system_message_max > 0):
					if($this->sanitize->length($request["message"], system_message_max, 2))
						response(500, __("response_message_toolong"));
				endif;

				if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], false)):
					$this->system->create("logger", [
                        "uid" => logged_id,
                        "type" => "ai_moderation",
                        "reason" => "Inappropriate Content!",
                        "content" => "Type: SMS, Message: {$request["message"]}",
                        "create_date" => date("Y-m-d H:i:s", time())
                    ]);

					response(500, __("response_aimoderation_detected"));
				endif;

        		if($request["mode"] < 2):
        			if(!isset($request["device"], $request["sim"], $request["priority"]))
        				response(500, __("response_invalid"));

        			$subscription = set_subscription(
	                    $this->system->checkSubscription(logged_id), 
	                    $this->system->getSubscription(false, logged_id), 
	                    $this->system->getSubscription(false, false, true)
	                );

					if(empty($subscription))
						response(500, __("response_package_nosubwarn"));

					if(!$this->sanitize->isInt($request["sim"]))
						response(500, __("response_invalid"));

					if(!$this->sanitize->isInt($request["priority"]))
						response(500, __("response_invalid"));

					if(limitation($subscription["send_limit"], $this->system->countQuota(logged_id, "sent")))
	    				response(500, __("response_limitation_send"));

	    			if($this->system->checkDevice(logged_id, $request["device"], "did") < 1)
    					response(500, __("response_invalid"));

	    			$device = $this->system->getDevice(logged_id, $request["device"], "did");
        		else:
        			if(!isset($request["gateway"]))
        				response(500, __("response_invalid"));

        			if($this->sanitize->isInt($request["gateway"])):
        				$gateways = $this->system->getGateways();

						if(!array_key_exists($request["gateway"], $gateways)):
							response(500, __("response_invalid"));
						endif;

						if(!$this->file->exists("system/gateways/" . md5($request["gateway"]) . ".php"))
							response(500, __("response_went_wrong"));

						try {
                            $gatewayHandler = require "system/gateways/" . md5($request["gateway"]) . ".php";
                        } catch(Exception $e){
                            response(500, __("requests_sms_encounteredgatewayerror"));
                        }
        			else:
        				$device = $this->system->getDevice(false, $request["gateway"], "global");

		    			if($device):
		    				if($device["uid"] == logged_id):
		    					response(500, __("requests_sms_gatewaycannotsendowndevices"));
		    				endif;

			    			if($device["global_device"] > 1):
			    				response(500, __("response_invalid"));
			    			endif;
			    		else:
			    			response(500, __("response_invalid"));
			    		endif;
        			endif;
        		endif;

				if($request["shortener"] > 0):
					if(!$this->file->exists("system/shorteners/" . md5($request["shortener"]) . ".php"))
						response(500, __("response_went_wrong"));

					$messageLinks = (new VStelmakh\UrlHighlight\UrlHighlight)->getUrls($request["message"]);

					if(!empty($messageLinks)):
						try {
							require "system/shorteners/" . md5($request["shortener"]) . ".php";
						} catch(Exception $e){
							response(500, __("requests_create_encounteredshortenererror"));
						}

						foreach($messageLinks as $key => $value):
							$shortLink = shortenUrl($value, $this);

							if($shortLink):
								$request["message"] = str_replace($value, $shortLink, $request["message"]);
							endif;
						endforeach;
					endif;
				endif;

				if($request["mode"] < 2):
					$this->system->create("sent", [
						"cid" => 0,
			        	"uid" => logged_id,
						"did" => $request["device"],
						"gateway" => 0,
						"sim" => $request["sim"] < 2 ? 1 : 2,
						"mode" => 1,
						"phone" => $phone,
						"message" => $this->spintax->process(footermark($subscription["footermark"], $request["message"], system_message_mark)),
						"status" => 1,
						"status_code" => false,
						"priority" => $request["priority"] < 2 ? 1 : 2,
						"api" => 2,
						"create_date" => date("Y-m-d H:i:s", time())
			        ]);
				else:
					$credits = $this->system->getCredits(logged_id);

					if($this->sanitize->isInt($request["gateway"])):
						$pricing = json_decode($gateways[$request["gateway"]]["pricing"], true);

						if(array_key_exists(strtolower($country), $pricing["countries"])):
							$price = $pricing["countries"][strtolower($country)];
						else:
							$price = $pricing["default"];
						endif;

						if($credits < $price)
				        	response(500, __("response_message_notenoughcredsend"));

						$gateway = $gateways[$request["gateway"]];

						$message = $this->spintax->process($request["message"]);

						$send = $gatewayHandler["send"]($request["phone"], $message, $this);

						if($send):
							$create = $this->system->create("sent", [
								"cid" => 0,
								"uid" => logged_id,
								"did" => false,
								"gateway" => $request["gateway"],
								"api" => 0,
								"sim" => 0,
								"mode" => 2,
								"priority" => 0,
								"phone" => $phone,
								"message" => $message,
								"status" => $gateway["callback"] < 2 ? 2 : 3,
								"status_code" => false,
								"create_date" => date("Y-m-d H:i:s", time())
							]);

							if($create):
								if($gateway["callback"] < 2):
									$this->cache->container("system.gateways");

									$this->cache->set("{$gateway["callback_id"]}.{$send}", $create);

									response(200, __("response_message_queuedforsend"));
								else:
									$this->process->_sanitize = $this->sanitize;

									$hooks = $this->process->actionHooks(logged_id, 1, 1, $phone, $message, $this->device->getActions(logged_id, 1));

									if(!empty($hooks)):
										foreach($hooks as $hook):
											$this->system->create("events", [
												"uid" => logged_id,
												"type" => 2,
												"create_date" => date("Y-m-d H:i:s", time())
											]);
										endforeach;
									endif;

									$this->system->credits(logged_id, "decrease", $price);

									response(200, __("response_message_sentsuccess"));
								endif;
							endif;
						else:
							$this->system->create("sent", [
								"cid" => 0,
								"uid" => logged_id,
								"did" => false,
								"gateway" => $request["gateway"],
								"api" => 0,
								"sim" => 0,
								"mode" => 2,
								"priority" => 0,
								"phone" => $phone,
								"message" => $message,
								"status" => 4,
								"status_code" => false,
								"create_date" => date("Y-m-d H:i:s", time())
							]);

							response(500, __("response_message_unablesendmesg"));
						endif;
					else:
						$currency = country($device["country"])->getCurrency()["iso_4217_code"];
						$final_price = $this->titansys->calculatePartnerSendPrice($currency, $device["rate"], $this->cache);

						if($final_price):
							if($credits < $final_price):
								response(500, __("response_message_notenoughcredsend"));
							endif;

							$slots = explode(",", $device["global_slots"]);

							$this->system->create("sent", [
								"cid" => 0,
								"uid" => logged_id,
								"did" => $request["gateway"],
								"gateway" => 0,
								"sim" => count($slots) > 1 ? rand(1, 2) : ($slots[0] < 2 ? 1 : 2),
								"mode" => 2,
								"phone" => $phone,
								"message" => $this->spintax->process($request["message"]),
								"status" => 1,
								"status_code" => false,
								"priority" => $device["global_priority"],
								"api" => 2,
								"create_date" => date("Y-m-d H:i:s", time())
							]);
						endif;
					endif;
				endif;

				if($request["mode"] < 2 || !$this->sanitize->isInt($request["gateway"])):
					$this->fcm->sendWithToken($device["fcm_token"], [
						"action" => "message_request"
					]);
				endif;

				response(200, __("response_message_queued"));

        		break;
        	case "sms.bulk":
        		if(!isset($request["campaign"], $request["mode"], $request["numbers"], $request["groups"], $request["shortener"], $request["message"]))
        			response(500, __("response_invalid"));

        		if(!$this->file->exists("system/storage/temporary/firebase.json")):
					response(500, __("response_system_sysconfigerr"));
				endif;

				if(!$this->sanitize->length($request["campaign"]))
					response(500, __("requests_create_campaignnametooshort"));

				if(!$this->sanitize->isInt($request["mode"]))
					response(500, __("response_invalid"));

				if(!is_array($request["groups"]))
        			response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["shortener"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->length($request["message"], system_message_min))
					response(500, __("response_message_short"));

				if(system_message_max > 0):
					if($this->sanitize->length($request["message"], system_message_max, 2))
						response(500, __("response_message_toolong"));
				endif;

				if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], false)):
					$this->system->create("logger", [
                        "uid" => logged_id,
                        "type" => "ai_moderation",
                        "reason" => "Inappropriate Content!",
                        "content" => "Type: SMS Bulk, Message: {$request["message"]}",
                        "create_date" => date("Y-m-d H:i:s", time())
                    ]);

					response(500, __("response_aimoderation_detected"));
				endif;

        		if($request["mode"] < 2):
        			if(!isset($request["devices"], $request["sim"], $request["priority"]))
        				response(500, __("response_invalid"));

					if(!is_array($request["devices"]))
						response(500, __("response_invalid"));

        			$subscription = set_subscription(
	                    $this->system->checkSubscription(logged_id), 
	                    $this->system->getSubscription(false, logged_id), 
	                    $this->system->getSubscription(false, false, true)
	                );

					if(empty($subscription))
						response(500, __("response_package_nosubwarn"));

					if(!$this->sanitize->isInt($request["sim"]))
						response(500, __("response_invalid"));

					if(!$this->sanitize->isInt($request["priority"]))
						response(500, __("response_invalid"));
					
					$devices = [];

					foreach($request["devices"] as $device):
						if($this->system->checkDevice(logged_id, $device, "did") > 0):
							$devices[$device] = $this->system->getDevice(logged_id, $device, "did");
						endif;
					endforeach;

					if(empty($devices)):
						response(500, __("response_invalid"));
					endif;
        		else:
        			if(!isset($request["gateways"]))
        				response(500, __("response_invalid"));

					if(!is_array($request["gateways"]))
						response(500, __("response_invalid"));

					$gatewaysArr = [];

					foreach($request["gateways"] as $gateway):
						if($this->sanitize->isInt($gateway)):
							$gateways = $this->system->getGateways();

							if(array_key_exists($gateway, $gateways) && $this->file->exists("system/gateways/" . md5($gateway) . ".php")):
								try {
									$gatewaysArr["external_{$gateway}"] = [
										"external_id" => $gateway,
										"name" => $gateways[$gateway]["name"],
										"function" => require "system/gateways/" . md5($gateway) . ".php"
									];
								} catch(Exception $e){
									// Ignore
								}
							endif;
						else:
							$device = $this->system->getDevice(false, $gateway, "global");
	
							if($device):
								if($device["uid"] != logged_id && $device["global_device"] < 2):
									$gatewaysArr["partner_{$device["id"]}"] = $device;
								endif;
							endif;
						endif;
					endforeach;

					if(empty($gatewaysArr)):
						response(500, __("response_invalid"));
					endif;
        		endif;

				$contactBook = [];

				$numbers = explode(",", $this->sanitize->normalizeContactsCommas($request["numbers"]));

				if(!empty($numbers) && !empty($numbers[0])):
					foreach($numbers as $number):
						$rejected = false;

						try {
						    $phone = $this->phone->parse($number, logged_country);

							if(!$phone->isValidNumber() && $phone->getRegionCode() != "BR")
								$rejected = true;

							$phoneNumber = $phone->format(Brick\PhoneNumber\PhoneNumberFormat::E164);
							$country = $phone->getRegionCode();

							if($this->system->checkUnsubscribed(logged_id, $phoneNumber) > 0):
								$rejected = true;
							endif;
						} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
							$rejected = true;
						}

						if(!$rejected):
							$contactBook[$phoneNumber] = [
								"name" => $phoneNumber,
								"phone" => $phoneNumber,
								"group" => "Unknown",
								"country" => $country,
								"price" => 0
							];
						endif;
					endforeach;
				endif;

				if(!in_array(0, $request["groups"])):
					foreach($request["groups"] as $group):
						if($this->system->checkGroup(logged_id, $group) > 0):
							$contacts = $this->system->getContactsByGroup(logged_id, $group);

							if(!empty($contacts)):
								foreach($contacts as $contact):
									$rejected = false;

									try {
									    $phone = $this->phone->parse($contact["phone"], logged_country);
										$country = $phone->getRegionCode();
									} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
										$rejected = true;
									}

									if($this->system->checkUnsubscribed(logged_id, $contact["phone"]) > 0)
										$rejected = true;

									if(!$rejected):
										$contactBook[$contact["phone"]] = [
											"name" => $contact["name"],
											"phone" => $contact["phone"],
											"group" => $contact["group"],
											"country" => $country,
											"price" => 0
										];
									endif;
								endforeach;
							endif;
						endif;
					endforeach;
				endif;

				if(empty($contactBook))
					response(500, __("form_smsbulknonumbers"));

				$distContainer = [];

				if($request["mode"] < 2):
					$chunkSize = ceil(count($contactBook) / count($devices));
					$contactChunks = array_chunk($contactBook, $chunkSize, true);
				
					foreach ($devices as $did => $device):
						$chunk = (isset($contactChunks[0]) ? array_shift($contactChunks) : []);
				
						$distContainer[$did] = array_merge($device, [
							"contacts" => $chunk
						]);
					endforeach;
				else:
					$chunkSize = ceil(count($contactBook) / count($gatewaysArr));
					$contactChunks = array_chunk($contactBook, $chunkSize, true);
				
					foreach ($gatewaysArr as $key => $gateway):
						$chunk = (isset($contactChunks[0]) ? array_shift($contactChunks) : []);
				
						foreach ($chunk as $contactKey => $contact):
							if($request["mode"] > 1 && isset($gateway["external_id"])):
								$pricing = json_decode($gateways[$gateway["external_id"]]["pricing"], true);

								if(array_key_exists(strtolower($country), $pricing["countries"])):
									$price = $pricing["countries"][strtolower($country)];
								else:
									$price = $pricing["default"];
								endif;

								$contact["price"] = $price;
							endif;

							$chunk[$contactKey] = $contact;
						endforeach;
				
						$distContainer[$key] = array_merge($gateway, [
							"contacts" => $chunk
						]);
					endforeach;
				endif;

				if($request["shortener"] > 0):
					if(!$this->file->exists("system/shorteners/" . md5($request["shortener"]) . ".php"))
						response(500, __("response_went_wrong"));

					$messageLinks = (new VStelmakh\UrlHighlight\UrlHighlight)->getUrls($request["message"]);

					if(!empty($messageLinks)):
						try {
							require "system/shorteners/" . md5($request["shortener"]) . ".php";
						} catch(Exception $e){
							response(500, __("requests_create_encounteredshortenererror"));
						}

						foreach($messageLinks as $key => $value):
							$shortLink = shortenUrl($value, $this);

							if($shortLink):
								$request["message"] = str_replace($value, $shortLink, $request["message"]);
							endif;
						endforeach;
					endif;
				endif;

				$sendCounter = 0;
				$sendCounterExternal = 0;

				foreach($distContainer as $distItem):
					if(!empty($distItem["contacts"])):
						$smsCampaign = $this->system->create("campaigns", [
							"uid" => logged_id,
							"did" => $request["mode"] < 2 ? $distItem["did"] : (isset($distItem["external_id"]) ? false : $distItem["did"]),
							"gateway" => $request["mode"] > 1 && isset($distItem["external_id"]) ? $distItem["external_id"] : 0,
							"mode" => $request["mode"],
							"status" => 1,
							"name" => "{$request["campaign"]} ({$distItem["name"]})",
							"contacts" => count($distItem["contacts"]),
							"create_date" => date("Y-m-d H:i:s", time())
						]);
						
						foreach($distItem["contacts"] as $contact):
							if($request["mode"] < 2):
								if(!limitation($subscription["send_limit"], $this->system->countQuota(logged_id, "sent"))):
									$rejectLimit = false;
		
									if(!$rejectLimit):
										$this->system->create("sent", [
											"cid" => $smsCampaign,
											"uid" => logged_id,
											"did" => $distItem["did"],
											"gateway" => 0,
											"sim" => $request["sim"] < 2 ? 1 : 2,
											"mode" => 1,
											"phone" => $contact["phone"],
											"message" => $this->spintax->process($this->lex->parse(footermark($subscription["footermark"], $request["message"], system_message_mark), [
												"contact" => [
													"name" => $contact["name"],
													"number" => $contact["phone"]
												],
												"group" => [
													"name" => $contact["group"]
												],
												"unsubscribe" => [
													"command" => "STOP",
													"link" => site_url("unsubscribe/" . logged_id . "/{$contact["phone"]}", true)
												],
												"date" => [
													"now" => date("F j, Y"),
													"time" => date("h:i A") 
												]
											])),
											"status" => 1,
											"status_code" => false,
											"priority" => $request["priority"] < 1 ? 1 : 2,
											"api" => 2,
											"create_date" => date("Y-m-d H:i:s", time())
										]);
		
										$sendCounter++;
									endif;
								endif;
							else:
								$credits = $this->system->getCredits(logged_id);
		
								if(isset($distItem["external_id"])):
									if($credits >= $contact["price"]):
										$gateway = $gateways[$distItem["external_id"]];
		
										$message = $this->spintax->process($this->lex->parse($request["message"], [
											"contact" => [
												"name" => $contact["name"],
												"number" => $contact["phone"]
											],
											"group" => [
												"name" => $contact["group"]
											],
											"unsubscribe" => [
												"command" => "STOP",
												"link" => site_url("unsubscribe/" . logged_id . "/{$contact["phone"]}", true)
											],
											"date" => [
												"now" => date("F j, Y"),
												"time" => date("h:i A") 
											]
										]));
		
										$send = $distItem["function"]["send"]($contact["phone"], $message, $this);
		
										if($send):
											$create = $this->system->create("sent", [
												"cid" => $smsCampaign,
												"uid" => logged_id,
												"did" => false,
												"gateway" => $distItem["external_id"],
												"api" => 0,
												"sim" => 0,
												"mode" => 2,
												"priority" => 0,
												"phone" => $contact["phone"],
												"message" => $message,
												"status" => $gateways[$distItem["external_id"]]["callback"] < 2 ? 2 : 3,
												"status_code" => false,
												"create_date" => date("Y-m-d H:i:s", time())
											]);
		
											if($create):
												if($gateways[$distItem["external_id"]]["callback"] < 2):
													$this->cache->container("system.gateways");
		
													$this->cache->set("{$gateways[$distItem["external_id"]]["callback_id"]}.{$send}", $create);
												else:
													$this->process->_sanitize = $this->sanitize;
								
													$hooks = $this->process->actionHooks(logged_id, 1, 1, $contact["phone"], $message, $this->device->getActions(logged_id, 1));
		
													if(!empty($hooks)):
														foreach($hooks as $hook):
															$this->system->create("events", [
																"uid" => logged_id,
																"type" => 2,
																"create_date" => date("Y-m-d H:i:s", time())
															]);
														endforeach;
													endif;
		
													$this->system->credits(logged_id, "decrease", $contact["price"]);
												endif;
											endif;
										else:
											$this->system->create("sent", [
												"cid" => $smsCampaign,
												"uid" => logged_id,
												"did" => false,
												"gateway" => $distItem["external_id"],
												"api" => 0,
												"sim" => 0,
												"mode" => 2,
												"priority" => 0,
												"phone" => $contact["phone"],
												"message" => $message,
												"status" => 4,
												"status_code" => false,
												"create_date" => date("Y-m-d H:i:s", time())
											]);
										endif;

										$sendCounterExternal++;
									endif;
								else:
									$currency = country($distItem["country"])->getCurrency()["iso_4217_code"];
									$final_price = $this->titansys->calculatePartnerSendPrice($currency, $device["rate"], $this->cache);
		
									if($final_price && $credits >= ($final_price * count($distItem["contacts"]))):
										$slots = explode(",", $distItem["global_slots"]);
										$sim = count($slots) > 1 ? rand(1, 2) : ($slots[0] < 2 ? 1 : 2);
		
										$rejectLimit = false;
		
										if(!$rejectLimit):
											$this->system->create("sent", [
												"cid" => $smsCampaign,
												"uid" => logged_id,
												"did" => $distItem["did"],
												"gateway" => 0,
												"sim" => $sim,
												"mode" => 2,
												"phone" => $contact["phone"],
												"message" => $this->spintax->process($this->lex->parse($request["message"], [
													"contact" => [
														"name" => $contact["name"],
														"number" => $contact["phone"]
													],
													"group" => [
														"name" => $contact["group"]
													],
													"unsubscribe" => [
														"command" => "STOP",
														"link" => site_url("unsubscribe/" . logged_id . "/{$contact["phone"]}", true)
													],
													"date" => [
														"now" => date("F j, Y"),
														"time" => date("h:i A") 
													]
												])),
												"status" => 1,
												"status_code" => false,
												"priority" => $distItem["global_priority"],
												"api" => 2,
												"create_date" => date("Y-m-d H:i:s", time())
											]);
										endif;
									endif;
								endif;
							endif;
						endforeach;

						if($request["mode"] < 2 || (!isset($distItem["external_id"]) && !$this->sanitize->isInt($distItem["did"]))):
							$this->fcm->sendWithToken($distItem["fcm_token"], [
								"action" => "message_request"
							]);
						endif;
					endif;
				endforeach;

				response(200, ___(__("response_message_bulkqueuednew"), [$sendCounter + $sendCounterExternal]));

        		break;
        	case "sms.excel":
        		if(!isset($_FILES["excel"], $request["campaign"], $request["mode"], $request["shortener"], $request["message"]))
        			response(500, __("response_invalid"));

        		if(!$this->file->exists("system/storage/temporary/firebase.json")):
					response(500, __("response_system_sysconfigerr"));
				endif;

				if(!$this->sanitize->length($request["campaign"]))
					response(500, __("requests_create_campaignnametooshort"));

				if(!$this->sanitize->isInt($request["mode"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["shortener"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->length($request["message"], system_message_min))
					response(500, __("response_message_short"));

				if(system_message_max > 0):
					if($this->sanitize->length($request["message"], system_message_max, 2))
						response(500, __("response_message_toolong"));
				endif;

        		if($request["mode"] < 2):
        			if(!isset($request["devices"]))
        				response(500, __("response_invalid"));

					if(!is_array($request["devices"]))
						response(500, __("response_invalid"));

        			$subscription = set_subscription(
	                    $this->system->checkSubscription(logged_id), 
	                    $this->system->getSubscription(false, logged_id), 
	                    $this->system->getSubscription(false, false, true)
	                );

					if(empty($subscription))
						response(500, __("response_package_nosubwarn"));

					$devices = [];

					foreach($request["devices"] as $device):
						if($this->system->checkDevice(logged_id, $device, "did") > 0):
							$devices[$device] = $this->system->getDevice(logged_id, $device, "did");
						endif;
					endforeach;

					if(empty($devices)):
						response(500, __("response_invalid"));
					endif;
        		else:
        			if(!isset($request["gateways"]))
        				response(500, __("response_invalid"));

					if(!is_array($request["gateways"]))
						response(500, __("response_invalid"));

					$gatewaysArr = [];

        			foreach($request["gateways"] as $gateway):
						if($this->sanitize->isInt($gateway)):
							$gateways = $this->system->getGateways();

							if(array_key_exists($gateway, $gateways) && $this->file->exists("system/gateways/" . md5($gateway) . ".php")):
								try {
									$gatewaysArr["external_{$gateway}"] = [
										"external_id" => $gateway,
										"name" => $gateways[$gateway]["name"],
										"function" => require "system/gateways/" . md5($gateway) . ".php"
									];
								} catch(Exception $e){
									// Ignore
								}
							endif;
						else:
							$device = $this->system->getDevice(false, $gateway, "global");
	
							if($device):
								if($device["uid"] != logged_id && $device["global_device"] < 2):
									$gatewaysArr["partner_{$device["id"]}"] = $device;
								endif;
							endif;
						endif;
					endforeach;

					if(empty($gatewaysArr)):
						response(500, __("response_invalid"));
					endif;
        		endif;

        		try {
        			$this->upload->upload($_FILES["excel"]);
	    			if($this->upload->uploaded):
	    				if(!in_array($this->upload->file_src_name_ext, ["xlsx"]))
	    					response(500, __("response_invalid_excel"));

	    				$this->upload->mime_check = false;
		                $this->upload->file_new_name_body = logged_hash;
		                $this->upload->file_overwrite = true;
		                $this->upload->process("uploads/sheets/");

						if($this->upload->processed)
							$this->upload->clean();
						else
							response(500, __("response_invalid_excel"));
					endif;
        		} catch(Exception $e){
        			response(500, __("response_invalid_excel"));
        		}

        		try {
        			$reader = $this->sheet->read("uploads/sheets/" . logged_hash . ".xlsx");
        		} catch(Exception $e){
        			response(500, __("response_invalid_excel"));
        		}

        		$contactBook = [];

        		foreach($reader->getSheetIterator() as $sheet):
				    if($sheet->getIndex() === 0):
				    	$headers = [];
				    	$singleHeader = [];
				    	$rowIndex = 0;

				        foreach($sheet->getRowIterator() as $row):
			        		if($rowIndex < 1):
			        			if(!in_array("phone", $row->toArray()))
			        				response(500, ___(__("response_smsexcel_phonenotfound"), ["phone"]));

			        			if(!in_array("sim", $row->toArray()))
			        				response(500, ___(__("response_smsexcel_phonenotfound"), ["sim"]));

			        			if(!in_array("priority", $row->toArray()))
			        				response(500, ___(__("response_smsexcel_phonenotfound"), ["priority"]));

					        	foreach($row->toArray() as $key => $value):
					        		$headers[$value] = $key;
					        	endforeach;
					        else:
					        	$cols = $row->toArray();

					        	$phone = isset($cols[$headers["phone"]]) ? $cols[$headers["phone"]] : '';
					        	$sim = isset($cols[$headers["sim"]]) ? $cols[$headers["sim"]] : 1;
					        	$priority = isset($cols[$headers["priority"]]) ? $cols[$headers["priority"]] : 0;

				        		$rejected = false;				        		

				        		try {
								    $number = $this->phone->parse($phone, logged_country);

								    if(!$number->isValidNumber() && $number->getRegionCode() != "BR")
										$rejected = true;

									$phoneNumber = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);
									$country = $number->getRegionCode();

									if($this->system->checkUnsubscribed(logged_id, $phoneNumber) > 0):
										$rejected = true;
									endif;
								} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
									$rejected = true;
								}

								if(!in_array($sim, [1, 2]))
									$rejected = true;

								if(!in_array($priority, [0, 1]))
									$rejected = true;

								if(!$rejected):
									$contactBook[$phoneNumber] = [
										"phone" => $phoneNumber,
										"sim" => $sim,
										"priority" => $priority,
										"country" => $country,
										"price" => 0
									];

									foreach($headers as $key => $value):
						        		if(!in_array($key, ["phone", "sim", "priority"])):
							        		$singleHeader[$phoneNumber][$key] = isset($cols[$value]) ? ($cols[$value] instanceof DateTime ? $cols[$value]->format(logged_date_format) : $cols[$value]) : '';
							        	endif;
						        	endforeach;
					        	endif;
					        endif;

					        $rowIndex++;
				        endforeach;
				    endif;	 
				endforeach;

				if(empty($contactBook))
					response(500, __("form_smsbulknonumbers"));

				$distContainer = [];

				if($request["mode"] < 2):
					$chunkSize = ceil(count($contactBook) / count($devices));
					$contactChunks = array_chunk($contactBook, $chunkSize, true);
				
					foreach ($devices as $did => $device):
						$chunk = (isset($contactChunks[0]) ? array_shift($contactChunks) : []);
				
						$distContainer[$did] = array_merge($device, [
							"contacts" => $chunk
						]);
					endforeach;
				else:
					$chunkSize = ceil(count($contactBook) / count($gatewaysArr));
					$contactChunks = array_chunk($contactBook, $chunkSize, true);
				
					foreach ($gatewaysArr as $key => $gateway):
						$chunk = (isset($contactChunks[0]) ? array_shift($contactChunks) : []);
				
						foreach ($chunk as $contactKey => $contact):
							if($request["mode"] > 1 && isset($gateway["external_id"])):
								$pricing = json_decode($gateways[$gateway["external_id"]]["pricing"], true);

								if(array_key_exists(strtolower($country), $pricing["countries"])):
									$price = $pricing["countries"][strtolower($country)];
								else:
									$price = $pricing["default"];
								endif;

								$contact["price"] = $price;
							endif;

							$chunk[$contactKey] = $contact;
						endforeach;
				
						$distContainer[$key] = array_merge($gateway, [
							"contacts" => $chunk
						]);
					endforeach;
				endif;

				if($request["shortener"] > 0):
					if(!$this->file->exists("system/shorteners/" . md5($request["shortener"]) . ".php"))
						response(500, __("response_went_wrong"));

					$messageLinks = (new VStelmakh\UrlHighlight\UrlHighlight)->getUrls($request["message"]);

					if(!empty($messageLinks)):
						try {
							require "system/shorteners/" . md5($request["shortener"]) . ".php";
						} catch(Exception $e){
							response(500, __("requests_create_encounteredshortenererror"));
						}

						foreach($messageLinks as $key => $value):
							$shortLink = shortenUrl($value, $this);

							if($shortLink):
								$request["message"] = str_replace($value, $shortLink, $request["message"]);
							endif;
						endforeach;
					endif;
				endif;

				$sendCounter = 0;
				$sendCounterExternal = 0;

				foreach($distContainer as $distItem):
					if(!empty($distItem["contacts"])):
						$smsCampaign = $this->system->create("campaigns", [
							"uid" => logged_id,
							"did" => $request["mode"] < 2 ? $distItem["did"] : (isset($distItem["external_id"]) ? false : $distItem["did"]),
							"gateway" => $request["mode"] > 1 && isset($distItem["external_id"]) ? $distItem["external_id"] : 0,
							"mode" => $request["mode"],
							"status" => 1,
							"name" => "{$request["campaign"]} ({$distItem["name"]})",
							"contacts" => count($distItem["contacts"]),
							"create_date" => date("Y-m-d H:i:s", time())
						]);
						
						foreach($distItem["contacts"] as $contact):
							if($request["mode"] < 2):
								if(!limitation($subscription["send_limit"], $this->system->countQuota(logged_id, "sent"))):
									$rejectLimit = false;
		
									if(!$rejectLimit):
										$this->system->create("sent", [
											"cid" => $smsCampaign,
											"uid" => logged_id,
											"did" => $distItem["did"],
											"gateway" => 0,
											"sim" => $contact["sim"] < 2 ? 1 : 2,
											"mode" => 1,
											"phone" => $contact["phone"],
											"message" => $this->spintax->process($this->lex->parse(footermark($subscription["footermark"], $request["message"], system_message_mark), [
												"phone" => $contact["phone"],
												"sim" => $contact["sim"],
												"priority" => $contact["priority"],
												"custom" => $singleHeader[$contact["phone"]],
												"unsubscribe" => [
													"command" => "STOP",
													"link" => site_url("unsubscribe/" . logged_id . "/{$contact["phone"]}", true)
												],
												"date" => [
													"now" => date("F j, Y"),
													"time" => date("h:i A") 
												]
											])),
											"status" => 1,
											"status_code" => false,
											"priority" => $contact["priority"] < 1 ? 1 : 2,
											"api" => 2,
											"create_date" => date("Y-m-d H:i:s", time())
										]);
		
										$sendCounter++;
									endif;
								endif;
							else:
								$credits = $this->system->getCredits(logged_id);
		
								if(isset($distItem["external_id"])):
									if($credits >= $contact["price"]):
										$gateway = $gateways[$distItem["external_id"]];
		
										$message = $this->spintax->process($this->lex->parse($request["message"], [
											"phone" => $contact["phone"],
											"sim" => $contact["sim"],
											"priority" => $contact["priority"],
											"custom" => $singleHeader[$contact["phone"]],
											"unsubscribe" => [
												"command" => "STOP",
												"link" => site_url("unsubscribe/" . logged_id . "/{$contact["phone"]}", true)
											],
											"date" => [
												"now" => date("F j, Y"),
												"time" => date("h:i A") 
											]
										]));
		
										$send = $distItem["function"]["send"]($contact["phone"], $message, $this);
		
										if($send):
											$create = $this->system->create("sent", [
												"cid" => $smsCampaign,
												"uid" => logged_id,
												"did" => false,
												"gateway" => $distItem["external_id"],
												"api" => 0,
												"sim" => 0,
												"mode" => 2,
												"priority" => 0,
												"phone" => $contact["phone"],
												"message" => $message,
												"status" => $gateways[$distItem["external_id"]]["callback"] < 2 ? 2 : 3,
												"status_code" => false,
												"create_date" => date("Y-m-d H:i:s", time())
											]);
		
											if($create):
												if($gateways[$distItem["external_id"]]["callback"] < 2):
													$this->cache->container("system.gateways");
		
													$this->cache->set("{$gateways[$distItem["external_id"]]["callback_id"]}.{$send}", $create);
												else:
													$this->process->_sanitize = $this->sanitize;
								
													$hooks = $this->process->actionHooks(logged_id, 1, 1, $contact["phone"], $message, $this->device->getActions(logged_id, 1));
		
													if(!empty($hooks)):
														foreach($hooks as $hook):
															$this->system->create("events", [
																"uid" => logged_id,
																"type" => 2,
																"create_date" => date("Y-m-d H:i:s", time())
															]);
														endforeach;
													endif;
		
													$this->system->credits(logged_id, "decrease", $contact["price"]);
												endif;
											endif;
										else:
											$this->system->create("sent", [
												"cid" => $smsCampaign,
												"uid" => logged_id,
												"did" => false,
												"gateway" => $distItem["external_id"],
												"api" => 0,
												"sim" => 0,
												"mode" => 2,
												"priority" => 0,
												"phone" => $contact["phone"],
												"message" => $message,
												"status" => 4,
												"status_code" => false,
												"create_date" => date("Y-m-d H:i:s", time())
											]);
										endif;

										$sendCounterExternal++;
									endif;
								else:
									$currency = country($distItem["country"])->getCurrency()["iso_4217_code"];
									$final_price = $this->titansys->calculatePartnerSendPrice($currency, $device["rate"], $this->cache);
		
									if($final_price && $credits >= ($final_price * count($distItem["contacts"]))):
										$slots = explode(",", $distItem["global_slots"]);
										$sim = count($slots) > 1 ? rand(1, 2) : ($slots[0] < 2 ? 1 : 2);
		
										$rejectLimit = false;
		
										if(!$rejectLimit):
											$this->system->create("sent", [
												"cid" => $smsCampaign,
												"uid" => logged_id,
												"did" => $distItem["did"],
												"gateway" => 0,
												"sim" => $sim,
												"mode" => 2,
												"phone" => $contact["phone"],
												"message" => $this->spintax->process($this->lex->parse($request["message"], [
													"phone" => $contact["phone"],
													"sim" => $contact["sim"],
													"priority" => $contact["priority"],
													"custom" => $singleHeader[$contact["phone"]],
													"unsubscribe" => [
														"command" => "STOP",
														"link" => site_url("unsubscribe/" . logged_id . "/{$contact["phone"]}", true)
													],
													"date" => [
														"now" => date("F j, Y"),
														"time" => date("h:i A") 
													]
												])),
												"status" => 1,
												"status_code" => false,
												"priority" => $device["global_priority"],
												"api" => 2,
												"create_date" => date("Y-m-d H:i:s", time())
											]);
										endif;
									endif;
								endif;
							endif;
						endforeach;

						if($request["mode"] < 2 || (!isset($distItem["external_id"]) && !$this->sanitize->isInt($distItem["did"]))):
							$this->fcm->sendWithToken($distItem["fcm_token"], [
								"action" => "message_request"
							]);
						endif;
					endif;
				endforeach;

				response(200, ___(__("response_message_bulkqueuednew"), [$sendCounter + $sendCounterExternal]));

        		break;
        	case "add.sms.scheduled":
        		if(!isset($request["mode"], $request["shortener"], $request["schedule"], $request["repeat"], $request["numbers"], $request["groups"], $request["message"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->isInt($request["mode"]))
					response(500, __("response_invalid"));

				if(!is_array($request["groups"]))
        			response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["shortener"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["repeat"]))
					response(500, __("response_invalid"));

				if(empty($request["numbers"]) && in_array(0, $request["groups"]))
					response(500, __("form_smsbulknonumbers"));

				if(!$this->sanitize->length($request["message"], system_message_min))
					response(500, __("response_message_short"));

				if(system_message_max > 0):
					if($this->sanitize->length($request["message"], system_message_max, 2))
						response(500, __("response_message_toolong"));
				endif;

				if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], false)):
					$this->system->create("logger", [
                        "uid" => logged_id,
                        "type" => "ai_moderation",
                        "reason" => "Inappropriate Content!",
                        "content" => "Type: SMS Scheduled, Message: {$request["message"]}",
                        "create_date" => date("Y-m-d H:i:s", time())
                    ]);
					
					response(500, __("response_aimoderation_detected"));
				endif;

				$subscription = set_subscription(
                    $this->system->checkSubscription(logged_id), 
                    $this->system->getSubscription(false, logged_id), 
                    $this->system->getSubscription(false, false, true)
                );

                if(empty($subscription))
					response(500, __("response_package_nosubwarn"));

                if(limitation($subscription["scheduled_limit"], $this->system->countScheduled(logged_id)))
    				response(500, __("requests_response_subschedmaxreached"));

				if($request["shortener"] > 0):
					if(!$this->file->exists("system/shorteners/" . md5($request["shortener"]) . ".php"))
						response(500, __("response_went_wrong"));

					$messageLinks = (new VStelmakh\UrlHighlight\UrlHighlight)->getUrls($request["message"]);

					if(!empty($messageLinks)):
						try {
							require "system/shorteners/" . md5($request["shortener"]) . ".php";
						} catch(Exception $e){
							response(500, __("requests_create_encounteredshortenererror"));
						}

						foreach($messageLinks as $key => $value):
							$shortLink = shortenUrl($value, $this);

							if($shortLink):
								$request["message"] = str_replace("{$value}", "{$shortLink}", $request["message"]);
							endif;
						endforeach;
					endif;
				endif;

        		if($request["mode"] < 2):
        			if(!isset($request["device"], $request["sim"]))
        				response(500, __("response_invalid"));

					if(!$this->sanitize->isInt($request["sim"]))
						response(500, __("response_invalid"));

	    			if($this->system->checkDevice(logged_id, $request["device"], "did") < 1)
    					response(500, __("response_invalid"));

	    			$device = $this->system->getDevice(logged_id, $request["device"], "did");

	    			if($device):
    					$filtered = [
							"uid" => logged_id,
							"did" => $request["device"],
							"sim" => $request["sim"] < 2 ? 1 : 2,
							"mode" => 1,
							"gateway" => 0,
							"groups" => implode(",", $request["groups"]),
							"name" => $request["name"],
							"numbers" => $this->sanitize->normalizeContactsCommas($request["numbers"]),
							"message" => $request["message"],
							"repeat" => $request["repeat"],
							"last_send" => false,
							"send_date" => strtotime($request["schedule"])
						];
		    		else:
		    			response(500, __("response_invalid"));
		    		endif;
        		else:
        			if(!isset($request["gateway"]))
        				response(500, __("response_invalid"));

        			if($this->sanitize->isInt($request["gateway"])):
        				$gateways = $this->system->getGateways();

						if(!array_key_exists($request["gateway"], $gateways)):
							response(500, __("response_invalid"));
						endif;

						$filtered = [
							"uid" => logged_id,
							"did" => false,
							"sim" => 0,
							"mode" => 2,
							"gateway" => $request["gateway"],
							"groups" => implode(",", $request["groups"]),
							"name" => $request["name"],
							"numbers" => $request["numbers"],
							"message" => $request["message"],
							"repeat" => $request["repeat"],
							"last_send" => false,
							"send_date" => strtotime($request["schedule"])
						];
        			else:
        				$device = $this->system->getDevice(false, $request["gateway"], "global");

		    			if($device):
			    			if($device["global_device"] > 1):
			    				response(500, __("response_invalid"));
			    			else:
			    				$slots = explode(",", $device["global_slots"]);

			    				$filtered = [
									"uid" => logged_id,
									"did" => $request["gateway"],
									"sim" => count($slots) > 1 ? rand(1, 2) : ($slots[0] < 2 ? 1 : 2),
									"mode" => 2,
									"gateway" => 0,
									"groups" => implode(",", $request["groups"]),
									"name" => $request["name"],
									"numbers" => $request["numbers"],
									"message" => $request["message"],
									"repeat" => $request["repeat"],
									"last_send" => false,
									"send_date" => strtotime($request["schedule"])
								];
			    			endif;
			    		else:
			    			response(500, __("response_invalid"));
			    		endif;
        			endif;
        		endif;

				if($this->system->create("scheduled", $filtered)):
					response(200, __("response_smssched_queued"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
        	case "whatsapp.quick":
        		if(!isset($request["phone"], $request["shortener"], $request["account"], $request["message"], $request["priority"], $request["message_type"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->isInt($request["shortener"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->isInt($request["account"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["priority"]))
					response(500, __("response_invalid"));

        		if(!in_array($request["message_type"], ["text", "media", "document"]))
        			response(500, __("response_invalid"));

        		if(in_array($request["message_type"], ["text"])):
					if(!$this->sanitize->length($request["message"], system_message_min))
						response(500, __("response_message_short"));

					if(system_message_max > 0):
						if($this->sanitize->length($request["message"], system_message_max, 2))
							response(500, __("response_message_toolong"));
					endif;

					if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], false)):
						$this->system->create("logger", [
							"uid" => logged_id,
							"type" => "ai_moderation",
							"reason" => "Inappropriate Content!",
							"content" => "Type: WhatsApp, Message: {$request["message"]}",
							"create_date" => date("Y-m-d H:i:s", time())
						]);
						
						response(500, __("response_aimoderation_detected"));
					endif;
				endif;

        		$subscription = set_subscription(
                    $this->system->checkSubscription(logged_id), 
                    $this->system->getSubscription(false, logged_id), 
                    $this->system->getSubscription(false, false, true)
                );

				if(empty($subscription))
					response(500, __("response_package_nosubwarn"));

				if($this->system->checkWaAccount(logged_id, $request["account"], "id") < 1)
					response(500, __("response_invalid"));

				if($this->system->checkQuota(logged_id) < 1):
					$this->system->create("quota", [
						"uid" => logged_id,
						"sent" => 0,
						"received" => 0,
						"wa_sent" => 0,
						"wa_received" => 0,
						"ussd" => 0
					]);
				endif;

				$account = $this->system->getWaAccount(logged_id, $request["account"], "id");

				if(!$account)
					response(500, __("response_invalid"));

				$waServer = $this->system->getWaServer($account["wsid"], "id");

				if($waServer && !$this->wa->check($waServer["url"], $waServer["port"]))
					response(500, __("response_whatsapp_noconnectserver"));

				$status = $this->wa->status($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"]);

                if(!$status || !in_array($status, ["connected"]))
                    response(500, __("response_waquick_waaccountnotconn"));

				if(limitation($subscription["wa_send_limit"], $this->system->countQuota(logged_id, "wa_sent")))
    				response(500, __("response_whatsapp_reachedmaxchat"));

				if(!find("@g.us", $request["phone"])):
					try {
						$number = $this->phone->parse($request["phone"], logged_country);

						if(!$number->isValidNumber() && $number->getRegionCode() != "BR")
							response(500, __("response_invalid_number"));

						$request["phone"] = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);

						if($number->getRegionCode() == "MX"):
							$request["phone"] = formatMexicoNumWa($request["phone"]);
						endif;
					} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
						response(500, __("response_invalid_number"));
					}
				endif;

				if($request["shortener"] > 0):
					if(!$this->file->exists("system/shorteners/" . md5($request["shortener"]) . ".php"))
						response(500, __("response_went_wrong"));

					$messageLinks = (new VStelmakh\UrlHighlight\UrlHighlight)->getUrls($request["message"]);

					if(!empty($messageLinks)):
						try {
							require "system/shorteners/" . md5($request["shortener"]) . ".php";
						} catch(Exception $e){
							response(500, __("requests_create_encounteredshortenererror"));
						}

						foreach($messageLinks as $key => $value):
							$shortLink = shortenUrl($value, $this);

							if($shortLink):
								$request["message"] = str_replace($value, $shortLink, $request["message"]);
							endif;
						endforeach;
					endif;
				endif;

				$request["message"] = $this->spintax->process(footermark($subscription["footermark"], $request["message"], system_message_mark));

				switch($request["message_type"]):
        			case "media":
        				try {
		        			$this->upload->upload($_FILES["media_file"]);
			    			if($this->upload->uploaded):
			    				if(!in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif", "mp4", "mp3", "ogg"]))
			    					response(500, __("response_whatsapp_invalidmediafile"));

			    				$mediaName = logged_hash . "_" . uniqid(logged_hash, true);

			    				$this->upload->mime_check = false;
				                $this->upload->file_new_name_body = $mediaName;
				                $this->upload->file_overwrite = true;
				                $this->upload->process("uploads/whatsapp/sent/" . logged_id . "/");

								if($this->upload->processed):
									if(in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif"])):
										// image
				        				$message = [
				        					"image" => [
				        						"url" => site_url("uploads/whatsapp/sent/" . logged_id . "/{$mediaName}.{$this->upload->file_src_name_ext}", true)
				        					],
				        					"caption" => $request["message"]
				        				];

										if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], $message["image"]["url"])):
											$this->system->create("logger", [
												"uid" => logged_id,
												"type" => "ai_moderation",
												"reason" => "Inappropriate Content!",
												"content" => empty($request["message"]) ? "Type: WhatsApp, Media: <a href=\"{$message["image"]["url"]}\" class=\"btn btn-sm btn-primary\" target=\"_blank\">Open Link</a>" : "Type: WhatsApp, Message: {$request["message"]}, Media: <a href=\"{$message["image"]["url"]}\" class=\"btn btn-sm btn-primary\" target=\"_blank\">Open Link</a>",
												"create_date" => date("Y-m-d H:i:s", time())
											]);	
											
											response(500, __("response_aimoderation_detected"));
										endif;
									elseif(in_array($this->upload->file_src_name_ext, ["mp3", "ogg"])):
										// audio
										$message = [
				        					"audio" => [
				        						"url" => site_url("uploads/whatsapp/sent/" . logged_id . "/{$mediaName}.{$this->upload->file_src_name_ext}", true)
											],
											"caption" => false
				        				];
									else:
										// video
				        				$message = [
				        					"video" => [
				        						"url" => site_url("uploads/whatsapp/sent/" . logged_id . "/{$mediaName}.{$this->upload->file_src_name_ext}", true)
				        					],
				        					"caption" => $request["message"]
				        				];
									endif;

									$this->upload->clean();
								else:
									response(500, __("response_whatsapp_invalidmediafile"));
								endif;
							endif;
		        		} catch(Exception $e){
		        			response(500, __("response_whatsapp_invalidmediafile"));
		        		}

        				break;
        			case "document":
        				try {
		        			$this->upload->upload($_FILES["doc_file"]);
			    			if($this->upload->uploaded):
			    				switch($this->upload->file_src_name_ext):
			    					case "pdf":
			    						$docMimetype = "application/pdf";

			    						break;
									case "xml":
										$docMimetype = "application/xml";
										
										break;
			    					case "xls":
			    						$docMimetype = "application/excel";
			    						
			    						break;
			    					case "xlsx":
			    						$docMimetype = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
			    						
			    						break;
			    					case "doc":
			    						$docMimetype = "application/msword";
			    						
			    						break;
			    					case "docx":
			    						$docMimetype = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
			    						
			    						break;
			    					default:
			    						response(500, __("response_whatsapp_invaldocumenfile"));
		    					endswitch;

								$fileName = $this->upload->file_src_name;
			    				$docName = logged_hash . "_" . uniqid(logged_hash, true);

			    				$this->upload->mime_check = false;
				                $this->upload->file_new_name_body = $docName;
				                $this->upload->file_overwrite = true;
				                $this->upload->process("uploads/whatsapp/sent/" . logged_id . "/");

								if($this->upload->processed):
									$message = [
			        					"document" => [
			        						"url" => site_url("uploads/whatsapp/sent/" . logged_id . "/{$docName}.{$this->upload->file_src_name_ext}", true)
			        					],
										"fileName" => $fileName,
			        					"mimetype" => $docMimetype,
			        					"caption" => $request["message"]
			        				];

									$this->upload->clean();
								else:
									response(500, __("response_whatsapp_invaldocumenfile"));
								endif;
							else:
								response(500, __("response_whatsapp_invaldocumenfile"));
							endif;
		        		} catch(Exception $e){
		        			response(500, __("response_whatsapp_invaldocumenfile"));
		        		}

        				break;
        			default:
        				$message = [
        					"text" => $request["message"]
        				];
        		endswitch;

				if(!isset($message))
					response(500, __("response_unable_to_process_msg"));

				$filtered = [
					"cid" => 0,
					"uid" => logged_id,
					"wid" => $account["wid"],
					"unique" => $account["unique"],
					"phone" => $request["phone"],
					"message" => json_encode($message),
					"status" => 1,
					"priority" => $request["priority"] < 2 ? 1 : 2,
					"api" => 2,
					"create_date" => date("Y-m-d H:i:s", time())
				];
				
				$create = $this->system->create("wa_sent", $filtered);

				if($create):
					if($filtered["priority"] < 2):
						$sendPriority = $this->wa->sendPriority($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"], $create, $filtered["phone"], $filtered["message"]);
						
						if($sendPriority):
							if($sendPriority == 200):
								$this->system->increment(logged_id, "wa_sent");
								response(200, __("requests_waquicksend_prioritysuccess"));
							else:
								response(500, __("requests_waquicksend_priorityfail"));
							endif;
						else:
							response(500, __("response_whatsapp_unableconnectserv"));
						endif;
					else:
						$addQueue = $this->wa->send($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"]);

						if($addQueue):
							if($addQueue == 200):
								response(200, __("response_whatsapp_chatqueued"));
							else:
								response(500, __("response_whatsapp_failedchatsqueue"));
							endif;
						else:
							response(500, __("response_whatsapp_unableconnectserv"));
						endif;
					endif;
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
        	case "whatsapp.bulk":
        		if(!isset($request["campaign"], $request["groups"], $request["numbers"], $request["shortener"], $request["accounts"], $request["message"], $request["message_type"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->length($request["campaign"]))
					response(500, __("requests_create_campaignnametooshort"));

				if(!is_array($request["groups"]))
        			response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["shortener"]))
					response(500, __("response_invalid"));

				if(!in_array($request["message_type"], ["text", "media", "document"]))
        			response(500, __("response_invalid"));

				if(in_array($request["message_type"], ["text"])):
					if(!$this->sanitize->length($request["message"], system_message_min))
						response(500, __("response_message_short"));

					if(system_message_max > 0):
						if($this->sanitize->length($request["message"], system_message_max, 2))
							response(500, __("response_message_toolong"));
					endif;

					if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], false)):
						$this->system->create("logger", [
							"uid" => logged_id,
							"type" => "ai_moderation",
							"reason" => "Inappropriate Content!",
							"content" => "Type: WhatsApp Bulk, Message: {$request["message"]}",
							"create_date" => date("Y-m-d H:i:s", time())
						]);
						
						response(500, __("response_aimoderation_detected"));
					endif;
				endif;

				if(!is_array($request["accounts"]))
					response(500, __("response_invalid"));

				foreach($request["accounts"] as $account):
					if(!$this->sanitize->isInt($account)):
						response(500, __("response_invalid"));
					endif;
				endforeach;

				$subscription = set_subscription(
                    $this->system->checkSubscription(logged_id), 
                    $this->system->getSubscription(false, logged_id), 
                    $this->system->getSubscription(false, false, true)
                );

				if(empty($subscription))
					response(500, __("response_package_nosubwarn"));

				$accounts = [];
				
				foreach($request["accounts"] as $account):
					if($this->system->checkWaAccount(logged_id, $account, "id") > 0):
						$getAccDetails = $this->system->getWaAccount(logged_id, $account, "id");

						if($getAccDetails):
							$waServer = $this->system->getWaServer($getAccDetails["wsid"], "id");

							try {
								$getAccStatus = $this->wa->status($waServer["secret"], $waServer["url"], $waServer["port"], $getAccDetails["unique"]);

								if($this->wa->check($waServer["url"], $waServer["port"])):
									if($getAccStatus && in_array($getAccStatus, ["connected"])):
										$accounts[$account] = $getAccDetails;
										$accounts[$account]["server"] = $waServer;
									endif;
								endif;

								unset($getAccDetails, $waServer, $getAccStatus);
							} catch(Exception $e){
								// Ignore
							}
						endif;
					endif;
				endforeach;

				if(empty($accounts))
					response(500, __("wa_bulk_selec_acc_unconnected"));

				if($this->system->checkQuota(logged_id) < 1):
					$this->system->create("quota", [
						"uid" => logged_id,
						"sent" => 0,
						"received" => 0,
						"wa_sent" => 0,
						"wa_received" => 0,
						"ussd" => 0
					]);
				endif;

				$contactBook = [];

				$numbers = explode(",", $this->sanitize->normalizeContactsCommas($request["numbers"]));

				if(!empty($numbers) && !empty($numbers[0])):
					foreach($numbers as $number):
						$rejected = false;

						$number = trim($number);

						if(find("@g.us", $number)):
							$phoneNumber = $number;
						else:
							try {
							    $phone = $this->phone->parse($number, logged_country);

								if(!$phone->isValidNumber() && $phone->getRegionCode() != "BR")
									$rejected = true;

								$phoneNumber = $phone->format(Brick\PhoneNumber\PhoneNumberFormat::E164);

								if($phone->getRegionCode() == "MX"):
									$phoneNumber = formatMexicoNumWa($phoneNumber);
								endif;

								if($this->system->checkUnsubscribed(logged_id, $phoneNumber) > 0):
									$rejected = true;
								endif;
							} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
								$rejected = true;
							}
						endif;

						if(!$rejected):
							$contactBook[$phoneNumber] = [
								"name" => $phoneNumber,
								"phone" => $phoneNumber,
								"group" => "Unknown"
							];
						endif;

						unset($rejected);
					endforeach;
				endif;

				if(!in_array(0, $request["groups"])):
					foreach($request["groups"] as $group):
						if($this->system->checkGroup(logged_id, $group) > 0):
							$contacts = $this->system->getContactsByGroup(logged_id, $group);
							
							if(!empty($contacts)):
								foreach($contacts as $contact):
									$rejected = false;

									try {
									    $phone = $this->phone->parse($contact["phone"]);

										$phoneNumber = $phone->format(Brick\PhoneNumber\PhoneNumberFormat::E164);

										if($phone->getRegionCode() == "MX"):
											$phoneNumber = formatMexicoNumWa($phoneNumber);
										endif;

										if($this->system->checkUnsubscribed(logged_id, $phoneNumber) > 0):
											$rejected = true;
										endif;
									} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
										$rejected = true;
									}

									if(!$rejected):
										$contactBook[$contact["phone"]] = [
											"name" => $contact["name"],
											"phone" => $phoneNumber,
											"group" => $contact["group"]
										];
									endif;
								endforeach;
							endif;
						endif;
					endforeach;
				endif;

				if(empty($contactBook))
					response(500, __("form_smsbulknonumbers"));

				$distContainer = [];

				$chunkSize = ceil(count($contactBook) / count($accounts));
				$contactChunks = array_chunk($contactBook, $chunkSize, true);
			
				foreach($accounts as $id => $account):
					$chunk = (isset($contactChunks[0]) ? array_shift($contactChunks) : []);
			
					$distContainer[$id] = array_merge($account, [
						"contacts" => $chunk
					]);
				endforeach;

				if($request["shortener"] > 0):
					if(!$this->file->exists("system/shorteners/" . md5($request["shortener"]) . ".php"))
						response(500, __("response_went_wrong"));

					$messageLinks = (new VStelmakh\UrlHighlight\UrlHighlight)->getUrls($request["message"]);

					if(!empty($messageLinks)):
						try {
							require "system/shorteners/" . md5($request["shortener"]) . ".php";
						} catch(Exception $e){
							response(500, __("requests_create_encounteredshortenererror"));
						}

						foreach($messageLinks as $key => $value):
							$shortLink = shortenUrl($value, $this);

							if($shortLink):
								$request["message"] = str_replace($value, $shortLink, $request["message"]);
							endif;
						endforeach;
					endif;
				endif;

				switch($request["message_type"]):
        			case "media":
        				try {
		        			$this->upload->upload($_FILES["media_file"]);
			    			if($this->upload->uploaded):
			    				if(!in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif", "mp4", "mp3", "ogg"]))
			    					response(500, __("response_whatsapp_invalidmediafile"));

			    				$mediaName = logged_hash . "_" . uniqid(logged_hash, true);

			    				$this->upload->mime_check = false;
				                $this->upload->file_new_name_body = $mediaName;
				                $this->upload->file_overwrite = true;
								$this->upload->process("uploads/whatsapp/sent/" . logged_id . "/");

								if($this->upload->processed):
									if(in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif"])):
										// image
				        				$message = [
				        					"image" => [
				        						"url" => site_url("uploads/whatsapp/sent/" . logged_id . "/{$mediaName}.{$this->upload->file_src_name_ext}", true)
				        					],
				        					"caption" => $request["message"]
				        				];

										if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], $message["image"]["url"])):
											$this->system->create("logger", [
												"uid" => logged_id,
												"type" => "ai_moderation",
												"reason" => "Inappropriate Content!",
												"content" => empty($request["message"]) ? "Type: WhatsApp Bulk, Media: <a href=\"{$message["image"]["url"]}\" class=\"btn btn-sm btn-primary\" target=\"_blank\">Open Link</a>" : "Type: WhatsApp Bulk, Message: {$request["message"]}, Media: <a href=\"{$message["image"]["url"]}\" class=\"btn btn-sm btn-primary\" target=\"_blank\">Open Link</a>",
												"create_date" => date("Y-m-d H:i:s", time())
											]);
											
											response(500, __("response_aimoderation_detected"));
										endif;
									elseif(in_array($this->upload->file_src_name_ext, ["mp3", "ogg"])):
										// audio
										$message = [
				        					"audio" => [
				        						"url" => site_url("uploads/whatsapp/sent/" . logged_id . "/{$mediaName}.{$this->upload->file_src_name_ext}", true)
											],
											"caption" => false
				        				];
									else:
										// video
				        				$message = [
				        					"video" => [
				        						"url" => site_url("uploads/whatsapp/sent/" . logged_id . "/{$mediaName}.{$this->upload->file_src_name_ext}", true)
				        					],
				        					"caption" => $request["message"]
				        				];
									endif;

									$this->upload->clean();
								else:
									response(500, __("response_whatsapp_invalidmediafile"));
								endif;
							endif;
		        		} catch(Exception $e){
		        			response(500, __("response_whatsapp_invalidmediafile"));
		        		}

        				break;
    				case "document":
        				try {
		        			$this->upload->upload($_FILES["doc_file"]);
			    			if($this->upload->uploaded):
			    				switch($this->upload->file_src_name_ext):
			    					case "pdf":
			    						$docMimetype = "application/pdf";

			    						break;
									case "xml":
										$docMimetype = "application/xml";
										
										break;
			    					case "xls":
			    						$docMimetype = "application/excel";
			    						
			    						break;
			    					case "xlsx":
			    						$docMimetype = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
			    						
			    						break;
			    					case "doc":
			    						$docMimetype = "application/msword";
			    						
			    						break;
			    					case "docx":
			    						$docMimetype = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
			    						
			    						break;
			    					default:
			    						response(500, __("response_whatsapp_invaldocumenfile"));
		    					endswitch;

								$fileName = $this->upload->file_src_name;
			    				$docName = logged_hash . "_" . uniqid(logged_hash, true);

			    				$this->upload->mime_check = false;
				                $this->upload->file_new_name_body = $docName;
				                $this->upload->file_overwrite = true;
				                $this->upload->process("uploads/whatsapp/sent/" . logged_id . "/");

								if($this->upload->processed):
									$message = [
			        					"document" => [
			        						"url" => site_url("uploads/whatsapp/sent/" . logged_id . "/{$docName}.{$this->upload->file_src_name_ext}", true)
			        					],
										"fileName" => $fileName,
			        					"mimetype" => $docMimetype,
			        					"caption" => $request["message"]
			        				];

									$this->upload->clean();
								else:
									response(500, __("response_whatsapp_invaldocumenfile"));
								endif;
							else:
								response(500, __("response_whatsapp_invaldocumenfile"));
							endif;
		        		} catch(Exception $e){
		        			response(500, __("response_whatsapp_invaldocumenfile"));
		        		}

        				break;
        			default:
        				$message = [
        					"text" => $request["message"]
        				];
        		endswitch;

				if(!isset($message))
					response(500, __("response_unable_to_process_msg"));

				$sendCounter = 0;

				foreach($distContainer as $distItem):
					if(!empty($distItem["contacts"])):
						$wid = explode(":", $distItem["wid"]);
						$waCampaign = $this->system->create("wa_campaigns", [
							"uid" => logged_id,
							"wid" => $distItem["wid"],
							"unique" => $distItem["unique"],
							"type" => $request["message_type"],
							"status" => 1,
							"name" => "{$request["campaign"]} (+{$wid[0]})",
							"contacts" => count($distItem["contacts"]),
							"processed" => 0,
							"create_date" => date("Y-m-d H:i:s", time())
						]);

						$msgText = isset($message["text"]) ? $message["text"] : $message["caption"];

						foreach($distItem["contacts"] as $contact):
							if(!limitation($subscription["wa_send_limit"], $this->system->countQuota(logged_id, "wa_sent"))):
								if(isset($message["text"]) || isset($message["caption"])):
									$messageContainer = $message;

									$formatMessage = $this->spintax->process($this->lex->parse(footermark($subscription["footermark"], $msgText, system_message_mark), [
										"contact" => [
											"name" => $contact["name"],
											"number" => $contact["phone"]
										],
										"group" => [
											"name" => $contact["group"]
										],
										"unsubscribe" => [
											"command" => "STOP",
											"link" => site_url("unsubscribe/" . logged_id . "/{$contact["phone"]}", true)
										],
										"date" => [
											"now" => date("F j, Y"),
											"time" => date("h:i A") 
										]
									]));

									if(isset($messageContainer["text"])):
										$messageContainer["text"] = $formatMessage;
									else:
										$messageContainer["caption"] = $formatMessage;
									endif;
								endif;

								$this->system->create("wa_sent", [
									"cid" => $waCampaign,
									"uid" => logged_id,
									"wid" => $distItem["wid"],
									"unique" => $distItem["unique"],
									"phone" => $contact["phone"],
									"message" => json_encode($messageContainer),
									"status" => 1,
									"priority" => 2,
									"api" => 2,
									"create_date" => date("Y-m-d H:i:s", time())
								]);

								$sendCounter++;
							endif;
						endforeach;

						if($this->wa->check($distItem["server"]["url"], $distItem["server"]["port"])):
							$this->wa->send($distItem["server"]["secret"], $distItem["server"]["url"], $distItem["server"]["port"], $distItem["unique"]);
						endif;
					endif;
				endforeach;
				
				if($sendCounter > 0):
					response(200, ___(__("response_whatsapp_bulkchatssentcount"), [$sendCounter]));
				else:
					response(500, __("response_whatsapp_failedchatsqueuemany"));
				endif;

        		break;
        	case "whatsapp.excel":
        		if(!isset($_FILES["excel"], $request["shortener"], $request["accounts"], $request["message"], $request["message_type"]))
        			response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["shortener"]))
					response(500, __("response_invalid"));

				if(!in_array($request["message_type"], ["text", "media", "document"]))
        			response(500, __("response_invalid"));

        		if(in_array($request["message_type"], ["text"])):
					if(!$this->sanitize->length($request["message"], system_message_min))
						response(500, __("response_message_short"));

					if(system_message_max > 0):
						if($this->sanitize->length($request["message"], system_message_max, 2))
							response(500, __("response_message_toolong"));
					endif;
				endif;

				if(!is_array($request["accounts"]))
					response(500, __("response_invalid"));

				foreach($request["accounts"] as $account):
					if(!$this->sanitize->isInt($account)):
						response(500, __("response_invalid"));
					endif;
				endforeach;

				$subscription = set_subscription(
                    $this->system->checkSubscription(logged_id), 
                    $this->system->getSubscription(false, logged_id), 
                    $this->system->getSubscription(false, false, true)
                );

				if(empty($subscription))
					response(500, __("response_package_nosubwarn"));

				$accounts = [];
			
				foreach($request["accounts"] as $account):
					if($this->system->checkWaAccount(logged_id, $account, "id") > 0):
						$getAccDetails = $this->system->getWaAccount(logged_id, $account, "id");

						if($getAccDetails):
							$waServer = $this->system->getWaServer($getAccDetails["wsid"], "id");

							try {
								$getAccStatus = $this->wa->status($waServer["secret"], $waServer["url"], $waServer["port"], $getAccDetails["unique"]);

								if($this->wa->check($waServer["url"], $waServer["port"])):
									if($getAccStatus && in_array($getAccStatus, ["connected"])):
										$accounts[$account] = $getAccDetails;
										$accounts[$account]["server"] = $waServer;
									endif;
								endif;

								unset($getAccDetails, $waServer, $getAccStatus);
							} catch(Exception $e){
								// Ignore
							}
						endif;
					endif;
				endforeach;

				if($this->system->checkQuota(logged_id) < 1):
					$this->system->create("quota", [
						"uid" => logged_id,
						"sent" => 0,
						"received" => 0,
						"wa_sent" => 0,
						"wa_received" => 0,
						"ussd" => 0
					]);
				endif;

        		try {
        			$this->upload->upload($_FILES["excel"]);
	    			if($this->upload->uploaded):
	    				if(!in_array($this->upload->file_src_name_ext, ["xlsx"]))
	    					response(500, __("response_invalid_excel"));

	    				$this->upload->mime_check = false;
		                $this->upload->file_new_name_body = logged_hash;
		                $this->upload->file_overwrite = true;
		                $this->upload->process("uploads/sheets/");

						if($this->upload->processed)
							$this->upload->clean();
						else
							response(500, __("response_invalid_excel"));
					endif;
        		} catch(Exception $e){
        			response(500, __("response_invalid_excel"));
        		}

        		try {
        			$reader = $this->sheet->read("uploads/sheets/" . logged_hash . ".xlsx");
        		} catch(Exception $e){
        			response(500, __("response_invalid_excel"));
        		}
				
				$contactBook = [];

        		foreach($reader->getSheetIterator() as $sheet):
				    if($sheet->getIndex() === 0):
				    	$headers = [];
				    	$rowIndex = 0;

				        foreach($sheet->getRowIterator() as $row):
			        		if($rowIndex < 1):
			        			if(!in_array("phone", $row->toArray()))
			        				response(500, ___(__("response_whatsapp_excelphonecolnotfound"), ["phone"]));

					        	foreach($row->toArray() as $key => $value):
					        		$headers[$value] = $key;
					        	endforeach;
					        else:
					        	$singleHeader = [];
					        	$cols = $row->toArray();

					        	foreach($headers as $key => $value):
					        		if(!in_array($key, ["phone"])):
						        		$singleHeader[$key] = isset($cols[$value]) ? ($cols[$value] instanceof DateTime ? $cols[$value]->format(logged_date_format) : $cols[$value]) : '';
						        	endif;
					        	endforeach;

					        	$phone = isset($cols[$headers["phone"]]) ? trim($cols[$headers["phone"]]) : '';

				        		$rejected = false;				        		

				        		if(find("@g.us", $phone)):
				        			$phoneNumber = $phone;
								else:
									try {
									    $number = $this->phone->parse($phone, logged_country);

										if(!$number->isValidNumber() && $number->getRegionCode() != "BR")
											$rejected = true;

										$phoneNumber = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);

										if($number->getRegionCode() == "MX"):
											$phoneNumber = formatMexicoNumWa($phoneNumber);
										endif;

										if($this->system->checkUnsubscribed(logged_id, $phoneNumber) > 0):
											$rejected = true;
										endif;
									} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
										$rejected = true;
									}
								endif;

								if(!$rejected):
									$contactBook[$phoneNumber] = [
										"phone" => $phoneNumber,
										"cols" => $singleHeader
									];
					        	endif;
					        endif;

					        $rowIndex++;
				        endforeach;
				    endif;	 
				endforeach;

				if(empty($contactBook))
					response(500, __("form_smsbulknonumbers"));

				$distContainer = [];

				$chunkSize = ceil(count($contactBook) / count($accounts));
				$contactChunks = array_chunk($contactBook, $chunkSize, true);
			
				foreach($accounts as $id => $account):
					$chunk = (isset($contactChunks[0]) ? array_shift($contactChunks) : []);
			
					$distContainer[$id] = array_merge($account, [
						"contacts" => $chunk
					]);
				endforeach;

				if($request["shortener"] > 0):
					if(!$this->file->exists("system/shorteners/" . md5($request["shortener"]) . ".php"))
						response(500, __("response_went_wrong"));

					$messageLinks = (new VStelmakh\UrlHighlight\UrlHighlight)->getUrls($request["message"]);

					if(!empty($messageLinks)):
						try {
							require "system/shorteners/" . md5($request["shortener"]) . ".php";
						} catch(Exception $e){
							response(500, __("requests_create_encounteredshortenererror"));
						}

						foreach($messageLinks as $key => $value):
							$shortLink = shortenUrl($value, $this);

							if($shortLink):
								$request["message"] = str_replace($value, $shortLink, $request["message"]);
							endif;
						endforeach;
					endif;
				endif;

				switch($request["message_type"]):
        			case "media":
        				try {
		        			$this->upload->upload($_FILES["media_file"]);
			    			if($this->upload->uploaded):
			    				if(!in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif", "mp4"]))
			    					response(500, __("response_whatsapp_invalidmediafile"));

			    				$mediaName = logged_hash . "_" . uniqid(logged_hash, true);

			    				$this->upload->mime_check = false;
				                $this->upload->file_new_name_body = $mediaName;
				                $this->upload->file_overwrite = true;
				                $this->upload->process("uploads/whatsapp/");

								if($this->upload->processed):
									if(in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif"])):
										// image
				        				$message = [
				        					"image" => [
				        						"url" => site_url("uploads/whatsapp/{$mediaName}.{$this->upload->file_src_name_ext}", true)
				        					],
				        					"caption" => $request["message"]
				        				];
									else:
										// video
				        				$message = [
				        					"video" => [
				        						"url" => site_url("uploads/whatsapp/{$mediaName}.{$this->upload->file_src_name_ext}", true)
				        					],
				        					"caption" => $request["message"]
				        				];
									endif;

									$this->upload->clean();
								else:
									response(500, __("response_whatsapp_invalidmediafile"));
								endif;
							endif;
		        		} catch(Exception $e){
		        			response(500, __("response_whatsapp_invalidmediafile"));
		        		}

        				break;
    				case "document":
        				try {
		        			$this->upload->upload($_FILES["doc_file"]);
			    			if($this->upload->uploaded):
			    				switch($this->upload->file_src_name_ext):
			    					case "pdf":
			    						$docMimetype = "application/pdf";

			    						break;
			    					case "xls":
			    						$docMimetype = "application/excel";
			    						
			    						break;
			    					case "xlsx":
			    						$docMimetype = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
			    						
			    						break;
			    					case "doc":
			    						$docMimetype = "application/msword";
			    						
			    						break;
			    					case "docx":
			    						$docMimetype = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
			    						
			    						break;
			    					default:
			    						response(500, __("response_whatsapp_invaldocumenfile"));
		    					endswitch;

								$fileName = $this->upload->file_src_name;
			    				$docName = logged_hash . "_" . uniqid(logged_hash, true);

			    				$this->upload->mime_check = false;
				                $this->upload->file_new_name_body = $docName;
				                $this->upload->file_overwrite = true;
				                $this->upload->process("uploads/whatsapp/");

								if($this->upload->processed):
									$message = [
			        					"document" => [
			        						"url" => site_url("uploads/whatsapp/{$docName}.{$this->upload->file_src_name_ext}", true)
			        					],
										"fileName" => $fileName,
			        					"mimetype" => $docMimetype,
			        					"caption" => $request["message"]
			        				];

									$this->upload->clean();
								else:
									response(500, __("response_whatsapp_invaldocumenfile"));
								endif;
							endif;
		        		} catch(Exception $e){
		        			response(500, __("response_whatsapp_invaldocumenfile"));
		        		}

        				break;
        			default:
        				$message = [
        					"text" => $request["message"]
        				];
        		endswitch;

				if(!isset($message))
					response(500, __("response_unable_to_process_msg"));

        		$sendCounter = 0;

				foreach($distContainer as $distItem):
					if(!empty($distItem["contacts"])):
						$wid = explode(":", $distItem["wid"]);
						$waCampaign = $this->system->create("wa_campaigns", [
							"uid" => logged_id,
							"wid" => $distItem["wid"],
							"unique" => $distItem["unique"],
							"type" => $request["message_type"],
							"status" => 1,
							"name" => "{$request["campaign"]} (+{$wid[0]})",
							"contacts" => count($distItem["contacts"]),
							"processed" => 0,
							"create_date" => date("Y-m-d H:i:s", time())
						]);

						$msgText = isset($message["text"]) ? $message["text"] : $message["caption"];
					
						foreach($distItem["contacts"] as $contact):
							if(!limitation($subscription["wa_send_limit"], $this->system->countQuota(logged_id, "wa_sent"))):
								if(isset($message["text"]) || isset($message["caption"])):
									$messageContainer = $message;

									$formatMessage = $this->spintax->process($this->lex->parse(footermark($subscription["footermark"], $msgText, system_message_mark), [
										"phone" => $phoneNumber,
										"unsubscribe" => [
											"command" => "STOP",
											"link" => site_url("unsubscribe/" . logged_id . "/{$phoneNumber}", true)
										],
										"custom" => $contact["cols"],
										"date" => [
											"now" => date("F j, Y"),
											"time" => date("h:i A") 
										]
									]));

									if(isset($messageContainer["text"])):
										$messageContainer["text"] = $formatMessage;
									else:
										$messageContainer["caption"] = $formatMessage;
									endif;
								endif;

								$this->system->create("wa_sent", [
									"cid" => $waCampaign,
									"uid" => logged_id,
									"wid" => $distItem["wid"],
									"unique" => $distItem["unique"],
									"phone" => $contact["phone"],
									"message" => json_encode($messageContainer),
									"status" => 1,
									"priority" => 2,
									"api" => 2,
									"create_date" => date("Y-m-d H:i:s", time())
								]);

								$sendCounter++;
							endif;
						endforeach;

						if($this->wa->check($distItem["server"]["url"], $distItem["server"]["port"])):
							$this->wa->send($distItem["server"]["secret"], $distItem["server"]["url"], $distItem["server"]["port"], $distItem["unique"]);
						endif;
					endif;
				endforeach;

				if($sendCounter > 0):
					response(200, ___(__("response_whatsapp_bulkchatssentcount"), [$sendCounter]));
				else:
					response(500, __("response_whatsapp_failedchatsqueuemany"));
				endif;

        		break;
        	case "add.whatsapp.scheduled":
        		if(!isset($request["shortener"], $request["account"], $request["message_type"], $request["schedule"], $request["repeat"], $request["numbers"], $request["groups"], $request["message"]))
        			response(500, __("response_invalid"));

				if(!is_array($request["groups"]))
        			response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["shortener"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["repeat"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["account"]))
					response(500, __("response_invalid"));

				if(!in_array($request["message_type"], ["text", "media", "document"]))
        			response(500, __("response_invalid"));

        		if(in_array($request["message_type"], ["text"])):
					if(!$this->sanitize->length($request["message"], system_message_min))
						response(500, __("response_message_short"));

					if(system_message_max > 0):
						if($this->sanitize->length($request["message"], system_message_max, 2))
							response(500, __("response_message_toolong"));
					endif;

					if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], false)):
						$this->system->create("logger", [
							"uid" => logged_id,
							"type" => "ai_moderation",
							"reason" => "Inappropriate Content!",
							"content" => "Type: WhatsApp Scheduled, Message: {$request["message"]}",
							"create_date" => date("Y-m-d H:i:s", time())
						]);
						
						response(500, __("response_aimoderation_detected"));
					endif;
				endif;

				$subscription = set_subscription(
                    $this->system->checkSubscription(logged_id), 
                    $this->system->getSubscription(false, logged_id), 
                    $this->system->getSubscription(false, false, true)
                );

				if(empty($subscription))
					response(500, __("response_package_nosubwarn"));

                if(limitation($subscription["scheduled_limit"], $this->system->countScheduled(logged_id)))
    				response(500, __("requests_response_subschedmaxreached"));

				if($this->system->checkWaAccount(logged_id, $request["account"], "id") < 1)
					response(500, __("response_invalid"));

				if($request["shortener"] > 0):
					if(!$this->file->exists("system/shorteners/" . md5($request["shortener"]) . ".php"))
						response(500, __("response_went_wrong"));

					$messageLinks = (new VStelmakh\UrlHighlight\UrlHighlight)->getUrls($request["message"]);

					if(!empty($messageLinks)):
						try {
							require "system/shorteners/" . md5($request["shortener"]) . ".php";
						} catch(Exception $e){
							response(500, __("requests_create_encounteredshortenererror"));
						}

						foreach($messageLinks as $key => $value):
							$shortLink = shortenUrl($value, $this);

							if($shortLink):
								$request["message"] = str_replace("{$value}", "{$shortLink}", $request["message"]);
							endif;
						endforeach;
					endif;
				endif;

				$request["message"] = encodeBraces($request["message"]);

				switch($request["message_type"]):
        			case "media":
        				try {
		        			$this->upload->upload($_FILES["media_file"]);
			    			if($this->upload->uploaded):
			    				if(!in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif", "mp4", "mp3", "ogg"]))
			    					response(500, __("response_whatsapp_invalidmediafile"));

			    				$mediaName = logged_hash . "_" . uniqid(logged_hash, true);

			    				$this->upload->mime_check = false;
				                $this->upload->file_new_name_body = $mediaName;
				                $this->upload->file_overwrite = true;
				                $this->upload->process("uploads/whatsapp/scheduled/" . logged_id . "/");

								if($this->upload->processed):
									if(in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif"])):
										// image
				        				$message = [
				        					"image" => [
				        						"url" => site_url("uploads/whatsapp/scheduled/" . logged_id . "/{$mediaName}.{$this->upload->file_src_name_ext}", true)
				        					],
				        					"caption" => $request["message"]
				        				];

										if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], $message["image"]["url"])):
											$this->system->create("logger", [
												"uid" => logged_id,
												"type" => "ai_moderation",
												"reason" => "Inappropriate Content!",
												"content" => empty($request["message"]) ? "Type: WhatsApp Scheduled, Media: <a href=\"{$message["image"]["url"]}\" class=\"btn btn-sm btn-primary\" target=\"_blank\">Open Link</a>" : "Type: WhatsApp Scheduled, Message: {$request["message"]}, Media: <a href=\"{$message["image"]["url"]}\" class=\"btn btn-sm btn-primary\" target=\"_blank\">Open Link</a>",
												"create_date" => date("Y-m-d H:i:s", time())
											]);
											
											response(500, __("response_aimoderation_detected"));
										endif;
									elseif(in_array($this->upload->file_src_name_ext, ["mp3", "ogg"])):
										// audio
										$message = [
				        					"audio" => [
				        						"url" => site_url("uploads/whatsapp/scheduled/" . logged_id . "/{$mediaName}.{$this->upload->file_src_name_ext}", true)
											],
											"caption" => false
				        				];
									else:
										// video
				        				$message = [
				        					"video" => [
				        						"url" => site_url("uploads/whatsapp/scheduled/" . logged_id . "/{$mediaName}.{$this->upload->file_src_name_ext}", true)
				        					],
				        					"caption" => $request["message"]
				        				];
									endif;

									$this->upload->clean();
								else:
									response(500, __("response_whatsapp_invalidmediafile"));
								endif;
							endif;
		        		} catch(Exception $e){
		        			response(500, __("response_whatsapp_invalidmediafile"));
		        		}

        				break;
					case "document":
						try {
							$this->upload->upload($_FILES["doc_file"]);
							if($this->upload->uploaded):
								switch($this->upload->file_src_name_ext):
									case "pdf":
										$docMimetype = "application/pdf";

										break;
									case "xml":
										$docMimetype = "application/xml";
										
										break;
									case "xls":
										$docMimetype = "application/excel";
										
										break;
									case "xlsx":
										$docMimetype = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
										
										break;
									case "doc":
										$docMimetype = "application/msword";
										
										break;
									case "docx":
										$docMimetype = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
										
										break;
									default:
										response(500, __("response_whatsapp_invaldocumenfile"));
								endswitch;

								$fileName = $this->upload->file_src_name;
								$docName = logged_hash . "_" . uniqid(logged_hash, true);

								$this->upload->mime_check = false;
								$this->upload->file_new_name_body = $docName;
								$this->upload->file_overwrite = true;
								$this->upload->process("uploads/whatsapp/scheduled/" . logged_id . "/");

								if($this->upload->processed):
									$message = [
										"document" => [
			        						"url" => site_url("uploads/whatsapp/scheduled/" . logged_id . "/{$docName}.{$this->upload->file_src_name_ext}", true)
			        					],
										"fileName" => $fileName,
										"mimetype" => $docMimetype,
										"caption" => $request["message"]
									];

									$this->upload->clean();
								else:
									response(500, __("response_whatsapp_invaldocumenfile"));
								endif;
							endif;
						} catch(Exception $e){
							response(500, __("response_whatsapp_invaldocumenfile"));
						}

						break;
        			default:
        				$message = [
        					"text" => $request["message"]
        				];
        		endswitch;

				if(!isset($message))
					response(500, __("response_unable_to_process_msg"));

				$account = $this->system->getWaAccount(logged_id, $request["account"], "id");

        		$filtered = [
					"uid" => logged_id,
					"wid" => $account["wid"],
					"unique" => $account["unique"],
					"groups" => implode(",", $request["groups"]),
					"name" => $request["name"],
					"numbers" => $this->sanitize->normalizeContactsCommas($request["numbers"]),
					"message" => json_encode($message),
					"repeat" => $request["repeat"],
					"last_send" => false,
					"send_date" => strtotime($request["schedule"])
				];

				if($this->system->create("wa_scheduled", $filtered)):
					response(200, __("response_whatsapp_chatscheduled"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
        	case "whatsapp.groups":
        		if(!isset($request["account"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->isInt($request["account"]))
					response(500, __("response_invalid"));

        		$subscription = set_subscription(
                    $this->system->checkSubscription(logged_id), 
                    $this->system->getSubscription(false, logged_id), 
                    $this->system->getSubscription(false, false, true)
                );

				if(empty($subscription))
					response(500, __("response_package_nosubwarn"));

				if($this->system->checkWaAccount(logged_id, $request["account"], "id") < 1)
					response(500, __("response_invalid"));

				$account = $this->system->getWaAccount(logged_id, $request["account"], "id");

				if(!$account)
					response(500, __("response_invalid"));

				$waServer = $this->system->getWaServer($account["wsid"], "id");

				if($waServer && !$this->wa->check($waServer["url"], $waServer["port"]))
					response(500, __("response_whatsapp_noconnectserver"));

				$getGroups = $this->wa->get_groups($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"]);

				if($getGroups):
					if(!empty($getGroups)):
						foreach($getGroups as $group):
							if($this->system->checkWaGroup(logged_id, $group["id"]) < 1):
								$this->system->create("wa_groups", [
									"uid" => logged_id,
									"wid" => $account["wid"],
									"unique" => $account["unique"],
									"gid" => $group["id"],
									"name" => $this->sanitize->string($group["subject"]),
									"create_date" => date("Y-m-d H:i:s", time())
								]);
							endif;
						endforeach;

						$this->cache->container("wa.contacts." . logged_hash);
						$this->cache->clear();

						response(200, ___(__("response_whatsapp_successfetchgroups"), [
							count($getGroups)
						]));
					endif;
				endif;

				response(500, __("response_whatsapp_nogroupsfoundfetch"));

        		break;
        	case "add.ussd":
        		if(!isset($request["code"], $request["sim"], $request["device"]))
        			response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["sim"]))
					response(500, __("response_invalid"));

				$subscription = set_subscription(
                    $this->system->checkSubscription(logged_id), 
                    $this->system->getSubscription(false, logged_id), 
                    $this->system->getSubscription(false, false, true)
                );

				if(empty($subscription))
					response(500, __("response_package_nosubwarn"));

				if(limitation($subscription["ussd_limit"], $this->system->countQuota(logged_id, "ussd")))
					response(500, __("response_ussd_maximumreq"));

				if($this->system->checkDevice(logged_id, $request["device"], "did") < 1)
					response(500, __("response_invalid"));

    			$device = $this->system->getDevice(logged_id, $request["device"], "did");

    			if(!$device)
    				response(500, __("response_invalid"));

        		$filtered = [
					"uid" => logged_id,
					"code" => $request["code"],
					"did" => $request["device"],
					"sim" => $request["sim"] < 2 ? 1 : 2,
					"response" => false,
					"status" => 1,
					"create_date" => date("Y-m-d H:i:s", time())
				];

				if($this->system->create("ussd", $filtered)):
					$this->fcm->sendWithToken($device["fcm_token"], [
						"action" => "ussd_request"
					]);

					response(200, __("response_ussd_queued"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
        	case "add.template":
        		if(!isset($request["name"], $request["format"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if(!$this->sanitize->length($request["format"], 5))
					response(500, __("response_format_short"));

				$filtered = [
					"uid" => logged_id,
					"name" => $request["name"],
					"format" => $request["format"]
				];

				if($this->system->create("templates", $filtered)):
					$this->cache->container("messages." . logged_hash);
					$this->cache->clear();

					response(200, __("response_template_added"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
			case "add.ai.key":
				if(!isset($request["name"], $request["provider"], $request["prompt"], $request["post_prompt"], $request["model_openai"], $request["model_geminiai"], $request["model_claudeai"], $request["model_deepseekai"], $request["history"], $request["max_tokens"], $request["transcription"], $request["apikey"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if(!$this->sanitize->length($request["prompt"], 5))
					response(500, __("response_addaikey_promptshort"));

				if(!in_array($request["provider"], ["openai", "geminiai", "claudeai", "grokai", "deepseekai"]))
					response(500, __("response_invalid"));

				$aiModel = false;

				if($request["provider"] == "openai"):
					if(isset($request["model_openai"]) && !in_array($request["model_openai"], ["gpt-5", "gpt-5-pro", "gpt-5-mini", "gpt-5-nano", "gpt-4.1", "gpt-4.1-mini", "gpt-4.1-nano"])):
						response(500, __("response_addaikey_modelinval"));
					endif;

					$aiModel = $request["model_openai"];
				endif;

				if($request["provider"] == "geminiai"):
					if(isset($request["model_geminiai"]) && !in_array($request["model_geminiai"], ["gemini-2.5-pro", "gemini-2.5-flash", "gemini-2.5-flash-lite"])):
						response(500, __("response_addaikey_modelinval"));
					endif;

					$aiModel = $request["model_geminiai"];
				endif;

				if($request["provider"] == "claudeai"):
					if(isset($request["model_claudeai"]) && !in_array($request["model_claudeai"], ["claude-opus-4-1", "claude-opus-4-0", "claude-sonnet-4-5", "claude-sonnet-4-0"])):
						response(500, __("response_addaikey_modelinval"));
					endif;

					$aiModel = $request["model_claudeai"];
				endif;

				if($request["provider"] == "grokai"):
					if(isset($request["model_grokai"]) && !in_array($request["model_grokai"], ["grok-3", "grok-3-mini"])):
						response(500, __("response_addaikey_modelinval"));
					endif;

					$aiModel = $request["model_grokai"];
				endif;

				if($request["provider"] == "deepseekai"):
					if(isset($request["model_deepseekai"]) && !in_array($request["model_deepseekai"], ["deepseek-chat"])):
						response(500, __("response_addaikey_modelinval"));
					endif;

					$aiModel = $request["model_deepseekai"];
				endif;

				if(!$aiModel)
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["history"]))
					response(500, __("response_addaikey_histothres"));

				if(!$this->sanitize->isInt($request["max_tokens"]))
					response(500, __("response_invalid"));

				if(isset($request["vision"])):
					if(!$this->sanitize->isInt($request["vision"])):
						response(500, __("response_invalid"));
					endif;
				else:
					$request["vision"] = 2;
				endif;

				if(!$this->sanitize->isInt($request["transcription"]))
					response(500, __("response_invalid"));

				if($request["history"] < 5)
					response(500, __("response_addaikey_histothresless"));

				if(!$this->sanitize->length($request["apikey"], 5))
					response(500, __("response_addaikey_apikeyshort"));

				$filtered = [
					"uid" => logged_id,
					"name" => $request["name"],
					"prompt" => $request["prompt"],
					"post_prompt" => $request["post_prompt"],
					"model" => $aiModel,
					"history" => $request["history"],
					"max_tokens" => $request["max_tokens"],
					"vision" => $request["vision"] < 2 ? 1 : 2,
					"transcription" => $request["transcription"] < 2 ? 1 : 2,
					"apikey" => $request["apikey"],
					"provider" => $request["provider"]
				];

				if($this->system->create("ai_keys", $filtered)):
					response(200, __("response_addaikey_success"));
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "add.ai.plugin":
				if(!isset($request["name"], $request["endpoint"], $request["content"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if(!empty($request["endpoint"]) && !$this->sanitize->length($request["endpoint"]))
					response(500, __("response_addaiplugin_endpointshort"));

				if(!empty($request["endpoint"]) && !$this->sanitize->isUrl($request["endpoint"]))
					response(500, __("response_addaiplugin_endpointinval"));

				if(!isPluginSchemaJsonValid($request["content"]))
					response(500, __("response_addaiplugin_schemainval"));

				$filtered = [
					"uid" => logged_id,
					"name" => $request["name"],
					"endpoint" => $request["endpoint"],
					"schema" => $request["content"]
				];

				if($this->system->create("ai_plugins", $filtered)):
					response(200, __("response_addaiplugin_success"));
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
        	case "add.contact":
        		if(!isset($request["name"], $request["phone"], $request["groups"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

        		$subscription = set_subscription(
                    $this->system->checkSubscription(logged_id), 
                    $this->system->getSubscription(false, logged_id), 
                    $this->system->getSubscription(false, false, true)
                );

				if(empty($subscription))
					response(500, __("response_package_nosubwarn"));

				if(limitation($subscription["contact_limit"], $this->system->countContacts(logged_id)))
    				response(500, __("response_limitation_contact"));

				try {
					$number = $this->phone->parse($request["phone"], logged_country);

					if (!$number->isValidNumber() && $number->getRegionCode() != "BR")
						response(500, __("response_invalid_number"));

					$request["phone"] = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);
				} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
					response(500, __("response_invalid_number"));
				}

				if(!is_array($request["groups"]))
					response(500, __("response_invalid"));

    			foreach($request["groups"] as $group):
    				if($this->system->checkGroup(logged_id, $group) < 1)
						response(500, __("response_invalid"));
    			endforeach;

				if($this->system->checkNumber(logged_id, $request["phone"]) > 0)
					response(500, __("response_number_exist"));

				$filtered = [
					"uid" => logged_id,
					"groups" => implode(",", $request["groups"]),
					"phone" => $request["phone"],
					"name" => $request["name"]
				];

				if($this->system->create("contacts", $filtered)):
					$this->cache->container("autocomplete.contacts." . logged_hash);
        			$this->cache->clear();
        			$this->cache->container("contacts." . logged_hash);
        			$this->cache->clear();
        			$this->cache->container("user." . logged_hash);
					$this->cache->clear();

					response(200, __("response_contact_added"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
        	case "import.contacts":
        		if(!isset($_FILES["excel"]))
        			response(500, __("response_invalid"));

        		$subscription = set_subscription(
                    $this->system->checkSubscription(logged_id), 
                    $this->system->getSubscription(false, logged_id), 
                    $this->system->getSubscription(false, false, true)
                );

				if(empty($subscription))
					response(500, __("response_package_nosubwarn"));

        		try {
        			$this->upload->upload($_FILES["excel"]);
	    			if($this->upload->uploaded):
	    				if(!in_array($this->upload->file_src_name_ext, ["xlsx"]))
	    					response(500, __("response_invalid_excel"));

	    				$this->upload->mime_check = false;
		                $this->upload->file_new_name_body = logged_hash;
		                $this->upload->file_overwrite = true;
		                $this->upload->process("uploads/sheets/");

						if($this->upload->processed)
							$this->upload->clean();
						else
							response(500, __("response_invalid_excel"));
					endif;
        		} catch(Exception $e){
        			response(500, __("response_invalid_excel"));
        		}

        		try {
        			$reader = $this->sheet->read("uploads/sheets/" . logged_hash . ".xlsx");
        		} catch(Exception $e){
        			response(500, __("response_invalid_excel"));
        		}

        		$countIndex = 0;

        		foreach($reader->getSheetIterator() as $sheet):
				    if($sheet->getIndex() === 0):
				    	$headers = [];
				    	$rowIndex = 0;

				        foreach($sheet->getRowIterator() as $row):
			        		if($rowIndex < 1):
			        			if(!in_array("phone", $row->toArray()))
			        				response(500, ___(__("response_importcontacts_phonenotfound"), ["phone"]));

			        			if(!in_array("groups", $row->toArray()))
			        				response(500, ___(__("response_importcontacts_groupsnotfound"), ["groups"]));

			        			if(!in_array("name", $row->toArray()))
			        				response(500, ___(__("response_importcontacts_namenotfound"), ["name"]));

					        	foreach($row->toArray() as $key => $value):
					        		$headers[$value] = $key;
					        	endforeach;
					        else:
					        	$singleHeader = [];
					        	$cols = $row->toArray();

					        	foreach($headers as $key => $value):
					        		if(!in_array($key, ["phone", "groups", "name"])):
						        		$singleHeader[$key] = isset($cols[$value]) ? $cols[$value] : '';
						        	endif;
					        	endforeach;

					        	$phone = isset($cols[$headers["phone"]]) ? $cols[$headers["phone"]] : '';
					        	$groups = isset($cols[$headers["groups"]]) ? $cols[$headers["groups"]] : '';

								$name = isset($cols[$headers["name"]]) ? $cols[$headers["name"]] : 
									(isset($cols[$headers["phone"]]) ? $cols[$headers["phone"]] : '');

				        		$rejected = false;				        		

				        		try {
								    $number = $this->phone->parse($phone, logged_country);

			    					$number->format(Brick\PhoneNumber\PhoneNumberFormat::INTERNATIONAL);

								    if(!$number->isValidNumber())
										$rejected = true;

									if(!is_messagable_number($number))
										$rejected = true;

									$phoneNumber = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);
								} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
									$rejected = true;
								}

								if(empty($groups))
									$rejected = true;

								$groupsArray = explode(",", $groups);

								if(!empty($groupsArray)):
									foreach($groupsArray as $group):
										if($this->system->checkGroup(logged_id, $group) < 1)
											$rejected = true;
									endforeach;
								endif;

								if(!$rejected):
									if(limitation($subscription["contact_limit"], $this->system->countContacts(logged_id))):
						        		$this->cache->container("user." . logged_hash);
										$this->cache->clear();

						        		response(500, ___(__("response_limitation_contact2"), [
						        			$countIndex
						        		]));
						        	endif;

						        	if($this->system->checkNumber(logged_id, $phoneNumber) < 1):
										$filtered = [
						        			"uid" => logged_id,
						        			"groups" => $groups,
						        			"phone" => $phoneNumber,
						        			"name" => $name
						        		];

						        		$this->system->create("contacts", $filtered);

						        		$countIndex++;
					        		endif;
					        	endif;
					        endif;

					        $rowIndex++;
				        endforeach;
				    endif;	 
				endforeach;

				$this->cache->container("autocomplete.contacts." . logged_hash);
    			$this->cache->clear();
    			$this->cache->container("contacts." . logged_hash);
    			$this->cache->clear();
    			$this->cache->container("user." . logged_hash);
				$this->cache->clear();

				response(200, ___(__("response_contacts_imported2"), [
        			$countIndex
        		]));

        		break;
        	case "add.group":
        		if(!isset($request["name"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				$filtered = [
					"uid" => logged_id,
					"name" => $request["name"]
				];

				if($this->system->create("groups", $filtered)):
					$this->cache->container("contacts." . logged_hash);
					$this->cache->clear();
					$this->cache->container("groups." . logged_hash);
					$this->cache->clear();

					response(200, __("response_group_added"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
        	case "add.apikey":
        		if(!isset($request["name"], $request["permissions"]))
        			response(500, __("response_invalid"));

        		$subscription = set_subscription(
                    $this->system->checkSubscription(logged_id), 
                    $this->system->getSubscription(false, logged_id), 
                    $this->system->getSubscription(false, false, true)
                );

				if(empty($subscription))
					response(500, __("response_package_nosubwarn"));

				if(limitation($subscription["key_limit"], $this->system->countKeys(logged_id)))
    				response(500, __("response_limitation_key"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

        		if(!is_array($request["permissions"]))
        			response(500, __("response_invalid"));

        		if(empty($request["permissions"]))
        			response(500, __("response_permission_min"));

        		foreach($request["permissions"] as $permission):
        			if(!in_array($permission, [
        				"otp",
        				"sms_send",
        				"sms_send_bulk",
        				"wa_send",
        				"wa_send_bulk",
        				"ussd",
						"validate_wa_phone",
        				"get_credits",
        				"get_earnings",
        				"get_subscription",
        				"get_sms_pending",
        				"get_wa_pending",
        				"get_sms_received",
        				"get_wa_received",
        				"get_sms_sent",
        				"get_sms_campaigns",
        				"get_wa_sent",
        				"get_wa_campaigns",
        				"get_contacts",
        				"get_groups",
        				"get_ussd",
        				"get_wa_accounts",
						"get_wa_groups",
        				"get_devices",
        				"get_rates",
        				"get_shorteners",
        				"get_unsubscribed",
        				"create_whatsapp",
        				"create_contact",
        				"create_group",
        				"start_sms_campaign",
        				"stop_sms_campaign",
        				"start_wa_campaign",
        				"stop_wa_campaign",
        				"delete_contact",
        				"delete_group",
        				"delete_sms_sent",
        				"delete_sms_campaign",
						"delete_wa_account",
        				"delete_wa_sent",
        				"delete_wa_campaign",
        				"delete_sms_received",
        				"delete_wa_received",
        				"delete_ussd",
        				"delete_unsubscribed"
        			])):
        				response(500, __("response_invalid"));
        			endif;
        		endforeach;

				$filtered = [
					"uid" => logged_id,
					"secret" => sha1(uniqid(time() . logged_hash, true)),
					"name" => $request["name"],
					"permissions" => implode(",", $request["permissions"])
				];

				if($this->system->create("keys", $filtered)):
					$this->cache->container("user." . logged_hash);
					$this->cache->clear();

					response(200, __("response_key_added"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
        	case "add.webhook":
        		if(!isset($request["name"], $request["events"], $request["url"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if(!is_array($request["events"]))
        			response(500, __("response_invalid"));

        		foreach($request["events"] as $event):
        			if(!in_array($event, ["sms", "whatsapp", "ussd"]))
        				response(500, __("response_invalid"));
        		endforeach;

        		if(!$this->sanitize->isUrl($request["url"]))
        			response(500, __("response_invalid_webhookurl"));

        		$subscription = set_subscription(
                    $this->system->checkSubscription(logged_id), 
                    $this->system->getSubscription(false, logged_id), 
                    $this->system->getSubscription(false, false, true)
                );

				if(empty($subscription))
					response(500, __("response_package_nosubwarn"));

        		if(limitation($subscription["webhook_limit"], $this->system->countWebhooks(logged_id)))
    				response(500, __("response_limitation_webhook"));

				$filtered = [
					"uid" => logged_id,
					"secret" => sha1(uniqid(time() . logged_id, true)),
					"name" => $request["name"],
					"url" => $this->sanitize->url($request["url"]),
					"events" => implode(",", $request["events"])
				];

				if($this->system->create("webhooks", $filtered)):
					response(200, __("response_webhook_added2"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
        	case "add.hook":
        		if(!isset($request["name"], $request["source"], $request["event"], $request["link"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

        		if(!$this->sanitize->isInt($request["source"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->isInt($request["event"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->isUrl($request["link"]))
        			response(500, __("response_invalid_linkstructure"));

        		$subscription = set_subscription(
                    $this->system->checkSubscription(logged_id), 
                    $this->system->getSubscription(false, logged_id), 
                    $this->system->getSubscription(false, false, true)
                );

                if(empty($subscription))
					response(500, __("response_package_nosubwarn"));

                if(limitation($subscription["action_limit"], $this->system->countActions(logged_id)))
    				response(500, __("requests_response_subactionsmaxreached"));

				$filtered = [
					"uid" => logged_id,
					"type" => 1,
					"name" => $request["name"],
					"source" => $request["source"] < 2 ? 1 : 2,
					"event" => $request["event"] < 2 ? 1 : 2,
					"link" => $request["link"],
					"message" => false,
					"keywords" => false,
					"sim" => 0,
					"device" => false,
					"priority" => 0,
					"match" => 0,
					"account" => false,
					"ai_key" => 0,
					"ai_plugins" => false, 
					"group_trigger" => 2
				];

				if($this->system->create("actions", $filtered)):
					response(200, __("response_hook_added2"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
			case "add.autoreply":
				if(!isset($request["name"], $request["source"], $request["match"], $request["priority"], $request["keywords"], $request["message"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if(!$this->sanitize->isInt($request["source"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["match"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["priority"]))
					response(500, __("response_invalid"));

				if($request["match"] < 5):
					if(empty($request["keywords"])):
						response(500, __("require_action_keywords"));
					endif;

					if(!$this->sanitize->length($request["keywords"], 1)):
						response(500, __("response_invalid_keywords"));
					endif;
				endif;

				if($request["match"] < 6):
					if(empty($request["message"])):
						response(500, __("response_message_short"));
					endif;

					if(!$this->sanitize->length($request["message"])):
						response(500, __("response_message_short"));
					endif;
				endif;

				if($request["match"] > 5):
					if(!isset($request["ai_key"]) && empty($request["ai_key"])):
						response(500, __("response_aiautoreply_mustselaikey"));
					endif;

					if($this->system->checkAiKey(logged_id, $request["ai_key"]) < 1):
						response(500, __("response_invalid"));
					endif;

					if(isset($request["ai_plugins"]) && !empty($request["ai_plugins"])):
						foreach($request["ai_plugins"] as $plugin):
							if(!str_starts_with($plugin, "ai-")):
								if($this->system->checkAiPlugin(logged_id, $plugin) < 1):
									response(500, __("response_invalid"));
								endif;
							endif;
						endforeach;
					endif;
				endif;

				if(!isset($request["ai_key"])):
					$request["ai_key"] = 0;
				endif;

				if(!isset($request["ai_plugins"])):
					$request["ai_plugins"] = [];
				endif;

				if(isset($request["group_trigger"])):
					if(!$this->sanitize->isInt($request["group_trigger"])):
						response(500, __("response_invalid"));
					endif;
				else:
					$request["group_trigger"] = 2;
				endif;

				if($request["source"] < 2):
					// sms

					if(!isset($request["device"], $request["sim"]))
						response(500, __("response_invalid"));

					if(!$this->sanitize->isInt($request["sim"]))
						response(500, __("response_invalid"));

					if($this->system->checkDevice(logged_id, $request["device"], "did") < 1)
						response(500, __("response_invalid"));

					$filtered = [
						"uid" => logged_id,
						"type" => 2,
						"source" => 1,
						"match" => $request["match"],
						"ai_key" => $request["ai_key"],
						"ai_plugins" => implode(",", $request["ai_plugins"]),
						"group_trigger" => 2,
						"sim" => $request["sim"] < 2 ? 1 : 2,
						"device" => $request["device"],
						"account" => false,
						"name" => $request["name"],
						"event" => 0,
						"priority" => 2,
						"keywords" => $this->sanitize->cleanAutoreplyKeywordCommas($request["keywords"]),
						"message" => $request["message"],
						"link" => false
					];
				else:
					// whatsapp

					if(!isset($request["message_type"], $request["account"]))
						response(500, __("response_invalid"));

					if(!in_array($request["message_type"], ["text", "media", "document"]))
						response(500, __("response_invalid"));

					if($this->system->checkWaAccount(logged_id, $request["account"], "id") < 1)
						response(500, __("response_invalid"));

					$this->file->mkdir("uploads/whatsapp/actions/{$request["account"]}");

					switch($request["message_type"]):
						case "media":
							try {
								$this->upload->upload($_FILES["media_file"]);
								if($this->upload->uploaded):
									if(!in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif", "mp4", "mp3", "ogg"]))
										response(500, __("response_whatsapp_invalidmediafile"));
	
									$mediaName = logged_hash . "_" . uniqid(logged_hash, true);
	
									$this->upload->mime_check = false;
									$this->upload->file_new_name_body = $mediaName;
									$this->upload->file_overwrite = true;
									$this->upload->process("uploads/whatsapp/actions/{$request["account"]}");
	
									if($this->upload->processed):
										if(in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif"])):
											// image
											$message = [
												"image" => [
													"url" => site_url("uploads/whatsapp/actions/{$request["account"]}/{$mediaName}.{$this->upload->file_src_name_ext}", true)
												],
												"caption" => $request["message"]
											];
										elseif(in_array($this->upload->file_src_name_ext, ["mp3", "ogg"])):
											// audio
											$message = [
												"audio" => [
													"url" => site_url("uploads/whatsapp/actions/{$request["account"]}/{$mediaName}.{$this->upload->file_src_name_ext}", true)
												],
												"caption" => false
											];
										else:
											// video
											$message = [
												"video" => [
													"url" => site_url("uploads/whatsapp/actions/{$request["account"]}/{$mediaName}.{$this->upload->file_src_name_ext}", true)
												],
												"caption" => $request["message"]
											];
										endif;
	
										$this->upload->clean();
									else:
										response(500, __("response_whatsapp_invalidmediafile"));
									endif;
								else:
									response(500, __("response_select_newmed_file_38"));
								endif;
							} catch(Exception $e){
								response(500, __("response_whatsapp_invalidmediafile"));
							}
	
							break;
						case "document":
							try {
								$this->upload->upload($_FILES["doc_file"]);
								if($this->upload->uploaded):
									switch($this->upload->file_src_name_ext):
										case "pdf":
											$docMimetype = "application/pdf";
	
											break;
										case "xls":
											$docMimetype = "application/excel";
											
											break;
										case "xlsx":
											$docMimetype = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
											
											break;
										case "doc":
											$docMimetype = "application/msword";
											
											break;
										case "docx":
											$docMimetype = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
											
											break;
										default:
											response(500, __("response_whatsapp_invaldocumenfile"));
									endswitch;
									
									$fileName = $this->upload->file_src_name;
									$docName = logged_hash . "_" . uniqid(logged_hash, true);
	
									$this->upload->mime_check = false;
									$this->upload->file_new_name_body = $docName;
									$this->upload->file_overwrite = true;
									$this->upload->process("uploads/whatsapp/actions/{$request["account"]}");
	
									if($this->upload->processed):
										$message = [
											"document" => [
												"url" => site_url("uploads/whatsapp/actions/{$request["account"]}/{$docName}.{$this->upload->file_src_name_ext}", true)
											],
											"fileName" => $fileName,
											"mimetype" => $docMimetype,
											"caption" => $request["message"]
										];
	
										$this->upload->clean();
									else:
										response(500, __("response_whatsapp_invaldocumenfile"));
									endif;
								else:
									response(500, __("response_select_newdoc_file_38"));
								endif;
							} catch(Exception $e){
								response(500, __("response_whatsapp_invaldocumenfile"));
							}
	
							break;
						default:
							$message = [
								"text" => $request["message"]
							];
					endswitch;

					$message["autoreply"] = true;
					$message["message_type"] = $request["message_type"];

					$filtered = [
						"uid" => logged_id,
						"type" => 2,
						"source" => 2,
						"match" => $request["match"],
						"ai_key" => $request["ai_key"],
						"ai_plugins" => implode(",", $request["ai_plugins"]),
						"group_trigger" => $request["group_trigger"] < 2 ? 1 : 2,
						"sim" => 0,
						"device" => false,
						"account" => $request["account"],
						"name" => $request["name"],
						"event" => 0,
						"priority" => $request["priority"] < 2 ? 1 : 2,
						"keywords" => $this->sanitize->cleanAutoreplyKeywordCommas($request["keywords"]),
						"message" => json_encode($message),
						"link" => false
					];
				endif;

				if($this->system->create("actions", $filtered)):
					response(200, __("response_autoreply_added_2025"));
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
        	case "add.user":
        		if(!permission("manage_users"))
					response(500, __("response_no_permission"));

	            if(!isset($request["name"], $request["email"], $request["password"], $request["theme_color"], $request["timezone"], $request["clock_format"], $request["date_format"], $request["date_separator"], $request["country"], $request["alertsound"], $request["role"], $request["language"], $request["credits"], $request["partner"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

	            if(!$this->sanitize->isEmail($request["email"]))
	            	response(500, __("response_invalid_email"));

	            if(!$this->sanitize->length($request["password"], 5))
	            	response(500, __("response_password_short"));

				if(!in_array($request["theme_color"], ["light", "dark"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->isInt($request["role"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->isInt($request["language"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->isInt($request["alertsound"]))
	            	response(500, __("response_invalid"));

	            if(!empty($request["credits"])):
		            if(!$this->sanitize->isNumeric($request["credits"]) && $request["credits"] < 0):
		            	response(500, __("response_invalid"));
		            endif;
		        else:
		        	$request["credits"] = 0;
		        endif;

	            if(!$this->sanitize->isInt($request["partner"]))
	            	response(500, __("response_invalid"));

	            if($this->system->checkRole($request["role"]) < 1)
	            	response(500, __("response_invalid"));

	            if($this->system->checkLanguage($request["language"]) < 1)
	            	response(500, __("response_invalid"));

	            if(!in_array($request["timezone"], $this->timezones->generate()))
	            	response(500, __("response_invalid"));

				if(!in_array($request["clock_format"], [1, 2]))
	            	response(500, __("response_invalid"));

	            if(!in_array($request["date_format"], [1, 2, 3, 4]))
	            	response(500, __("response_invalid"));

	            if(!in_array($request["date_separator"], [1, 2, 3, 4]))
	            	response(500, __("response_invalid"));

	            if(!array_key_exists($request["country"], \CountryCodes::get("alpha2", "country")))
	            	response(500, __("response_invalid"));

				$date_separator = [
					1 => "-",
					2 => "/",
					3 => ".",
					4 => " "
				];

				$date_format = [
					1 => "n{$date_separator[$request["date_separator"]]}j{$date_separator[$request["date_separator"]]}Y",
					2 => "j{$date_separator[$request["date_separator"]]}n{$date_separator[$request["date_separator"]]}Y",
					3 => "Y{$date_separator[$request["date_separator"]]}n{$date_separator[$request["date_separator"]]}j",
					4 => "Y{$date_separator[$request["date_separator"]]}j{$date_separator[$request["date_separator"]]}n"
				];

            	$filtered = [
            		"country" => $request["country"],
            		"role" => $request["role"],
            		"name" => $request["name"],
            		"language" => $request["language"],
					"theme_color" => $request["theme_color"],
            		"email" => $this->sanitize->email($request["email"]),
            		"credits" => $request["credits"],
            		"earnings" => 0,
            		"suspended" => 0,
            		"providers" => false,
            		"timezone" => false,
            		"country" => $request["country"],
            		"timezone" => strtolower($request["timezone"]),
            		"formatting" => json_encode([
        				"clock" => $request["clock_format"] < 2 ? "g:i A" : "H:i",
        				"date" => $date_format[$request["date_format"]],
        				"container" => [
        					"clock_format" => (int) $request["clock_format"],
        					"date_format" => (int) $request["date_format"],
        					"date_separator" => (int) $request["date_separator"],
        					"separator_selected" => $date_separator[$request["date_separator"]]
        				]
        			]),
            		"alertsound" => $request["alertsound"] < 2 ? 1 : 2,
            		"credits" => $request["credits"],
            		"partner" => $request["partner"] < 2 ? 1 : 2,
            		"confirmed" => 1,
            		"password" => password_hash($request["password"], PASSWORD_DEFAULT)
            	];

            	if($this->system->checkEmail($filtered["email"]) < 1):
            		$create = $this->system->create("users", $filtered);

            		if($create):
            			$this->cache->container("system.users");
        				$this->cache->clear();

            			response(200, __("response_user_added"));
            		else:
            			response(500, __("response_went_wrong"));
            		endif;
            	else:
            		response(500, __("response_email_unavailable"));
            	endif;

				break;
			case "add.role":
				if(!permission("manage_roles"))
					response(500, __("response_no_permission"));

        		if(!isset($request["name"], $request["permissions"]))
        			response(500, __("response_invalid"));

        		if(!is_array($request["permissions"]))
        			response(500, __("response_invalid"));

        		if(empty($request["permissions"]))
        			response(500, __("response_permission_min"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

        		foreach($request["permissions"] as $permission):
        			if(!in_array($permission, permission_list())):
        				response(500, __("response_invalid"));
        			endif;
        		endforeach;

				$filtered = [
					"name" => $request["name"],
					"permissions" => implode(",", $request["permissions"])
				];

				if($this->system->create("roles", $filtered)):
					$this->cache->container("system.roles");
					$this->cache->clear();
					
					response(200, __("role_added"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
			case "add.package":
				if(!permission("manage_packages"))
					response(500, __("response_no_permission"));

				$columns = [
					"send_limit",
					"receive_limit",
					"ussd_limit",
					"contact_limit",
					"device_limit",
					"key_limit",
					"webhook_limit",
					"action_limit",
					"scheduled_limit",
					"wa_send_limit",
					"wa_receive_limit",
					"wa_account_limit",
					"name",
					"price",
					"footermark",
					"hidden",
					"services"
				];

				foreach($columns as $column):
					if(!isset($request[$column])):
						response(500, __("response_invalid"));
					endif;

					if(in_array($column, ["services"]) && !is_array($request[$column])):
						response(500, __("response_invalid"));
					endif;

					if(!in_array($column, ["name", "services"])):
						if(!$this->sanitize->isInt($request[$column])):
							response(500, __("requests_packageform_invalidint"));
						endif;
					endif;
				endforeach;

	            if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if($request["price"] < 1)
					response(500, __("response_package_pricenotlessone"));

				$request["footermark"] = $request["footermark"] < 2 ? 1 : 2;
				$request["hidden"] = $request["hidden"] < 2 ? 1 : 2;
				$request["services"] = empty($request["services"]) ? false : implode(",", $request["services"]);

				$filtered = [];

            	foreach($columns as $column):
            		$filtered[$column] = $request[$column];
            	endforeach;

            	if($this->system->create("packages", $filtered)):
            		foreach($this->system->getUsers() as $user):
    					$this->cache->container("user.{$user["hash"]}");
						$this->cache->clear();
        			endforeach;

            		$this->cache->container("system.packages");
            		$this->cache->clear();

        			response(200, __("response_package_added"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "add.voucher":
				if(!permission("manage_vouchers"))
					response(500, __("response_no_permission"));

	            if(!isset($request["name"], $request["count"], $request["duration"], $request["package"]))
	            	response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["count"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["duration"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["package"]))
					response(500, __("response_invalid"));

				if($request["count"] < 1 || $request["count"] > 1000)
					response(500, __("response_invalid"));

				if($request["duration"] < 1)
					response(500, __("response_invalid"));

				if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if($request["package"] < 2)
                    response(500, __("response_invalid"));
                
				if($this->system->checkPackage($request["package"]) < 1)
	            	response(500, __("response_invalid"));

	            if($request["count"] < 2):
	            	$filtered = [
	            		"code" => md5(uniqid(time(), true)),
	            		"name" => $request["name"],
	            		"package" => $request["package"],
	            		"duration" => $request["duration"]
	            	];

	            	if($this->system->create("vouchers", $filtered)):
	        			response(200, __("voucher_added"));
	        		else:
	        			response(500, __("response_went_wrong"));
	        		endif;
	            else:
	            	for($i = 1; $i <= $request["count"]; $i++):
	            		$filtered = [
		            		"code" => md5(uniqid(time() . "_{$i}", true)),
		            		"name" => $request["name"] . " #{$i}",
		            		"package" => $request["package"],
		            		"duration" => $request["duration"]
		            	];

		            	$this->system->create("vouchers", $filtered);
	            	endfor;

					response(200, __("voucher_added"));
	            endif;

				break;
			case "add.subscription":
				if(!permission("manage_subscriptions"))
					response(500, __("response_no_permission"));
			
				if(!isset($request["user"], $request["package"], $request["duration"]))
					response(500, __("response_invalid"));
			
				if(!$this->sanitize->isInt($request["user"]) || !$this->sanitize->isInt($request["package"]) || !$this->sanitize->isInt($request["duration"]))
					response(500, __("response_invalid"));
			
				if($this->system->checkUser($request["user"]) < 1 || $this->system->checkPackage($request["package"]) < 1)
					response(500, __("response_invalid"));
			
				$package = $this->system->getPackage($request["package"]);
				$txn = md5(uniqid(time(), true));
			
				$transaction = $this->system->create("transactions", [
					"uid" => $request["user"],
					"pid" => $request["package"],
					"type" => 1,
					"price" => $package["price"],
					"currency" => system_currency,
					"duration" => $request["duration"],
					"provider" => "manual",
					"txn" => "manual-{$txn}"
				]);
			
				$existing = $this->system->getSubscription(false, $request["user"]);
				$now = time();
			
				if ($existing && strtotime($existing["sub_date"]) > $now):
					$startFrom = strtotime($existing["sub_date"]);
				else:
					$startFrom = $now;
				endif;
			
				$newExpiry = date("Y-m-d H:i:s", strtotime("+{$request["duration"]} months", $startFrom));
			
				$filtered = [
					"uid" => $request["user"],
					"pid" => $request["package"],
					"tid" => $transaction,
					"date" => $newExpiry
				];
			
				$this->system->delete($filtered["uid"], false, "subscriptions");
			
				if($this->system->create("subscriptions", $filtered)):
					$this->cache->container("system.packages"); $this->cache->clear();
					$this->cache->container("system.transactions"); $this->cache->clear();
					$this->cache->container("system.users"); $this->cache->clear();
			
					response(200, __("response_subscription_addedsuccess"));
				else:
					response(500, __("response_went_wrong"));
				endif;
			
				break;				
			case "add.page":
				if(!permission("manage_pages"))
					response(500, __("response_no_permission"));

	            if(!isset($request["name"], $request["roles"], $request["logged"], $request["content"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

	            if(!is_array($request["roles"]))
	            	response(500, __("response_invalid"));

	            if(empty($request["roles"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->isInt($request["logged"]))
					response(500, __("response_invalid"));

				if(!in_array($request["logged"], [1, 2]))
					response(500, __("response_invalid"));

            	$filtered = [
					"slug" => (new Cocur\Slugify\Slugify())->slugify($request["name"]),
            		"logged" => $request["logged"],
            		"name" => $request["name"],
            		"roles" => implode(",", $request["roles"]),
            		"content" => $this->sanitize->htmlEncode($request["content"])
            	];

            	if($this->system->create("pages", $filtered)):
            		$this->cache->container("system.pages");
            		$this->cache->clear();

        			response(200, __("response_page_added"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "add.push":
				if(!permission("manage_marketing"))
					response(500, __("response_no_permission"));

	            if(!isset($request["title"], $request["message"], $request["users"], $request["roles"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["title"]))
					response(500, __("response_push_titletooshort"));

				if(!$this->sanitize->length($request["message"]))
					response(500, __("response_push_mesgtooshort"));

	            if(!is_array($request["users"]))
	            	response(500, __("response_invalid"));

	            if(!is_array($request["roles"]))
	            	response(500, __("response_invalid"));

	            if(in_array("0", $request["users"]) && in_array("0", $request["roles"]))
	            	response(500, __("response_push_selectatleastoneuser"));

	            $image = uniqid(time() . "push", true);

				if(isset($_FILES["image"])):
        			$this->upload->upload($_FILES["image"]);
        			if($this->upload->uploaded):
        				$this->upload->allowed = [
        					"image/*"
        				];

		                $this->upload->file_new_name_body = $image;
		                $this->upload->image_convert = "png";
		                $this->upload->file_overwrite = true;
		                $this->upload->process("uploads/temporary/");

						if($this->upload->processed)
							$this->upload->clean();
						else
							response(500, __("response_push_imgfailed"));
					endif;
	            endif;

	            $users = [];

	            if(!empty($request["users"]) && !in_array("0", $request["users"])):
		            foreach($request["users"] as $user):
	        			$users[] = $user;
	        		endforeach;
	        	endif;

	            if(!empty($request["roles"]) && !in_array("0", $request["roles"])):
		            foreach($request["roles"] as $role):
	        			$roleUsers = $this->system->getUsersByRole($role);

	        			if(!empty($roleUsers)):
		        			foreach($roleUsers as $user):
		        				$users[] = $user["id"];
		        			endforeach;
		        		endif;
	        		endforeach;
	        	endif;

	        	if(!empty($users)):
	            	$filtered = [
	            		"type" => 1,
	            		"users" => implode(",", $request["users"]),
	            		"roles" => implode(",", $request["roles"]),
	            		"title" => $request["title"],
	            		"content" => $request["message"],
	            		"image" => $this->file->exists("uploads/temporary/{$image}.png") ? $image : false
	            	];

	            	if($this->system->create("marketing", $filtered)):
		        		foreach($users as $user):
							$userDevices = $this->system->getDevices($user);
							
							foreach($userDevices as $device):
								if($this->file->exists("uploads/temporary/{$image}.png")):
									$this->fcm->sendWithNotification($device["fcm_token"], $filtered["title"], $filtered["content"], site_url("uploads/temporary/{$image}.png", true));
								else:
									$this->fcm->sendWithNotification($device["fcm_token"], $filtered["title"], $filtered["content"]);
								endif;	
							endforeach;
						endforeach;
	        		else:
	        			response(500, __("response_went_wrong"));
	        		endif;

        			response(200, __("response_push_created"));
	        	else:
	        		response(500, __("response_push_nousers"));
	        	endif;

				break;
			case "add.notify":
				if(!permission("manage_marketing"))
					response(500, __("response_no_permission"));

	            if(!isset($request["title"], $request["message"], $request["color"], $request["users"], $request["roles"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["title"]))
					response(500, __("response_notify_titletooshort"));

				if(!$this->sanitize->length($request["message"]))
					response(500, __("response_notify_mesgtooshort"));

				if(!in_array($request["color"], [1, 2, 3, 4]))
					response(500, __("response_invalid"));

	            if(!is_array($request["users"]))
	            	response(500, __("response_invalid"));

	            if(!is_array($request["roles"]))
	            	response(500, __("response_invalid"));

	            if(in_array("0", $request["users"]) && in_array("0", $request["roles"]))
	            	response(500, __("response_notify_selectuserrole"));

	            $image = uniqid(time() . "notify", true);

	            if(isset($_FILES["image"])):
        			$this->upload->upload($_FILES["image"]);
        			if($this->upload->uploaded):
        				$this->upload->allowed = [
        					"image/*"
        				];	

		                $this->upload->file_new_name_body = $image;
		                $this->upload->image_convert = "png";
		                $this->upload->file_overwrite = true;
		                $this->upload->process("uploads/temporary/");

						if($this->upload->processed)
							$this->upload->clean();
						else
							response(500, __("response_notify_imgfailed"));
					endif;
	            endif;

	            $users = [];

	            if(!empty($request["users"]) && !in_array("0", $request["users"])):
		            foreach($request["users"] as $user):
	        			$users[] = $user;
	        		endforeach;
	        	endif;

	            if(!empty($request["roles"]) && !in_array("0", $request["roles"])):
		            foreach($request["roles"] as $role):
	        			$roleUsers = $this->system->getUsersByRole($role);

	        			if(!empty($roleUsers)):
		        			foreach($roleUsers as $user):
		        				$users[] = $user["id"];
		        			endforeach;
		        		endif;
	        		endforeach;
	        	endif;

	        	if(!empty($users)):
	            	$filtered = [
	            		"type" => 2,
	            		// Stored exactly like every other marketing type: the
	            		// users picked by hand, with roles kept separately. The
	            		// broadcast fetch resolves role membership at read time,
	            		// so this column stays comparable across types and
	            		// cannot grow with the size of a role.
	            		"users" => implode(",", $request["users"]),
	            		"roles" => implode(",", $request["roles"]),
	            		"title" => $request["title"],
	            		"content" => $request["message"],
	            		"image" => $this->file->exists("uploads/temporary/{$image}.png") ? $image : false
	            	];

	            	if($marketing = $this->system->create("marketing", $filtered)):
		        		// Only the row id and the colour go over the wire. Every
		        		// browser receives this, so it must carry nothing that
		        		// identifies the recipients or reveals the message; each
		        		// one then fetches the row over its own session.
						$this->echo->trigger("broadcast", "ping", [
							"id" => $marketing,
							"color" => $request["color"]
						]);

		        		response(200, __("response_notify_created"));
	        		else:
	        			response(500, __("response_went_wrong"));
	        		endif;
        		else:
	        		response(500, __("response_notify_nousers"));
	        	endif;

				break;
			case "add.mailer":
				if(!permission("manage_marketing"))
					response(500, __("response_no_permission"));

	            if(!isset($request["title"], $request["content"], $request["users"], $request["roles"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["title"]))
					response(500, __("response_mailer_titletooshort"));

				if(!$this->sanitize->length($request["content"]))
					response(500, __("response_mailer_contentooshort"));

	            if(!is_array($request["users"]))
	            	response(500, __("response_invalid"));

	            if(!is_array($request["roles"]))
	            	response(500, __("response_invalid"));

	            if(in_array("0", $request["users"]) && in_array("0", $request["roles"]))
	            	response(500, __("response_mailer_nouserorrole"));

	            $users = [];

	            if(!empty($request["users"]) && !in_array("0", $request["users"])):
		            foreach($request["users"] as $user):
	        			$users[] = $this->system->getUser($user);
	        		endforeach;
	        	endif;

	            if(!empty($request["roles"]) && !in_array("0", $request["roles"])):
		            foreach($request["roles"] as $role):
	        			$roleUsers = $this->system->getUsersByRole($role);

	        			if(!empty($roleUsers)):
		        			foreach($roleUsers as $user):
		        				$users[] = $this->system->getUser($user["id"]);
		        			endforeach;
		        		endif;
	        		endforeach;
	        	endif;

	        	if(!empty($users)):
	            	$filtered = [
	            		"type" => 3,
	            		"users" => implode(",", $request["users"]),
	            		"roles" => implode(",", $request["roles"]),
	            		"title" => $request["title"],
	            		"content" => $request["content"],
	            		"image" => false
	            	];

	            	if($this->system->create("marketing", $filtered)):
			        	$sendIndex = 1;
			        	$sendTotal = count($users);

			        	foreach($users as $user):
							if($this->mail->send([
								"title" => system_site_name,
								"data" => [
									"subject" => mail_title($request["title"])
								]
							], $user["email"], "string: {$request["content"]}", $this->smarty)):
								$this->echo->trigger("private-user-" . logged_hash, "notification", [
									"status" => 1,
									"content" => ___(__("response_mailer_sendcounter"), [$sendIndex, $sendTotal])
								]);
							endif;

							$sendIndex++;
			        	endforeach;
	        		else:
	        			response(500, __("response_went_wrong"));
	        		endif;

    				response(200, __("response_mailer_mailprocessed"));
		        else:
		        	response(500, __("response_mailer_nousersorrole"));
		        endif;

				break;
			case "add.language":
				if(!permission("manage_languages"))
					response(500, __("response_no_permission"));

	            if(!isset($request["name"], $request["iso"], $request["rtl"], $request["translations"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

	            if(!array_key_exists($request["iso"], \CountryCodes::get("alpha2", "country")))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->isInt($request["rtl"]))
	            	response(500, __("response_invalid"));

	            if(!in_array($request["rtl"], [1, 2]))
	            	response(500, __("response_invalid"));

            	$filtered = [
            		"name" => $request["name"],
            		"rtl" => $request["rtl"],
            		"iso" => strtoupper($request["iso"]),
            		"order" => 0
            	];

            	$create = $this->system->create("languages", $filtered);

            	if($create):
            		$this->cache->container("system.languages");
            		$this->cache->clear();

            		if($this->file->put("system/languages/" . md5($create) . ".lang", $request["translations"]) === false)
            			response(500, __("response_went_wrong"));

        			response(301, __("response_language_added"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "add.waserver":
				if(!permission("manage_waservers"))
					response(500, __("response_no_permission"));

				if(!isset($request["name"], $request["accounts"], $request["packages"], $request["url"], $request["port"], $request["secret"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["accounts"]))
					response(500, __("response_invalid"));

				if(!is_array($request["packages"]))
					response(500, __("requests_waserver_selectatleastonepack"));

				$request["url"] = rtrim($request["url"], "/");

				$filtered = [
					"secret" => $request["secret"],
					"name" => $request["name"],
					"url" => $request["url"],
					"port" => $request["port"],
					"accounts" => $request["accounts"],
					"packages" => implode(",", $request["packages"])
				];

				$create = $this->system->create("wa_servers", $filtered);

				if($create):
					$this->cache->container("system.waservers");
					$this->cache->clear();
					
					response(200, __("requests_waserver_addedsuccess"));
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "add.gateway":
				if(!permission("manage_gateways"))
					response(500, __("response_no_permission"));

	            if(!isset($request["name"], $request["callback"], $request["callback_id"], $request["content"], $_FILES["controller"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->isInt($request["callback"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if($_FILES["controller"]["error"] == 4)
					response(500, __("response_gateway_controllercannotempty"));

            	$filtered = [
            		"name" => $request["name"],
            		"callback" => $request["callback"] < 2 ? 1 : 2,
            		"callback_id" => $request["callback_id"],
            		"pricing" => $request["content"]
            	];

            	$create = $this->system->create("gateways", $filtered);

            	if($create):
            		try {
	        			$this->upload->upload($_FILES["controller"]);
	        			
		    			if($this->upload->uploaded):
		    				if(!in_array($this->upload->file_src_name_ext, ["php"]))
		    					response(500, __("response_shortener_invalidcontroller"));

			                $this->upload->file_new_name_body = md5($create);
			                $this->upload->file_new_name_ext = "php";
			                $this->upload->file_overwrite = true;
			                $this->upload->process("system/gateways/");

							if($this->upload->processed)
								$this->upload->clean();
							else
								response(500, __("response_gateway_invalidcontroller"));
						endif;
	        		} catch(Exception $e){
	        			response(500, __("response_gateway_invalidcontroller"));
	        		}

        			response(200, __("response_gateway_addeddcontroller"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "add.shortener":
				if(!permission("manage_shorteners"))
					response(500, __("response_no_permission"));

	            if(!isset($request["name"], $_FILES["controller"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if($_FILES["controller"]["error"] == 4)
					response(500, __("response_shortener_cannotempty"));

            	$filtered = [
            		"name" => $request["name"]
            	];

            	$create = $this->system->create("shorteners", $filtered);

            	if($create):
            		try {
	        			$this->upload->upload($_FILES["controller"]);
	        			
		    			if($this->upload->uploaded):
		    				if(!in_array($this->upload->file_src_name_ext, ["php"]))
		    					response(500, __("response_shortener_invalidcontroller"));

			                $this->upload->file_new_name_body = md5($create);
			                $this->upload->file_new_name_ext = "php";
			                $this->upload->file_overwrite = true;
			                $this->upload->process("system/shorteners/");

							if($this->upload->processed)
								$this->upload->clean();
							else
								response(500, __("response_shortener_invalidcontroller"));
						endif;
	        		} catch(Exception $e){
	        			response(500, __("response_shortener_invalidcontroller"));
	        		}

        			response(200, __("response_shortener_addedcontroller"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "add.plugin":
				if(!permission("manage_plugins"))
					response(500, __("response_no_permission"));

				if(!isset($_FILES["plugin"]))
        			response(500, __("response_invalid"));

	            $this->upload->upload($_FILES["plugin"]);
    			if($this->upload->uploaded):
    				if(!in_array($this->upload->file_src_name_ext, ["zip"]))
	    				response(500, __("response_invalid"));

	                $this->upload->file_new_name_body = "plugin";
	                $this->upload->file_overwrite = true;
	                $this->upload->process("uploads/temporary/");

					if($this->upload->processed)
						$this->upload->clean();
				endif;

				if(!$this->file->exists("uploads/temporary/plugin.zip"))
					response(500, __("response_pluginwentwrong"));

				$pluginFolder = false;

				try {
					$zip = new \PhpZip\ZipFile();
					
					// Extract to tmp directory (Linux filesystem) to avoid permission issues
					$tmpExtractPath = "/tmp/plugin_extract_" . uniqid();
					
					// Create the temporary directory first
					if (!mkdir($tmpExtractPath, 0755, true)) {
						response(500, __("response_went_wrong"));
					}
					
					$zip->openFile("uploads/temporary/plugin.zip")->extractTo($tmpExtractPath); 
					$zipItems = $zip->getListFiles();

					foreach($zipItems as $item):
						if($zip->getEntry($item)->isDirectory()):
							$pluginFolder = rtrim($item, "/");

							// The name comes straight out of the archive and is then
							// interpolated into filesystem paths that are passed to
							// rmrf(), mkdir() and the copy target. A crafted entry such
							// as "../../uploads" would delete or overwrite files well
							// outside system/plugins/installables/, so require a plain
							// directory name with no separators or traversal.
							if(!preg_match('/^[A-Za-z0-9._-]+$/', $pluginFolder)
								|| $pluginFolder === "." || $pluginFolder === ".."):
								$zip->close();
								rmrf($tmpExtractPath);
								response(500, __("response_pluginwentwrong"));
							endif;

							break;
						else:
							response(500, __("response_pluginwentwrong"));
						endif;
					endforeach;

					$zip->close();

					if(!$pluginFolder) {
						// Clean up tmp directory
						rmrf($tmpExtractPath);
						response(500, __("response_pluginwentwrong"));
					}

					// Copy the plugin folder from tmp to final location
					// If plugin folder exists, remove it first to allow reinstallation
					if(file_exists("system/plugins/installables/{$pluginFolder}")) {
						rmrf("system/plugins/installables/{$pluginFolder}");
					}

					// Create destination directory - use is_dir check to avoid mkdir error on existing dirs
					if(!is_dir("system/plugins/installables/{$pluginFolder}")) {
						if(!mkdir("system/plugins/installables/{$pluginFolder}", 0755, true)) {
							rmrf($tmpExtractPath);
							response(500, __("response_went_wrong"));
						}
					}

					// Recursively copy files
					$iterator = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator("{$tmpExtractPath}/{$pluginFolder}", RecursiveDirectoryIterator::SKIP_DOTS),
						RecursiveIteratorIterator::SELF_FIRST
					);

					foreach ($iterator as $item) {
						$target = "system/plugins/installables/{$pluginFolder}" . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
						if ($item->isDir()) {
							if(!mkdir($target, 0755, true)) {
								rmrf($tmpExtractPath);
								response(500, __("response_went_wrong"));
							}
						} else {
							if(!copy($item, $target)) {
								rmrf($tmpExtractPath);
								response(500, __("response_went_wrong"));
							}
						}
					}

					// Clean up tmp directory
					rmrf($tmpExtractPath);

					if(!$this->file->exists("system/plugins/installables/{$pluginFolder}/plugin.json")):
						response(500, __("response_pluginwentwrong"));
					endif;
				} catch(Exception $e){
					$this->file->put("system/storage/temporary/error.log", "Plugin Install Error: {$e->getMessage()} (" . date("m/d/Y g:i A") . ")\n\n", FILE_APPEND);
					response(500, __("response_pluginwentwrong"));
				}

				$pluginData = json_decode($this->file->get("system/plugins/installables/{$pluginFolder}/plugin.json"), true);

				if(array_key_exists("setup", $pluginData)):
					if(isset($pluginData["setup"]["install"])):
						try {
							$this->guzzle->get(site_url("plugin?name={$pluginData["directory"]}&action={$pluginData["setup"]["install"]["action"]}&token=" . system_token, true), [
								"connect_timeout" => 30,
								"timeout" => 30,
								"allow_redirects" => true,
								"http_errors" => false
							]);
						} catch(Exception $e){
							$this->file->put("system/storage/temporary/error.log", "Plugin Install Error: {$e->getMessage()} (" . date("m/d/Y g:i A") . ")\n\n", FILE_APPEND);
						}
					endif;
				endif;

				$pluginDataArray = [];

				if(array_key_exists("data", $pluginData)):
					foreach($pluginData["data"] as $key => $value):
						$pluginDataArray[$key] = $value["value"];
					endforeach;
				endif;

            	$filtered = [
            		"name" => $pluginData["name"],
					"directory" => $pluginData["directory"],
            		"data" => json_encode($pluginDataArray)
            	];

            	if($this->system->checkPlugin($pluginData["directory"]) > 0)
            		response(500, __("response_plugin_samenameinstalled"));

            	if($this->system->create("plugins", $filtered)):
            		$this->cache->container("system.plugins");
            		$this->cache->clear();

					try {
						$this->file->delete("uploads/temporary/plugin.zip");
					} catch(Exception $e){
						// Ignore
					}

        			response(200, __("response_plugininstalled"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "system.update":
				if(!super_admin)
					response(500, __("response_no_permission"));

				if(!isset($_FILES["update"]))
        			response(500, __("response_invalid"));

	            $this->upload->upload($_FILES["update"]);

    			if($this->upload->uploaded):
    				if(!in_array($this->upload->file_src_name_ext, ["zip"]))
	    				response(500, __("response_invalid"));

	                $this->upload->file_new_name_body = "update";
	                $this->upload->file_overwrite = true;
	                $this->upload->process("uploads/temporary/");

					if($this->upload->processed)
						$this->upload->clean();
				endif;

				if(!$this->file->exists("uploads/temporary/update.zip"))
					response(500, __("requests_systemupdate_faileduploadingfile"));

				try {
					rmrf("uploads/temporary/update");
					mkdir("uploads/temporary/update");
				} catch(Exception $e){
					// Ignore
				}

				try {
					$zip = $this->zip->open("uploads/temporary/update.zip", "uploads/temporary/update");
				} catch(Exception $e){
					$this->file->put("system/storage/temporary/error.log", "System Update Error: {$e->getMessage()} (" . date("m/d/Y g:i A") . ")\n\n", FILE_APPEND);
				}

				try {
					copyDirectory("uploads/temporary/update/files/", "./");
				} catch(Exception $e){
					// Ignore
				}

				if($this->file->exists("uploads/temporary/update/manual.conf")):
					$manualLink = $this->file->get("uploads/temporary/update/manual.conf");
					response(303, __("response_systemupdate_manualinterreq"), $manualLink);
				endif;

				if($this->file->exists("uploads/temporary/update/instructions.conf")):
					$instructions = explode("\n", $this->file->get("uploads/temporary/update/instructions.conf"));

					if(!empty($instructions)):
						foreach($instructions as $instruction):
							if(trim($instruction) == "database"):
								try {
									new Thamaraiselvam\MysqlImport\Import("uploads/temporary/update/update.sql", env["dbuser"], env["dbpass"], env["dbname"], env["dbhost"], env["dbport"]);
								} catch(Exception $e){
									$this->file->put("system/storage/temporary/error.log", "Update SQL Error: {$e->getMessage()} (" . date("m/d/Y g:i A") . ")\n\n", FILE_APPEND);
								}
							endif;

							if(trim($instruction) == "cache"):
								try {
									rmrf("system/storage/cache");
									mkdir("system/storage/cache");
								} catch(Exception $e){
									// Ignore
								}
							endif;
						endforeach;
					endif;
				endif;

				if($this->file->exists("uploads/temporary/update/remove.conf")):
					$remove = explode("\n", $this->file->get("uploads/temporary/update/remove.conf"));

					if(!empty($remove)):
						foreach($remove as $file):
							try {
								$this->file->delete($file);
							} catch(Exception $e){
								// Ignore
							}
						endforeach;
					endif;
				endif;
				
				try {
					rmrf("uploads/temporary/update");
				} catch(Exception $e){
					// Ignore
				}

				response(301, __("response_update_success"));

				break;
        	default:
        		response(500, __("response_invalid"));
        endswitch;
	}

	public function update()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
            response(302);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        $this->cache->container("system.plugins");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getPlugins());
        endif;

        set_plugins($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

        $type = $this->sanitize->string($this->url->segment(4));
        $request = $this->sanitize->array($_POST, 
        	in_array($type, ["edit.ai.plugin", "edit.page", "edit.gateway", "admin.theme", "admin.settings"]) ? ["content", "layout", "script", "css", "bank_template"] : []
    	);

        if(!isset($request["id"]) || !$this->sanitize->isInt($request["id"]))
        	response(500, __("response_invalid"));

        switch($type):
        	case "user.settings":
        		if(!isset($request["name"], $request["email"], $request["password"], $request["theme_color"], $request["timezone"], $request["clock_format"], $request["date_format"], $request["date_separator"], $request["country"], $request["alertsound"], $request["current_email"]))
        			response(500, __("response_invalid"));

        		if($request["id"] != logged_id)
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->isInt($request["alertsound"]))
        			response(500, __("response_invalid"));

				if(!in_array($request["theme_color"], ["light", "dark"]))
	            	response(500, __("response_invalid"));

        		if(!in_array($request["timezone"], $this->timezones->generate()))
	            	response(500, __("response_invalid"));

	            if(!in_array($request["clock_format"], [1, 2]))
	            	response(500, __("response_invalid"));

	            if(!in_array($request["date_format"], [1, 2, 3, 4]))
	            	response(500, __("response_invalid"));

	            if(!in_array($request["date_separator"], [1, 2, 3, 4]))
	            	response(500, __("response_invalid"));

	            if(!array_key_exists($request["country"], \CountryCodes::get("alpha2", "country")))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["name"]))
        			response(500, __("response_name_short"));

        		if(!$this->sanitize->isEmail($request["email"]))
        			response(500, __("response_invalid_email"));

        		if($request["current_email"] != $request["email"] && $this->system->checkEmail($request["email"]) > 0)
	            	response(500, __("response_email_unavailable"));

	            $date_separator = [
	            	1 => "-",
	            	2 => "/",
	            	3 => ".",
	            	4 => " "
	            ];

	            $date_format = [
	            	1 => "n{$date_separator[$request["date_separator"]]}j{$date_separator[$request["date_separator"]]}Y",
	            	2 => "j{$date_separator[$request["date_separator"]]}n{$date_separator[$request["date_separator"]]}Y",
	            	3 => "Y{$date_separator[$request["date_separator"]]}n{$date_separator[$request["date_separator"]]}j",
	            	4 => "Y{$date_separator[$request["date_separator"]]}j{$date_separator[$request["date_separator"]]}n"
	            ];

        		$filtered = [
        			"name" => $request["name"],
        			"timezone" => strtolower($request["timezone"]),
        			"formatting" => json_encode([
        				"clock" => $request["clock_format"] < 2 ? "g:i A" : "H:i",
        				"date" => $date_format[$request["date_format"]],
        				"container" => [
        					"clock_format" => (int) $request["clock_format"],
        					"date_format" => (int) $request["date_format"],
        					"date_separator" => (int) $request["date_separator"],
        					"separator_selected" => $date_separator[$request["date_separator"]]
        				]
        			]),
        			"country" => strtoupper($request["country"]),
					"theme_color" => $request["theme_color"],
        			"alertsound" => $request["alertsound"] < 2 ? 1 : 2,
        			"email" => $this->sanitize->email($request["email"])
        		];

        		if(!empty($request["password"])):
        			if(!$this->sanitize->length($request["password"], 5))
        				response(500, __("response_password_short"));
        			else
        				$filtered["password"] = password_hash($request["password"], PASSWORD_DEFAULT);
        		endif;

        		if(isset($_FILES["avatar"])):
        			$this->upload->upload($_FILES["avatar"]);
        			if($this->upload->uploaded):
        				$this->upload->allowed = [
        					"image/*"
        				];

		                $this->upload->file_new_name_body = logged_hash;
		                $this->upload->image_convert = "jpg";
						$this->upload->image_resize = true;
						$this->upload->image_x = 150;
						$this->upload->image_ratio_y = true;
		                $this->upload->file_overwrite = true;
		                $this->upload->process("uploads/avatars/");

						if($this->upload->processed)
							$this->upload->clean();
						else
							response(500, __("response_avatar_invalid"));
					endif;
	            endif;

        		if($this->system->update($request["id"], false, "users", $filtered)):
        			$this->session->set(
        				"logged", 
        				$this->system->getUser(logged_id)
        			);

        			response(301, __("response_profile_updated"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

        		break;
			case "edit.sms.scheduled":
				if(!isset($request["mode"], $request["schedule"], $request["repeat"], $request["numbers"], $request["groups"], $request["message"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->isInt($request["mode"]))
					response(500, __("response_invalid"));

				if(!is_array($request["groups"]))
        			response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["repeat"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->length($request["message"], system_message_min))
					response(500, __("response_message_short"));

				if(system_message_max > 0):
					if($this->sanitize->length($request["message"], system_message_max, 2))
						response(500, __("response_message_toolong"));
				endif;

				if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], false)):
					$this->system->create("logger", [
						"uid" => logged_id,
						"type" => "ai_moderation",
						"reason" => "Inappropriate Content!",
						"content" => "Type: SMS Scheduled, Message: {$request["message"]}",
						"create_date" => date("Y-m-d H:i:s", time())
					]);
					
					response(500, __("response_aimoderation_detected"));
				endif;

        		if($request["mode"] < 2):
        			if(!isset($request["device"], $request["sim"]))
        				response(500, __("response_invalid"));

        			$subscription = set_subscription(
	                    $this->system->checkSubscription(logged_id), 
	                    $this->system->getSubscription(false, logged_id), 
	                    $this->system->getSubscription(false, false, true)
	                );

					if(empty($subscription))
						response(500, __("response_package_nosubwarn"));

					if(!$this->sanitize->isInt($request["sim"]))
						response(500, __("response_invalid"));

	    			if($this->system->checkDevice(logged_id, $request["device"], "did") < 1)
    					response(500, __("response_invalid"));

	    			$device = $this->system->getDevice(logged_id, $request["device"], "did");

	    			if($device):
    					$filtered = [
							"did" => $request["device"],
							"sim" => $request["sim"] < 2 ? 1 : 2,
							"mode" => 1,
							"gateway" => 0,
							"groups" => implode(",", $request["groups"]),
							"name" => $request["name"],
							"numbers" => $this->sanitize->normalizeContactsCommas($request["numbers"]),
							"message" => $request["message"],
							"repeat" => $request["repeat"],
							"last_send" => false,
							"send_date" => strtotime($request["schedule"])
						];
		    		else:
		    			response(500, __("response_invalid"));
		    		endif;
        		else:
        			if(!isset($request["gateway"]))
        				response(500, __("response_invalid"));

        			if($this->sanitize->isInt($request["gateway"])):
        				$gateways = $this->system->getGateways();

						if(!array_key_exists($request["gateway"], $gateways)):
							response(500, __("response_invalid"));
						endif;

						$filtered = [
							"did" => false,
							"sim" => 0,
							"mode" => 2,
							"gateway" => $request["gateway"],
							"groups" => implode(",", $request["groups"]),
							"name" => $request["name"],
							"numbers" => $request["numbers"],
							"message" => $request["message"],
							"repeat" => $request["repeat"],
							"last_send" => false,
							"send_date" => strtotime($request["schedule"])
						];
        			else:
        				$device = $this->system->getDevice(false, $request["gateway"], "global");

		    			if($device):
			    			if($device["global_device"] > 1):
			    				response(500, __("response_invalid"));
			    			else:
			    				$slots = explode(",", $device["global_slots"]);

			    				$filtered = [
									"did" => $request["gateway"],
									"sim" => count($slots) > 1 ? rand(1, 2) : ($slots[0] < 2 ? 1 : 2),
									"mode" => 2,
									"gateway" => 0,
									"groups" => implode(",", $request["groups"]),
									"name" => $request["name"],
									"numbers" => $request["numbers"],
									"message" => $request["message"],
									"repeat" => $request["repeat"],
									"last_send" => false,
									"send_date" => strtotime($request["schedule"])
								];
			    			endif;
			    		else:
			    			response(500, __("response_invalid"));
			    		endif;
        			endif;
        		endif;

				if($this->system->update($request["id"], logged_id, "scheduled", $filtered)):
					response(200, __("response_smscheduled_editsuccess"));
				else:
					response(500, __("response_went_wrong"));
				endif;
			case "edit.whatsapp.scheduled":
        		if(!isset($request["account"], $request["schedule"], $request["repeat"], $request["numbers"], $request["groups"], $request["message"]))
        			response(500, __("response_invalid"));

				if(!is_array($request["groups"]))
        			response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["repeat"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->length($request["message"], system_message_min))
					response(500, __("response_message_short"));

				if(system_message_max > 0):
					if($this->sanitize->length($request["message"], system_message_max, 2)):
						response(500, __("response_message_toolong"));
					endif;

					if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], false)):
						$this->system->create("logger", [
							"uid" => logged_id,
							"type" => "ai_moderation",
							"reason" => "Inappropriate Content!",
							"content" => "Type: WhatsApp Scheduled, Message: {$request["message"]}",
							"create_date" => date("Y-m-d H:i:s", time())
						]);
						
						response(500, __("response_aimoderation_detected"));
					endif;
				endif;

				$subscription = set_subscription(
                    $this->system->checkSubscription(logged_id), 
                    $this->system->getSubscription(false, logged_id), 
                    $this->system->getSubscription(false, false, true)
                );

				if(empty($subscription))
					response(500, __("response_package_nosubwarn"));

				if($this->system->checkWaAccount(logged_id, $request["account"], "id") < 1)
					response(500, __("response_invalid"));

				$waSent = $this->system->getWaScheduled($request["id"]);

				$decodeMessage = json_decode($waSent["message"], true);

				if(isset($decodeMessage["text"])):
					$decodeMessage["text"] = encodeBraces($request["message"]);
				else:
					$decodeMessage["caption"] = encodeBraces($request["message"]);
				endif;

        		$filtered = [
					"groups" => implode(",", $request["groups"]),
					"name" => $request["name"],
					"numbers" => $this->sanitize->normalizeContactsCommas($request["numbers"]),
					"message" => json_encode($decodeMessage),
					"repeat" => $request["repeat"],
					"send_date" => strtotime($request["schedule"])
				];

				if($this->system->update($request["id"], logged_id, "wa_scheduled", $filtered)):
					response(200, __("response_chatscheduled_editsuccess"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
        	case "edit.whatsapp":
        		if(!isset($request["receive_chats"], $request["random_send"], $request["random_min"], $request["random_max"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->isInt($request["receive_chats"]) || !$this->sanitize->isInt($request["random_send"]) || !$this->sanitize->isInt($request["random_min"]) || !$this->sanitize->isInt($request["random_max"]))
					response(500, __("response_invalid"));

				if($request["random_max"] < $request["random_min"])
					response(500, __("response_editdevice_invalidmaxinterval"));

				if($request["random_max"] > 5000)
					response(500, __("response_gateway_randommax_new"));

				$account = $this->system->getWaAccount(logged_id, $request["id"], "id");

				if(!$account)
					response(500, __("response_went_wrong"));

				$waServer = $this->system->getWaServer($account["wsid"], "id");

				$filtered = [
					"unique" => $account["unique"],
					"wsid" => $account["wsid"],
        			"receive_chats" => $request["receive_chats"],
        			"random_send" => $request["random_send"],
        			"random_min" => $request["random_min"],
        			"random_max" => $request["random_max"]
        		];

				if($waServer && $this->wa->check($waServer["url"], $waServer["port"])):
	        		if($this->system->update($request["id"], logged_id, "wa_accounts", $filtered)):
						if($this->wa->update($waServer["secret"], $filtered, $waServer["url"], $waServer["port"])):
							response(200, __("requests_editwhatsapp_success"));
						endif;
	        		else:
	        			response(500, __("response_went_wrong"));
	        		endif;
	        	else:
	        		response(500, __("response_whatsapp_noconnectserver"));
	        	endif;

        		break;
        	case "edit.template":
        		if(!isset($request["name"], $request["format"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if(!$this->sanitize->length($request["format"], 5))
					response(500, __("response_format_short"));

        		$filtered = [
        			"name" => $request["name"],
        			"format" => $request["format"]
        		];

        		if($this->system->update($request["id"], logged_id, "templates", $filtered)):
        			response(200, __("response_template_updated"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

        		break;
			case "edit.ai.key":
				if(!isset($request["name"], $request["provider"], $request["prompt"], $request["post_prompt"], $request["model_openai"], $request["model_geminiai"], $request["model_claudeai"], $request["model_deepseekai"], $request["history"], $request["max_tokens"], $request["transcription"], $request["apikey"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if(!$this->sanitize->length($request["prompt"], 5))
					response(500, __("response_editaikey_promptshort"));

				if(!in_array($request["provider"], ["openai", "geminiai", "claudeai", "grokai", "deepseekai"]))
					response(500, __("response_invalid"));

				$aiModel = false;

				if($request["provider"] == "openai"):
					if(isset($request["model_openai"]) && !in_array($request["model_openai"], ["gpt-5", "gpt-5-pro", "gpt-5-mini", "gpt-5-nano", "gpt-4.1", "gpt-4.1-mini", "gpt-4.1-nano"])):
						response(500, __("response_addaikey_modelinval"));
					endif;

					$aiModel = $request["model_openai"];
				endif;

				if($request["provider"] == "geminiai"):
					if(isset($request["model_geminiai"]) && !in_array($request["model_geminiai"], ["gemini-2.5-pro", "gemini-2.5-flash", "gemini-2.5-flash-lite"])):
						response(500, __("response_addaikey_modelinval"));
					endif;

					$aiModel = $request["model_geminiai"];
				endif;

				if($request["provider"] == "claudeai"):
					if(isset($request["model_claudeai"]) && !in_array($request["model_claudeai"], ["claude-opus-4-1", "claude-opus-4-0", "claude-sonnet-4-5", "claude-sonnet-4-0"])):
						response(500, __("response_addaikey_modelinval"));
					endif;

					$aiModel = $request["model_claudeai"];
				endif;

				if($request["provider"] == "grokai"):
					if(isset($request["model_grokai"]) && !in_array($request["model_grokai"], ["grok-3", "grok-3-mini"])):
						response(500, __("response_addaikey_modelinval"));
					endif;

					$aiModel = $request["model_grokai"];
				endif;

				if($request["provider"] == "deepseekai"):
					if(isset($request["model_deepseekai"]) && !in_array($request["model_deepseekai"], ["deepseek-chat"])):
						response(500, __("response_addaikey_modelinval"));
					endif;

					$aiModel = $request["model_deepseekai"];
				endif;

				if(!$aiModel)
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["history"]))
					response(500, __("response_editaikey_histothres"));

				if(!$this->sanitize->isInt($request["max_tokens"]))
					response(500, __("response_invalid"));

				if(isset($request["vision"])):
					if(!$this->sanitize->isInt($request["vision"])):
						response(500, __("response_invalid"));
					endif;
				else:
					$request["vision"] = 2;
				endif;

				if(!$this->sanitize->isInt($request["transcription"]))
					response(500, __("response_invalid"));

				if($request["history"] < 5)
					response(500, __("response_editaikey_histothresless"));

				if(!$this->sanitize->length($request["apikey"], 5))
					response(500, __("response_editaikey_apikeyshort"));

				$filtered = [
					"name" => $request["name"],
					"prompt" => $request["prompt"],
					"post_prompt" => $request["post_prompt"],
					"model" => $aiModel,
					"history" => $request["history"],
					"max_tokens" => $request["max_tokens"],
					"vision" => $request["vision"] < 2 ? 1 : 2,
					"transcription" => $request["transcription"] < 2 ? 1 : 2,
					"apikey" => $request["apikey"],
					"provider" => $request["provider"]
				];

				if($this->system->update($request["id"], logged_id, "ai_keys", $filtered)):
					if($this->aiprovider->resetForUser(logged_id)):
						$this->system->create("logger", [
							"uid" => logged_id,
							"type" => "ai_conversation",
							"reason" => "Conversation Reset!",
							"content" => "Conversations have been cleared for changes to take effect!",
							"create_date" => date("Y-m-d H:i:s", time())
						]);
					endif;

					response(200, __("response_editaikey_success"));
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "edit.ai.plugin":
				if(!isset($request["name"], $request["endpoint"], $request["content"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if(!empty($request["endpoint"]) && !$this->sanitize->length($request["endpoint"]))
					response(500, __("response_editaiplugin_endpointshort"));

				if(!empty($request["endpoint"]) && !$this->sanitize->isUrl($request["endpoint"]))
					response(500, __("response_editaiplugin_endpointinval"));

				if(!isPluginSchemaJsonValid($request["content"]))
					response(500, __("response_editaiplugin_schemainval"));

				$filtered = [
					"name" => $request["name"],
					"endpoint" => $request["endpoint"],
					"schema" => $request["content"]
				];

				if($this->system->update($request["id"], logged_id, "ai_plugins", $filtered)):
					response(200, __("response_editaiplugin_success"));
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
        	case "edit.contact":
        		if(!isset($request["name"], $request["phone"], $request["groups"], $request["current_phone"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

        		if($request["phone"] != $request["current_phone"]):
    				try {
					    $number = $this->phone->parse($request["phone"], logged_country);

					    if(!$number->isValidNumber() && $number->getRegionCode() != "BR")
							response(500, __("response_invalid_number"));

						$request["phone"] = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);
					} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
						response(500, __("response_invalid_number"));
					}

        			if($this->system->checkNumber(logged_id, $request["phone"]) > 0)
        				response(500, __("response_number_exist"));
        		endif;

        		if(!is_array($request["groups"]))
        			response(500, __("response_invalid"));

        		foreach($request["groups"] as $group):
    				if($this->system->checkGroup(logged_id, $group) < 1)
						response(500, __("response_invalid"));
    			endforeach;

        		$filtered = [
        			"groups" => implode(",", $request["groups"]),
        			"name" => $request["name"],
        			"phone" => $request["phone"]
        		];

        		if($this->system->update($request["id"], logged_id, "contacts", $filtered)):
        			$this->cache->container("autocomplete.contacts." . logged_hash);
        			$this->cache->clear();
        			$this->cache->container("contacts." . logged_hash);
        			$this->cache->clear();
        			$this->cache->container("user." . logged_hash);
					$this->cache->clear();

        			response(200, __("response_contact_updated"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

        		break;
        	case "edit.group":
        		if(!isset($request["name"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

        		$filtered = [
        			"name" => $request["name"]
        		];

        		if($this->system->update($request["id"], logged_id, "groups", $filtered)):
        			$this->cache->container("contacts." . logged_hash);
					$this->cache->clear();
					$this->cache->container("groups." . logged_hash);
					$this->cache->clear();

        			response(200, __("response_group_updated"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

        		break;
        	case "edit.device":
        		if(!isset($request["name"], $request["receive_sms"], $request["random_send"], $request["random_min"], $request["random_max"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_editdevice_nametooshort"));

				if($this->sanitize->length($request["name"], 15, 2))
					response(500, __("response_editdevice_nametoolong"));

				if(!$this->sanitize->isInt($request["receive_sms"]) || !$this->sanitize->isInt($request["random_send"]) || !$this->sanitize->isInt($request["random_min"]) || !$this->sanitize->isInt($request["random_max"]))
					response(500, __("response_invalid"));

				if($request["random_max"] < $request["random_min"])
					response(500, __("response_editdevice_invalidmaxinterval"));

				if($request["random_max"] > 5000)
					response(500, __("response_gateway_randommax_new"));

				if($this->system->checkDevice(logged_id, $request["id"], "id") < 1)
					response(500, __("response_invalid"));

				$partnerStatus = $this->system->getPartnership(logged_id);

        		if($partnerStatus < 2):
        			if(isset($request["rate"], $request["global_device"], $request["country"], $request["global_priority"], $request["global_slots"])):
			            if(!array_key_exists($request["country"], \CountryCodes::get("alpha2", "country")))
			            	response(500, __("response_invalid"));

			            if(!$this->sanitize->isInt($request["global_priority"]))
			            	response(500, __("response_invalid"));

			            // Proper rate validation to prevent negative/zero/extreme rates
			            if(!is_numeric($request["rate"]))
			            	response(500, __("response_editdevice_invalidrate"));

			            if($request["rate"] <= 0)
			            	response(500, "Rate must be greater than zero");

			            if($request["rate"] > 100)
			            	response(500, "Rate cannot exceed 100");
			        endif;
        		endif;

        		$filtered = [
        			"name" => $request["name"],
        			"receive_sms" => $request["receive_sms"],
        			"random_send" => $request["random_send"],
        			"random_min" => $request["random_min"],
        			"random_max" => $request["random_max"]
        		];

        		if($partnerStatus < 2):
        			if(isset($request["rate"], $request["global_device"], $request["country"], $request["global_priority"], $request["global_slots"])):
	        			$filtered["rate"] = $request["rate"];
	        			$filtered["global_device"] = $request["global_device"];
	        			$filtered["country"] = $request["country"];
	        			$filtered["global_priority"] = $request["global_priority"];

	        			// Validate SIM slots to prevent XSS and invalid values
	        			if(is_array($request["global_slots"])):
	        				$valid_slots = [];
	        				foreach($request["global_slots"] as $slot):
	        					// Only allow 1 or 2
	        					if(!in_array($slot, ["1", "2", 1, 2])):
	        						response(500, "Invalid SIM slot value. Only 1 or 2 allowed");
	        					endif;
	        					$valid_slots[] = (int) $slot;
	        				endforeach;

	        				// Remove duplicates
	        				$valid_slots = array_unique($valid_slots);

	        				if(count($valid_slots) === 0):
	        					$valid_slots = [1];
	        				endif;

	        				$filtered["global_slots"] = implode(",", $valid_slots);
	        			else:
	        				$filtered["global_slots"] = "1";
	        			endif;
	        		endif;
        		endif;

        		$device = $this->system->getDevice(logged_id, $request["id"], "id");

        		if($this->system->update($request["id"], logged_id, "devices", $filtered)):
        			$this->cache->container("user." . logged_hash);
					$this->cache->clear();

					$this->fcm->sendWithToken($device["fcm_token"], [
						"action" => "device_update",
						"name" => $filtered["name"],
						"receive_sms" => $filtered["receive_sms"],
						"random_send" => $filtered["random_send"],
						"random_min" => $filtered["random_min"],
						"random_max" => $filtered["random_max"]
					]);

        			response(200, __("response_device_updated"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

        		break;
        	case "edit.apikey":
        		if(!isset($request["name"], $request["permissions"]))
        			response(500, __("response_invalid"));

        		$subscription = set_subscription(
                    $this->system->checkSubscription(logged_id), 
                    $this->system->getSubscription(false, logged_id), 
                    $this->system->getSubscription(false, false, true)
                );

				if(empty($subscription))
					response(500, __("response_package_nosubwarn"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

        		if(!is_array($request["permissions"]))
        			response(500, __("response_invalid"));

        		if(empty($request["permissions"]))
        			response(500, __("response_permission_min"));

        		foreach($request["permissions"] as $permission):
        			if(!in_array($permission, [
        				"otp",
        				"sms_send",
        				"sms_send_bulk",
        				"wa_send",
        				"wa_send_bulk",
        				"ussd",
						"validate_wa_phone",
        				"get_credits",
        				"get_earnings",
        				"get_subscription",
        				"get_sms_pending",
        				"get_wa_pending",
        				"get_sms_received",
        				"get_wa_received",
        				"get_sms_sent",
        				"get_sms_campaigns",
        				"get_wa_sent",
        				"get_wa_campaigns",
        				"get_contacts",
        				"get_groups",
        				"get_ussd",
        				"get_wa_accounts",
						"get_wa_groups",
        				"get_devices",
        				"get_rates",
        				"get_shorteners",
        				"get_unsubscribed",
        				"create_whatsapp",
        				"create_contact",
        				"create_group",
        				"start_sms_campaign",
        				"stop_sms_campaign",
        				"start_wa_campaign",
        				"stop_wa_campaign",
        				"delete_contact",
        				"delete_group",
        				"delete_sms_sent",
        				"delete_sms_campaign",
						"delete_wa_account",
        				"delete_wa_sent",
        				"delete_wa_campaign",
        				"delete_sms_received",
        				"delete_wa_received",
        				"delete_ussd",
        				"delete_unsubscribed"
        			])):
        				response(500, __("response_invalid"));
        			endif;
        		endforeach;

				$filtered = [
					"name" => $request["name"],
					"permissions" => implode(",", $request["permissions"])
				];

				if($this->system->update($request["id"], logged_id, "`keys`", $filtered)):
					$this->cache->container("user." . logged_hash);
					$this->cache->clear();

					response(200, __("response_key_updated"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
        	case "edit.webhook":
        		if(!isset($request["name"], $request["events"], $request["url"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if(!is_array($request["events"]))
        			response(500, __("response_invalid"));

        		foreach($request["events"] as $event):
        			if(!in_array($event, ["sms", "whatsapp", "ussd"]))
        				response(500, __("response_invalid"));
        		endforeach;

        		if(!$this->sanitize->isUrl($request["url"]))
        			response(500, __("response_invalid_webhookurl"));

				$filtered = [
					"name" => $request["name"],
					"url" => $this->sanitize->url($request["url"]),
					"events" => implode(",", $request["events"])
				];

				if($this->system->update($request["id"], logged_id, "webhooks", $filtered)):
					response(200, __("response_webhook_updated"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
        	case "edit.hook":
        		if(!isset($request["name"], $request["source"], $request["event"], $request["link"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if(!$this->sanitize->isInt($request["source"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->isInt($request["event"]))
        			response(500, __("response_invalid"));

        		if(!$this->sanitize->isUrl($request["link"]))
        			response(500, __("response_invalid_linkstructure"));

				$filtered = [
					"name" => $request["name"],
					"source" => $request["source"] < 2 ? 1 : 2,
					"event" => $request["event"] < 2 ? 1 : 2,
					"link" => $request["link"]
				];

				if($this->system->update($request["id"], logged_id, "actions", $filtered)):
					response(200, __("response_hook_updated_2025"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
			case "edit.autoreply":
				if(!isset($request["name"], $request["source"], $request["match"], $request["priority"], $request["keywords"], $request["message"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if(!$this->sanitize->isInt($request["source"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["match"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["priority"]))
					response(500, __("response_invalid"));

				if($request["match"] < 5):
					if(empty($request["keywords"])):
						response(500, __("require_action_keywords"));
					endif;

					if(!$this->sanitize->length($request["keywords"], 1)):
						response(500, __("response_invalid_keywords"));
					endif;
				endif;

				if($request["match"] < 6):
					if(empty($request["message"])):
						response(500, __("response_message_short"));
					endif;

					if(!$this->sanitize->length($request["message"])):
						response(500, __("response_message_short"));
					endif;
				endif;

				if($request["match"] > 5):
					if(!isset($request["ai_key"]) && empty($request["ai_key"])):
						response(500, __("response_aiautoreply_mustselaikey"));
					endif;

					if($this->system->checkAiKey(logged_id, $request["ai_key"]) < 1):
						response(500, __("response_invalid"));
					endif;

					if(isset($request["ai_plugins"]) && !empty($request["ai_plugins"])):
						foreach($request["ai_plugins"] as $plugin):
							if(!str_starts_with($plugin, "ai-")):
								if($this->system->checkAiPlugin(logged_id, $plugin) < 1):
									response(500, __("response_invalid"));
								endif;
							endif;
						endforeach;
					endif;
				endif;

				if(!isset($request["ai_key"])):
					$request["ai_key"] = 0;
				endif;

				if(!isset($request["ai_plugins"])):
					$request["ai_plugins"] = [];
				endif;

				if(isset($request["group_trigger"])):
					if(!$this->sanitize->isInt($request["group_trigger"])):
						response(500, __("response_invalid"));
					endif;
				else:
					$request["group_trigger"] = 2;
				endif;

				if($request["source"] < 2):
					// sms

					if(!isset($request["device"], $request["sim"]))
						response(500, __("response_invalid"));

					if(!$this->sanitize->isInt($request["sim"]))
						response(500, __("response_invalid"));

					if($this->system->checkDevice(logged_id, $request["device"], "did") < 1)
						response(500, __("response_invalid"));

					$filtered = [
						"uid" => logged_id,
						"type" => 2,
						"source" => 1,
						"match" => $request["match"],
						"ai_key" => $request["ai_key"],
						"ai_plugins" => implode(",", $request["ai_plugins"]),
						"group_trigger" => $request["group_trigger"] < 2 ? 1 : 2,
						"sim" => $request["sim"] < 2 ? 1 : 2,
						"device" => $request["device"],
						"name" => $request["name"],
						"event" => 0,
						"priority" => 2,
						"keywords" => $this->sanitize->cleanAutoreplyKeywordCommas($request["keywords"]),
						"message" => $request["message"],
						"link" => false
					];
				else:
					// whatsapp

					if(!isset($request["message_type"], $request["account"]))
						response(500, __("response_invalid"));

					if(!in_array($request["message_type"], ["text", "media", "document"]))
						response(500, __("response_invalid"));
					
					if($this->system->checkWaAccount(logged_id, $request["account"], "id") < 1)
						response(500, __("response_invalid"));

					$this->file->mkdir("uploads/whatsapp/actions/{$request["account"]}");

					switch($request["message_type"]):
						case "media":
							try {
								$this->upload->upload($_FILES["media_file"]);
								if($this->upload->uploaded):
									if(!in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif", "mp4", "mp3", "ogg"]))
										response(500, __("response_whatsapp_invalidmediafile"));
	
									$mediaName = logged_hash . "_" . uniqid(logged_hash, true);
	
									$this->upload->mime_check = false;
									$this->upload->file_new_name_body = $mediaName;
									$this->upload->file_overwrite = true;
									$this->upload->process("uploads/whatsapp/actions/{$request["account"]}/");
	
									if($this->upload->processed):
										if(in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif"])):
											// image
											$message = [
												"image" => [
													"url" => site_url("uploads/whatsapp/actions/{$request["account"]}/{$mediaName}.{$this->upload->file_src_name_ext}", true)
												],
												"caption" => $request["message"]
											];
										elseif(in_array($this->upload->file_src_name_ext, ["mp3", "ogg"])):
											// audio
											$message = [
												"audio" => [
													"url" => site_url("uploads/whatsapp/actions/{$request["account"]}/{$mediaName}.{$this->upload->file_src_name_ext}", true)
												],
												"caption" => false
											];
										else:
											// video
											$message = [
												"video" => [
													"url" => site_url("uploads/whatsapp/actions/{$request["account"]}/{$mediaName}.{$this->upload->file_src_name_ext}", true)
												],
												"caption" => $request["message"]
											];
										endif;

										$this->upload->clean();
									else:
										response(500, __("response_whatsapp_invalidmediafile"));
									endif;
								else:
									response(500, __("response_select_newmed_file_38"));
								endif;
							} catch(Exception $e){
								response(500, __("response_whatsapp_invalidmediafile"));
							}
	
							break;
						case "document":
							try {
								$this->upload->upload($_FILES["doc_file"]);
								if($this->upload->uploaded):
									switch($this->upload->file_src_name_ext):
										case "pdf":
											$docMimetype = "application/pdf";
	
											break;
										case "xls":
											$docMimetype = "application/excel";
											
											break;
										case "xlsx":
											$docMimetype = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
											
											break;
										case "doc":
											$docMimetype = "application/msword";
											
											break;
										case "docx":
											$docMimetype = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
											
											break;
										default:
											response(500, __("response_whatsapp_invaldocumenfile"));
									endswitch;
									
									$fileName = $this->upload->file_src_name;
									$docName = logged_hash . "_" . uniqid(logged_hash, true);
	
									$this->upload->mime_check = false;
									$this->upload->file_new_name_body = $docName;
									$this->upload->file_overwrite = true;
									$this->upload->process("uploads/whatsapp/actions/{$request["account"]}/");
	
									if($this->upload->processed):
										$message = [
											"document" => [
												"url" => site_url("uploads/whatsapp/actions/{$request["account"]}/{$docName}.{$this->upload->file_src_name_ext}", true)
											],
											"fileName" => $fileName,
											"mimetype" => $docMimetype,
											"caption" => $request["message"]
										];

										$this->upload->clean();
									else:
										response(500, __("response_whatsapp_invaldocumenfile"));
									endif;
								else:
									response(500, __("response_select_newdoc_file_38"));
								endif;
							} catch(Exception $e){
								response(500, __("response_whatsapp_invaldocumenfile"));
							}
	
							break;
						default:
							$message = [
								"text" => $request["message"]
							];
					endswitch;

					$action = $this->system->getAction($request["id"]);

					if($action):
						try {
							$msgDecode = json_decode($action["message"], true, JSON_THROW_ON_ERROR);
							
							if(isset($msgDecode["autoreply"])):
								if(isset($msgDecode["image"])):
									$fileUrl = explode("/", $msgDecode["image"]["url"]);
								endif;
			
								if(isset($msgDecode["audio"])):
									$fileUrl = explode("/", $msgDecode["audio"]["url"]);
								endif;
			
								if(isset($msgDecode["video"])):
									$fileUrl = explode("/", $msgDecode["video"]["url"]);
								endif;
			
								if(isset($msgDecode["document"])):
									$fileUrl = explode("/", $msgDecode["document"]["url"]);
								endif;
								
								if(isset($fileUrl)):
									$this->file->delete("uploads/whatsapp/actions/{$request["account"]}/" . end($fileUrl));
								endif;
							endif;
						} catch(Exception $e){
							// Ignore
						}
					else:
						response(500, __("response_invalid"));
					endif;

					$message["autoreply"] = true;
					$message["message_type"] = $request["message_type"];

					$filtered = [
						"uid" => logged_id,
						"type" => 2,
						"source" => 2,
						"match" => $request["match"],
						"ai_key" => $request["ai_key"],
						"ai_plugins" => implode(",", $request["ai_plugins"]),
						"group_trigger" => $request["group_trigger"] < 2 ? 1 : 2,
						"sim" => 0,
						"account" => $request["account"],
						"name" => $request["name"],
						"event" => 0,
						"priority" => $request["priority"] < 2 ? 1 : 2,
						"keywords" => $this->sanitize->cleanAutoreplyKeywordCommas($request["keywords"]),
						"message" => json_encode($message),
						"link" => false
					];
				endif;

				if($this->system->update($request["id"], logged_id, "actions", $filtered)):
					if($request["match"] > 5):
						if($this->aiprovider->resetForUser(logged_id)):
							$this->system->create("logger", [
								"uid" => logged_id,
								"type" => "ai_conversation",
								"reason" => "Conversation Reset!",
								"content" => "Conversations have been cleared for changes to take effect!",
								"create_date" => date("Y-m-d H:i:s", time())
							]);
						endif;
					endif;
			
					response(200, __("response_autoreply_updated_2025"));
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
        	case "edit.user":
        		if(!permission("manage_users"))
					response(500, __("response_no_permission"));

				if($request["id"] < 2):
					if(!super_admin)
						response(500, __("response_no_permission"));
				endif;

	            if(!isset($request["name"], $request["email"], $request["password"], $request["theme_color"], $request["timezone"], $request["clock_format"], $request["date_format"], $request["date_separator"], $request["country"], $request["alertsound"], $request["role"], $request["language"], $request["credits"], $request["partner"], $request["current_email"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->isInt($request["role"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->isInt($request["language"]))
	            	response(500, __("response_invalid"));

				if(!in_array($request["theme_color"], ["light", "dark"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->isInt($request["alertsound"]))
	            	response(500, __("response_invalid"));

	            if(!empty($request["credits"])):
		            if(!$this->sanitize->isNumeric($request["credits"]) || $request["credits"] < 0):
		            	response(500, __("response_invalid"));
		            endif;
		        else:
		        	$request["credits"] = 0;
		        endif;

	            if(!$this->sanitize->isInt($request["partner"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

	            if(!$this->sanitize->isEmail($request["email"]))
	            	response(500, __("response_invalid_email"));

	            if(!in_array(strtolower($request["timezone"]), $this->timezones->generate()))
	            	response(500, __("response_invalid"));

	            if(!in_array($request["clock_format"], [1, 2]))
	            	response(500, __("response_invalid"));

	            if(!in_array($request["date_format"], [1, 2, 3, 4]))
	            	response(500, __("response_invalid"));

	            if(!in_array($request["date_separator"], [1, 2, 3, 4]))
	            	response(500, __("response_invalid"));

	            if(!array_key_exists(strtoupper($request["country"]), \CountryCodes::get("alpha2", "country")))
	            	response(500, __("response_invalid"));

	            if($this->system->checkRole($request["role"]) < 1)
	            	response(500, __("response_invalid"));

	            if($this->system->checkLanguage($request["language"]) < 1)
	            	response(500, __("response_invalid"));

	            if($request["current_email"] != $request["email"] && $this->system->checkEmail($request["email"]) > 0)
	            	response(500, __("response_email_unavailable"));

	            $date_separator = [
	            	1 => "-",
	            	2 => "/",
	            	3 => ".",
	            	4 => " "
	            ];

	            $date_format = [
	            	1 => "n{$date_separator[$request["date_separator"]]}j{$date_separator[$request["date_separator"]]}Y",
	            	2 => "j{$date_separator[$request["date_separator"]]}n{$date_separator[$request["date_separator"]]}Y",
	            	3 => "Y{$date_separator[$request["date_separator"]]}n{$date_separator[$request["date_separator"]]}j",
	            	4 => "Y{$date_separator[$request["date_separator"]]}j{$date_separator[$request["date_separator"]]}n"
	            ];

            	$filtered = [
            		"country" => strtoupper($request["country"]),
            		"role" => $request["role"],
            		"name" => $request["name"],
            		"language" => $request["language"],
					"theme_color" => $request["theme_color"],
            		"timezone" => strtolower($request["timezone"]),
            		"formatting" => json_encode([
        				"clock" => $request["clock_format"] < 2 ? "g:i A" : "H:i",
        				"date" => $date_format[$request["date_format"]],
        				"container" => [
        					"clock_format" => (int) $request["clock_format"],
        					"date_format" => (int) $request["date_format"],
        					"date_separator" => (int) $request["date_separator"],
        					"separator_selected" => $date_separator[$request["date_separator"]]
        				]
        			]),
            		"alertsound" => $request["alertsound"] < 2 ? 1 : 2,
            		"credits" => $request["credits"], 
            		"partner" => $request["partner"] < 2 ? 1 : 2,
            		"email" => $this->sanitize->email($request["email"])
            	];

            	if(!empty($request["password"])):
	            	if(!$this->sanitize->length($request["password"], 5))
	            		response(500, __("response_password_short"));
            		else
            			$filtered["password"] = password_hash($request["password"], PASSWORD_DEFAULT);
		        endif;

        		if($this->system->update($request["id"], false, "users", $filtered)):
        			$this->cache->container("system.users");
        			$this->cache->clear();

					$this->echo->trigger("private-user-" . md5($request["id"]), "reload");

        			response(200, __("response_user_updated"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "edit.role":
				if(!permission("manage_roles"))
					response(500, __("response_no_permission"));

				if($request["id"] < 2)
					response(500, __("role_default_update"));

        		if(!isset($request["name"], $request["permissions"]))
        			response(500, __("response_invalid"));

        		if(!is_array($request["permissions"]))
        			response(500, __("response_invalid"));

        		if(empty($request["permissions"]))
        			response(500, __("response_permission_min"));

        		if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if($this->system->checkRole($request["id"]) < 1)
                    response(500, __("response_invalid"));

        		foreach($request["permissions"] as $permission):
        			if(!in_array($permission, permission_list())):
        				response(500, __("response_invalid"));
        			endif;
        		endforeach;

				$filtered = [
					"name" => $request["name"],
					"permissions" => implode(",", $request["permissions"])
				];

				if($this->system->update($request["id"], false, "roles", $filtered)):
					$this->cache->container("system.roles");
					$this->cache->clear();

					$this->echo->trigger("private-user-" . logged_hash, "reload");

					response(200, __("role_updated"));
				else:
					response(500, __("response_went_wrong"));
				endif;

        		break;
			case "edit.package":
				if(!permission("manage_packages"))
					response(500, __("response_no_permission"));

	            $columns = [
					"send_limit",
					"receive_limit",
					"ussd_limit",
					"contact_limit",
					"device_limit",
					"key_limit",
					"webhook_limit",
					"action_limit",
					"scheduled_limit",
					"wa_send_limit",
					"wa_receive_limit",
					"wa_account_limit",
					"name",
					"price",
					"footermark",
					"hidden",
					"services"
				];

				foreach($columns as $column):
					if(!isset($request[$column])):
						response(500, __("response_invalid"));
					endif;

					if(in_array($column, ["services"]) && !is_array($request[$column])):
						response(500, __("response_invalid"));
					endif;

					if(!in_array($column, ["name", "services"])):
						if(!$this->sanitize->isInt($request[$column])):
							response(500, __("requests_packageform_invalidint"));
						endif;
					endif;
				endforeach;

	            if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

				if($request["id"] > 1 && $request["price"] < 1)
					response(500, __("response_package_pricenotlessone"));

				if($request["id"] < 2)
	            	$request["hidden"] = 2;

				$request["footermark"] = $request["footermark"] < 2 ? 1 : 2;
				$request["hidden"] = $request["hidden"] < 2 ? 1 : 2;
				$request["services"] = empty($request["services"]) ? false : implode(",", $request["services"]);

				$filtered = [];

            	foreach($columns as $column):
            		$filtered[$column] = $request[$column];
            	endforeach;

        		if($this->system->update($request["id"], false, "packages", $filtered)):
					foreach($this->system->getUsers() as $user):
    					$this->cache->container("user.{$user["hash"]}");
						$this->cache->clear();
        			endforeach;

        			$this->cache->container("system.packages");
            		$this->cache->clear();

        			response(200, __("response_package_updated"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "edit.page":
				if(!permission("manage_pages"))
					response(500, __("response_no_permission"));

	            if(!isset($request["name"], $request["roles"], $request["logged"], $request["content"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

	            if(!is_array($request["roles"]))
	            	response(500, __("response_invalid"));

	            if(empty($request["roles"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->isInt($request["logged"]))
					response(500, __("response_invalid"));

				if(!in_array($request["logged"], [1, 2]))
					response(500, __("response_invalid"));

            	$filtered = [
            		"slug" => (new Cocur\Slugify\Slugify())->slugify($request["name"]),
            		"logged" => $request["logged"],
            		"name" => $request["name"],
            		"roles" => implode(",", $request["roles"]),
            		"content" => $this->sanitize->htmlEncode($request["content"])
            	];

            	if($this->system->update($request["id"], false, "pages", $filtered)):
            		$this->cache->container("system.pages");
            		$this->cache->clear();

        			response(200, __("response_page_updated"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "edit.language":
				if(!permission("manage_languages"))
					response(500, __("response_no_permission"));

	            if(!isset($request["name"], $request["iso"], $request["rtl"], $request["translations"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

	            if(!array_key_exists($request["iso"], \CountryCodes::get("alpha2", "country")))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->isInt($request["rtl"]))
	            	response(500, __("response_invalid"));

	            if(!in_array($request["rtl"], [1, 2]))
	            	response(500, __("response_invalid"));

            	$filtered = [
            		"name" => $request["name"],
            		"rtl" => $request["rtl"],
            		"iso" => strtoupper($request["iso"])
            	];

            	if($this->system->update($request["id"], false, "languages", $filtered)):
            		$this->cache->container("system.languages");
            		$this->cache->clear();
            		
            		if($this->file->put("system/languages/" . md5($request["id"]) . ".lang", $request["translations"]) === false)
            			response(500, __("response_went_wrong"));

        			response(301, __("response_language_updated"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "edit.waserver":
				if(!permission("manage_waservers"))
					response(500, __("response_no_permission"));

				if(!isset($request["name"], $request["accounts"], $request["packages"], $request["url"], $request["port"], $request["secret"]))
					response(500, __("response_invalid"));

				if(!$this->sanitize->isInt($request["accounts"]))
					response(500, __("response_invalid"));

				if(!is_array($request["packages"]))
					response(500, __("requests_waserver_selectatleastonepack"));

				$request["url"] = rtrim($request["url"], "/");

				$filtered = [
					"secret" => $request["secret"],
					"name" => $request["name"],
					"url" => $request["url"],
					"port" => $request["port"],
					"accounts" => $request["accounts"],
					"packages" => implode(",", $request["packages"])
				];

				if($this->system->update($request["id"], false, "wa_servers", $filtered)):
					$this->cache->container("system.waservers");
					$this->cache->clear();

        			response(200, __("requests_waserver_updatedsuccess"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "edit.gateway":
				if(!permission("manage_gateways"))
					response(500, __("response_no_permission"));

	            if(!isset($request["name"], $request["callback"], $request["content"], $_FILES["controller"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->isInt($request["callback"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

            	$filtered = [
            		"name" => $request["name"],
            		"callback" => $request["callback"] < 2 ? 1 : 2,
            		"pricing" => $request["content"]
            	];

            	if($this->system->update($request["id"], false, "gateways", $filtered)):
            		try {
	        			$this->upload->upload($_FILES["controller"]);
	        			
		    			if($this->upload->uploaded):
		    				if(!in_array($this->upload->file_src_name_ext, ["php"]))
		    					response(500, __("response_gateway_invalidcontroller"));

			                $this->upload->file_new_name_body = md5($request["id"]);
			                $this->upload->file_new_name_ext = "php";
			                $this->upload->file_overwrite = true;
			                $this->upload->process("system/gateways/");

							if($this->upload->processed)
								$this->upload->clean();
							else
								response(500, __("response_gateway_invalidcontroller"));
						endif;
	        		} catch(Exception $e){
	        			response(500, __("response_gateway_invalidcontroller"));
	        		}

        			response(200, __("response_gateway_updatedsuccess"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "edit.shortener":
				if(!permission("manage_shorteners"))
					response(500, __("response_no_permission"));

	            if(!isset($request["name"], $_FILES["controller"]))
	            	response(500, __("response_invalid"));

	            if(!$this->sanitize->length($request["name"]))
					response(500, __("response_name_short"));

            	$filtered = [
            		"name" => $request["name"]
            	];

            	if($this->system->update($request["id"], false, "shorteners", $filtered)):
            		try {
	        			$this->upload->upload($_FILES["controller"]);
	        			
		    			if($this->upload->uploaded):
		    				if(!in_array($this->upload->file_src_name_ext, ["php"]))
		    					response(500, __("response_shortener_invalidcontroller"));

			                $this->upload->file_new_name_body = md5($request["id"]);
			                $this->upload->file_new_name_ext = "php";
			                $this->upload->file_overwrite = true;
			                $this->upload->process("system/shorteners/");

							if($this->upload->processed)
								$this->upload->clean();
							else
								response(500, __("response_shortener_invalidcontroller"));
						endif;
	        		} catch(Exception $e){
	        			response(500, __("response_shortener_invalidcontroller"));
	        		}

        			response(200, __("response_shortener_editsuccess"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "edit.plugin":
				if(!permission("manage_plugins"))
					response(500, __("response_no_permission"));

				foreach($request as $key => $value):
					if($key != "id")
						$pluginData[$key] = $value;
				endforeach;

				$plugin = $this->system->getPlugin($request["id"], "id");

				$decode = json_decode($plugin["data"], true);

            	$filtered = [
            		"data" => json_encode($pluginData)
            	];

            	if($this->system->update($request["id"], false, "plugins", $filtered)):
            		$this->cache->container("system.plugins");
            		$this->cache->clear();

        			response(200, __("response_editplugin_success"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "update.plugin":
				if(!permission("manage_plugins"))
					response(500, __("response_no_permission"));

				if(!isset($_FILES["plugin"]))
        			response(500, __("response_invalid"));

	            $this->upload->upload($_FILES["plugin"]);
    			if($this->upload->uploaded):
    				if(!in_array($this->upload->file_src_name_ext, ["zip"]))
	    				response(500, __("response_invalid"));

	                $this->upload->file_new_name_body = "plugin";
	                $this->upload->file_overwrite = true;
	                $this->upload->process("uploads/temporary/");

					if($this->upload->processed)
						$this->upload->clean();
				endif;

				if(!$this->file->exists("uploads/temporary/plugin.zip"))
					response(500, __("response_pluginwentwrong"));

				$pluginFolder = false;

				try {
					$zip = new \PhpZip\ZipFile();
					
					// Extract to tmp directory (Linux filesystem) to avoid permission issues
					$tmpExtractPath = "/tmp/plugin_extract_" . uniqid();
					
					// Create the temporary directory first
					if (!mkdir($tmpExtractPath, 0755, true)) {
						response(500, __("response_went_wrong"));
					}
					
					$zip->openFile("uploads/temporary/plugin.zip")->extractTo($tmpExtractPath); 
					$zipItems = $zip->getListFiles();

					foreach($zipItems as $item):
						if($zip->getEntry($item)->isDirectory()):
							$pluginFolder = rtrim($item, "/");

							// The name comes straight out of the archive and is then
							// interpolated into filesystem paths that are passed to
							// rmrf(), mkdir() and the copy target. A crafted entry such
							// as "../../uploads" would delete or overwrite files well
							// outside system/plugins/installables/, so require a plain
							// directory name with no separators or traversal.
							if(!preg_match('/^[A-Za-z0-9._-]+$/', $pluginFolder)
								|| $pluginFolder === "." || $pluginFolder === ".."):
								$zip->close();
								rmrf($tmpExtractPath);
								response(500, __("response_pluginwentwrong"));
							endif;

							break;
						else:
							response(500, __("response_pluginwentwrong"));
						endif;
					endforeach;

					$zip->close();

					if(!$pluginFolder) {
						// Clean up tmp directory
						rmrf($tmpExtractPath);
						response(500, __("response_pluginwentwrong"));
					}

					// Remove existing plugin folder if it exists (for update)
					if(file_exists("system/plugins/installables/{$pluginFolder}")) {
						rmrf("system/plugins/installables/{$pluginFolder}");
					}

					// Copy the plugin folder from tmp to final location
					// Create destination directory
					if(!mkdir("system/plugins/installables/{$pluginFolder}", 0755, true)) {
						rmrf($tmpExtractPath);
						response(500, __("response_went_wrong"));
					}
					
					// Recursively copy files
					$iterator = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator("{$tmpExtractPath}/{$pluginFolder}", RecursiveDirectoryIterator::SKIP_DOTS),
						RecursiveIteratorIterator::SELF_FIRST
					);
					
					foreach ($iterator as $item) {
						$target = "system/plugins/installables/{$pluginFolder}" . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
						if ($item->isDir()) {
							if(!mkdir($target, 0755, true)) {
								rmrf($tmpExtractPath);
								response(500, __("response_went_wrong"));
							}
						} else {
							if(!copy($item, $target)) {
								rmrf($tmpExtractPath);
								response(500, __("response_went_wrong"));
							}
						}
					}

					// Clean up tmp directory
					rmrf($tmpExtractPath);

					if(!$this->file->exists("system/plugins/installables/{$pluginFolder}/plugin.json"))
						response(500, __("response_pluginwentwrong"));
				} catch(Exception $e){
					$this->file->put("system/storage/temporary/error.log", "Plugin Install Error: {$e->getMessage()} (" . date("m/d/Y g:i A") . ")\n\n", FILE_APPEND);
					response(500, __("response_pluginwentwrong"));
				}

				$pluginData = json_decode($this->file->get("system/plugins/installables/{$pluginFolder}/plugin.json"), true);

				if($this->system->checkPlugin($pluginData["directory"]) < 1)
            		response(500, __("response_invalid"));

				$pluginDataArray = [];

				foreach($pluginData["data"] as $key => $value):
					$pluginDataArray[$key] = $value["value"];
				endforeach;

				$oldPlugin = $this->system->getPlugin($pluginData["directory"], "directory");

				$oldPluginData = json_decode($oldPlugin["data"], true);

				foreach($oldPluginData as $key => $value):
					if(isset($pluginDataArray[$key])):
						$pluginDataArray[$key] = $value;
					endif;
				endforeach;

            	$filtered = [
            		"name" => $pluginData["name"],
            		"data" => json_encode($pluginDataArray)
            	];

            	if($this->system->update($request["id"], false, "plugins", $filtered)):
            		$this->cache->container("system.plugins");
            		$this->cache->clear();

					try {
						$this->file->delete("uploads/temporary/plugin.zip");
					} catch(Exception $e){
						// Ignore
					}

        			response(200, __("response_updateplugin_updated"));
        		else:
        			response(500, __("response_went_wrong"));
        		endif;

				break;
			case "admin.suspend":
				if(!permission("manage_users"))
					response(500, __("response_no_permission"));

				if($request["id"] < 2)
					response(500, __("response_adminsuspend"));

				if($this->system->checkUser($request["id"]) > 0):
					if($this->system->update($request["id"], false, "users", [
						"suspended" => 1
					])):
	        			response(200, __("response_successsuspend"));
	        		else:
	        			response(500, __("response_went_wrong"));
	        		endif;
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "admin.unsuspend":
				if(!permission("manage_users"))
					response(500, __("response_no_permission"));

				if($request["id"] < 2)
					response(500, __("response_invalid"));

				if($this->system->checkUser($request["id"]) > 0):
					if($this->system->update($request["id"], false, "users", [
						"suspended" => 0
					])):
	        			response(200, __("response_successunsuspend"));
	        		else:
	        			response(500, __("response_went_wrong"));
	        		endif;
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "admin.theme":
				if(!super_admin)
					response(500, __("response_no_permission"));

				if(isset($_FILES["logo_light_img"])):
        			$this->upload->upload($_FILES["logo_light_img"]);
        			if($this->upload->uploaded):
        				$this->upload->allowed = [
        					"image/*"
        				];
		                $this->upload->file_new_name_body = "logo-light";
		                $this->upload->image_convert = "png";
		                $this->upload->file_overwrite = true;
		                $this->upload->process("uploads/theme/");

						if($this->upload->processed):
							$this->upload->clean();
						else:
							response(500, __("response_theme_sitelogofailed"));
						endif;
					endif;
	            endif;

	            if(isset($_FILES["logo_dark_img"])):
        			$this->upload->upload($_FILES["logo_dark_img"]);
        			if($this->upload->uploaded):
        				$this->upload->allowed = [
        					"image/*"
        				];
		                $this->upload->file_new_name_body = "logo-dark";
		                $this->upload->image_convert = "png";
		                $this->upload->file_overwrite = true;
		                $this->upload->process("uploads/theme/");

						if($this->upload->processed)
							$this->upload->clean();
						else
							response(500, __("response_theme_sitelogofailed"));
					endif;
	            endif;

	            if(isset($_FILES["bg_img"])):
        			$this->upload->upload($_FILES["bg_img"]);
        			if($this->upload->uploaded):
        				$this->upload->allowed = [
        					"image/*"
        				];
		                $this->upload->file_new_name_body = "bg";
		                $this->upload->image_convert = "png";
		                $this->upload->file_overwrite = true;
		                $this->upload->process("uploads/theme/");

						if($this->upload->processed):
							$this->upload->clean();
						else:
							response(500, __("response_theme_sitebgfailed"));
						endif;
					endif;
	            endif;

	            if(isset($_FILES["favicon_img"])):
        			$this->upload->upload($_FILES["favicon_img"]);
        			if($this->upload->uploaded):
        				$this->upload->allowed = [
        					"image/*"
        				];
		                $this->upload->file_new_name_body = "favicon";
		                $this->upload->image_convert = "png";
		                $this->upload->image_resize = true;
						$this->upload->image_x = 50;
						$this->upload->image_ratio_y = true;
		                $this->upload->file_overwrite = true;
		                $this->upload->process("uploads/theme/");

						if($this->upload->processed):
							$this->upload->clean();
						else:
							response(500, __("response_theme_sitefavfailed"));
						endif;
					endif;
	            endif;

	            try {
	            	$script = <<<JAVASCRIPT
window._customSystem = {
	hookOnload: () => {
		{$request["script"]}
	},

	hookOnloaded: () => {
		{$request["script"]}
	}
}
JAVASCRIPT;

					$this->file->put("templates/_assets/js/custom.run.js", $script);
					$this->file->put("templates/_assets/js/custom.js", $request["script"]);
					$this->file->put("templates/_assets/css/custom.css", $request["css"]);
	            } catch(Exception $e){
					response(500, __("response_went_wrong"));
	            }

				try {
	            	$this->scss->setVariables([
					    "theme" => $request["theme_background"],
					    "themeText" => $request["theme_highlight"],
					    "themeSpinner" => $request["theme_spinner"]
					]);
					
					$this->scss->setFormatter("ScssPhp\ScssPhp\Formatter\Crunched");
					$this->scss->setImportPaths("templates/_scss/");

			// Relative to templates/dashboard/assets/css/, so the compiled stylesheet is
					// correct for any domain, port or subdirectory. Using an absolute URL here
					// baked whichever host last saved the theme into a tracked file, and that
					// value then shipped to every customer.
					$bgRelative = file_exists("uploads/theme/bg.png")
						? "../../../../uploads/theme/bg.png"
						: "../../../../uploads/theme/default-bg.jpg";

					$this->file->put("templates/dashboard/assets/css/style.min.css", $this->scss->compile("
						.navbar-dark.navbar-vibrant {
							background-image: linear-gradient(to bottom right,rgba(" . system_theme_background . ",.4),rgba(" . system_theme_background . ",.9)), url(" . $bgRelative . ") !important;
						}
						@import 'dashboard.scss';
					"));
					$this->file->put("templates/default/assets/css/style.min.css", $this->scss->compile("@import 'default.scss';"));

					$this->file->put("templates/dashboard/assets/css/style.rtl.min.css", $this->scss->compile("
						.navbar-dark.navbar-vibrant {
							background-image: linear-gradient(to bottom right,rgba(" . system_theme_background . ",.4),rgba(" . system_theme_background . ",.9)), url(" . $bgRelative . ") !important;
						}
						@import 'dashboard.rtl.scss';
					"));
					$this->file->put("templates/default/assets/css/style.rtl.min.css", $this->scss->compile("@import 'default.rtl.scss';"));
	            } catch(Exception $e){
	            	response(500, __("response_went_wrong"));
	            }

	            foreach($request as $key => $value):
            		$this->system->settings($key, $value);
	            endforeach;

	            $this->cache->container("system.settings");
	            $this->cache->clear();

    			response(301, __("response_theme_updated"));

				break;
			case "admin.gateway":
				if(!super_admin)
					response(500, __("response_no_permission"));

				if(isset($_FILES["apk_file"])):
					try {
						$upload = $_FILES["apk_file"];
						if($upload["error"] === UPLOAD_ERR_OK):
							$fileExt = pathinfo($upload["name"], PATHINFO_EXTENSION);
							if(!in_array($fileExt, ["apk"]))
								response(500, __("response_admin_gateway_invalidapkfile"));

							if(!preg_match('/_v(\d+\.\d+\.\d+)\.apk$/', $upload["name"], $matches))
								response(500, __("response_admin_gateway_invalidapkfilename"));

							$version = $matches[1];
							$uploadDir = "uploads/builder/";
							$uploadPath = $uploadDir . "gateway.apk";

							if(!move_uploaded_file($upload["tmp_name"], $uploadPath)):
								response(500, __("response_admin_gateway_faileduploadapk"));
							endif;

							$this->system->settings("apk_version", $version);
						else:
							response(500, __("response_admin_gateway_faileduploadapk"));
						endif;
					} catch(Exception $e) {
						response(500, __("response_admin_gateway_faileduploadapk"));
					}
				endif;

				if(isset($_FILES["firebase"])):
					try {
						$upload = $_FILES["firebase"];
						if($upload["error"] === UPLOAD_ERR_OK):
							$fileExt = pathinfo($upload["name"], PATHINFO_EXTENSION);
							if(!in_array($fileExt, ["json"]))
								response(500, __("response_builder_invalidfirebasefile"));

							$uploadDir = "system/storage/temporary/";
							$uploadPath = $uploadDir . "firebase." . $fileExt;

							if(!move_uploaded_file($upload["tmp_name"], $uploadPath)):
								response(500, __("response_builder_invalidfirebasefile"));
							endif;
						else:
							response(500, __("response_builder_invalidfirebasefile"));
						endif;
					} catch(Exception $e) {
						response(500, __("response_builder_invalidfirebasefile"));
					}
				endif;

				// The client half of Firebase. The file above is the service
				// account, which authorises this server to SEND pushes; this one
				// is what the gateway app needs to REGISTER for a token. It used
				// to be compiled into a per-customer APK build, which is why one
				// prebuilt app could not serve every site.
				if(isset($_FILES["google"])):
					try {
						$upload = $_FILES["google"];

						if($upload["error"] !== UPLOAD_ERR_OK)
							response(500, __("response_builder_invalidgooglefile"));

						if(pathinfo($upload["name"], PATHINFO_EXTENSION) !== "json")
							response(500, __("response_builder_invalidgooglefile"));

						// Validate before storing. A file that parses but lacks the
						// four values is useless to the app, and rejecting it here
						// is the difference between an error the admin can act on
						// and a device that pairs and never receives anything.
						$parsed = json_decode(file_get_contents($upload["tmp_name"]), true);

						if(!is_array($parsed)
							|| !isset($parsed["project_info"]["project_id"])
							|| !isset($parsed["project_info"]["project_number"])
							|| !isset($parsed["client"][0]["client_info"]["mobilesdk_app_id"])
							|| !isset($parsed["client"][0]["api_key"][0]["current_key"]))
							response(500, __("response_builder_invalidgooglefile"));

						if(!move_uploaded_file($upload["tmp_name"], "system/storage/temporary/google-services.json"))
							response(500, __("response_builder_invalidgooglefile"));
					} catch(Exception $e) {
						response(500, __("response_builder_invalidgooglefile"));
					}
				endif;

				$this->cache->container("system.settings");
				$this->cache->clear();

				response(301, __("response_admin_gateway_updatesuccess"));

				break;
			case "admin.settings":
				if(!super_admin)
					response(500, __("response_no_permission"));

				if(isset($request["admin_api"])):
					if(!is_array($request["admin_api"]))
						response(500, __("response_invalid"));

					if(empty($request["admin_api"]))
						response(500, __("response_invalid"));

					$request["admin_api"] = implode(",", $request["admin_api"]);
				endif;

				if(isset($request["mailing_triggers"])):
					if(!is_array($request["mailing_triggers"]))
						response(500, __("response_invalid"));

					if(empty($request["mailing_triggers"]))
						response(500, __("response_invalid"));

					$request["mailing_triggers"] = implode(",", $request["mailing_triggers"]);
				endif;

				if(isset($request["providers"])):
					if(!is_array($request["providers"]))
						response(500, __("response_invalid"));

					if(empty($request["providers"]))
						response(500, __("response_leastprovider"));

					foreach($request["providers"] as $provider):
						if(!in_array($provider, ["paypal", "mollie", "bank"]))
							response(500, __("response_invalid"));
					endforeach;

					$request["providers"] = implode(",", $request["providers"]);
				else:
					$request["providers"] = false;
				endif;

				if(isset($request["social_platforms"])):
					if(!is_array($request["social_platforms"]))
						response(500, __("response_invalid"));

					if(empty($request["social_platforms"]))
						response(500, __("response_minplatforms"));

					foreach($request["social_platforms"] as $provider):
						if(!in_array($provider, ["facebook", "google"]))
							response(500, __("response_invalid"));
					endforeach;

					$request["social_platforms"] = implode(",", $request["social_platforms"]);
				else:
					$request["social_platforms"] = false;
				endif;

				if(isset($request["partner_commission"]) && ($request["partner_commission"] < 1 || $request["partner_commission"] > 80)):
					response(500, __("response_partners_commisionerror"));
				endif;

				if(!in_array($request["currency"], ["AED", "AFN", "ALL", "AMD", "ANG", "AOA", "ARS", "AUD", "AWG", "AZN", "BAM", "BBD", "BDT", "BGN", "BHD", "BIF", "BMD", "BND", "BOB", "BRL", "BSD", "BTC", "BTN", "BWP", "BYN", "BZD", "CAD", "CDF", "CHF", "CLF", "CLP", "CNH", "CNY", "COP", "CRC", "CUC", "CUP", "CVE", "CZK", "DJF", "DKK", "DOP", "DZD", "EGP", "ERN", "ETB", "EUR", "FJD", "FKP", "GBP", "GEL", "GGP", "GHS", "GIP", "GMD", "GNF", "GTQ", "GYD", "HKD", "HNL", "HRK", "HTG", "HUF", "IDR", "ILS", "IMP", "INR", "IQD", "IRR", "ISK", "JEP", "JMD", "JOD", "JPY", "KES", "KGS", "KHR", "KMF", "KPW", "KRW", "KWD", "KYD", "KZT", "LAK", "LBP", "LKR", "LRD", "LSL", "LYD", "MAD", "MDL", "MGA", "MKD", "MMK", "MNT", "MOP", "MRO", "MRU", "MUR", "MVR", "MWK", "MXN", "MYR", "MZN", "NAD", "NGN", "NIO", "NOK", "NPR", "NZD", "OMR", "PAB", "PEN", "PGK", "PHP", "PKR", "PLN", "PYG", "QAR", "RON", "RSD", "RUB", "RWF", "SAR", "SBD", "SCR", "SDG", "SEK", "SGD", "SHP", "SLL", "SOS", "SRD", "SSP", "STD", "STN", "SVC", "SYP", "SZL", "THB", "TJS", "TMT", "TND", "TOP", "TRY", "TTD", "TWD", "TZS", "UAH", "UGX", "USD", "UYU", "UZS", "VES", "VND", "VUV", "WST", "XAF", "XAG", "XAU", "XCD", "XDR", "XOF", "XPD", "XPF", "XPT", "YER", "ZAR", "ZMW", "ZWL"])):
					response(500, __("response_invalid"));
				endif;

				$request["tawk_id"] = str_replace("https://tawk.to/chat/", "", $request["tawk_id"]);

				if(isset($request["realtime_driver"]) && !in_array($request["realtime_driver"], ["pusher", "compatible"]))
					response(500, __("response_invalid"));

				// The modal never echoes the realtime credentials back, so an
				// untouched field arrives empty. Writing that through would
				// silently wipe a working configuration.
				foreach(["realtime_key", "realtime_secret"] as $credential):
					if(isset($request[$credential]) && $request[$credential] === "")
						unset($request[$credential]);
				endforeach;

	            foreach($request as $key => $value):
            		$this->system->settings($key, $value);
	            endforeach;

	            $this->cache->container("system.settings");
	            $this->cache->clear();

    			response(301, __("response_system_settingsupdated"));

				break;
        	default:
        		response(500, __("response_invalid"));
        endswitch;
	}

	public function delete()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
            response(302);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        $this->cache->container("system.plugins");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getPlugins());
        endif;

        set_plugins($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language);

        $type = $this->sanitize->string($this->url->segment(4));
        $id = $this->sanitize->string($this->url->segment(5));

        if(!$this->sanitize->isInt($id))
        	response(500, __("response_invalid"));

		switch($type):
			case "sent":
				$sent = $this->system->getSent($id);

				$device = $this->system->getDevice(logged_id, $sent["did"], "did");

				if($this->system->delete(logged_id, $id, "sent")):
					try {
						if($sent["status"] < 3):
							if($device):
								$this->fcm->sendWithToken($device["fcm_token"], [
									"action" => "message_delete",
									"message_id" => $id
								]);
							endif;
						endif;
					} catch(Exception $e){
						// Ignore
					}

					$vars = [
						"message" => __("response_deleted_sent")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "sms.campaign":
				$campaign = $this->system->getSmsCampaign(logged_id, $id);
				
				if($this->system->delete(logged_id, $id, "campaigns")):
					if($this->system->clearCampaignSms(logged_id, $id)):
						$device = $this->system->getDevice(logged_id, $campaign["did"], "did");

						$this->fcm->sendWithToken($device["fcm_token"], [
							"action" => "campaign_delete",
							"campaign_id" => $id
						]);
					endif;

					$vars = [
						"message" => __("response_delete_smsdeletecampaign")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "received":
				try {
					$received = $this->system->getMessageReceived(logged_id, $id);

					if($this->system->checkDeleted($received["uid"], $received["rid"], $received["did"]) < 1):
						$this->system->create("deleted", [
							"rid" => $received["rid"],
							"uid" => $received["uid"],
							"did" => $received["did"]
						]);
					endif;
				} catch(Exception $e){
					// Ignore
				}

				if($this->system->delete(logged_id, $id, "received")):
					$vars = [
						"message" => __("response_deleted_received")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "scheduled":
				if($this->system->delete(logged_id, $id, "scheduled")):
					$vars = [
						"message" => __("response_scheduled_deleted")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "ussd":
				$ussdCode = $this->system->getUssd($id, logged_id);

				if($this->system->delete(logged_id, $id, "ussd")):
					$device = $this->system->getDevice(logged_id, $ussdCode["did"], "did");

					if($device):
						$this->fcm->sendWithToken($device["fcm_token"], [
							"action" => "ussd_delete",
							"ussd_id" => $id
						]);
					endif;

					$vars = [
						"message" => __("response_ussddelete_success")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "wa.sent":
				try {
					$sent = $this->system->getWaSent($id);
					
					if($sent["priority"] > 1):
						$waServer = $this->system->getWaServer($sent["unique"], "unique");
						
						if($waServer && !$this->wa->check($waServer["url"], $waServer["port"]))
							response(500, __("response_whatsapp_noconnectserver"));

						if($this->system->delete(logged_id, $id, "wa_sent")):
							if($sent["status"] < 3):
								try {
									$this->wa->delete_chat($waServer["secret"], $waServer["url"], $waServer["port"], $sent["unique"], logged_hash, $sent["cid"], $id);
								} catch(Exception $e){
									// Ignore
								}
							endif;
						endif;
					else:
						$this->system->delete(logged_id, $id, "wa_sent");
					endif;
				} catch(Exception $e){
					// Ignore
				}
				
				try {
					$msgDecode = json_decode($sent["message"], true, JSON_THROW_ON_ERROR);
					
					if(!isset($msgDecode["autoreply"])):
						if(isset($msgDecode["image"])):
							$fileUrl = explode("/", $msgDecode["image"]["url"]);
						endif;
	
						if(isset($msgDecode["audio"])):
							$fileUrl = explode("/", $msgDecode["audio"]["url"]);
						endif;
	
						if(isset($msgDecode["video"])):
							$fileUrl = explode("/", $msgDecode["video"]["url"]);
						endif;
	
						if(isset($msgDecode["document"])):
							$fileUrl = explode("/", $msgDecode["document"]["url"]);
						endif;
						
						if(isset($fileUrl)):
							$this->file->delete("uploads/whatsapp/sent/" . logged_id . "/" . end($fileUrl));
						endif;
					endif;
				} catch(Exception $e){
					// Ignore
				}

				$vars = [
					"message" => __("response_whatsapp_sentdeletesuccess")
				];

				break;
			case "wa.campaign":
				$waServer = $this->system->getWaServer($id, "campaign_id");

				if($waServer && !$this->wa->check($waServer["url"], $waServer["port"]))
					response(500, __("response_whatsapp_noconnectserver"));

				$campaign = $this->system->getWaCampaign(logged_id, $id, "id");

				if($this->system->delete(logged_id, $id, "wa_campaigns")):
					if($this->system->clearCampaignChats(logged_id, $id)):
						try {
							$this->wa->delete_campaign($waServer["secret"], $waServer["url"], $waServer["port"], $campaign["unique"], logged_hash, $id);
						} catch(Exception $e){
							// Ignore
						}
					endif;

					$vars = [
						"message" => __("response_delete_wacampaigndeleted")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "wa.received":
				$received = $this->system->getWaReceived($id);

				if($this->system->delete(logged_id, $id, "wa_received")):
					if($received):
						$fileName = checkFile($received["id"], "uploads/whatsapp/received/{$received["unique"]}");

						if($fileName):
							$this->file->delete("uploads/whatsapp/received/{$received["unique"]}/{$fileName}");
						endif;
					endif;

					$vars = [
						"message" => __("response_whatsapp_receivedeletesuccess")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "wa.scheduled":
				$scheduled = $this->system->getWaScheduled($id);

				if($this->system->delete(logged_id, $id, "wa_scheduled")):
					try {
						$msgDecode = json_decode($scheduled["message"], true, JSON_THROW_ON_ERROR);
						
						if(!isset($msgDecode["autoreply"])):
							if(isset($msgDecode["image"])):
								$fileUrl = explode("/", $msgDecode["image"]["url"]);
							endif;
		
							if(isset($msgDecode["audio"])):
								$fileUrl = explode("/", $msgDecode["audio"]["url"]);
							endif;
		
							if(isset($msgDecode["video"])):
								$fileUrl = explode("/", $msgDecode["video"]["url"]);
							endif;
		
							if(isset($msgDecode["document"])):
								$fileUrl = explode("/", $msgDecode["document"]["url"]);
							endif;
							
							if(isset($fileUrl)):
								$this->file->delete("uploads/whatsapp/sent/" . logged_id . "/" . end($fileUrl));
							endif;
						endif;
					} catch(Exception $e){
						// Ignore
					}

					$vars = [
						"message" => __("response_whatsapp_scheduleddeletesuccess")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "wa.group":
				if($this->system->delete(logged_id, $id, "wa_groups")):
					$this->cache->container("wa.contacts." . logged_hash);
					$this->cache->clear();

					$vars = [
						"message" => __("response_delete_wagroupdeleted")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "wa.account":
				$account = $this->system->getWaAccount(logged_id, $id, "id");

				if($account):
					$waServer = $this->system->getWaServer($account["wsid"], "id");

					if($this->system->delete(logged_id, $id, "wa_accounts")):
						try {
							$this->wa->delete($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"]);
						} catch(Exception $e){
							// Ignore
						}

						$vars = [
							"message" => __("response_whatsapp_accountdeletesuccess")
						];
					else:
						response(500, __("response_went_wrong"));
					endif;
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "contacts":
				if($this->system->delete(logged_id, $id, "contacts")):
					$this->cache->container("autocomplete.contacts." . logged_hash);
	                $this->cache->clear();
	                $this->cache->container("contacts." . logged_hash);
	                $this->cache->clear();
	                $this->cache->container("user." . logged_hash);
	                $this->cache->clear();

					$vars = [
						"message" => __("response_deleted_contact")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "groups":
				if($this->system->delete(logged_id, $id, "groups")):
					$contacts = $this->system->getContactsByGroup(logged_id, $id);

					if(!empty($contacts)):
						foreach($contacts as $contact):
							$this->system->delete(logged_id, $contact["id"], "contacts");
						endforeach;
					endif;

					$vars = [
						"message" => __("response_deleted_group")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "unsubscribed":
				if($this->system->delete(logged_id, $id, "unsubscribed")):
					$vars = [
						"message" => __("response_unsibscribe_contactdeletedsuccess")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "devices":
				$device = $this->system->getDevice(logged_id, $id, "id");

				if($this->system->delete(logged_id, $id, "devices")):
					$this->cache->container("user." . logged_hash);
					$this->cache->clear();

					$this->fcm->sendWithToken($device["fcm_token"], [
				    	"action" => "device_delete"
				    ]);

					$vars = [
						"message" => __("response_deleted_device")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "keys":
				if($this->system->delete(logged_id, $id, "keys")):
					$this->cache->container("user." . logged_hash);
					$this->cache->clear();

					$vars = [
						"message" => __("response_deleted_key")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "webhooks":
				if($this->system->delete(logged_id, $id, "webhooks")):
					$vars = [
						"message" => __("response_deleted_hook")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "actions":
				$action = $this->system->getAction($id);

				if($this->system->delete(logged_id, $id, "actions")):
					try {
						$msgDecode = json_decode($action["message"], true, JSON_THROW_ON_ERROR);
						
						if(!isset($msgDecode["autoreply"])):
							if(isset($msgDecode["image"])):
								$fileUrl = explode("/", $msgDecode["image"]["url"]);
							endif;
		
							if(isset($msgDecode["audio"])):
								$fileUrl = explode("/", $msgDecode["audio"]["url"]);
							endif;
		
							if(isset($msgDecode["video"])):
								$fileUrl = explode("/", $msgDecode["video"]["url"]);
							endif;
		
							if(isset($msgDecode["document"])):
								$fileUrl = explode("/", $msgDecode["document"]["url"]);
							endif;
							
							if(isset($fileUrl)):
								$this->file->delete("uploads/whatsapp/actions/{$action["account"]}/" . end($fileUrl));
							endif;
						endif;
					} catch(Exception $e){
						// Ignore
					}

					$vars = [
						"message" => __("response_action_deleted_2025")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "templates":
				if($this->system->delete(logged_id, $id, "templates")):
					$vars = [
						"message" => __("response_deleted_template")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "flows":
				if($this->system->delete(logged_id, $id, "flows")):
					$vars = [
						"message" => __("response_flow_deleted")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "ai.keys":
				if($this->system->delete(logged_id, $id, "ai_keys")):
					$vars = [
						"message" => __("response_aikey_deleted")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "ai.plugins":
				if($this->system->delete(logged_id, $id, "ai_plugins")):
					$vars = [
						"message" => __("response_aiplugin_deleted")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "users":
				if(!permission("manage_users"))
					response(500, __("response_no_permission"));

				if($id < 2)
					response(500, __("response_deleted_defaultuserfalse"));

				if($this->system->delete(false, $id, "users")):
					$this->system->deleteUserData($id);
					
					$this->cache->container("system.users");
        			$this->cache->clear();

					$vars = [
						"message" => __("response_deleted_user")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "roles":
				if(!permission("manage_roles"))
					response(500, __("response_no_permission"));

				if($id < 2)
					response(500, __("role_default_delete"));

				if($this->system->delete(false, $id, "roles")):
					$vars = [
						"message" => __("role_deleted")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "packages":
				if(!permission("manage_packages"))
					response(500, __("response_no_permission"));

				if($id < 2)
					response(500, __("response_deleted_defaultpackagefalse"));

				if($this->system->delete(false, $id, "packages")):
					foreach($this->system->getUsers() as $user):
    					$this->cache->container("user.{$user["hash"]}");
						$this->cache->clear();
        			endforeach;

					$this->cache->container("system.packages");
            		$this->cache->clear();

					$vars = [
						"message" => __("response_deleted_package")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "vouchers":
				if(!permission("manage_vouchers"))
					response(500, __("response_no_permission"));

				if($this->system->delete(false, $id, "vouchers")):
					$vars = [
						"message" => __("voucher_deleted")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "subscriptions":
				if(!permission("manage_subscriptions"))
					response(500, __("response_no_permission"));

				if($this->system->delete(false, $id, "subscriptions")):
					$vars = [
						"message" => __("response_deleted_subscription")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "pages":
				if(!permission("manage_pages"))
					response(500, __("response_no_permission"));

				if($this->system->delete(false, $id, "pages")):
					$this->cache->container("system.pages");
            		$this->cache->clear();

					$vars = [
						"message" => __("response_page_deleted")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "marketing":
				if(!permission("manage_marketing"))
					response(500, __("response_no_permission"));

				if($this->system->delete(false, $id, "marketing")):
					$vars = [
						"message" => __("response_marketing_logdeletedsuccess")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "languages":
				if(!permission("manage_languages"))
					response(500, __("response_no_permission"));

				if($id < 2)
					response(500, __("response_deleted_defaultlangfalse"));
				
				if($this->system->delete(false, $id, "languages")):
					$this->cache->container("system.languages");
            		$this->cache->clear();
            		
					$this->file->delete("system/languages/" . md5($id) . ".lang");

					$vars = [
						"message" => __("response_deleted_language")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "wa.servers":
				if(!permission("manage_waservers"))
					response(500, __("response_no_permission"));
				
				if($this->system->delete(false, $id, "wa_servers")):
					// delete whatsapp accounts
					// delete messages

					$vars = [
						"message" => __("requests_waserver_deletedsuccess")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "gateways":
				if(!permission("manage_gateways"))
					response(500, __("response_no_permission"));

				if($this->system->delete(false, $id, "gateways")):
					$vars = [
						"message" => __("response_gateway_controllerdeletedsuccess")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "shorteners":
				if(!permission("manage_shorteners"))
					response(500, __("response_no_permission"));

				if($this->system->delete(false, $id, "shorteners")):
					$vars = [
						"message" => __("response_shortener_controllerdeletedsuccess")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			case "plugins":
				if(!permission("manage_plugins"))
					response(500, __("response_no_permission"));
				
				$plugin = $this->system->getPlugin($id, "id");

				if($plugin && $this->system->delete(false, $id, "plugins")):
					$pluginData = json_decode($this->file->get("system/plugins/installables/{$plugin["directory"]}/plugin.json"), true);

					if(array_key_exists("setup", $pluginData)):
						if(isset($pluginData["setup"]["uninstall"])):
							try {
								$this->guzzle->get(site_url("plugin?name={$pluginData["directory"]}&action={$pluginData["setup"]["uninstall"]["action"]}&token=" . system_token, true), [
									"connect_timeout" => 30,
									"timeout" => 30,
									"allow_redirects" => true,
									"http_errors" => false
								]);
							} catch(Exception $e){
								$this->file->put("system/storage/temporary/error.log", "Plugin Install Error: {$e->getMessage()} (" . date("m/d/Y g:i A") . ")\n\n", FILE_APPEND);
							}
						endif;
					endif;

					try {
						rmrf("system/plugins/installables/{$plugin["directory"]}");
					} catch(Exception $e){
						// Ignore
					}

					$vars = [
						"message" => __("requests_delete_pluginssuccess")
					];
				else:
					response(500, __("response_went_wrong"));
				endif;

				break;
			default:
				response(500, __("response_invalid"));
		endswitch;

		response(200, $vars["message"]);
	}
}