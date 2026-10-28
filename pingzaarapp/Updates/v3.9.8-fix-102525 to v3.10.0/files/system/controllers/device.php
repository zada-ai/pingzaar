<?php
/**
 * @controller Device
 */

class Device_Controller extends MVC_Controller
{
	public function index()
	{
		$this->header->allow();

		$this->prepareSystem();

		$request = $this->sanitize->json();

		if(!isset($request["hash"], $request["did"]))
			response(400, false, []);

		$decode = $this->hash->decode($request["hash"], system_token);

		if(!$decode)
			response(403, false, []);

		$uid = $decode;
		
		if($this->device->checkUserId($uid) < 1)
			response(403, false, []);

		if($this->device->checkDevice($request["did"]) < 1)
			response(403, false, []);

		$device = $this->device->getDevice($request["did"]);

		response(200, false, [
			"name" => $device["name"],
			"receive_sms" => $device["receive_sms"],
			"random_send" => $device["random_send"],
			"random_min" => $device["random_min"],
			"random_max" => $device["random_max"]
		]);
	}

	public function update()
	{
		$this->header->allow();

		$this->prepareSystem();

		// Firebase config rides along here as well as on the pairing response, so
		// a device that paired before the admin uploaded google-services.json --
		// or one that signed in with credentials, since device/login predates the
		// config entirely -- picks it up on its next poll instead of needing to be
		// re-paired.
		response(200, false, [
			"latest_version" => system_apk_version,
			"apk_url" => site_url("uploads/builder/gateway.apk", true),
			"firebase" => $this->device->getFirebaseClientConfig()
		]);
	}

	public function token()
	{
		$this->header->allow();

		$this->prepareSystem();

		$request = $this->sanitize->json();
		
		if(!isset($request["hash"], $request["did"], $request["token"]))
			response(400);

		$decode = $this->hash->decode($request["hash"], system_token);

		if(!$decode)
			response(403);

		$uid = $decode;

		if($this->device->checkUserId($uid) < 1)
    		response(403);

        if($this->device->checkDevice($request["did"]) < 1)
        	response(403);

		$device = $this->device->getDevice($request["did"]);

		$this->system->update($device["id"], false, "devices", [
			"fcm_token" => $request["token"]
		]);

		response(200);
	}

	public function messages()
	{
		$this->header->allow();

		$this->prepareSystem();

		$request = $this->sanitize->json();

		if(!isset($request["hash"], $request["did"], $request["diff"]))
			response(400);

		$decode = $this->hash->decode($request["hash"], system_token);

		if(!$decode)
			response(403);

		$uid = $decode;

		if($this->device->checkUserId($uid) < 1)
    		response(403);

		$user = $this->system->getUser($uid);

		set_logged($user);

		$subscription = set_subscription(
			$this->system->checkSubscription($user["id"]), 
			$this->system->getSubscription(false, $user["id"]), 
			$this->system->getSubscription(false, false, true)
		);

        if($this->device->checkDevice($request["did"]) < 1)
        	response(403);

        $messages = $this->device->getPendingMessages($request["did"], $request["diff"]);

        $messageContainer = [];
        $hashContainer = [];

        if(!empty($messages)):
	        foreach($messages as $row):
				$row["message"] = htmlspecialchars_decode($row["message"]);
				
	        	if($user["id"] == $row["uid"]):
					if(empty($subscription))
						response(400);

		        	if(!limitation($subscription["send_limit"], $this->system->countQuota($user["id"], "sent"))):
		        		$messageContainer[] = $row;

			        	$this->system->update($row["id"], false, "sent", [
		        			"status" => 2
		        		]);

		        		$this->system->increment($user["id"], "sent");
		        	else:
		        		$this->system->update($row["id"], false, "sent", [
		        			"status" => 4
		        		]);
		        	endif;

		        	$hashContainer[$user["id"]] = $user["hash"];
		        else:
		        	$messageContainer[] = $row;

		        	$this->system->update($row["id"], false, "sent", [
	        			"status" => 2
	        		]);

	        		$hashContainer[$row["uid"]] = md5($row["uid"]);
		        endif;
			endforeach;
        endif;

        response(200, false, $messageContainer);
	}

	public function ussd()
	{
		$this->header->allow();

		$this->prepareSystem();

		$request = $this->sanitize->json();

		if(!isset($request["hash"], $request["did"]))
			response(400);

		$decode = $this->hash->decode($request["hash"], system_token);

		if(!$decode)
			response(403);

		$uid = $decode;

		if($this->device->checkUserId($uid) < 1)
    		response(403);

        if($this->device->checkDevice($request["did"]) < 1)
        	response(403);

        $ussd = $this->device->getPendingUssd($request["did"]);

        if(!empty($ussd)):
	        foreach($ussd as $row):
	        	$this->system->update($row["id"], false, "ussd", [
        			"status" => 2
        		]);
			endforeach;
        endif;

        response(200, false, $ussd);
	}

