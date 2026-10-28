<?php

class Whatsapp_Controller extends MVC_Controller
{
	public function index()
	{
		$this->header->allow();

		response(404);
	}

	public function server()
	{
		$this->header->allow();

		$request = $this->sanitize->array($_GET);

		if(!isset($request["key"], $request["url"], $request["port"]))
			response(500);

		if(!$this->sanitize->isUrl($request["url"]))
			response(500);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

		$waServer = $this->system->getWaServer($request["key"], "secret");

		if(!$waServer)
			response(500);

		$this->system->update($waServer["id"], false, "wa_servers", [
			"url" => $request["url"],
			"port" => $request["port"]
		]);

		$this->cache->container("system.waservers");
		$this->cache->clear();

		response(200);
	}

	public function messages()
	{
		$this->header->allow();

		$request = $this->sanitize->array($_GET);

		if(!isset($request["system_token"], $request["uid"], $request["hash"], $request["unique"], $request["diff"]))
			response(500);

		if($request["system_token"] != system_token)
			response(500);

		if(!$this->sanitize->isInt($request["diff"]))
			response(500);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

		$getUser = $this->system->getUser($request["uid"]);

		if(!$getUser)
			response(500);

		set_logged($getUser);

		if($this->whatsapp->checkAccount($request["uid"], $request["unique"]) > 0):
			$subscription = set_subscription(
	            $this->system->checkSubscription($request["uid"]), 
	            $this->system->getSubscription(false, $request["uid"]), 
	            $this->system->getSubscription(false, false, true)
	        );

			if(empty($subscription))
				response(500);

			$account = $this->system->getWaAccount($request["uid"], $request["unique"], "unique");
			$diff = $request["diff"] < 1 ? 100 : ($request["diff"] > 100 ? 100 : $request["diff"]);
			$messages = $this->whatsapp->getPendingMessages($request["uid"], $request["unique"], $diff);

			if(!empty($messages)):
				$messageContainer = [];

				foreach($messages as $row):
					if(!limitation($subscription["wa_send_limit"], $this->system->countQuota($request["uid"], "wa_sent"))):
						$messageContainer[] = $row;

			        	$this->system->update($row["id"], false, "wa_sent", [
		        			"status" => 2
		        		]);

		        		$this->system->increment($request["uid"], "wa_sent");
		        	else:
		        		$this->system->update($row["id"], false, "wa_sent", [
		        			"status" => 4
		        		]);
		        	endif;
				endforeach;

				if(empty($messageContainer))
					response(500);

				response(200, false, [
					"site_name" => system_site_name,
					"receive_chats" => $account["receive_chats"],
					"random_send" => $account["random_send"],
					"random_min" => $account["random_min"],
					"random_max" => $account["random_max"],
					"messages" => $messageContainer
				]);
			else:
				response(500);
			endif;
		else:
			response(500);
		endif;
	}

