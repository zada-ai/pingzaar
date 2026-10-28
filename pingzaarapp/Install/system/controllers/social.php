<?php

class Social_Controller extends MVC_Controller
{
	public function index()
	{
		if($this->session->has("logged"))
            $this->header->redirect(site_url("dashboard"));

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

		set_template("dashboard");

		set_logged($this->session->get("logged"));

        set_language(logged_language, $this->system->isLanguageRtl(logged_language));

		$this->cache->container("system.plugins");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getPlugins());
        endif;

        set_plugins($this->cache->getAll());

        $platform = ($this->sanitize->string($this->url->segment(3)) ?: "facebook");

		if(!in_array($platform, explode(",", system_social_platforms))):
			$this->socialError(__("page_socialerror_authwentwrong"));
		endif;

        switch($platform):
        	case "facebook":
    			$config = [
				   	"callback" => site_url("social/facebook", true),
				   	"scope" => "email",
				    "keys" => [
				    	"id" => system_facebook_id, 
				    	"secret" => system_facebook_secret
				    ]
				];

				try {
					$adapter = new Hybridauth\Provider\Facebook($config);
			        $adapter->authenticate();
			        $profile = $adapter->getUserProfile();
			    } catch (\Exception $e) {
			        $this->socialError($e->getMessage());
			    }

        		break;
        	case "google":
        		$config = [
				   	"callback" => site_url("social/google", true),
				    "keys" => [
				    	"id" => system_google_id, 
				    	"secret" => system_google_secret
				    ]
				];

				try {
					$adapter = new Hybridauth\Provider\Google($config);
			        $adapter->authenticate();
			        $profile = $adapter->getUserProfile();
			    } catch (\Exception $e) {
			        $this->socialError($e->getMessage());
			    }
        		
        		break;
        	default:
        		$this->header->redirect(site_url);
        endswitch;

        if($this->social->checkIdentifier($profile->identifier) < 1):
        	if(empty($profile->email)):
				$this->socialError(___("page_socialerror_authemailnotfound", [
					$platform
				]));
        	else:
        		if($this->social->checkEmail($profile->email) > 0):
        			$user = $this->social->getUserByEmail($profile->email);

        			if($user["suspended"] > 0):
						$this->socialError(__("page_socialerror_authsuspended"));
        			endif;

        			if(!empty($user["providers"])):
        				$decoded = json_decode($user["providers"], true);
        				$decoded[$platform] = $profile->identifier;

        				$this->social->updateSocial($profile->email, [
        					"providers" => json_encode($decoded)
        				]);
        			else:
        				$this->social->updateSocial($profile->email, [
        					"providers" => json_encode([
        						$platform => $profile->identifier
        					])
        				]);
        			endif;

        			$this->session->set("logged", $user);
        			$this->session->delete("language");
                    $this->header->redirect(site_url("dashboard"));
        		else:
        			$create = $this->system->create("users", [
        				"role" => 1,
        				"email" => $profile->email,
        				"password" => uniqid(system_token, rand(0, 1000)),
        				"name" => $profile->displayName,
                        "credits" => 0,
                        "earnings" => 0,
        				"language" => system_default_lang,
						"theme_color" => system_default_scheme,
        				"providers" => json_encode([
        					$platform => $profile->identifier
        				]),
                        "alertsound" => 1,
                        "timezone" => system_default_timezone,
                        "formatting" => false,
                        "country" => system_default_country,
                        "partner" => 2,
                        "confirmed" => 1,
        				"suspended" => 0
        			]);

        			if($create):
        				if(!empty(system_mailing_address) && in_array("admin_new_user", explode(",", system_mailing_triggers))):
            				$mailingContent = <<<HTML
							<p>Hi there!</p>
							<p>This is to inform you that a new user with email <strong>{$profile->email}</strong> have registered via {$platform}!</p> 
							HTML;

	            			$this->mail->send([
								"title" => system_site_name,
								"data" => [
									"subject" => mail_title("Admin Alert Message from " . system_site_name . "!"),
									"content" => $mailingContent
								]
							], system_mailing_address, "_mail/default.tpl", $this->smarty);
	            		endif;

        				$this->session->set("logged", 
	        				$this->social->getUserById($create)
	        			);

                        $this->header->redirect(site_url("dashboard"));
        			else:
						$this->socialError(__("page_socialerror_authwentwrong"));
        			endif;
        		endif;
        	endif;
        else:
        	$this->session->set("logged", 
        		$this->social->getUserByIdentifier($profile->identifier)
        	);

			$this->session->delete("language");
            $this->header->redirect(site_url("dashboard"));
        endif;
	}

	private function socialError($message){
		$this->smarty->assign([
			"title" => __("page_socialerror_autherrortitle"),
			"page"=> "auth/social.error",
			"message" => $message
		]);

		$this->smarty->display(template . "/header.tpl");
		$this->smarty->display(template . "/pages/errors/social.error.tpl");
		$this->smarty->display(template . "/footer.tpl");

		die;
	}
}