	public function login()
	{
		$this->header->allow();

		$this->prepareSystem();

        set_language(system_default_lang);

		$request = $this->sanitize->json();

		if(!isset($request["email"], $request["password"], $request["device_unique"], $request["device_model"], $request["device_version"], $request["device_manufacturer"]))
			response(400, "Invalid Request!", null);

		if(!$this->sanitize->isEmail($request["email"]))
			response(400, __("device_login_usevalidemail"), null);

		if($this->device->checkUserEmail($request["email"]) > 0):
			$user = $this->system->getUser($request["email"], "email");

			if($user["suspended"] > 0):
				response(403, __("device_login_accountsuspended"), null);
			endif;

			if(!password_verify($request["password"], $user["password"])):
				response(401, __("device_login_incorrectmailorpass"), null);
			endif;

			set_logged($user);
		else:
			response(401, __("device_login_incorrectmailorpass"), null);
		endif;

        $subscription = set_subscription(
            $this->system->checkSubscription($user["id"]), 
            $this->system->getSubscription(false, $user["id"]), 
            $this->system->getSubscription(false, false, true)
        );

		if(empty($subscription))
			response(403, __("device_login_nosubscription"), null);

		if($this->device->checkDevice($request["device_unique"]) > 0):
			$device = $this->device->getDevice($request["device_unique"]);

			if($device["uid"] != $user["id"]):
				response(403, ___(__("app_response_devicealreadylinkednew"), [maskEmail($user["email"])]), null);
			endif;
			
			response(200, false, [
				"hash" => $this->hash->encode($user["id"], system_token),
				"name" => $device["name"],
				"receive_sms" => $device["global_device"] < 2 ? 2 : $device["receive_sms"],
				"random_send" => $device["random_send"],
				"random_min" => $device["random_min"],
				"random_max" => $device["random_max"]
			]);
		endif;

		if(limitation($subscription["device_limit"], $this->system->countDevices($user["id"])))
			response(403, __("device_login_nomoredevice"), null);

    	date_default_timezone_set($user["timezone"]);

		$filtered = [
			"uid" => $user["id"],
			"did" => $request["device_unique"],
			"name" => $request["device_model"],
			"version" => $request["device_version"],
			"manufacturer" => ucfirst($request["device_manufacturer"]),
			"random_send" => 1,
			"random_min" => 5,
			"random_max" => 10,
			"receive_sms" => 1,
			"global_device" => 2,
			"global_priority" => 2,
			"global_slots" => 1,
			"country" => "US",
			"rate" => "0.01",
			"fcm_token" => false,
			"create_date" => date("Y-m-d H:i:s", time())
		];

		if($this->system->create("devices", $filtered)):
			$this->cache->container("user.{$user["hash"]}");
			$this->cache->clear();

			$this->echo->trigger("private-user-{$user["hash"]}", "table", [
				"modal" => true
			]);

			if(!empty(system_mailing_address) && in_array("admin_new_device", explode(",", system_mailing_triggers))):
				$mailingContent = <<<HTML
				<p>Hi there!</p>
				<p>This is to inform you that a new device has been linked to account: <strong>{$user["email"]}</strong></p> 
				HTML;

    			$this->mail->send([
					"title" => system_site_name,
					"data" => [
						"subject" => mail_title("Admin Alert Message from " . system_site_name . "!"),
						"content" => $mailingContent
					]
				], system_mailing_address, "_mail/default.tpl", $this->smarty);
    		endif;

			response(200, false, [
				"hash" => $this->hash->encode($user["id"], system_token),
				"name" => $request["device_model"],
				"receive_sms" => $filtered["receive_sms"],
				"random_send" => $filtered["random_send"],
				"random_min" => $filtered["random_min"],	
				"random_max" => $filtered["random_max"]
			]);
		else:
			response(500, "Failed to create device!", null);
		endif;
	}