	public function link()
	{
		$this->header->allow();

		$type = $this->url->segment(4);
		$request = $this->sanitize->array($_GET);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        $this->cache->container("system.whatsapp", true);

		if($type == "success"):
			if(!isset($request["system_token"], $request["api_token"], $request["wsid"], $request["uid"], $request["wid"], $request["unique"]))
				response(500);

			if($request["system_token"] != system_token)
				response(500);

			set_language($this->whatsapp->getUserLanguage($request["uid"]));

			date_default_timezone_set($this->whatsapp->getUserTimezone($request["uid"]));

			if($this->system->checkWaAccount($request["uid"], $request["unique"], "unique") > 0):
				$phone = explode(":", $request["wid"]);

				$this->echo->trigger("private-user-" . md5($request["uid"]), "whatsapp", [
					"content" => ___(__("whatsapp_accountlink_notifymessage"), ["<strong>+{$phone[0]}</strong>"])
				]);
				
				$waAccount = $this->system->getWaAccount($request["uid"], $request["unique"], "unique");

				if($waAccount):
					$waAccountData = [
						"unique" => $waAccount["unique"],
						"wsid" => $waAccount["wsid"],
						"receive_chats" => $waAccount["receive_chats"],
						"random_send" => $waAccount["random_send"],
						"random_min" => $waAccount["random_min"],
						"random_max" => $waAccount["random_max"]
					];
					
					$waServer = $this->system->getWaServer($waAccount["wsid"], "id");

					if($waServer && $this->wa->check($waServer["url"], $waServer["port"])):
						$this->wa->update($waServer["secret"], $waAccountData, $waServer["url"], $waServer["port"]);
					endif;
				endif;

				response(200);
			else:
				$filtered = [
					"uid" => $request["uid"],
					"wsid" => $request["wsid"],
					"wid" => $request["wid"],
					"unique" => $request["unique"],
					"receive_chats" => 2,
					"random_send" => 1,
					"random_min" => 1,
					"random_max" => 5,
					"create_date" => date("Y-m-d H:i:s", time())
				];

				$phone = explode(":", $request["wid"]);

				if(count($phone) > 1):
					if($this->whatsapp->checkWid($request["uid"], $phone[0]) < 1):
						$createAccount = $this->system->create("wa_accounts", $filtered);

						if($createAccount):
							if($this->cache->has($request["api_token"])):
								$tokenContent = $this->cache->get($request["api_token"]);
								$newTokenContent = [
									"secret" => $tokenContent["secret"],
									"qrstring" => $tokenContent["qrstring"],
									"wa_info" => [
										"wid" => $request["wid"],
										"unique" => $request["unique"]
									]
								];

								$this->cache->set($request["api_token"], $newTokenContent, 600);

								try {
									$waServer = $this->system->getWaServer($filtered["wsid"], "id");
	
									$disableReceive = [
										"unique" => $filtered["unique"],
										"wsid" => $filtered["wsid"],
										"receive_chats" => 2,
										"random_send" => 1,
										"random_min" => 1,
										"random_max" => 5
									];
					
									if($waServer && $this->wa->check($waServer["url"], $waServer["port"])):
										$this->wa->update($waServer["secret"], $disableReceive, $waServer["url"], $waServer["port"]);
									endif;
								} catch(Exception $e){
									// Ignore
								}
							endif;

							if(!empty(system_mailing_address) && in_array("admin_new_whatsapp", explode(",", system_mailing_triggers))):
								$userAccount = $this->system->getUser($request["uid"]);

								$mailingContent = <<<HTML
								<p>Hi there!</p>
								<p>This is to inform you that a new WhatsApp has been linked to account: <strong>{$userAccount["email"]}</strong></p> 
								HTML;

								$this->mail->send([
									"title" => system_site_name,
									"data" => [
										"subject" => mail_title("Admin Alert Message from " . system_site_name . "!"),
										"content" => $mailingContent
									]
								], system_mailing_address, "_mail/default.tpl", $this->smarty);
							endif;

							$this->echo->trigger("private-user-" . md5($filtered["uid"]), "whatsapp", [
								"content" => ___(__("whatsapp_accountlink_notifymessage"), ["<strong>+{$phone[0]}</strong>"])
							]);

							response(200);
						else:
							response(500);
						endif;
					else:
						response(302);
					endif;
				else:
					response(500);
				endif;
			endif;
		else:
			if(!isset($request["system_token"], $request["uid"], $request["hash"], $request["unique"]))
				response(500);

			if($request["system_token"] != system_token)
				response(500);

			if($this->system->checkWaAccount($request["uid"], $request["unique"], "unique") > 0):
				$user = $this->system->getUser($request["uid"]);

				set_language($user["language"]);

				$account = $this->system->getWaAccount($request["uid"], $request["unique"], "unique");
				$wid = explode(":", $account["wid"]);

				$this->mail->send([
					"title" => system_site_name,
					"data" => [
						"subject" => mail_title(__("mail_waunlink_title")),
						"number" => "+{$wid[0]}",
						"unique" => $request["unique"]
					]
				], $user["email"], "_mail/wa_unlink.tpl", $this->smarty);

				response(200);
			else:
				response(500);
			endif;
		endif;
	}

