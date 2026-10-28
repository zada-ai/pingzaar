<?php

ini_set("memory_limit", "1024M");

class Cron_Controller extends MVC_Controller
{
	public function index()
	{
		$this->header->allow();

		$type = $this->sanitize->string($this->url->segment(3));
		$token = $this->sanitize->string($this->url->segment(4));

		if($token != system_token)
			response(500, false);

		$this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

		switch($type):
			case "quota":
				$this->cache->container("system.cron");
				$this->cache->set($type, date("F j, Y (h:i A)"));

				$quotas = $this->cron->getQuota();

				if(!empty($quotas)):
					foreach($quotas as $quota):
						$this->cache->container("user.{$quota["hash"]}");
						$this->cache->clear();
					endforeach;
				endif;

				$this->cron->resetQuota();

				break;
			case "sender":
				$this->cache->container("system.cron");
				$this->cache->set($type, date("F j, Y (h:i A)"));

				$getPendingSms = $this->cron->getPendingSms();

				$smsQueue = [];

				if(!empty($getPendingSms)):
					foreach($getPendingSms as $sms):
						$smsQueue[sha1("{$sms["mode"]}_{$sms["uid"]}_{$sms["did"]}")] = [
							"uid" => $sms["uid"],
							"did" => $sms["did"],
							"mode" => $sms["mode"],
							//"fcm_token" => $sms["fcm_token"]
						];
					endforeach;

					if(!empty($smsQueue)):
						foreach($smsQueue as $queue):
							if($queue["mode"] < 2):
								$device = $this->system->getDevice($queue["uid"], $queue["did"], "did");
							else:
								$device = $this->system->getDevice(false, $queue["did"], "global");
							endif;

							if($device):
								$this->fcm->sendWithToken($device["fcm_token"], [
									"action" => "message_request"
								]);
							endif;
						endforeach;
					endif;
				endif;

				$getPendingWa = $this->cron->getPendingWa();

				$waQueue = [];

				if(!empty($getPendingWa)):
					foreach($getPendingWa as $wa):
						$waQueue[sha1("{$wa["uid"]}_{$wa["unique"]}")] = [
							"uid" => $wa["uid"],
							"unique" => $wa["unique"]
						];
					endforeach;

					if(!empty($waQueue)):
						foreach($waQueue as $queue):
							$account = $this->system->getWaAccount($queue["uid"], $queue["unique"], "unique");

							if($account):
								$waServer = $this->system->getWaServer($account["wsid"], "id");

								try {
									$this->wa->send($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"]);
								} catch(Exception $e){
									// Ignore
								}
							endif;
						endforeach;
					endif;
				endif;

				break;
			case "sms.scheduled":
				$this->cache->container("system.cron");
				$this->cache->set($type, date("F j, Y (h:i A)"));
				
				$schedules = $this->cron->getScheduled();

				if(!empty($schedules)):
					foreach($schedules as $scheduled):
				    	date_default_timezone_set(
				    		$scheduled["timezone"] ?: "UTC"
				    	);

				    	$rejected = false;

				    	if($scheduled["mode"] < 2):
				    		$subscription = set_subscription(
			                    $this->system->checkSubscription($scheduled["uid"]), 
			                    $this->system->getSubscription(false, $scheduled["uid"]), 
			                    $this->system->getSubscription(false, false, true)
			                );

							if(empty($subscription))
								$rejected = true;

							if(!$this->sanitize->isInt($scheduled["sim"]))
								$rejected = true;

			    			if(empty($this->system->getDevices($scheduled["uid"])))
			    				$rejected = true;

			    			if($this->system->checkDevice($scheduled["uid"], $scheduled["did"], "did") < 1)
		    					$rejected = true;

			    			$device = $this->system->getDevice($scheduled["uid"], $scheduled["did"], "did");
				    	else:
				    		if($this->sanitize->isInt($scheduled["gateway"])):
				    			$gateways = $this->system->getGateways();

								if(!array_key_exists($scheduled["gateway"], $gateways)):
									$rejected = true;
								endif;

								if(!$this->file->exists("system/gateways/" . md5($scheduled["gateway"]) . ".php"))
									$rejected = true;

								try {
									$gatewayHandler = require "system/gateways/" . md5($scheduled["gateway"]) . ".php";
								} catch(Exception $e){
									$rejected = true;
								}
				    		else:
				    			$device = $this->system->getDevice(false, $scheduled["gateway"], "global");

				    			if($device):
									if($device["uid"] == $scheduled["uid"]):
										$rejected = true;
									endif;

					    			if($device["global_device"] > 1):
					    				$rejected = true;
					    			endif;
					    		else:
					    			$rejected = true;
					    		endif;
				    		endif;
				    	endif;
						
						if(!$rejected):
							$approve = true;

					        if($scheduled["repeat"] > 0):
				        		if(!empty($scheduled["last_send"])):
									$original_time = date("H:i:s", $scheduled["send_date"]);
				        			$expected_send = strtotime(date("Y-m-d {$original_time}", $scheduled["last_send"]) . " +{$scheduled["repeat"]} days");

				        			if($scheduled["repeat"] == 365):
				        				$expected_send = strtotime(date("Y-m-d {$original_time}", $scheduled["last_send"]) . " +1 year");

				        				if($expected_send > time()):
											$approve = false;
										endif;
				        			else:
				        				if($expected_send > time()):
											$approve = false;
										endif;
				        			endif;
				        		else:
				        			if(time() < $scheduled["send_date"]):
										$approve = false;
									endif;
				        		endif;
							else:
								if(time() < $scheduled["send_date"]):
									$approve = false;
								endif;
							endif;

							if($approve):
								$contactBook = [];

				    			$groups = explode(",", $scheduled["groups"]);

				    			if(!in_array(0, $groups)):
									foreach($groups as $group):
										if($this->system->checkGroup($scheduled["uid"], $group) > 0):
											$contacts = $this->system->getContactsByGroup($scheduled["uid"], $group);

											if(!empty($contacts)):
												foreach($contacts as $contact):
													$rejected = false;

													try {
														$phone = $this->phone->parse($contact["phone"]);
														$country = $phone->getRegionCode();
													} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
														$rejected = true;
													}

													if($this->system->checkUnsubscribed($scheduled["uid"], $contact["phone"]) > 0)
														$rejected = true;

													if(!$rejected):
														if($scheduled["mode"] > 1 && $this->sanitize->isInt($scheduled["gateway"])):
															$pricing = json_decode($gateways[$scheduled["gateway"]]["pricing"], true);
							
															if(array_key_exists(strtolower($country), $pricing["countries"])):
																$price = $pricing["countries"][strtolower($country)];
															else:
																$price = $pricing["default"];
															endif;
														else:
															$price = 0;
														endif;

														$contactBook[] = [
															"name" => $contact["name"],
															"phone" => $contact["phone"],
															"group" => $contact["group"],
															"country" => $country,
															"price" => $price
														];
													endif;
												endforeach;
											endif;
										endif;
									endforeach;
								endif;

								$numbers = explode(",", trim($scheduled["numbers"]));

								if(!empty($numbers) && !empty($numbers[0])):
									foreach($numbers as $number):
										$valid = true;

										try {
										    $phone = $this->phone->parse($number, $scheduled["country"]);

							    			if(!$phone->isValidNumber())
												$valid = false;

											$phoneNumber = $phone->format(Brick\PhoneNumber\PhoneNumberFormat::E164);
											$country = $phone->getRegionCode();
										} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
											$valid = false;
										}

