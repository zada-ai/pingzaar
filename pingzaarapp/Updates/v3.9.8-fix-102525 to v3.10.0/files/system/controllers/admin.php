<?php

class Admin_Controller extends MVC_Controller
{
	public function index()
	{
        $this->header->allow();

        if(!$this->session->has("logged"))
            response(401);

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

        if(!is_admin)
        	response(403);

        $countries = \CountryCodes::get("alpha2", "country");

        try {
            $phoneSample = $this->phone->getExampleNumber(logged_country, Brick\PhoneNumber\PhoneNumberType::MOBILE);
            $localPhone = $phoneSample->format(Brick\PhoneNumber\PhoneNumberFormat::NATIONAL);
            $country = $countries[$phoneSample->getRegionCode()];
        } catch(Exception $e){
            $phoneSample = "+63123456789";
            $localPhone = "09123456789";
            $country = $countries["PH"];
        }
        
        renderApiSpec("admin_spec", [
            "site_name" => system_site_name,
            "site_url" => site_url(false, true),
            "phone_number" => $phoneSample,
            "local_phone" => str_replace(" ", "", $localPhone),
            "country" => $country
        ]);
	}

	public function get()
	{
        $this->header->allow();

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        if(in_array("0", explode(",", system_admin_api)))
            response(403, "Admin API is disabled!");

        $request = $this->sanitize->array($_REQUEST);
        $type = $this->sanitize->string($this->url->segment(4));

        if(!isset($request["token"]))
            response(400, "Invalid Parameters!");

        if($request["token"] != system_admin_token)
            response(401, "Invalid system token supplied!");

        switch($type):
            case "users":
                if(!in_array("get_users", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $users = $this->admin->getUsers(abs($page), abs($limit));

                $userArray = [];

                if(!empty($users)):
                    foreach($users as $user):
                        $userArray[] = [
                            "id" => (int) $user["id"],
                            "role" => (int) $user["role"],
                            "credits" => (float) $user["credits"],
                            "earnings" => (float) $user["earnings"],
                            "partner" => (int) $user["partner"] < 2 ? true : false,
                            "email" => $user["email"],
                            "name" => $user["name"],
                            "country" => $user["country"],
                            "timezone" => $user["timezone"],
                            "language" => (int) $user["language"],
                            "notification_sounds" => $user["alertsound"] < 2 ? true : false,
                            "suspended" => $user["suspended"] < 1 ? false : true,
                            "registered" => strtotime($user["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "System Users", $userArray);

                break;
            case "roles":
                if(!in_array("get_roles", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $roles = $this->admin->getRoles(abs($page), abs($limit));

                $roleArray = [];

                if(!empty($roles)):
                    foreach($roles as $role):
                        $permissions = explode(",", $role["permissions"]);
                        $roleArray[] = [
                            "id" => (int) $role["id"],
                            "name" => $role["name"],
                            "permissions" => $role["id"] < 2 ? ["default_permissions"] : (is_array($permissions) ? $permissions : [])
                        ];
                    endforeach;
                endif;

                response(200, "System Roles", $roleArray);

                break;
            case "packages":
                if(!in_array("get_packages", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $packages = $this->admin->getPackages(abs($page), abs($limit));

                $packageArray = [];

                if(!empty($packages)):
                    foreach($packages as $package):
                        $packageArray[] = [
                            "id" => (int) $package["id"],
                            "price" => (int) $package["price"],
                            "hidden" => (int) $package["hidden"] < 2 ? true : false,
                            "footermark" => (int) $package["footermark"] < 2 ? true : false,
                            "services" => $package["services"],
                            "name" => $package["name"],
                            "sms_send_limit" => (int) $package["send_limit"],
                            "sms_receive_limit" => (int) $package["receive_limit"],
                            "ussd_limit" => (int) $package["ussd_limit"],
                            "device_limit" => (int) $package["device_limit"],
                            "wa_send_limit" => (int) $package["wa_send_limit"],
                            "wa_receive_limit" => (int) $package["wa_receive_limit"],
                            "wa_account_limit" => (int) $package["wa_account_limit"],
                            "scheduled_limit" => (int) $package["scheduled_limit"],
                            "key_limit" => (int) $package["key_limit"],
                            "webhook_limit" => (int) $package["webhook_limit"],
                            "action_limit" => (int) $package["action_limit"],
                            "created" => strtotime($package["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "System Packages", $packageArray);

                break;
            case "vouchers":
                if(!in_array("get_vouchers", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $vouchers = $this->admin->getVouchers(abs($page), abs($limit));

                $voucherArray = [];

                if(!empty($vouchers)):
                    foreach($vouchers as $voucher):
                        $voucherArray[] = [
                            "id" => (int) $voucher["id"],
                            "package" => (int) $voucher["package"],
                            "duration" => (int) $voucher["duration"],
                            "name" => $voucher["name"],
                            "code" => $voucher["code"],
                            "created" => strtotime($voucher["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "System Vouchers", $voucherArray);

                break;
            case "subscriptions":
                if(!in_array("get_subscriptions", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $subscriptions = $this->admin->getSubscriptions(abs($page), abs($limit));

                $subscriptionArray = [];

                if(!empty($subscriptions)):
                    foreach($subscriptions as $subscription):
                        $subscriptionArray[] = [
                            "id" => (int) $subscription["id"],
                            "user" => (int) $subscription["uid"],
                            "package" => (int) $subscription["pid"],
                            "transaction" => (int) $subscription["tid"],
                            "created" => strtotime($subscription["date"])
                        ];
                    endforeach;
                endif;

                response(200, "System Subscriptions", $subscriptionArray);

                break;
            case "transactions":
                if(!in_array("get_transactions", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $transactions = $this->admin->getTransactions(abs($page), abs($limit));

                $transactionArray = [];

                if(!empty($transactions)):
                    foreach($transactions as $transaction):
                        $transactionArray[] = [
                            "id" => (int) $transaction["id"],
                            "user" => (int) $transaction["uid"],
                            "package" => (int) $transaction["pid"],
                            "type" => $transaction["type"] == 2 ? "credits" : "package",
                            "price" => (float) $transaction["price"],
                            "currency" => $transaction["currency"],
                            "duration" => (int) $transaction["duration"],
                            "provider" => $transaction["provider"],
                            "created" => strtotime($transaction["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "System Transactions", $transactionArray);

                break;
            case "languages":
                if(!in_array("get_languages", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $languages = $this->admin->getLanguages(abs($page), abs($limit));

                $languageArray = [];

                if(!empty($languages)):
                    foreach($languages as $language):
                        $languageArray[] = [
                            "id" => (int) $language["id"],
                            "order" => (int) $language["order"],
                            "rtl" => $language["rtl"] < 2 ? true : false,
                            "iso" => $language["iso"],
                            "name" => $language["name"]
                        ];
                    endforeach;
                endif;

                response(200, "System Languages", $languageArray);

                break;
            case "apikeys":
                if(!in_array("get_apikeys", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $apikeys = $this->admin->getApikeys(abs($page), abs($limit));

                $apikeyArray = [];

                if(!empty($apikeys)):
                    foreach($apikeys as $apikey):
                        $apikeyArray[] = [
                            "id" => (int) $apikey["id"],
                            "uid" => (int) $apikey["uid"],
                            "name" => $apikey["name"],
                            "permissions" => explode(",", $apikey["permissions"]),
                            "created" => strtotime($apikey["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "System API Keys", $apikeyArray);

                break;
            default:
                response(500, "Invalid API Endpoint!");
        endswitch;
	}

	public function create()
	{
        $this->header->allow();

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        if(in_array("0", explode(",", system_admin_api)))
            response(403, "Admin API is disabled!");

        $request = $this->sanitize->array($_REQUEST);
        $type = $this->sanitize->string($this->url->segment(4));

        if(!isset($request["token"]))
            response(400, "Invalid Parameters!");

        if($request["token"] != system_admin_token)
            response(401, "Invalid system token supplied!");

        switch($type):
            case "user":
                if(!in_array("create_user", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["name"], $request["email"], $request["password"], $request["timezone"], $request["country"], $request["language"]))
                    response(400, "Invalid Parameters!");

                if(isset($request["role"])):
                    if(!$this->sanitize->isInt($request["role"])):
                        response(400, "Invalid Parameters!");
                    endif;
                else:
                    $request["role"] = 1;
                endif;

                if(isset($request["theme"])):
                    if(!in_array($request["theme"], ["light", "dark"])):
                        response(400, "Invalid Parameters!");
                    endif;
                else:
                    $request["theme"] = "light";
                endif;

                if(!$this->sanitize->isInt($request["language"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->length($request["name"]))
                    response(400, "Name is too short!");

                if(!$this->sanitize->isEmail($request["email"]))
                    response(400, "Invalid email address!");

                if(!$this->sanitize->length($request["password"], 5))
                    response(400, "Password is too short!");

                if(!in_array(strtolower($request["timezone"]), $this->timezones->generate()))
                    response(400, "Invalid Parameters!");

                if(!array_key_exists(strtoupper($request["country"]), \CountryCodes::get("alpha2", "country")))
                    response(400, "Invalid Parameters!");

                if($this->system->checkRole($request["role"]) < 1)
                    response(400, "Invalid Parameters!");

                if($this->system->checkLanguage($request["language"]) < 1)
                    response(400, "Invalid Parameters!");

                $filtered = [
                    "timezone" => strtolower($request["timezone"]),
                    "formatting" => false,
                    "country" => strtoupper($request["country"]),
                    "role" => $request["role"],
                    "name" => $request["name"],
                    "language" => $request["language"],
                    "theme_color" => $request["theme"],
                    "email" => $this->sanitize->email($request["email"]),
                    "credits" => isset($request["credits"]) && $this->sanitize->isInt($request["credits"]) ? $request["credits"] : 0,
                    "earnings" => 0,
                    "suspended" => 0,
                    "providers" => false,
                    "alertsound" => 1,
                    "partner" => 2,
                    "confirmed" => 1,
                    "password" => password_hash($request["password"], PASSWORD_DEFAULT)
                ];

                if($this->system->checkEmail($filtered["email"]) < 1):
                    $create = $this->system->create("users", $filtered);
                    if($create):
                        response(200, "User has been created!", [
                            "id" => $create
                        ]);
                    else:
                        response(500, "Something went wrong!");
                    endif;
                else:
                    response(400, "Email address was already used!");
                endif;

                break;
            case "role":
                if(!in_array("create_role", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["name"], $request["permissions"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->length($request["name"]))
                    response(400, "Role name is too short!");

                $permissions = explode(",", $request["permissions"]);

                if(!is_array($permissions))
                    response(400, "Invalid Parameters!");

                if(empty($permissions))
                    response(400, "Invalid Parameters!");

                foreach($permissions as $permission):
                    if(!in_array($permission, [
                        ...restriction_list(),
                        ...permission_list()
                    ])):
                        response(400, "Invalid Parameters!");
                    endif;
                endforeach;

                $filtered = [
                    "name" => $request["name"],
                    "permissions" => implode(",", $permissions)
                ];

                $create = $this->system->create("roles", $filtered);

                if($create):
                    response(200, "Role has been created!", [
                        "id" => $create
                    ]);
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "package":
                if(!in_array("create_package", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

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
                        response(400, "Invalid Parameters!");
                    endif;

                    if(!in_array($column, ["name", "services"])):
                        if(!$this->sanitize->isInt($request[$column])):
                            response(400, "Invalid Parameters!");
                        endif;
                    endif;
                endforeach;

                if(!$this->sanitize->length($request["name"]))
                    response(400, "Name is too short!");

                if($request["price"] < 1)
                    response(400, "Package price cannot be less than 1!");

                $request["footermark"] = $request["footermark"] < 2 ? 1 : 2;
                $request["hidden"] = $request["hidden"] < 2 ? 1 : 2;

                if(empty($request["services"])):
                    response(400, "Please enter at least one service!");
                endif;

                $servicesList = explode(",", $request["services"]);

                foreach($servicesList as $service):
                    if(!in_array(trim($service), ["sms", "whatsapp", "android_ussd", "api", "webhooks", "templates", "actions", "flows", "ai"])):
                        response(400, "One or more services are invalid!");
                    endif;
                endforeach;

                $filtered = [];

                foreach($columns as $column):
                    $filtered[$column] = $request[$column];
                endforeach;

                $create = $this->system->create("packages", $filtered);

                if($create):
                    response(200, "Package has been created!", [
                        "id" => $create
                    ]);
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "voucher":
                if(!in_array("create_voucher", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["name"], $request["count"], $request["duration"], $request["package"]))
                    response(400, "Invalid Parameters!");

                if($request["count"] < 1 || $request["count"] > 1000)
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["count"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["duration"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["package"]))
                    response(400, "Invalid Parameters!");

                if($request["duration"] < 1)
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->length($request["name"]))
                    response(400, "Name is too short!");

                if($request["package"] < 2)
                    response(400, "Invalid Parameters!");

                if($this->system->checkPackage($request["package"]) < 1)
                    response(400, "Invalid Parameters!");

                $voucherArray = [];

                if($request["count"] < 2):
                    $filtered = [
                        "code" => md5(uniqid(time(), true)),
                        "name" => $request["name"],
                        "package" => (int) $request["package"],
                        "duration" => (int) $request["duration"]
                    ];

                    $create = $this->system->create("vouchers", $filtered);

                    if($create):
                        $filtered["id"] = $create;
                        $voucherArray[] = $filtered;

                        response(200, "Voucher has been created!", $voucherArray);
                    else:
                        response(500, "Something went wrong!");
                    endif;
                else:
                    for($i = 1; $i <= $request["count"]; $i++):
                        $filtered = [
                            "code" => md5(uniqid(time() . "_{$i}", true)),
                            "name" => $request["name"] . " #{$i}",
                            "package" => (int) $request["package"],
                            "duration" => (int) $request["duration"]
                        ];

                        $create = $this->system->create("vouchers", $filtered);

                        if($create):
                            $filtered["id"] = $create;
                            $voucherArray[] = $filtered;
                        endif;
                    endfor;

                    response(200, "Vouchers has been created!", $voucherArray);
                endif;

                break;
            case "subscription":
                if(!in_array("create_subscription", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["user"], $request["package"], $request["duration"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["user"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["package"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["duration"]))
                    response(400, "Invalid Parameters!");

                if($this->system->checkUser($request["user"]) < 1)
                    response(400, "Invalid Parameters!");

                if($this->system->checkPackage($request["package"]) < 1)
                    response(400, "Invalid Parameters!");

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
			
				if($existing && strtotime($existing["sub_date"]) > $now):
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

                $create = $this->system->create("subscriptions", $filtered);

                if($create):
                    response(200, "Subscription has been created!", [
                        "id" => $create
                    ]);
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "apikey":
                if(!in_array("create_apikey", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["id"], $request["name"], $request["permissions"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($this->system->checkUser($request["id"]) < 1)
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->length($request["name"]))
                    response(400, "Name is too short!");

                if(!is_array($request["permissions"]))
                    response(400, "Invalid Parameters!");

                if(empty($request["permissions"]))
                    response(400, "Invalid Parameters!");

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
                        response(400, "Invalid Parameters!");
                    endif;
                endforeach;

                $filtered = [
                    "uid" => $request["id"],
                    "secret" => sha1(uniqid(time() . $request["id"], true)),
                    "name" => $request["name"],
                    "permissions" => implode(",", $request["permissions"])
                ];

                $create = $this->system->create("keys", $filtered);

                if($create):
                    response(200, "API key has been created!", [
                        "id" => $create,
                        "secret" => $filtered["secret"]
                    ]);
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            default:
                response(500, "Invalid API Endpoint!");
        endswitch;
	}

    public function update()
    {
        $this->header->allow();

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        if(in_array("0", explode(",", system_admin_api)))
            response(403, "Admin API is disabled!");

        $request = $this->sanitize->array($_REQUEST);
        $type = $this->sanitize->string($this->url->segment(4));

        if(!isset($request["token"]))
            response(400, "Invalid Parameters!");

        if($request["token"] != system_admin_token)
            response(401, "Invalid system token supplied!");

        switch($type):
            case "user":
                if(!in_array("edit_user", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                $filtered = [];

                if(isset($request["role"])):
                    if(!$this->sanitize->isInt($request["role"]))
                        response(400, "Invalid Parameters!");

                    if($this->system->checkRole($request["role"]) < 1)
                        response(400, "Invalid Parameters!");

                    $filtered["role"] = $request["role"];
                endif;

                if(isset($request["language"])):
                    if(!$this->sanitize->isInt($request["language"]))
                        response(400, "Invalid Parameters!");

                    if($this->system->checkLanguage($request["language"]) < 1)
                        response(400, "Invalid Parameters!");

                    $filtered["language"] = $request["language"];
                endif;

                if(isset($request["theme"])):
                    if(!in_array($request["theme"], ["light", "dark"]))
                        response(400, "Invalid Parameters!");

                    $filtered["theme_color"] = $request["theme"];
                endif;

                if(isset($request["name"])):
                    if(!$this->sanitize->length($request["name"]))
                        response(400, "Name is too short!");

                    $filtered["name"] = $request["name"];
                endif;

                if(isset($request["email"])):
                    if(!$this->sanitize->isEmail($request["email"]))
                        response(400, "Invalid email address!");

                    if($this->system->checkEmail($request["email"]) > 0)
                        response(403, "Email was already used!");

                    $filtered["email"] = $this->sanitize->email($request["email"]);
                endif;

                if(isset($request["credits"]) && $this->sanitize->isInt($request["credits"])):
                    $filtered["credits"] = $request["credits"];
                endif;

                if(isset($request["timezone"])):
                    if(!in_array(strtolower($request["timezone"]), $this->timezones->generate()))
                        response(400, "Invalid Parameters!");

                    $filtered["timezone"] = strtolower($request["timezone"]);
                endif;

                if(isset($request["country"])):
                    if(!array_key_exists(strtoupper($request["country"]), \CountryCodes::get("alpha2", "country")))
                        response(400, "Invalid Parameters!");

                    $filtered["country"] = strtoupper($request["country"]);
                endif;

                if(isset($request["password"])):
                    if(!$this->sanitize->length($request["password"], 5))
                        response(400, "Password is too short!");
                    else
                        $filtered["password"] = password_hash($request["password"], PASSWORD_DEFAULT);
                endif;

                if(!empty($filtered)):
                    if($this->system->update($request["id"], false, "users", $filtered)):
                        response(200, "User has been updated!");
                    else:
                        response(500, "Something went wrong!");
                    endif;
                else:
                    response(400, "Invalid Parameters!");
                endif;

                break;
            case "role":
                if(!in_array("edit_role", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if($request["id"] < 2)
                    response(500, "Default role cannot be edited!");

                if($this->system->checkRole($request["id"]) < 1)
                    response(400, "Invalid Parameters!");

                $filtered = [];

                if(isset($request["permissions"])):
                    $permissions = explode(",", $request["permissions"]);

                    if(!is_array($permissions))
                        response(400, "Invalid Parameters!");

                    if(empty($permissions))
                        response(400, "Invalid Parameters!");

                     foreach($permissions as $permission):
                        if(!in_array($permission, [
                            ...restriction_list(),
                            ...permission_list()
                        ])):
                            response(400, "Invalid Parameters!");
                        endif;
                    endforeach;

                    $filtered["permissions"] = implode(",", $permissions);
                endif;

                if(isset($request["name"])):
                    if(!$this->sanitize->length($request["name"]))
                        response(400, "Name is too short!");

                    $filtered["name"] = $request["name"];
                endif;

                if($this->system->update($request["id"], false, "roles", $filtered)):
                    response(200, "Role has been updated!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "package":
                if(!in_array("edit_package", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                $filtered = [];
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
                    if(isset($request[$column])):
                        if(!in_array($column, ["name", "services"])):
                            if(!$this->sanitize->isInt($request[$column])):
                                response(400, "Invalid Parameters!");
                            endif;

                            if($column == "price"):
                                if($request["price"] < 1)
                                    response(400, "Package price cannot be less than 1!");
                            endif;
                        else:
                            if(!$this->sanitize->length($request["name"]))
                                response(400, "Name is too short!");
                        endif;

                        $filtered[$column] = $request[$column];
                    endif;
                endforeach;

                if(isset($request["footermark"]))
                    $request["footermark"] = $request["footermark"] < 2 ? 1 : 2;

                if(isset($request["hidden"])):
                    if($request["id"] < 2):
                        $request["hidden"] = 2;
                    else:
                        $request["hidden"] = $request["hidden"] < 2 ? 1 : 2;
                    endif;
                endif;

                if(isset($request["services"])):
                    if(empty($request["services"])):
                        response(400, "Please enter at least one service!");
                    endif;
    
                    $servicesList = explode(",", $request["services"]);
    
                    foreach($servicesList as $service):
                        if(!in_array(trim($service), ["sms", "whatsapp", "android_ussd", "api", "webhooks", "templates", "actions", "ai"])):
                            response(400, "One or more services are invalid!");
                        endif;
                    endforeach;

                    $filtered["services"] = implode(",", $servicesList);
                endif;

                if(!empty($filtered)):
                    if($this->system->update($request["id"], false, "packages", $filtered)):
                        response(200, "Package has been updated!");
                    else:
                        response(500, "Something went wrong!");
                    endif;
                else:
                    response(400, "Invalid Parameters!");
                endif;

                break;
            default:
                response(500, "Invalid API Endpoint!");
        endswitch;
    }

	public function delete()
	{
        $this->header->allow();

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        if(in_array("0", explode(",", system_admin_api)))
            response(403, "Admin API is disabled!");

        $request = $this->sanitize->array($_REQUEST);
        $type = $this->sanitize->string($this->url->segment(4));

        if(!isset($request["token"]))
            response(400, "Invalid Parameters!");

        if($request["token"] != system_admin_token)
            response(401, "Invalid system token supplied!");

        switch($type):
            case "user":
                if(!in_array("delete_user", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($request["id"] < 2)
                    response(400, "Default admin account cannot be deleted!");

                if($this->system->delete(false, $request["id"], "users")):
                    $this->system->deleteUserData($request["id"]);

                    response(200, "User has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "role":
                if(!in_array("delete_role", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($request["id"] < 2)
                    response(400, "Default role cannot be deleted!");

                if($this->system->delete(false, $request["id"], "roles")):
                    response(200, "Role has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "package":
                if(!in_array("delete_package", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($request["id"] < 2)
                    response(400, "Default package cannot be deleted!");

                if($this->system->delete(false, $request["id"], "packages")):
                    response(200, "Package has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "voucher":
                if(!in_array("delete_voucher", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($this->system->delete(false, $request["id"], "vouchers")):
                    response(200, "Voucher has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "subscription":
                if(!in_array("delete_subscription", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($this->system->delete(false, $request["id"], "subscriptions")):
                    response(200, "Subscription has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "transaction":
                if(!in_array("delete_transaction", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($this->system->delete(false, $request["id"], "transactions")):
                    response(200, "Transaction has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "apikey":
                if(!in_array("delete_apikey", explode(",", system_admin_api)))
                    response(403, "This API endpoint is disabled!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($this->system->delete(false, $request["id"], "keys")):
                    response(200, "API key has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            default:
                response(500, "Invalid API Endpoint!");
        endswitch;
	}

    public function redeem()
    {
        $this->header->allow();

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        if(in_array("0", explode(",", system_admin_api)))
            response(403, "Admin API is disabled!");

        if(!in_array("redeem_voucher", explode(",", system_admin_api)))
            response(403, "This API endpoint is disabled!");

        $request = $this->sanitize->array($_REQUEST);

        if(!isset($request["token"], $request["user"], $request["code"]))
            response(400, "Invalid Parameters!");

        if($request["token"] != system_admin_token)
            response(401, "Invalid system token supplied!");

        if(!$this->sanitize->isInt($request["user"]))
            response(400, "Invalid Parameters!");

        if($this->system->checkUser($request["user"]) < 1)
            response(400, "Invalid Parameters!");

        if($this->system->checkVoucher($request["code"]) > 0):
            $voucher = $this->system->getVoucher($request["code"]);
            $package = $this->system->getPackage($voucher["package"]);

            // transactions.txn is NOT NULL and carries a unique index, so every
            // path that records a transaction has to supply one. These two
            // voucher branches were the only ones that did not, and relied on
            // MySQL substituting an empty string.
            $txn = md5(uniqid(time(), true));

            if($this->system->checkSubscription($request["user"]) > 0):
                $transaction = $this->system->create("transactions", [
                    "uid" => $request["user"],
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
                    "tid" => $transaction
                ];

                $subscription = $this->system->getSubscription(false, $request["user"]);

                $this->system->update($subscription["sid"], $request["user"], "subscriptions", $filtered);
            else:
                $transaction = $this->system->create("transactions", [
                    "uid" => $request["user"],
                    "pid" => $package["id"],
                    "type" => 3,
                    "price" => $package["price"],
                    "currency" => system_currency,
                    "duration" => $voucher["duration"],
                    "provider" => "Voucher",
                    "txn" => "voucher-{$txn}"
                ]);

                $filtered = [
                    "uid" => $request["user"],
                    "pid" => $package["id"],
                    "tid" => $transaction
                ];

                $this->system->create("subscriptions", $filtered);
            endif;

            if($this->system->delete(false, $voucher["id"], "vouchers")):
                response(200, "Voucher has been redeemed!");
            else:
                response(500, "Something went wrong!");
            endif;
        else:
            response(403, "Invalid voucher code!");
        endif;
    }

    public function clear()
    {
        $this->header->allow();

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        if(in_array("0", explode(",", system_admin_api)))
            response(403, "Admin API is disabled!");

        if(!in_array("clear_cache", explode(",", system_admin_api)))
            response(403, "This API endpoint is disabled!");

        $request = $this->sanitize->array($_REQUEST);
        $type = $this->sanitize->string($this->url->segment(4));

        if(!isset($request["token"]))
            response(400, "Invalid Parameters!");

        if($request["token"] != system_admin_token)
            response(401, "Invalid system token supplied!");

        switch($type):
            case "cache":
                try {
                    rmrf("system/storage/cache");
                    mkdir("system/storage/cache");
                } catch(Exception $e){
                    // Ignore
                }

                response(200, "System cache files has been cleared!");

                break;
            default:
                response(500, "Invalid API Endpoint!");
        endswitch;
    }
}