	public function sent()
	{
		$this->header->allow();

		$request = $this->sanitize->array($_GET);

		if(!isset($request["system_token"], $request["id"], $request["cid"], $request["uid"], $request["hash"], $request["status"]))
			response(500);

		if($request["system_token"] != system_token)
			response(500);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_language($this->whatsapp->getUserLanguage($request["uid"]));

        $subscription = set_subscription(
            $this->system->checkSubscription($request["uid"]), 
            $this->system->getSubscription(false, $request["uid"]), 
            $this->system->getSubscription(false, false, true)
        );

		if(empty($subscription))
			response(403);

		if($this->system->checkQuota($request["uid"]) < 1):
			$this->system->create("quota", [
				"uid" => $request["uid"],
				"sent" => 0,
				"received" => 0,
				"wa_sent" => 0,
				"wa_received" => 0,
				"ussd" => 0
			]);
		endif;

		date_default_timezone_set($this->whatsapp->getUserTimezone($request["uid"]));

		$filtered = [
			"status" => $request["status"],
			"create_date" => date("Y-m-d H:i:s", time())
		];

		if($this->system->update($request["id"], $request["uid"], "wa_sent", $filtered)):
			if($request["cid"] > 0):
				$this->whatsapp->incrementProcessed($request["cid"]);
			endif;

			if($request["status"] == 3):
				$this->echo->trigger("private-user-{$request["hash"]}", "message", [
					"status" => 1,
					"content" => ___(__("whatsapp_sent_sentsuccess"), ["<strong><a href=\"#\" class=\"text-warning\" system-toggle=\"view/wa.sent-{$request["id"]}\">#{$request["id"]}</a></strong>"])
				]);
			else:
				$this->echo->trigger("private-user-{$request["hash"]}", "message", [
					"status" => 2,
					"content" => ___(__("whatsapp_sent_sentfailed"), ["<strong><a href=\"#\" class=\"text-warning\" system-toggle=\"view/wa.sent-{$request["id"]}\">#{$request["id"]}</a></strong>"])
				]);
			endif;

			$this->process->_sanitize = $this->sanitize;

			/**
			 * Process Action Hooks
			 */

			$whatsappChat = $this->whatsapp->getChat($request["id"]);

			if($whatsappChat):
				$hooks = $this->process->actionHooks($request["uid"], 2, 1, $whatsappChat["phone"], $whatsappChat["message"], $this->whatsapp->getActions($request["uid"], 1));

				if(!empty($hooks)):
					foreach($hooks as $hook):
						$this->system->create("events", [
							"uid" => $request["uid"],
							"type" => 2,
							"create_date" => date("Y-m-d H:i:s", time())
						]);
					endforeach;
				endif;
			endif;

			response(200);
		else:
			response(500);
		endif;
	}