										// Numbers typed straight into a campaign were never
										// checked against the unsubscribe list — only those
										// pulled from contact groups were. Unsubscribing has
										// to hold whichever way the recipient was added.
										// The list stores E164, which is what $phoneNumber is.
										if($valid && $this->system->checkUnsubscribed($scheduled["uid"], $phoneNumber) > 0)
											$valid = false;

										if($valid):
											if($scheduled["mode"] > 1 && $this->sanitize->isInt($scheduled["gateway"])):
												$pricing = json_decode($gateways[$scheduled["gateway"]]["pricing"], true);
				
												if(array_key_exists(strtolower($country), $pricing["countries"])):
													$price = $pricing["countries"][strtolower($country)];
												else:
													$price = $pricing["default"];
												endif;
											else:
												$price = 0;
											endif;

											$contactBook[] = [
												"name" => $phoneNumber,
												"phone" => $phoneNumber,
												"group" => "Unknown",
												"country" => $country,
												"price" => $price
											];
										endif;
									endforeach;
								endif;

								if($scheduled["mode"] < 2):
									$device = $this->system->getDevice($scheduled["uid"], $scheduled["did"], "did");
								else:
									if(!$this->sanitize->isInt($scheduled["gateway"])):
										$device = $this->system->getDevice($scheduled["uid"], $scheduled["did"], "did");
									endif;
								endif;