	/**
	 * Exchanges a one-time pairing token for a device credential.
	 *
	 * Replaces scan() for the universal build. scan() takes the account hash
	 * straight out of the QR, which makes that QR a durable credential -- anyone
	 * who photographs it can attach their own device to the account. The token
	 * here is single use and expires, so a leaked screenshot is worthless.
	 *
	 * Also returns the Firebase client configuration, because the app is no
	 * longer built per customer and has no google-services.json baked in.
	 */
	public function pair()
	{
		$this->header->allow();

		$this->prepareSystem();

		set_language(system_default_lang);

		$request = $this->sanitize->json();

		if(!isset($request["token"], $request["device_unique"], $request["device_model"], $request["device_version"], $request["device_manufacturer"]))
			response(400, "Invalid Request!", null);

		// Burn the token first, atomically. A single DELETE is the claim: of two
		// concurrent scans of the same QR exactly one removes a row and wins, so
		// the token cannot be redeemed twice however the requests interleave.
		$uid = $this->device->claimPairingToken($request["token"]);

		if(!$uid)
			response(403, __("device_pair_invalidtoken"), null);

		if($this->device->checkUserId($uid) < 1)
			response(403, "Invalid Request!", null);

		$user = $this->system->getUser($uid);

		set_logged($user);

		$subscription = set_subscription(
			$this->system->checkSubscription($user["id"]),
			$this->system->getSubscription(false, $user["id"]),
			$this->system->getSubscription(false, false, true)
		);

		if(empty($subscription))
			response(403, __("device_scan_nosub"), null);

		if($this->device->checkSuspension($user["id"]) > 0)
			response(403, __("device_scan_usersuspended"), null);

		if($this->device->checkDevice($request["device_unique"]) > 0):
			$device = $this->device->getDevice($request["device_unique"]);

			if($device["uid"] != $user["id"])
				response(403, ___(__("app_response_devicealreadylinkednew"), [maskEmail($user["email"])]), null);

			$this->echo->trigger("private-user-{$user["hash"]}", "table", [
				"modal" => true
			]);

			response(200, false, [
				"hash" => $this->hash->encode($user["id"], system_token),
				"name" => $device["name"],
				"receive_sms" => $device["global_device"] < 2 ? 2 : $device["receive_sms"],
				"random_send" => $device["random_send"],
				"random_min" => $device["random_min"],
				"random_max" => $device["random_max"],
				"firebase" => $this->device->getFirebaseClientConfig()
			]);
		endif;

		if(limitation($subscription["device_limit"], $this->system->countDevices($user["id"])))
			response(403, __("device_scan_nomoredevices"), null);

		date_default_timezone_set($user["timezone"]);

		$filtered = [
			"uid" => $user["id"],
			"did" => $request["device_unique"],
			"name" => $request["device_model"],
			"version" => $request["device_version"],
			"manufacturer" => ucfirst($request["device_manufacturer"]),
			"random_send" => 1,
			"random_min" => 5,
			"random_max" => 10,
			"receive_sms" => 1,
			"global_device" => 2,
			"global_priority" => 2,
			"global_slots" => 1,
			"country" => "US",
			"rate" => "0.01",
			"fcm_token" => false,
			"create_date" => date("Y-m-d H:i:s", time())
		];

		if(!$this->system->create("devices", $filtered))
			response(500, "Failed to create device!", null);

		$this->cache->container("user.{$user["hash"]}");
		$this->cache->clear();

		$this->echo->trigger("private-user-{$user["hash"]}", "table", [
			"modal" => true
		]);

		if(!empty(system_mailing_address) && in_array("admin_new_device", explode(",", system_mailing_triggers))):
			$mailingContent = <<<HTML
			<p>Hi there!</p>
			<p>This is to inform you that a new device has been linked to account: <strong>{$user["email"]}</strong></p>
			HTML;

			$this->mail->send([
				"title" => system_site_name,
				"data" => [
					"subject" => mail_title("Admin Alert Message from " . system_site_name . "!"),
					"content" => $mailingContent
				]
			], system_mailing_address, "_mail/default.tpl", $this->smarty);
		endif;

		response(200, false, [
			"hash" => $this->hash->encode($user["id"], system_token),
			"name" => $request["device_model"],
			"receive_sms" => $filtered["receive_sms"],
			"random_send" => $filtered["random_send"],
			"random_min" => $filtered["random_min"],
			"random_max" => $filtered["random_max"],
			"firebase" => $this->device->getFirebaseClientConfig()
		]);
	}