	public function received()
	{
		$this->header->allow();

		$request = $this->sanitize->array($_POST);

		if(!isset($request["system_token"], $request["uid"], $request["hash"], $request["unique"], $request["phone"], $request["message"], $request["timestamp"]))
			response(500);

		if($request["system_token"] != system_token)
			response(500);

		if(!is_string($request["phone"]))
			response(500);

		if(!preg_match("/^[0-9-]+@g\.us$/", $request["phone"]) && !preg_match("/^\+?[0-9]{5,15}$/", $request["phone"]))
			response(200);

		// The guard above tolerates a leading "+", but every consumer below
		// prepends one of its own. Normalise to bare digits here so an E164
		// sender cannot produce "++63…". Group JIDs are left untouched.
		if(!find("@g.us", $request["phone"]))
			$request["phone"] = ltrim($request["phone"], "+");

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_language($this->whatsapp->getUserLanguage($request["uid"]));

        $subscription = set_subscription(
            $this->system->checkSubscription($request["uid"]), 
            $this->system->getSubscription(false, $request["uid"]), 
            $this->system->getSubscription(false, false, true)
        );

		if(empty($subscription))
			response(403);

		if($this->system->checkQuota($request["uid"]) < 1):
			$this->system->create("quota", [
				"uid" => $request["uid"],
				"sent" => 0,
				"received" => 0,
				"wa_sent" => 0,
				"wa_received" => 0,
				"ussd" => 0
			]);
		endif;

		date_default_timezone_set($this->whatsapp->getUserTimezone($request["uid"]));

		if(!limitation($subscription["wa_receive_limit"], $this->system->countQuota($request["uid"], "wa_received"))):
			if($this->whatsapp->checkAccount($request["uid"], $request["unique"]) > 0):
				$account = $this->system->getWaAccount($request["uid"], $request["unique"], "unique");
				
				$filtered = [
					"uid" => $request["uid"],
					"wid" => $account["wid"],
					"unique" => $account["unique"],
					"phone" => strpos($request["phone"], "@g.us") !== false ? $request["phone"] : "+{$request["phone"]}",
					"group" => isset($request["group"]) && !empty($request["group"]) ? $request["group"] : false,
					"message" => $request["message"],
					"receive_date" => date("Y-m-d H:i:s", time())
				];

				$received = $this->system->create("wa_received", $filtered);

				if($received):
					$waServer = $this->system->getWaServer($request["unique"], "unique");
					
					if($waServer):
						if(strtolower(substr($filtered["message"], 0, 4)) === "stop"):
							if($this->system->checkUnsubscribed($request["uid"], $filtered["phone"]) < 1):
								$this->system->create("unsubscribed", [
									"uid" => $request["uid"],
									"phone" => $filtered["phone"]
								]);
							endif;
						endif;

						if(isset($request["file"]) && !empty($request["file"])):
							$this->wa->download($this->file, $waServer["secret"], $waServer["url"], $waServer["port"], $filtered["unique"], $request["file"], $received);
						endif;

						$this->echo->trigger("private-user-{$request["hash"]}", "message", [
							"status" => 1,
							"content" => ___(__("whatsapp_received_chat"), ["<strong><a href=\"#\" class=\"text-warning\" system-toggle=\"view/wa.received-{$received}\">#{$received}</a></strong>"])
						]);

						$this->system->increment($request["uid"], "wa_received");

						$this->process->_sanitize = $this->sanitize;

						/**
						 * Process Webhooks
						 */

						$wid = explode(":", $filtered["wid"]);

						$attachmentLink = false;

						try {
							$fileName = checkFile($received, "uploads/whatsapp/received/{$filtered["unique"]}");

							if($fileName):
								$attachmentLink = site_url("uploads/whatsapp/received/{$filtered["unique"]}/{$fileName}", true);
							endif;
						} catch(Exception $e){
							// Ignore
						}
						
						$webhooks = $this->process->webhooks($filtered["uid"], "whatsapp", [
							"id" => (int) $received,
							"wid" => "+{$wid[0]}",
							"phone" => $filtered["phone"],
							"message" => $filtered["message"],
							"attachment" => $attachmentLink,
							"timestamp" => strtotime($filtered["receive_date"])
						], $this->whatsapp->getWebhooks($filtered["uid"], "whatsapp"));

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
										"uid" => $filtered["uid"],
										"type" => 1,
										"create_date" => date("Y-m-d H:i:s", time())
									]);
								endif;
							endforeach;
						endif;

						/**
						 * Process Action Hooks
						 */

						$hooks = $this->process->actionHooks($filtered["uid"], 2, 2, $filtered["phone"], $filtered["message"], $this->whatsapp->getActions($filtered["uid"], 1));

						if(!empty($hooks)):
							foreach($hooks as $hook):
								$this->system->create("events", [
									"uid" => $filtered["uid"],
									"type" => 2,
									"create_date" => date("Y-m-d H:i:s", time())
								]);
							endforeach;
						endif;

						/**
						 * Process Action Autoreplies
						 */
						
						if (!$this->flowlib->isUserPaused($filtered["uid"], $filtered["phone"], "autoreply") && 
							!$this->flowlib->isUserPaused($filtered["uid"], $filtered["phone"], "all")):
							
							$autoreplies = $this->process->actionAutoreplies($filtered["uid"], 2, $filtered["phone"], $filtered["message"], $this->whatsapp->getActions($filtered["uid"], 2), $subscription, [
								"account" => $account["id"],
								"titansys" => $this->titansys,
								"aiprovider" => $this->aiprovider,
								"attachment" => $attachmentLink
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
									$createReply = $this->system->create("wa_sent", [
										"cid" => 0,
										"uid" => $filtered["uid"],
										"wid" => $account["wid"],
										"unique" => $filtered["unique"],
										"phone" => $filtered["phone"],
										"message" => $autoreply["message"],
										"status" => 1,
										"priority" => $autoreply["priority"] < 2 ? 1 : 2,
										"api" => 2,
										"create_date" => date("Y-m-d H:i:s", time())
									]);

									if($createReply):
										$this->system->create("events", [
											"uid" => $filtered["uid"],
											"type" => 2,
											"create_date" => date("Y-m-d H:i:s", time())
										]);

										if($autoreply["priority"] < 2):
											$this->wa->sendPriority($waServer["secret"], $waServer["url"], $waServer["port"], $filtered["unique"], $createReply, $filtered["phone"], $autoreply["message"]);
										else:
											$this->wa->send($waServer["secret"], $waServer["url"], $waServer["port"], $filtered["unique"]);
										endif;
									endif;
								endif;
							endforeach;
						endif;

						/**
						 * Process Flows
						 */

						$flows = $this->flow->getFlows($filtered["uid"], "whatsapp");
						
						$isFlowPaused = $this->flowlib->isUserPaused($filtered["uid"], $filtered["phone"], "flow");
						$isAllPaused = $this->flowlib->isUserPaused($filtered["uid"], $filtered["phone"], "all");
						
						$messageLeadsToResume = $this->flowlib->messageLeadsToResume(
							$flows, 
							$filtered["message"], 
							"whatsapp",
							["account" => $filtered["unique"]]
						);
						
						if (!$isFlowPaused && !$isAllPaused) {
							$flowSteps = $this->flowlib->start($filtered["uid"], $filtered["phone"], $filtered["message"], $flows, $subscription, [
								"account" => $filtered["unique"],
								"titansys" => $this->titansys,
								"aiprovider" => $this->aiprovider,
								"attachment" => $attachmentLink
							]);
						} elseif (($isFlowPaused || $isAllPaused) && $messageLeadsToResume) {
							$flowSteps = $this->flowlib->start($filtered["uid"], $filtered["phone"], $filtered["message"], $flows, $subscription, [
								"account" => $filtered["unique"],
								"titansys" => $this->titansys,
								"aiprovider" => $this->aiprovider,
								"attachment" => $attachmentLink
							]);
						} else {
							$flowSteps = [];
						}

						if(!empty($flowSteps)):
							foreach($flowSteps as $flowStep):
								foreach($flowStep["steps"] as $step):
									// Skip steps without type key to prevent errors
									if(!isset($step["type"])):
										continue;
									endif;
									
									if($step["type"] == "ai_reply"):
										// Get AI key configuration
										$userAiKeys = $this->flow->getAiKeys($filtered["uid"]);
										$aiKey = isset($userAiKeys[$step["ai_key"]]) ? $userAiKeys[$step["ai_key"]] : null;
										
										if(!$aiKey || $aiKey["uid"] != $filtered["uid"]):
											// Invalid or missing AI key - skip without breaking flow
											continue;
										endif;
										
										// Generate unique conversation identifier (same pattern as autoreply)
										$conversationId = sha1($filtered["phone"]) . "_" . md5($filtered["uid"]) . "_" . sha1(site_url);
										
										// Call AI service using local provider library
										$responseFromAi = $this->aiprovider->conversation(
											$filtered["uid"],                   // Zender user id
											$conversationId,                    // Unique conversation ID
											$aiKey["provider"],                 // Provider (openai, geminiai, claudeai, etc.)
											$aiKey["apikey"],                   // API key
											$aiKey["model"],                    // Model
											$aiKey["prompt"] . " (User Phone Number: {$filtered["phone"]})", // Prompt with context
											$filtered["message"],               // User message
											$aiKey["max_tokens"],               // Max tokens
											$aiKey["history"]                   // Use history
										);

										if($responseFromAi !== false && $responseFromAi !== ""):
											// Log successful AI response
											$this->system->create("logger", [
												"uid" => $filtered["uid"],
												"type" => "flow_event",
												"reason" => "AI Flow Response Generated",
												"content" => "Type: WhatsApp Flow, User: {$filtered["phone"]}, AI Key: {$step["ai_key"]}, Message: {$filtered["message"]}",
												"create_date" => date("Y-m-d H:i:s", time())
											]);

											$responses = [$responseFromAi];

											foreach($responses as $aiReply):
												// Not !empty() — empty("0") is true, so a
												// valid one-character reply of "0" would be
												// dropped here despite passing the guard above.
												if($aiReply !== false && $aiReply !== ""):
													// Create message record
													$sendMessage = $this->system->create("wa_sent", [
														"cid" => 0,
														"uid" => $filtered["uid"],
														"wid" => $account["wid"],
														"unique" => $filtered["unique"],
														"phone" => $filtered["phone"],
														"message" => json_encode(["text" => $aiReply]),
														"status" => 1,
														"priority" => $step["priority"] ?? 2,  // Use step priority (default 2)
														"api" => 2,
														"create_date" => date("Y-m-d H:i:s", time())
													]);

													if($sendMessage):
														// Use priority-based sending (matches other flow steps)
														if(($step["priority"] ?? 2) < 2):
															// High priority (1) - send immediately
															$this->wa->sendPriority($waServer["secret"], $waServer["url"], $waServer["port"], 
																$filtered["unique"], $sendMessage, $filtered["phone"], json_encode(["text" => $aiReply]));
														else:
															// Normal priority (2+) - add to queue
															$this->wa->send($waServer["secret"], $waServer["url"], $waServer["port"], $filtered["unique"]);
														endif;
													endif;
												endif;
											endforeach;
										else:
											// Log failed AI response
											$this->system->create("logger", [
												"uid" => $filtered["uid"],
												"type" => "flow_event",
												"reason" => "AI Flow Response Failed",
												"content" => "Type: WhatsApp Flow, User: {$filtered["phone"]}, AI Key: {$step["ai_key"]}, Message: {$filtered["message"]}, Error: No Response",
												"create_date" => date("Y-m-d H:i:s", time())
											]);
										endif;
									elseif($step["type"] == "save_contact"):
										// Log save contact flow event
										$this->system->create("logger", [
											"uid" => $filtered["uid"],
											"type" => "flow_event",
											"reason" => "Contact Save Flow Executed",
											"content" => "Type: WhatsApp Flow, User: {$filtered["phone"]}, Group: {$step["group"]}, Action: Save Contact",
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
											"content" => "Type: WhatsApp Flow, User: {$filtered["phone"]}, Priority: {$step["priority"]}, Message: " . substr($step["message"], 0, 100) . (strlen($step["message"]) > 100 ? "..." : ""),
											"create_date" => date("Y-m-d H:i:s", time())
										]);
										
										$sendMessage = $this->system->create("wa_sent", [
											"cid" => 0,
											"uid" => $filtered["uid"],
											"wid" => $account["wid"],
											"unique" => $filtered["unique"],
											"phone" => $filtered["phone"],
											"message" => $step["message"],
											"status" => 1,
											"priority" => $step["priority"] < 2 ? 1 : 2,
											"api" => 2,
											"create_date" => date("Y-m-d H:i:s", time())
										]);
		
										if($sendMessage):
											if($step["priority"] < 2):
												$this->wa->sendPriority($waServer["secret"], $waServer["url"], $waServer["port"], $filtered["unique"], $sendMessage, $filtered["phone"], $step["message"]);
											else:
												$this->wa->send($waServer["secret"], $waServer["url"], $waServer["port"], $filtered["unique"]);
											endif;
										endif;
									endif;
								endforeach;
							endforeach;
						endif;
					endif;

					response(200);
				else:
					response(500);
				endif;
			endif;
		endif;
	}
}