								$smsCampaign = $this->system->create("campaigns", [
									"uid" => $scheduled["uid"],
									"did" => $scheduled["mode"] < 2 ? $scheduled["did"] : ($this->sanitize->isInt($scheduled["gateway"]) ? false : $scheduled["gateway"]),
									"gateway" => $scheduled["mode"] > 1 && $this->sanitize->isInt($scheduled["gateway"]) ? $scheduled["gateway"] : 0,
									"mode" => $scheduled["mode"],
									"status" => 1,
									"name" => "{$scheduled["name"]}",
									"contacts" => count($contactBook),
									"create_date" => date("Y-m-d H:i:s", time())
								]);

								$sendCounter = 0;

								foreach($contactBook as $contact):
									if($scheduled["mode"] < 2):
										if(!limitation($subscription["send_limit"], $this->system->countQuota($scheduled["uid"], "sent"))):
											$rejectLimit = false;

							    			if(!$rejectLimit):
								        		$this->system->create("sent", [
								        			"cid" => $smsCampaign,
										        	"uid" => $scheduled["uid"],
													"did" => $scheduled["did"],
													"gateway" => 0,
													"sim" => $scheduled["sim"],
													"mode" => 1,
													"phone" => $contact["phone"],
													"message" => $this->spintax->process($this->lex->parse(footermark($subscription["footermark"], $scheduled["message"], system_message_mark), [
								        				"contact" => [
								        					"name" => $contact["name"],
								        					"number" => $contact["phone"]
								        				],
								        				"group" => [
								        					"name" => $contact["group"]
								        				],
								        				"date" => [
								        					"now" => date("F j, Y"),
								        					"time" => date("h:i A") 
								        				]
								        			])),
													"status" => 1,
													"status_code" => false,
													"priority" => 1,
													"api" => 2,
													"create_date" => date("Y-m-d H:i:s", time())
										        ]);

										        $sendCounter++;
								        	endif;
									    endif;
									else:
										$credits = $this->system->getCredits($scheduled["uid"]);

										if($this->sanitize->isInt($scheduled["gateway"])):
											if($credits >= $contact["price"]):
												$gateway = $gateways[$scheduled["gateway"]];
				
												$message = $this->spintax->process($this->lex->parse($scheduled["message"], [
													"contact" => [
														"name" => $contact["name"],
														"number" => $contact["phone"]
													],
													"group" => [
														"name" => $contact["group"]
													],
													"date" => [
														"now" => date("F j, Y"),
														"time" => date("h:i A") 
													]
												]));

												$send = $gatewayHandler["send"]($contact["phone"], $message, $this);
				
												if($send):
													$create = $this->system->create("sent", [
														"cid" => $smsCampaign,
														"uid" => $scheduled["uid"],
														"did" => false,
														"gateway" => $scheduled["gateway"],
														"api" => 0,
														"sim" => 0,
														"mode" => 2,
														"priority" => 0,
														"phone" => $contact["phone"],
														"message" => $message,
														"status" => $gateway["callback"] < 2 ? 2 : 3,
														"status_code" => false,
														"create_date" => date("Y-m-d H:i:s", time())
													]);
				
													if($create):
														if($gateway["callback"] < 2):
															$this->cache->container("system.gateways");
				
															$this->cache->set("{$gateway["callback_id"]}.{$send}", $create);
														else:
															$this->process->_sanitize = $this->sanitize;
										
															$hooks = $this->process->actionHooks($scheduled["uid"], 1, 1, $contact["phone"], $message, $this->device->getActions($scheduled["uid"], 1));
				