	public function scan()
	{
		$this->header->allow();

		$this->prepareSystem();

		set_language(system_default_lang);

		$request = $this->sanitize->json();

		if(!isset($request["hash"], $request["device_unique"], $request["device_model"], $request["device_version"], $request["device_manufacturer"]))
			response(400, "Invalid Request!", null);

		$decode = $this->hash->decode($request["hash"], system_token);

		if(!$decode)
			response(403, "Invalid Request!", null);

		$uid = $decode;

		if($this->device->checkUserId($uid) < 1)
    		response(403, "Invalid Request!", null);

		$user = $this->system->getUser($uid);

		set_logged($user);

        $subscription = set_subscription(
	        $this->system->checkSubscription($user["id"]), 
	        $this->system->getSubscription(false, $user["id"]), 
	        $this->system->getSubscription(false, false, true)
	    );

		if(empty($subscription))
			response(403, __("device_scan_nosub"), null);

		if($this->device->checkSuspension($user["id"]) > 0)
			response(403, __("device_scan_usersuspended"), null);

		if($this->device->checkDevice($request["device_unique"]) > 0):
			$device = $this->device->getDevice($request["device_unique"]);

			if($device["uid"] != $user["id"]):
				response(403, ___(__("app_response_devicealreadylinkednew"), [maskEmail($user["email"])]), null);
			endif;

			$this->echo->trigger("private-user-{$user["hash"]}", "table", [
				"modal" => true
			]);

			response(200, false, [
				"hash" => $this->hash->encode($user["id"], system_token),
				"name" => $device["name"],
				"receive_sms" => $device["global_device"] < 2 ? 2 : $device["receive_sms"],
				"random_send" => $device["random_send"],
				"random_min" => $device["random_min"],
				"random_max" => $device["random_max"]
			]);
		endif;

		if(limitation($subscription["device_limit"], $this->system->countDevices($user["id"])))
			response(403, __("device_scan_nomoredevices"), null);

    	date_default_timezone_set($user["timezone"]);

		$filtered = [
			"uid" => $user["id"],
			"did" => $request["device_unique"],
			"name" => $request["device_model"],
			"version" => $request["device_version"],
			"manufacturer" => ucfirst($request["device_manufacturer"]),
			"random_send" => 1,
			"random_min" => 5,
			"random_max" => 10,
			"receive_sms" => 1,
			"global_device" => 2,
			"global_priority" => 2,
			"global_slots" => 1,
			"country" => "US",
			"rate" => "0.01",
			"fcm_token" => false,
			"create_date" => date("Y-m-d H:i:s", time())
		];

		if($this->system->create("devices", $filtered)):
			$this->cache->container("user.{$user["hash"]}");
			$this->cache->clear();

			$this->echo->trigger("private-user-{$user["hash"]}", "table", [
				"modal" => true
			]);

			if(!empty(system_mailing_address) && in_array("admin_new_device", explode(",", system_mailing_triggers))):
				$mailingContent = <<<HTML
				<p>Hi there!</p>
				<p>This is to inform you that a new device has been linked to account: <strong>{$user["email"]}</strong></p> 
				HTML;

    			$this->mail->send([
					"title" => system_site_name,
					"data" => [
						"subject" => mail_title("Admin Alert Message from " . system_site_name . "!"),
						"content" => $mailingContent
					]
				], system_mailing_address, "_mail/default.tpl", $this->smarty);
    		endif;

			response(200, false, [
				"hash" => $request["hash"],
				"name" => $request["device_model"],
				"receive_sms" => $filtered["receive_sms"],
				"random_send" => $filtered["random_send"],
				"random_min" => $filtered["random_min"],
				"random_max" => $filtered["random_max"]
			]);
		else:
			response(500, "Failed to create device!", null);
		endif;
	}