															if(!empty($hooks)):
																foreach($hooks as $hook):
																	$this->system->create("events", [
																		"uid" => $scheduled["uid"],
																		"type" => 2,
																		"create_date" => date("Y-m-d H:i:s", time())
																	]);
																endforeach;
															endif;
				
															$this->system->credits($scheduled["uid"], "decrease", $contact["price"]);
														endif;
													endif;
												endif;
											endif;
										else:
											$currency = country($device["country"])->getCurrency()["iso_4217_code"];
											$final_price = $this->titansys->calculatePartnerSendPrice($currency, $device["rate"], $this->cache);

											if($final_price && $credits >= ($final_price * count($contactBook))):
												$slots = explode(",", $device["global_slots"]);
												$sim = count($slots) > 1 ? rand(1, 2) : ($slots[0] < 2 ? 1 : 2);

												$rejectLimit = false;

												if(!$rejectLimit):
													$this->system->create("sent", [
														"cid" => $smsCampaign,
														"uid" => $scheduled["uid"],
														"did" => $scheduled["did"],
														"gateway" => 0,
														"sim" => $sim,
														"mode" => 2,
														"phone" => $contact["phone"],
														"message" => $this->spintax->process($this->lex->parse($scheduled["message"], [
															"contact" => [
																"name" => $contact["name"],
																"number" => $contact["phone"]
															],
															"group" => [
																"name" => $contact["group"]
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

								if($scheduled["mode"] < 2 || !$this->sanitize->isInt($scheduled["gateway"])):
									if($sendCounter < 1):
										$this->system->delete($scheduled["uid"], $smsCampaign, "campaigns");
										response(500);
									endif;

									if($sendCounter < count($contactBook)):
										$this->system->update($smsCampaign, $scheduled["uid"], "campaigns", [
											"contacts" => $sendCounter
										]);
									endif;
								endif;

								if($sendCounter > 0):
									if($scheduled["mode"] < 2 || $scheduled["gateway"] < 1):
										$this->fcm->sendWithToken($device["fcm_token"], [
											"action" => "message_request"
										]);
									endif;
								endif;

								if($scheduled["repeat"] > 0):
									$this->cron->updateLastSend($scheduled["id"], time());
								else:
									$this->system->delete($scheduled["uid"], $scheduled["id"], "scheduled");
								endif;

								unset($sendCounter);
							endif;
						endif;
					endforeach;
				endif;

				break;
			case "wa.scheduled":
				$this->cache->container("system.cron");
				$this->cache->set($type, date("F j, Y (h:i A)"));

				$schedules = $this->cron->getScheduled(true);

				if(!empty($schedules)):
					foreach($schedules as $scheduled):
				    	date_default_timezone_set(
				    		$scheduled["timezone"] ?: "UTC"
				    	);

				    	$rejected = false;

				    	$subscription = set_subscription(
		                    $this->system->checkSubscription($scheduled["uid"]), 
		                    $this->system->getSubscription(false, $scheduled["uid"]), 
		                    $this->system->getSubscription(false, false, true)
		                );

						if(empty($subscription))
							$rejected = true;

						if($this->cron->checkWaAccount($scheduled["uid"], $scheduled["wid"]) < 1)
							$rejected = true;

						if($this->system->checkQuota($scheduled["uid"]) < 1):
							$this->system->create("quota", [
								"uid" => $scheduled["uid"],
								"sent" => 0,
								"received" => 0,
								"wa_sent" => 0,
								"wa_received" => 0,
								"ussd" => 0
							]);
						endif;
						
						if(!$rejected):
							$approve = true;

							if($scheduled["repeat"] > 0):
				        		if(!empty($scheduled["last_send"])):
				        			$original_time = date("H:i:s", $scheduled["send_date"]);
				        			$expected_send = strtotime(date("Y-m-d {$original_time}", $scheduled["last_send"]) . " +{$scheduled["repeat"]} days");

				        			if($scheduled["repeat"] == 365):
				        				$expected_send = strtotime(date("Y-m-d {$original_time}", $scheduled["last_send"]) . " +1 year");

				        				if($expected_send > time()):
											$approve = false;
										endif;
				        			else:
				        				if($expected_send > time()):
											$approve = false;
										endif;
				        			endif;
				        		else:
				        			if(time() < $scheduled["send_date"]):
										$approve = false;
									endif;
				        		endif;
							else:
								if(time() < $scheduled["send_date"]):
									$approve = false;
								endif;
							endif;

							if($approve):
								$contactBook = [];

								$numbers = explode(",", trim($scheduled["numbers"]));

								if(!empty($numbers) && !empty($numbers[0])):
									foreach($numbers as $number):
										$valid = true;

										if(!find("@g.us", $number)):
											try {
											    $phone = $this->phone->parse($number, $scheduled["country"]);

											    if(!$phone->isValidNumber())
													$valid = false;

												$phoneNumber = $phone->format(Brick\PhoneNumber\PhoneNumberFormat::E164);

												if($phone->getRegionCode() == "MX"):
													$phoneNumber = formatMexicoNumWa($phoneNumber);
												endif;
											} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
												$valid = false;
											}
										else:
											$phoneNumber = $number;
										endif;

										// As above: honour the unsubscribe list for typed
										// numbers too. Group JIDs are skipped — unsubscribing
										// applies to a person, not a WhatsApp group.
										if($valid && !find("@g.us", $number) && $this->system->checkUnsubscribed($scheduled["uid"], $phoneNumber) > 0)
											$valid = false;

										if($valid):
											$contactBook[] = [
												"name" => $phoneNumber,
												"phone" => $phoneNumber,
												"group" => "Unknown"
											];
										endif;
									endforeach;
								endif;

				    			$groups = explode(",", $scheduled["groups"]);

				    			if(!in_array(0, $groups)):
									foreach($groups as $group):
										if($this->system->checkGroup($scheduled["uid"], $group) > 0):
											$contacts = $this->system->getContactsByGroup($scheduled["uid"], $group);

											if(!empty($contacts)):
												foreach($contacts as $contact):
													$rejected = false;

													try {
														$number = $this->phone->parse($contact["phone"]);
														
														$contact["phone"] = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);
														
														if($number->getRegionCode() == "MX"):
															$contact["phone"] = formatMexicoNumWa($contact["phone"]);
														endif;
													} catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
														$rejected = true;
													}

													if($this->system->checkUnsubscribed($scheduled["uid"], $contact["phone"]) > 0)
														$rejected = true;

													if(!$rejected):
														$contactBook[] = [
															"name" => $contact["name"],
															"phone" => $contact["phone"],
															"group" => $contact["group"]
														];
													endif;
												endforeach;
											endif;
										endif;
									endforeach;
								endif;

								if(!empty($contactBook)):
									$account = $this->system->getWaAccount($scheduled["uid"], $scheduled["unique"], "unique");

									if($account):
										$waServer = $this->system->getWaServer($account["wsid"], "id");

										if($waServer && $this->wa->check($waServer["url"], $waServer["port"])):
											$status = $this->wa->status($waServer["secret"], $waServer["url"], $waServer["port"], $scheduled["unique"]);

											if($status == "connected"):
												$account = $this->cron->getWaAccount($scheduled["uid"], $scheduled["wid"]);

												$waCampaign = $this->system->create("wa_campaigns", [
													"uid" => $scheduled["uid"],
													"wid" => $account["wid"],
													"unique" => $account["unique"],
													"type" => "scheduled",
													"status" => 1,
													"name" => $scheduled["name"],
													"contacts" => count($contactBook),
													"processed" => 0,
													"create_date" => date("Y-m-d H:i:s", time())
												]);

												$sendCounter = 0;

												try {
													$message = json_decode($scheduled["message"], true, JSON_THROW_ON_ERROR);

													foreach($contactBook as $contact):
														if(!limitation($subscription["wa_send_limit"], $this->system->countQuota($scheduled["uid"], "wa_sent"))):
															if(isset($message["text"]) || isset($message["caption"])):
																$messageContainer = $message;
				
																$formatMessage = $this->spintax->process($this->lex->parse(footermark($subscription["footermark"], isset($messageContainer["text"]) ? decodeBraces($messageContainer["text"]) : decodeBraces($messageContainer["caption"]), system_message_mark), [
																	"contact" => [
																		"name" => $contact["name"],
																		"number" => $contact["phone"]
																	],
																	"group" => [
																		"name" => $contact["group"]
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
																"uid" => $scheduled["uid"],
																"wid" => $scheduled["wid"],
																"unique" => $scheduled["unique"],
																"phone" => $contact["phone"],
																"message" => isset($messageContainer) ? json_encode($messageContainer) : json_encode($message),
																"status" => 1,
																"priority" => 2,
																"api" => 2,
																"create_date" => date("Y-m-d H:i:s", time())
															]);
				
															$sendCounter++;
														endif;
													endforeach;
												
													if($sendCounter > 0):
														$addQueue = $this->wa->send($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"]);
				
														if($addQueue):
															if($addQueue == 200):
																if($scheduled["repeat"] > 0):
																	$this->cron->updateLastSend($scheduled["id"], time(), true);
																else:
																	$this->system->delete($scheduled["uid"], $scheduled["id"], "wa_scheduled");
																endif;
															endif;
														endif;
													endif;
				
													unset($sendCounter);
												} catch(Exception $e){
													// Ignore
												}
											endif;
										endif;
									endif;
								endif;
							endif;
						endif;
					endforeach;
				endif;

				break;
			case "subscription":
				$this->cache->container("system.cron");
				$this->cache->set($type, date("F j, Y (h:i A)"));
			
				$subscriptions = $this->cron->getSubscriptions();
			
				if(!empty($subscriptions)):
					$this->cache->container("subscription.expiring", true);
			
					foreach($subscriptions as $subscription):
						$remainingDays = round(($subscription["expire"] - time()) / (60 * 60 * 24));
			
						if($remainingDays === 5):
							if(!$this->cache->has($subscription["id"])):
								$this->cache->set($subscription["id"], true, 518400);
			
								// Resolve the subscriber's language up front. __() reads
								// $GLOBALS["language"], which set_language() populates only
								// once per request, so evaluating the subject inline left the
								// first mail of a run with an undefined subject and every
								// later one stuck in the first subscriber's language.
								$subscriberLang = set_language($subscription["language"]);

								$this->mail->send([
									"title" => system_site_name,
									"data" => [
										"subject" => mail_title($subscriberLang["cron_package_expiring"] ?? __("cron_package_expiring"))
									],
									"lang" => $subscriberLang
								], $subscription["email"], "_mail/expiring.tpl", $this->smarty);
							endif;
						endif;
			
						if(time() >= $subscription["expire"]):
							if($this->cache->has($subscription["id"]))
								$this->cache->delete($subscription["id"]);
			
							$this->system->delete(false, $subscription["id"], "subscriptions");
			
							$subscriberLang = set_language($subscription["language"]);

							$this->mail->send([
								"title" => system_site_name,
								"data" => [
									"subject" => mail_title($subscriberLang["cron_package_expired"] ?? __("cron_package_expired"))
								],
								"lang" => $subscriberLang
							], $subscription["email"], "_mail/expired.tpl", $this->smarty);
						endif;
					endforeach;
				endif;
			
				break;				
			case "cleaner":
				$this->cache->container("system.cron");
				$this->cache->set($type, date("F j, Y (h:i A)"));

				$directories = [
					"uploads/whatsapp/received/*/*",
					"uploads/whatsapp/sent/*/*"
				];

				$daysOld = system_garbage_retention < 1 ? 7 : system_garbage_retention;
				$totalDays = $daysOld * 24 * 60 * 60;
				$currentTime = time();

				foreach($directories as $directoryPattern):
					$files = glob($directoryPattern);

					if($files):
						foreach($files as $filePath):
							if(is_file($filePath)):
								$fileModificationTime = filemtime($filePath);
								$timeDifference = $currentTime - $fileModificationTime;
								
								if($timeDifference > $totalDays):
									$this->file->delete($filePath);
								endif;
							endif;
						endforeach;
					endif;
				endforeach;

				$waServers = $this->system->getWaServers();

				if(!empty($waServers)):
					foreach($waServers as $waServer):
						$this->wa->garbage($waServer["url"], $waServer["port"], $daysOld);
					endforeach;
				endif;

				break;
			default:
				response(500);
		endswitch;

		response(200);
	}
}