	public function report()
	{
		$this->header->allow();

		$this->prepareSystem();

		$request = $this->sanitize->json();

		if(!isset($request["hash"], $request["did"], $request["uid"], $request["mid"], $request["code"], $request["status"]))
			response(400);

		if(!in_array($request["status"], ["failed", "success"]))
			response(400);

		$decode = $this->hash->decode($request["hash"], system_token);

		if(!$decode)
			response(403);

		$uid = $decode;

		if($this->device->checkUserId($uid) < 1)
    		response(403);

		$user = $this->system->getUser($uid);

		set_logged($user);

		set_language($user["language"]);

		if($this->device->checkDevice($request["did"]) < 1)
        	response(403);

        $device = $this->device->getDevice($request["did"]);

        if($device["uid"] != $user["id"]):
			response(403, ___(__("app_response_devicealreadylinkedunablenew"), [maskEmail($user["email"])]));
		endif;

        date_default_timezone_set($user["timezone"]);

        if($this->system->checkQuota($user["id"]) < 1):
			$this->system->create("quota", [
				"uid" => $user["id"],
				"sent" => 0,
				"received" => 0,
				"wa_sent" => 0,
				"wa_received" => 0,
				"ussd" => 0
			]);
		endif;

        $this->system->update($request["mid"], false, "sent", [
			"status" => $request["status"] === "success" ? 3 : 4,
			"status_code" => $request["code"],
			"create_date" => date("Y-m-d H:i:s", time())
        ]);

		if($device["global_device"] < 2 && $this->system->getPartnership($device["uid"]) < 2 && $request["uid"] != $device["uid"]):
			// Verify sender UID matches message record to prevent tampering
			$sent_message = $this->system->getSentMessage($request["mid"]);

			if(!$sent_message || $sent_message["uid"] != $request["uid"]):
				response(403, "UID verification failed");
			endif;

			$currency = country($device["country"])->getCurrency()["iso_4217_code"];
			$final_price = $this->titansys->calculatePartnerSendPrice($currency, $device["rate"], $this->cache);

			if($final_price):
				// Check sender has sufficient balance before processing commission
				$sender_balance = $this->system->getBalance($request["uid"]);

				if(!$sender_balance || $sender_balance["credits"] < $final_price):
					// Update message status to failed due to insufficient balance
					$this->system->update($request["mid"], false, "sent", [
						"status" => 4,
						"status_code" => "INSUFFICIENT_BALANCE"
					]);

					response(200, "Message delivery reported but sender has insufficient balance");
				endif;

				$commission = (float) (system_partner_commission / 100) * $final_price;

				if($request["status"] == "success"):
					$this->system->credits($request["uid"], "decrease", $final_price);
					$this->system->earnings($device["uid"], "increase", $final_price - $commission);

					// Store commission rate with currency as JSON for future-proofing
					$commission_data = json_encode([
						"currency" => system_currency,
						"rate" => system_partner_commission
					]);

					$this->system->create("commissions", [
						"pid" => $device["uid"],
						"sid" => $request["uid"],
						"mid" => $request["mid"],
						"did" => $request["did"],
						"original_amount" => $final_price,
						"commission_amount" => $commission,
						"currency" => $commission_data,
						"create_date" => date("Y-m-d H:i:s", time())
					]);
				endif;
			endif;
    	endif;

		try {
			switch($request["code"]):
				case "SMS_SENT":
					$notifyMessage = __("device_api_messagesent");
					$notifyType = 1;
					break;
				case "SMS_DELIVERED":
					$notifyMessage = __("device_api_messagedelivered");
					$notifyType = 1;
					break;
				case "SMS_PENDING":
					$notifyMessage = __("device_api_smspending");
					$notifyType = 2;
					break;
				case "DELIVERY_PENDING":
					$notifyMessage = __("device_api_messagepending");
					$notifyType = 2;
					break;
				case "DELIVERY_FAILED":
					$notifyMessage = __("device_api_messagedeliveryfailed");
					$notifyType = 3;
					break;
				default:
					$notifyMessage = __("device_api_messagefailed");
					$notifyType = 3;
			endswitch;

			$this->echo->trigger("private-user-{$user["hash"]}", "message", [
				"status" => $notifyType,
				"content" => ___($notifyMessage, ["<strong><a href=\"#\" class=\"text-warning\" system-toggle=\"view/sent-{$request["mid"]}\">#{$request["mid"]}</a></strong>", "<strong>{$device["name"]}</strong>"])
			]);
		} catch(Exception $e){
			// Ignore
		}

		if($request["uid"] == $user["id"]):
			$this->process->_sanitize = $this->sanitize;

			/**
			 * Process Action Hooks
			 */

			$sent = $this->system->getSent($request["mid"]);

			if($sent):
				$hooks = $this->process->actionHooks($user["id"], 1, 1, $sent["phone"], $sent["message"], $this->device->getActions($user["id"], 1));

				if(!empty($hooks)):
					foreach($hooks as $hook):
						$this->system->create("events", [
							"uid" => $user["id"],
							"type" => 2,
							"create_date" => date("Y-m-d H:i:s", time())
						]);
					endforeach;
				endif;
			endif;
		endif;

		response(200);
	}

	public function received()
	{
		$this->header->allow();

		$this->prepareSystem();

		$request = $this->sanitize->json();

		if(!isset($request["hash"], $request["did"], $request["sim"], $request["message_id"], $request["sender"], $request["message"], $request["attachment"]))
			response(400);
		
		$decode = $this->hash->decode($request["hash"], system_token);

		if(!$decode)
			response(403);

		$uid = $decode;

		if($this->device->checkUserId($uid) < 1)
    		response(403);

		$user = $this->system->getUser($uid);

		set_logged($user);

		set_language($user["language"]);

        $subscription = set_subscription(
            $this->system->checkSubscription($user["id"]), 
            $this->system->getSubscription(false, $user["id"]), 
            $this->system->getSubscription(false, false, true)
        );

		if(empty($subscription))
			response(403);

		if($this->device->checkDevice($request["did"]) < 1)
        	response(403);

        $device = $this->device->getDevice($request["did"]);

        if($this->system->checkQuota($user["id"]) < 1):
			$this->system->create("quota", [
				"uid" => $user["id"],
				"sent" => 0,
				"received" => 0,
				"wa_sent" => 0,
				"wa_received" => 0,
				"ussd" => 0
			]);
		endif;

		$received_date = date("Y-m-d", time());

        if($device["global_device"] < 2):
        	if($this->system->checkDeleted($user["id"], $request["message_id"], $request["did"]) < 1):
	        	$this->system->create("deleted", [
					"rid" => $request["message_id"],
					"uid" => $user["id"],
					"did" => $request["did"]
				]);
	        endif;

			response(500);
        endif;

        if(!empty($request["message"])):
        	date_default_timezone_set($user["timezone"]);

        	if(strtolower(substr($request["message"], 0, 4)) === "stop"):
            	try {
				    $number = $this->phone->parse($request["sender"]);

				    if(!$number->isValidNumber())
						response(403);

					$phone = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);

					if($this->system->checkUnsubscribed($user["id"], $phone) < 1):
						$this->system->create("unsubscribed", [
							"uid" => $user["id"],
							"phone" => $phone
						]);
					endif;
				} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
					// Ignore
				}
            endif;

        	if($this->system->checkDeleted($user["id"], $request["message_id"], $request["did"]) < 1):
				if($this->device->checkReceived($request["message_id"], $user["id"], $request["did"]) < 1):
	        		if(!limitation($subscription["receive_limit"], $this->system->countQuota($user["id"], "received"))):
	        			$filtered = [
							"rid" => $request["message_id"],
							"uid" => $user["id"],
							"did" => $request["did"],
							"slot" => $request["sim"],
							"phone" => $request["sender"],
							"message" => $request["message"],
							"receive_date" => date("Y-m-d H:i:s", time())
						];

						$received = $this->system->create("received", $filtered);

						if($received):
							$this->echo->trigger("private-user-{$user["hash"]}", "message", [
								"status" => 1,
								"content" => ___(__("device_received_smsreceived"), ["<strong><a href=\"#\" class=\"text-warning\" system-toggle=\"view/received-{$received}\">" . __("response_notify_smsreceivedmessage") . "</a></strong>", "<strong>{$device["name"]}</strong>"])
							]);

							$this->system->increment($user["id"], "received");

							$this->process->_sanitize = $this->sanitize;

							/**
							 * Process Webhooks
							 */

							$webhooks = $this->process->webhooks($user["id"], "sms", [
								"id" => (int) $received,
								"rid" => (int) $filtered["rid"],
								"sim" => $filtered["slot"],
								"device" => $filtered["did"],
								"phone" => $filtered["phone"],
								"message" => $filtered["message"],
								"timestamp" => strtotime($filtered["receive_date"])
							], $this->device->getWebhooks($user["id"], "sms"));

							if(!empty($webhooks)):
								foreach($webhooks as $webhook):
									if(isset($webhook["logger"]) && !empty($webhook["logger"])):
										$this->system->create("logger", [
											"uid" => $filtered["uid"],
											"type" => $webhook["logger"]["type"],
											"reason" => $webhook["logger"]["reason"],
											"content" => $webhook["logger"]["content"],
											"create_date" => date("Y-m-d H:i:s", time())
										]);
									else:
										$this->system->create("events", [
											"uid" => $user["id"],
											"type" => 1,
											"create_date" => date("Y-m-d H:i:s", time())
										]);
									endif;
								endforeach;
							endif;

							/**
							 * Process Action Hooks
							 */

							$hooks = $this->process->actionHooks($user["id"], 1, 2, $filtered["phone"], $filtered["message"], $this->device->getActions($user["id"], 1));

							if(!empty($hooks)):
								foreach($hooks as $hook):
									$this->system->create("events", [
										"uid" => $user["id"],
										"type" => 2,
										"create_date" => date("Y-m-d H:i:s", time())
									]);
								endforeach;
							endif;

							/**
							 * Process Action Autoreplies
							 */

							if (!$this->flowlib->isUserPaused($user["id"], $filtered["phone"], "autoreply") && 
								!$this->flowlib->isUserPaused($user["id"], $filtered["phone"], "all")):
								
								$autoreplies = $this->process->actionAutoreplies($user["id"], 1, $filtered["phone"], $filtered["message"], $this->device->getActions($user["id"], 2), $subscription, [
									"sim" => $filtered["slot"],
									"device" => $filtered["did"],
									"titansys" => $this->titansys,
									"aiprovider" => $this->aiprovider
								]);
							else:
								$autoreplies = [];
							endif;

							if(!empty($autoreplies)):
								foreach($autoreplies as $autoreply):
									if(isset($autoreply["logger"]) && !empty($autoreply["logger"])):
										$this->system->create("logger", [
											"uid" => $filtered["uid"],
											"type" => $autoreply["logger"]["type"],
											"reason" => $autoreply["logger"]["reason"],
											"content" => $autoreply["logger"]["content"],
											"create_date" => date("Y-m-d H:i:s", time())
										]);
									else:
										$createReply = $this->system->create("sent", [
											"cid" => 0,
											"uid" => $user["id"],
											"did" => $filtered["did"],
											"gateway" => 0,
											"sim" => $filtered["slot"],
											"mode" => 1,
											"phone" => $filtered["phone"],
											"message" => $autoreply["message"],
											"status" => 1,
											"status_code" => false,
											"priority" => 2,
											"api" => 2,
											"create_date" => date("Y-m-d H:i:s", time())
										]);

										if($createReply):
											$this->system->create("events", [
												"uid" => $user["id"],
												"type" => 2,
												"create_date" => date("Y-m-d H:i:s", time())
											]);
										endif;
									endif;
								endforeach;

								$device = $this->system->getDevice($user["id"], $filtered["did"], "did");

								if($device):
									$this->fcm->sendWithToken($device["fcm_token"], [
										"action" => "message_request"
									]);
								endif;
							endif;

							/**
							 * Process Flows
							 */

							$flows = $this->flow->getFlows($filtered["uid"], "sms");
							$isFlowPaused = $this->flowlib->isUserPaused($filtered["uid"], $filtered["phone"], "flow");
							$isAllPaused = $this->flowlib->isUserPaused($filtered["uid"], $filtered["phone"], "all");
							$messageLeadsToResume = $this->flowlib->messageLeadsToResume(
								$flows, 
								$filtered["message"], 
								"sms",
								["device" => $filtered["did"]]
							);
							
							if (!$isFlowPaused && !$isAllPaused) {
								// Normal flow execution - not paused
								$flowSteps = $this->flowlib->start($filtered["uid"], $filtered["phone"], $filtered["message"], $flows, $subscription, [
									"sim" => $filtered["slot"],
									"device" => $filtered["did"],
									"titansys" => $this->titansys,
									"aiprovider" => $this->aiprovider
								]);
							} elseif (($isFlowPaused || $isAllPaused) && $messageLeadsToResume) {
								// User is paused but message leads to resume - allow execution
								$flowSteps = $this->flowlib->start($filtered["uid"], $filtered["phone"], $filtered["message"], $flows, $subscription, [
									"sim" => $filtered["slot"],
									"device" => $filtered["did"],
									"titansys" => $this->titansys,
									"aiprovider" => $this->aiprovider
								]);
							} else {
								// User is paused and message doesn't lead to resume - skip flow
								$flowSteps = [];
							}

							if(!empty($flowSteps)):
								foreach($flowSteps as $flowStep):
									foreach($flowStep["steps"] as $step):
										if($step["type"] == "ai_reply"):
											$aiKey = $this->widget->getAiKey($step["ai_key"]);
											
											if($aiKey && $aiKey["uid"] == $filtered["uid"]):
												$unique = md5($filtered["phone"] . ":ai:" . $step["ai_key"] . ":" . uniqid());

												$aiResponse = $this->aiprovider->conversation(
													$filtered["uid"],
													$unique,
													$aiKey["provider"],
													$aiKey["apikey"],
													$aiKey["model"],
													$aiKey["prompt"],
													$step["message"],
													$aiKey["max_tokens"],
													$aiKey["history"]
												);

												if($aiResponse !== false && $aiResponse !== ""):
													// Log successful AI response
													$this->system->create("logger", [
														"uid" => $filtered["uid"],
														"type" => "flow_event",
														"reason" => "AI Flow Response Generated",
														"content" => "Type: SMS Flow, User: {$filtered["phone"]}, AI Key: {$step["ai_key"]}, Message: {$step["message"]}",
														"create_date" => date("Y-m-d H:i:s", time())
													]);
													
													$aiReplyText = $aiResponse;
													
													if($step["priority"] < 2):
														$sendID = $this->system->create("sent", [
															"uid" => $filtered["uid"],
															"did" => $filtered["did"],
															"gateway" => 1,
															"message" => $aiReplyText,
															"phone" => $filtered["phone"],
															"sim" => 1,
															"priority" => 1,
															"mode" => 1,
															"create_date" => date("Y-m-d H:i:s")
														]);
													else:
														$sendID = $this->system->create("sent", [
															"uid" => $filtered["uid"],
															"did" => $filtered["did"],
															"gateway" => 1,
															"message" => $aiReplyText,
															"phone" => $filtered["phone"],
															"sim" => 1,
															"priority" => 2,
															"mode" => 1,
															"create_date" => date("Y-m-d H:i:s")
														]);
													endif;
													
													if($sendID):
														$device = $this->system->getDevice($filtered["uid"], $filtered["did"], "did");
														if($device):
															$this->fcm->sendWithToken($device["fcm_token"], [
																"action" => "message_request"
															]);
														endif;
													endif;
												else:
													// Log failed AI response
													$this->system->create("logger", [
														"uid" => $filtered["uid"],
														"type" => "flow_event",
														"reason" => "AI Flow Response Failed",
														"content" => "Type: SMS Flow, User: {$filtered["phone"]}, AI Key: {$step["ai_key"]}, Message: {$step["message"]}, Error: No Response",
														"create_date" => date("Y-m-d H:i:s", time())
													]);
												endif;
											endif;
										elseif($step["type"] == "save_contact"):
											// Log save contact flow event
											$this->system->create("logger", [
												"uid" => $filtered["uid"],
												"type" => "flow_event",
												"reason" => "Contact Save Flow Executed",
												"content" => "Type: SMS Flow, User: {$filtered["phone"]}, Group: {$step["group"]}, Action: Save Contact",
												"create_date" => date("Y-m-d H:i:s", time())
											]);
											
											if($this->flow->checkContactNumber($filtered["uid"], $filtered["phone"]) < 1):
												$this->system->create("contacts", [
													"uid" => $filtered["uid"],
													"phone" => $filtered["phone"],
													"name" => $filtered["phone"],
													"groups" => $step["group"]
												]);
											else:
												$contact = $this->flow->getContact($filtered["uid"], $filtered["phone"]);

												$contactGroups = explode(",", $contact["groups"]);	

												if(!in_array($step["group"], $contactGroups)):
													$contactGroups[] = $step["group"];
												endif;

												if($contact):
													$this->system->update($contact["id"], $filtered["uid"], "contacts", [
														"groups" => implode(",", $contactGroups)
													]);
												endif;
											endif;
										else:
											// Log other flow event (send message)
											$this->system->create("logger", [
												"uid" => $filtered["uid"],
												"type" => "flow_event",
												"reason" => "Send Message Flow Executed",
												"content" => "Type: SMS Flow, User: {$filtered["phone"]}, Priority: {$step["priority"]}, Message: " . substr($step["message"], 0, 100) . (strlen($step["message"]) > 100 ? "..." : ""),
												"create_date" => date("Y-m-d H:i:s", time())
											]);
											
											$this->system->create("sent", [
												"cid" => 0,
												"uid" => $filtered["uid"],
												"did" => $filtered["did"],
												"gateway" => 0,
												"sim" => $filtered["slot"],
												"mode" => 1,
												"phone" => $filtered["phone"],
												"message" => $step["message"],
												"status" => 1,
												"status_code" => false,
												"priority" => $step["priority"] < 2 ? 1 : 2,
												"api" => 2,
												"create_date" => date("Y-m-d H:i:s", time())
											]);
										endif;
									endforeach;
								endforeach;

								$device = $this->system->getDevice($filtered["uid"], $filtered["did"], "did");

								if($device):
									$this->fcm->sendWithToken($device["fcm_token"], [
										"action" => "message_request"
									]);
								endif;
							endif;
						endif;
					endif;
	        	endif;
			endif;
	    endif;

        response(200);
	}

	public function response()
	{
		$this->header->allow();

		$this->prepareSystem();

		$request = $this->sanitize->json();

		if(!isset($request["hash"], $request["did"], $request["ussd_id"], $request["response"], $request["status"]))
			response(400);

		if(empty($request["ussd_id"]))
			response(400);

		if(!in_array($request["status"], [3, 4]))
			response(400);

		$decode = $this->hash->decode($request["hash"], system_token);

		if(!$decode)
			response(403);

		$uid = $decode;

		if($this->device->checkUserId($uid) < 1)
    		response(403);

		$user = $this->system->getUser($uid);

		set_logged($user);

		set_language($user["language"]);

		if($this->device->checkDevice($request["did"]) < 1)
        	response(403);

        $device = $this->device->getDevice($request["did"]);

        if($this->system->checkQuota($user["id"]) < 1):
			$this->system->create("quota", [
				"uid" => $user["id"],
				"sent" => 0,
				"received" => 0,
				"wa_sent" => 0,
				"wa_received" => 0,
				"ussd" => 0
			]);
		endif;

        if($request["status"] == 3):
        	date_default_timezone_set($user["timezone"]);

        	$filtered = [
        		"response" => $request["response"],
        		"status" => 3,
        		"create_date" => date("Y-m-d H:i:s", time())
        	];

        	$ussd = $this->system->update($request["ussd_id"], false, "ussd", $filtered);

        	if($ussd):
				$ussdCode = $this->device->getUssd($request["ussd_id"]);

				if($ussdCode):
					$this->echo->trigger("private-user-{$user["hash"]}", "ussd", [
						"status" => 1,
						"content" => ___(__("device_ussd_responsereceive"), ["<strong>{$device["name"]}</strong>"])
					]);

					$this->system->increment($user["id"], "ussd");

					$this->system->create("utilities", [
	        			"uid" => $user["id"],
	        			"type" => 1,
	        			"create_date" => date("Y-m-d H:i:s", time())
		        	]);

					$this->process->_sanitize = $this->sanitize;

					/**
					 * Process Webhooks
					 */

		        	$webhooks = $this->process->webhooks($user["id"], "ussd", [
						"id" => (int) $ussd,
						"sim" => (int) $ussdCode["sim"],
						"device" => $ussdCode["did"],
						"code" => $ussdCode["code"],
						"response" => $filtered["response"],
						"timestamp" => strtotime($filtered["create_date"])
					], $this->device->getWebhooks($user["id"], "ussd"));

					if(!empty($webhooks)):
						foreach($webhooks as $webhook):
							$this->system->create("events", [
								"uid" => $user["id"],
								"type" => 1,
								"create_date" => date("Y-m-d H:i:s", time())
							]);
						endforeach;
					endif;
				endif;
        	endif;
	    else:
			$filtered = [
        		"response" => $request["response"],
        		"status" => 4,
        		"create_date" => date("Y-m-d H:i:s", time())
        	];

        	$ussd = $this->system->update($request["ussd_id"], false, "ussd", $filtered);
		endif;

        response(200);
	}

	private function prepareSystem(): void
	{
		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());
	}
}