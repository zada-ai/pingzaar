<?php
/**
 * @controller Widget
 */

class Widget_Controller extends MVC_Controller
{
	public function index()
	{
		$this->header->allow(site_url);
        
        $type = $this->sanitize->string($this->url->segment(3));
        set_template($this->sanitize->string($this->url->segment(4)));

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

		/**
		 * @type Modals
		 * @desc Modal template processor
		 */

		if($type == "modal"):
			$tpl = $this->sanitize->string($this->url->segment(5));
			$id = $this->sanitize->string($this->url->segment(6));

			if(!in_array($tpl, ["languages"])):
				if(!$this->session->has("logged")):
					response(302);
				endif;
			endif;

			if(!$this->smarty->templateExists(template . "/widgets/modals/{$tpl}.tpl")):
				response(500, __("response_invalid"));
			endif;

			switch($tpl):
				case "languages":
					$this->cache->container("system.languages");

			        if($this->cache->empty()):
			            $this->cache->setArray($this->system->getLanguages());
			        endif;

					$vars = [
						"template" => [
							"title" => __("widget_alllang_title"),
							"data" => [
								"languages" => $this->cache->getAll()
							]
						],
						"handler" => [
							"size" => "md"
						]
					];
					
					break;
				case "system.update":
					$vars = [
						"template" => [
							"title" => __("widget_systemupdate_title")
						],
						"handler" => [
							"tpl" => $tpl,
							"type" => "create",
							"size" => "md",
							"loader" => __("widget_systemupdate_loader"),
							"require" => "update|{$GLOBALS["__"]("widget_systemupdate_requireupdate")}"
						]
					];
					
					break;
				case "user.settings":
					try {
						$user = $this->widget->getUser(logged_id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$formatting = isset($user["formatting"]) && !empty($user["formatting"]) ? json_decode($user["formatting"], true) : [
				        "clock" => "g:i A",
				        "date" => "n/j/Y",
				        "container" => [
        					"clock_format" => 1,
        					"date_format" => 1,
        					"date_separator" => 2,
        					"separator_selected" => "/" 
        				]
				    ]; 

					$vars = [
						"template" => [
							"title" => __("modal_usersettings_title"),
							"data" => [
								"user" => $user,
								"formatting" => $formatting,
								"avatar" => $this->file->exists("uploads/avatars/" . logged_hash . ".jpg"),
								"timezones" => $this->timezones->generate(),
								"countries" => \CountryCodes::get("alpha2", "country")
							]
						],
						"handler" => [
							"id" => logged_id,
							"tpl" => $tpl,
							"type" => "update",
							"size" => "md",
							"loader" => __("widget_usersettings_loader"),
							"require" => "name|{$GLOBALS["__"]("require_name")}<=>email|{$GLOBALS["__"]("require_email")}"
						]
					];
					
					break;
				case "user.subscription":
					$vars = [
						"template" => [
							"title" => __("modal_subscription_title"),
							"data" => [
								"usage" => [
		                            "quota" => [
		                            	"sms_send" => number_format($this->system->countQuota(logged_id, "sent")),
		                            	"sms_receive" => number_format($this->system->countQuota(logged_id, "received")),
		                            	"wa_send" => number_format($this->system->countQuota(logged_id, "wa_sent")),
		                            	"wa_receive" => number_format($this->system->countQuota(logged_id, "wa_received")),
		                            	"ussd" => number_format($this->system->countQuota(logged_id, "ussd"))
		                            ],
		                            "scheduled" => number_format($this->system->countScheduled(logged_id)),
		                            "contacts" => number_format($this->system->countContacts(logged_id)),
		                            "keys" => number_format($this->system->countKeys(logged_id)),
		                            "webhooks" => number_format($this->system->countWebhooks(logged_id)),
		                            "actions" => number_format($this->system->countActions(logged_id)),
		                            "devices" => number_format($this->system->countDevices(logged_id)),
		                            "wa_accounts" => number_format($this->system->countWaAccounts(logged_id))
		                        ],
		                        "subscription" => set_subscription(
				                    $this->system->checkSubscription(logged_id), 
				                    $this->system->getSubscription(false, logged_id), 
				                    $this->system->getSubscription(false, false, true)
				                )
							]
						],
						"handler" => [
							"size" => "md"
						]
					];
					
					break;
				case "payment":
					if($this->sanitize->isInt($id)):
						$duration = $this->sanitize->string($this->url->segment(7));

						if($duration < 1)
							response(500, __("response_invalid"));

						if($this->widget->checkPackage($id) < 1)
							response(500, __("response_invalid"));

						$package = $this->widget->getPackage($id);

						$price = $this->titansys->convertSystemCurrencyToUsd($package["price"], $this->cache);

						// Refuse the checkout rather than proceeding on a failed
						// conversion. false multiplied by a duration is 0 in PHP, so
						// falling through here handed the gateway a zero amount and
						// sold the package for nothing.
						if($price === false || !is_numeric($price) || $price <= 0):
							@file_put_contents("system/storage/temporary/error.log",
								"Payment Error: currency conversion to USD failed for package {$id}; checkout refused (" . date("m/d/Y g:i A") . ")\n\n", FILE_APPEND);

							response(500, __("response_invalid"));
						endif;

				        $item = [
				        	"type" => 1,
				        	"data" => [
								"base_currency" => strtoupper(system_currency),
								"original_price" => $package["price"] * $duration,
								"converted_price" => $price * $duration,
								"package" => $package,
								"credits" => false,
								"duration" => $duration,
								"user" => [
									"id" => logged_id,
									"hash" => logged_hash,
									"name" => logged_name,
									"email" => logged_email
								]
							]
				        ];
					else:
						$credits = $this->sanitize->string($this->url->segment(7));

						if(!$this->sanitize->isInt($credits))
							response(500, __("response_invalid"));

						if($credits < 10)
							response(500, __("widget_payment_creditamountnotless"));

						$price = $this->titansys->convertSystemCurrencyToUsd($credits, $this->cache);

						// Same guard as the package branch above: a failed conversion
						// must not become a zero-price credit purchase.
						if($price === false || !is_numeric($price) || $price <= 0):
							@file_put_contents("system/storage/temporary/error.log",
								"Payment Error: currency conversion to USD failed for a {$credits} credit purchase; checkout refused (" . date("m/d/Y g:i A") . ")\n\n", FILE_APPEND);

							response(500, __("response_invalid"));
						endif;

						$item = [
				        	"type" => 2,
				        	"data" => [
								"base_currency" => strtoupper(system_currency),
								"original_price" => $credits,
								"converted_price" => $price,
								"package" => [],
								"credits" => $credits,
								"duration" => 0,
								"user" => [
									"id" => logged_id,
									"hash" => logged_hash,
									"name" => logged_name,
									"email" => logged_email
								]
							]
				        ];
					endif;

					if($this->system->delete(logged_id, false, "orders")):
						$this->system->create("orders", [
							"uid" => logged_id,
							"data" => json_encode($item)
						]);
					endif;

					$vars = [
						"template" => [
							"title" => __("widget_payment_title"),
							"data" => [
								"original_price" => $item["data"]["original_price"]
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "md"
						]
					];
					
					break;
				case "view":
					$type = explode("-", $id);

					if(count($type) < 2)
						response(500, __("response_invalid"));

					if(!$this->sanitize->isInt($type[1]))
						response(500, __("response_invalid"));

					switch($type[0]):
						case "sent":
							$title = __("and_view20");
							$icon = "book-reader";
							$content = $this->widget->getContent($type[1], "sent", "message");

							break;
						case "received":
							$title = __("and_view20");
							$icon = "book-reader";
							$content = $this->widget->getContent($type[1], "received", "message");

							break;
						case "wa.sent":
							$title = __("and_view20");
							$icon = "book-reader";
							
							try {
								$msgDecode = json_decode($this->widget->getContent($type[1], "wa_sent", "message"), true, JSON_THROW_ON_ERROR);
								
								if(isset($msgDecode["audio"])):
									$waMessage = __("table_wareceived_attachmentnomsg");
								else:
									$waMessage = isset($msgDecode["text"]) ? $msgDecode["text"] : $msgDecode["caption"];
								endif;
							} catch(Exception $e){
								$waMessage = $this->widget->getContent($type[1], "wa_sent", "message");
							}

							$content = $waMessage;
							
							break;
						case "wa.received":
							$title = __("and_view20");
							$icon = "book-reader";
							$waMessage = $this->widget->getContent($type[1], "wa_received", "message");
							$content = empty($waMessage) ? __("table_wareceived_attachmentnomsg") : $waMessage;

							break;
						case "bank":
							$title = __("widgets_viewbank_titlenew");
							$icon = "money-check";
							$content = $this->lex->parse('<span style="white-space: pre-line;">' . system_bank_template . '</span>', [
		        				"user" => [
		        					"name" => logged_name,
		        					"email" => logged_email,
		        					"country" => strtoupper(logged_country)
		        				],
		        				"order" => [
		        					"price" => number_format($type[1]) . " " . strtoupper(system_currency)
		        				]
		        			]);

							break;
						default:
							response(500, __("response_invalid"));
					endswitch;

					$vars = [
						"template" => [
							"data" => [
								"title" => $title,
								"icon" => $icon,
								"content" => $content ? $content : __("widget_view_contentremoved")
							]
						],
						"handler" => [
							"size" => "md"
						]
					];

					break;
				case "redeem":
					$vars = [
						"template" => [
							"title" => __("widget_redeem_title")
						],
						"handler" => [
							"id" => $id,
							"tpl" => "redeem",
							"size" => "md",
							"type" => "create",
							"loader" => __("widget_redeem_loader"),
							"require" => "code|{$GLOBALS["__"]("require_vouchercode")}"
						]
					];
					
					break;
				case "gateway.rates":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					$gateway = $this->widget->getGateway($id);

					if(!$gateway)
						response(500, __("response_invalid"));

					$pricing = json_decode($gateway["pricing"], true);

					$vars = [
						"template" => [
							"title" => ___(__("and_rate_gate_3new"), [$gateway["name"]]),
							"data" => [
								"gateway" => $gateway,
								"pricing" => $pricing
							] 
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg"
						]
					];
					
					break;
				case "add.duration":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					if($this->widget->checkPackage($id) < 1)
						response(500, __("response_invalid"));

					$vars = [
						"template" => [
							"title" => __("form_durationtitle"),
							"data" => [
								"package" => $this->widget->getPackage($id)
							]
						],
						"handler" => [
							"tpl" => $tpl
						]
					];
					
					break;
				case "add.credits":
					$vars = [
						"template" => [
							"title" => __("widget_addcredits_title")
						],
						"handler" => [
							"size" => "sm"
						]
					];
					
					break;
				case "add.payout":
					$vars = [
						"template" => [
							"title" => __("widget_addpayout_title")
						],
						"handler" => [
							"tpl" => $tpl,
							"type" => "create",
							"size" => "md",
							"loader" => __("widget_addpayout_loader"),
							"require" => "amount|{$GLOBALS["__"]("widget_addpayout_requireamount")}<=>provider|{$GLOBALS["__"]("widget_addpayout_requireprovider")}<=>address|{$GLOBALS["__"]("widget_addpayout_requireaddress")}"
						]
					];
					
					break;
				case "sms.quick":
					try {
						$phoneSample = $this->phone->getExampleNumber(logged_country, Brick\PhoneNumber\PhoneNumberType::MOBILE);
					} catch(Exception $e){
						$phoneSample = "+639123456789";
					}

					$vars = [
						"template" => [
							"title" => __("modal_smsquick_title"),
							"data" => [
								"phone" => $phoneSample,
								"devices" => $this->widget->getDevices(logged_id),
								"devicesGlobal" => $this->widget->getGlobalDevices(logged_id),
								"gateways" => $this->widget->getGateways(),
								"shorteners" => $this->widget->getShorteners(),
								"spintax_sample" => [
									"main" => ___(__("form_spintaxsample_main"), [
										"<strong>{" . __("form_spintaxsample_good") . "|" . __("form_spintaxsample_bad") . "}</strong>"
									]),
									"good" => __("form_spintaxsample_good"),
									"bad" => __("form_spintaxsample_bad")
								]
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"type" => "create",
							"size" => "md",
							"table" => "android.sent",
							"loader" => __("widget_smsquick_loader"),
							"require" => "phone|{$GLOBALS["__"]("require_phone")}<=>sim|{$GLOBALS["__"]("require_sim")}<=>priority|{$GLOBALS["__"]("require_priority")}<=>message|{$GLOBALS["__"]("require_message")}"
						]
					];
					
					break;
				case "sms.bulk":
					try {
						$phoneSample = $this->phone->getExampleNumber(logged_country, Brick\PhoneNumber\PhoneNumberType::MOBILE);
					} catch(Exception $e){
						$phoneSample = "+639123456789";
					}

					$vars = [
						"template" => [
							"title" => __("modal_smsbulk_title"),
							"data" => [
								"number" => $phoneSample,
								"groups" => $this->widget->getGroups(logged_id),
								"devices" => $this->widget->getDevices(logged_id),
								"devicesGlobal" => $this->widget->getGlobalDevices(logged_id),
								"gateways" => $this->widget->getGateways(),
								"shorteners" => $this->widget->getShorteners(),
								"templates" => $this->widget->getTemplates(logged_id),
								"spintax_sample" => [
									"main" => ___(__("form_spintaxsample_main"), [
										"<strong>{" . __("form_spintaxsample_good") . "|" . __("form_spintaxsample_bad") . "}</strong>"
									]),
									"good" => __("form_spintaxsample_good"),
									"bad" => __("form_spintaxsample_bad")
								]
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "create",
							"table" => "android.sent",
							"loader" => __("widget_smsbulk_loader"),
							"require" => "message|{$GLOBALS["__"]("require_message")}"
						]
					];
					
					break;
				case "sms.excel":
					$vars = [
						"template" => [
							"title" => __("widget_smsexcel_title"),
							"data" => [
								"devices" => $this->widget->getDevices(logged_id),
								"devicesGlobal" => $this->widget->getGlobalDevices(logged_id),
								"gateways" => $this->widget->getGateways(),
								"shorteners" => $this->widget->getShorteners(),
								"templates" => $this->widget->getTemplates(logged_id),
								"spintax_sample" => [
									"main" => ___(__("form_spintaxsample_main"), [
										"<strong>{" . __("form_spintaxsample_good") . "|" . __("form_spintaxsample_bad") . "}</strong>"
									]),
									"good" => __("form_spintaxsample_good"),
									"bad" => __("form_spintaxsample_bad")
								]
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "create",
							"table" => "android.sent",
							"loader" => __("widget_smsexcel_loader")
						]
					];
					
					break;
				case "add.sms.scheduled":
					try {
						$phoneSample = $this->phone->getExampleNumber(logged_country, Brick\PhoneNumber\PhoneNumberType::MOBILE);
					} catch(Exception $e){
						$phoneSample = "+639123456789";
					}

					$vars = [
						"template" => [
							"title" => __("form_scheduled_title"),
							"data" => [
								"number" => $phoneSample,
								"groups" => $this->widget->getGroups(logged_id),
								"devices" => $this->widget->getDevices(logged_id),
								"devicesGlobal" => $this->widget->getGlobalDevices(logged_id),
								"gateways" => $this->widget->getGateways(),
								"shorteners" => $this->widget->getShorteners(),
								"templates" => $this->widget->getTemplates(logged_id)
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "create",
							"table" => "android.scheduled",
							"loader" => __("widget_addsmsscheduled_loader"),
							"require" => "name|{$GLOBALS["__"]("require_scheduled_name")}<=>groups|{$GLOBALS["__"]("require_groups")}<=>sim|{$GLOBALS["__"]("require_sim")}<=>schedule|{$GLOBALS["__"]("require_scheduled_date")}<=>message|{$GLOBALS["__"]("require_message")}"
						]
					];
					
					break;
				case "edit.sms.scheduled":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$scheduled = $this->widget->getScheduled(logged_id, $id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					try {
						$phoneSample = $this->phone->getExampleNumber(logged_country, Brick\PhoneNumber\PhoneNumberType::MOBILE);
					} catch(Exception $e){
						$phoneSample = "+639123456789";
					}

					$scheduled["numbers"] = str_replace(",", "\n", $scheduled["numbers"]);

					$vars = [
						"template" => [
							"title" => __("widget_editscheduled_title"),
							"data" => [
								"number" => $phoneSample,
								"groups" => $this->widget->getGroups(logged_id),
								"devices" => $this->widget->getDevices(logged_id),
								"devicesGlobal" => $this->widget->getGlobalDevices(logged_id),
								"gateways" => $this->widget->getGateways(),
								"shorteners" => $this->widget->getShorteners(),
								"scheduled" => $scheduled
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "update",
							"table" => "android.scheduled",
							"loader" => __("widget_editscheduled_loader"),
							"require" => "name|{$GLOBALS["__"]("require_scheduled_name")}<=>groups|{$GLOBALS["__"]("require_groups")}<=>sim|{$GLOBALS["__"]("require_sim")}<=>schedule|{$GLOBALS["__"]("require_scheduled_date")}<=>message|{$GLOBALS["__"]("require_message")}"
						]
					];
					
					break;
				case "add.ussd":
					$vars = [
						"template" => [
							"title" => __("widget_addussd_title"),
							"data" => [
								"devices" => $this->widget->getDevices(logged_id, true)
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"type" => "create",
							"size" => "md",
							"table" => "android.ussd",
							"loader" => __("widget_addussd_loader"),
							"require" => "code|{$GLOBALS["__"]("and_ussd_line17")}<=>sim|{$GLOBALS["__"]("require_sim")}"
						]
					];
					
					break;
				case "add.device":
					// A fresh single-use token per modal open, so the code in an
					// older screenshot stops working. The QR carries the site URL
					// too, because the gateway app is no longer built per install
					// and has no other way to learn which site it belongs to.
					$vars = [
						"template" => [
							"title" => __("modal_adddevice_title"),
							"data" => [
								"hash" => $this->hash->encode(logged_id, system_token),
								"pairing" => json_encode([
									"v" => 1,
									"u" => site_url("", true),
									"t" => $this->device->createPairingToken(logged_id)
								])
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "md"
						]
					];
					
					break;
				case "edit.device":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$device = $this->widget->getDevice($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("widget_editdevice_title"),
							"data" => [
								"device" => $device,
								"partner" => $this->system->getPartnership(logged_id),
								"countries" => \CountryCodes::get("alpha2", "country")
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"type" => "update",
							"size" => "lg",
							"table" => "devices.registered",
							"loader" => __("widget_editdevice_loader"),
							"require" => "name|{$GLOBALS["__"]("require_devicename")}<=>random_min|{$GLOBALS["__"]("require_randommin")}<=>random_max|{$GLOBALS["__"]("require_randommax")}"
						]
					];
					
					break;
				case "whatsapp.quick":
					try {
						$phoneSample = $this->phone->getExampleNumber(logged_country, Brick\PhoneNumber\PhoneNumberType::MOBILE);
					} catch(Exception $e){
						$phoneSample = "+639123456789";
					}

					$vars = [
						"template" => [
							"title" => __("widget_waquick_title"),
							"data" => [
								"phone" => $phoneSample,
								"accounts" => $this->widget->getWaAccounts(logged_id),
								"shorteners" => $this->widget->getShorteners(),
								"spintax_sample" => [
									"main" => ___(__("form_spintaxsample_main"), [
										"<strong>{" . __("form_spintaxsample_good") . "|" . __("form_spintaxsample_bad") . "}</strong>"
									]),
									"good" => __("form_spintaxsample_good"),
									"bad" => __("form_spintaxsample_bad")
								]
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"type" => "create",
							"size" => "lg",
							"table" => "whatsapp.sent",
							"loader" => __("widget_waquick_loader"),
							"require" => "phone|{$GLOBALS["__"]("require_phone")}<=>account|{$GLOBALS["__"]("widget_waquick_requireaccountnew1")}"
						]
					];
					
					break;
				case "whatsapp.bulk":
					try {
						$phoneSample = $this->phone->getExampleNumber(logged_country, Brick\PhoneNumber\PhoneNumberType::MOBILE);
					} catch(Exception $e){
						$phoneSample = "+639123456789";
					}

					$vars = [
						"template" => [
							"title" => __("widget_wabulk_title"),
							"data" => [
								"number" => $phoneSample,
								"accounts" => $this->widget->getWaAccounts(logged_id),
								"groups" => $this->widget->getGroups(logged_id),
								"shorteners" => $this->widget->getShorteners(),
								"templates" => $this->widget->getTemplates(logged_id),
								"spintax_sample" => [
									"main" => ___(__("form_spintaxsample_main"), [
										"<strong>{" . __("form_spintaxsample_good") . "|" . __("form_spintaxsample_bad") . "}</strong>"
									]),
									"good" => __("form_spintaxsample_good"),
									"bad" => __("form_spintaxsample_bad")
								]
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"type" => "create",
							"size" => "lg",
							"table" => "whatsapp.sent",
							"loader" => __("widget_wabulk_loader"),
							"require" => "accounts|{$GLOBALS["__"]("widget_waquick_requireaccountnew1")}"
						]
					];
					
					break;
				case "whatsapp.excel":
					$vars = [
						"template" => [
							"title" => __("widget_waexcel_title"),
							"data" => [
								"accounts" => $this->widget->getWaAccounts(logged_id),
								"groups" => $this->widget->getGroups(logged_id),
								"shorteners" => $this->widget->getShorteners(),
								"templates" => $this->widget->getTemplates(logged_id),
								"spintax_sample" => [
									"main" => ___(__("form_spintaxsample_main"), [
										"<strong>{" . __("form_spintaxsample_good") . "|" . __("form_spintaxsample_bad") . "}</strong>"
									]),
									"good" => __("form_spintaxsample_good"),
									"bad" => __("form_spintaxsample_bad")
								]
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"type" => "create",
							"size" => "lg",
							"table" => "whatsapp.sent",
							"loader" => __("widget_waexcel_loader"),
							"require" => "accounts|{$GLOBALS["__"]("widget_waquick_requireaccountnew1")}"
						]
					];
					
					break;
				case "add.whatsapp.scheduled":
					try {
						$phoneSample = $this->phone->getExampleNumber(logged_country, Brick\PhoneNumber\PhoneNumberType::MOBILE);
					} catch(Exception $e){
						$phoneSample = "+639123456789";
					}

					$vars = [
						"template" => [
							"title" => __("widget_wascheduled_title"),
							"data" => [
								"number" => $phoneSample,
								"accounts" => $this->widget->getWaAccounts(logged_id),
								"groups" => $this->widget->getGroups(logged_id),
								"shorteners" => $this->widget->getShorteners(),
								"templates" => $this->widget->getTemplates(logged_id)
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"type" => "create",
							"size" => "lg",
							"table" => "whatsapp.scheduled",
							"loader" => __("widget_wascheduled_loader"),
							"require" => "name|{$GLOBALS["__"]("require_scheduled_name")}<=>groups|{$GLOBALS["__"]("require_groups")}<=>account|{$GLOBALS["__"]("widget_wascheduled_requireaccount")}<=>schedule|{$GLOBALS["__"]("require_scheduled_date")}<=>message|{$GLOBALS["__"]("require_message")}"
						]
					];
					
					break;
				case "edit.whatsapp.scheduled":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$scheduled = $this->widget->getScheduled(logged_id, $id, true);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					try {
						$phoneSample = $this->phone->getExampleNumber(logged_country, Brick\PhoneNumber\PhoneNumberType::MOBILE);
					} catch(Exception $e){
						$phoneSample = "+639123456789";
					}

					$decodeMessage = json_decode($scheduled["message"], true);

					$scheduled["message"] = decodeBraces(isset($decodeMessage["text"]) ? $decodeMessage["text"] : $decodeMessage["caption"]);

					$scheduled["numbers"] = str_replace(",", "\n", $scheduled["numbers"]);

					$vars = [
						"template" => [
							"title" => __("widget_waeditscheduled_title"),
							"data" => [
								"number" => $phoneSample,
								"accounts" => $this->widget->getWaAccounts(logged_id),
								"groups" => $this->widget->getGroups(logged_id),
								"shorteners" => $this->widget->getShorteners(),
								"templates" => $this->widget->getTemplates(logged_id),
								"scheduled" => $scheduled
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"type" => "update",
							"size" => "lg",
							"table" => "whatsapp.scheduled",
							"loader" => __("widget_waeditscheduled_loader"),
							"require" => "name|{$GLOBALS["__"]("require_scheduled_name")}<=>groups|{$GLOBALS["__"]("require_groups")}<=>account|{$GLOBALS["__"]("widget_waeditscheduled_requireaccount")}<=>schedule|{$GLOBALS["__"]("require_scheduled_date")}<=>message|{$GLOBALS["__"]("require_message")}"
						]
					];
					
					break;
				case "whatsapp.groups":
					$vars = [
						"template" => [
							"title" => __("widget_fetchgroups_titlefetch"),
							"data" => [
								"accounts" => $this->widget->getWaAccounts(logged_id)
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"type" => "create",
							"table" => "whatsapp.groups",
							"loader" => __("widget_fetchgroups_loaderfetch"),
							"require" => "account|{$GLOBALS["__"]("widget_waquick_requireaccountnew1")}"
						]
					];
					
					break;
				case "add.whatsapp":
					$subscription = set_subscription(
						$this->system->checkSubscription(logged_id), 
						$this->system->getSubscription(false, logged_id), 
						$this->system->getSubscription(false, false, true)
					);
	
					if(empty($subscription))
						response(500, __("response_package_nosubwarn"));

					$waServers = $this->system->getWaServers($subscription["pid"]);

					$waServerList = [];

					if(!empty($waServers)):
						foreach($waServers as $waServer):
							$waServerList[$waServer["id"]] = [
								"id" => $waServer["id"],
								"name" => $waServer["name"]
							];
						endforeach;
					endif;

					$vars = [
						"template" => [
							"title" => __("widget_waaddaccount_title"),
							"data" => [
								"linkbtn" => "<strong class=\"text-uppercase\">{$GLOBALS["__"]("whatsapp_accountlink_linkadevicebtn")}</strong>",
								"wa_servers" => $waServerList
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "md"
						]
					];
					
					break;
				case "edit.whatsapp":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$account = $this->widget->getWhatsapp($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("widgets_editwhatsapp_title"),
							"data" => [
								"account" => $account
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"type" => "update",
							"size" => "md",
							"table" => "whatsapp.accounts",
							"loader" => __("widgets_editwhatsapp_loader"),
							"require" => "random_min|{$GLOBALS["__"]("require_randommin")}<=>random_max|{$GLOBALS["__"]("require_randommax")}"
						]
					];
					
					break;
				case "import.contacts":
					$vars = [
						"template" => [
							"title" => __("widget_importcontacts_title")
						],
						"handler" => [
							"tpl" => $tpl,
							"type" => "create",
							"table" => "contacts.saved",
							"loader" => __("widget_importcontacts_loaded")
						]
					];
					
					break;
				case "add.contact":
					try {
						$phoneSample = $this->phone->getExampleNumber(logged_country, Brick\PhoneNumber\PhoneNumberType::MOBILE);
					} catch(Exception $e){
						$phoneSample = "+639123456789";
					}

					$vars = [
						"template" => [
							"title" => __("modal_addcontact_title"),
							"data" => [
								"number" => $phoneSample,
								"groups" => $this->widget->getGroups(logged_id)
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"type" => "create",
							"table" => "contacts.saved",
							"loader" => __("widget_addcontact_loader"),
							"require" => "name|{$GLOBALS["__"]("require_contactname")}<=>phone|{$GLOBALS["__"]("require_phone")}<=>groups|{$GLOBALS["__"]("require_group")}"
						]
					];
					
					break;
				case "edit.contact":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$contact = $this->widget->getContact(logged_id, $id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					try {
						$phoneSample = $this->phone->getExampleNumber(logged_country, Brick\PhoneNumber\PhoneNumberType::MOBILE);
					} catch(Exception $e){
						$phoneSample = "+639123456789";
					}

					$vars = [
						"template" => [
							"title" => __("modal_editcontact_title"),
							"data" => [
								"contact" => $contact,
								"number" => $phoneSample,
								"groups" => $this->widget->getGroups(logged_id)
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"type" => "update",
							"table" => "contacts.saved",
							"loader" => __("widgets_editcontact_loader"),
							"require" => "name|{$GLOBALS["__"]("lang_require_contactname")}<=>phone|{$GLOBALS["__"]("require_phone")}<=>groups|{$GLOBALS["__"]("require_group")}"
						]
					];
					
					break;
				case "add.group":
					$vars = [
						"template" => [
							"title" => __("modal_addgroup_title")
						],
						"handler" => [
							"tpl" => $tpl,
							"type" => "create",
							"table" => "contacts.groups",
							"loader" => __("widgets_addgroup_loader"),
							"require" => "name|{$GLOBALS["__"]("require_groupname")}"
						]
					];
					
					break;
				case "edit.group":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$group = $this->widget->getGroup(logged_id, $id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("modal_editgroup_title"),
							"data" => [
								"group" => $group
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"type" => "update",
							"table" => "contacts.groups",
							"loader" => __("widgets_editgroup_loader"),
							"require" => "name|{$GLOBALS["__"]("require_groupname")}"
						]
					];
					
					break;
				case "add.apikey":
					$vars = [
						"template" => [
							"title" => __("modal_addkey_title")
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "md",
							"type" => "create",
							"table" => "tools.keys",
							"loader" => __("widgets_addapikey_loader"),
							"require" => "name|{$GLOBALS["__"]("require_apiname")}<=>permissions|{$GLOBALS["__"]("require_permissions")}"
						]
					];
					
					break;
				case "edit.apikey":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$key = $this->widget->getKey(logged_id, $id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$permissions = [
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
        			];

					$vars = [
						"template" => [
							"title" => __("modal_editkey_title"),
							"data" => [
								"key" => $key,
								"permissions" => $permissions
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "md",
							"type" => "update",
							"table" => "tools.keys",
							"loader" => __("widgets_editapikey_loader"),
							"require" => "name|{$GLOBALS["__"]("require_apiname")}<=>permissions|{$GLOBALS["__"]("require_permissions")}"
						]
					];
					
					break;
				case "add.webhook":
					$vars = [
						"template" => [
							"title" => __("widgets_addwebhook_title")
						],
						"handler" => [
							"tpl" => $tpl,
							"type" => "create",
							"size" => "md",
							"table" => "tools.webhooks",
							"loader" => __("widgets_addwebhook_loader"),
							"require" => "name|{$GLOBALS["__"]("require_hookname")}<=>url|{$GLOBALS["__"]("require_hookurl")}<=>events|{$GLOBALS["__"]("widgets_addwebhook_requireevents")}"
						]
					];
					
					break;
				case "edit.webhook":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$webhook = $this->widget->getWebhook($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("modal_edithook_title"),
							"data" => [
								"webhook" => $webhook
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "md",
							"type" => "update",
							"table" => "tools.webhooks",
							"loader" => __("widgets_editwebhook_loader"),
							"require" => "name|{$GLOBALS["__"]("require_hookname")}<=>url|{$GLOBALS["__"]("require_hookurl")}<=>events|{$GLOBALS["__"]("widgets_addwebhook_requireevents")}"
						]
					];
					
					break;
				case "add.hook":
					$vars = [
						"template" => [
							"title" => __("form_hook_addtitle")
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "md",
							"type" => "create",
							"table" => "tools.actions",
							"loader" => __("widgets_addhook_loader"),
							"require" => "name|{$GLOBALS["__"]("require_action_name")}<=>event|{$GLOBALS["__"]("require_action_event")}<=>link|{$GLOBALS["__"]("require_action_link")}"
						]
					];
					
					break;
				case "edit.hook":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$action = $this->widget->getAction(logged_id, $id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("form_hook_edittitle"),
							"data" => [
								"hook" => $action
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "md",
							"type" => "update",
							"table" => "tools.actions",
							"loader" => __("widgets_edithook_loader"),
							"require" => "name|{$GLOBALS["__"]("require_action_name")}<=>event|{$GLOBALS["__"]("require_action_event")}<=>link|{$GLOBALS["__"]("require_action_link")}"
						]
					];
					
					break;
				case "add.autoreply":
					$getPlugins = $this->system->getPlugins();
					$getAiPlugins = $this->widget->getAiPlugins(logged_id);

					$aiPlugins = [];

					if(!empty($getPlugins)):
						foreach($getPlugins as $plugin):
							if(str_starts_with($plugin["directory"], "ai-")):
								$aiPlugins[$plugin["directory"]] = [
									"id" => $plugin["directory"],
									"name" => $plugin["name"],
									"global" => true
								];
							endif;
						endforeach;
					endif;

					if(!empty($getAiPlugins)):
						foreach($getAiPlugins as $plugin):
							$aiPlugins[$plugin["id"]] = [
								"id" => $plugin["id"],
								"name" => $plugin["name"],
								"global" => false
							];
						endforeach;
					endif;

					$vars = [
						"template" => [
							"title" => __("form_autoreply_addtitle"),
							"data" => [
								"devices" => $this->widget->getDevices(logged_id),
								"accounts" => $this->widget->getWaAccounts(logged_id, "unique"),
								"ai_keys" => $this->widget->getAiKeys(logged_id),
								"ai_plugins" => $aiPlugins
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "create",
							"table" => "tools.actions",
							"loader" => __("widgets_addautoreply_loader"),
							"require" => "name|{$GLOBALS["__"]("require_action_name")}"
						]
					];
					
					break;
				case "edit.autoreply":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$action = $this->widget->getAction(logged_id, $id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$waType = "text";
					
					try {
						$msgDecode = json_decode($action["message"], true, JSON_THROW_ON_ERROR);

						if(isset($msgDecode["audio"])):
							$waMessage = false;
						else:
							$waMessage = isset($msgDecode["text"]) ? $msgDecode["text"] : $msgDecode["caption"];
						endif;

						$waType = $msgDecode["message_type"];
					} catch(Exception $e){
						$waMessage = $action["message"];
					}

					$getPlugins = $this->system->getPlugins();
					$getAiPlugins = $this->widget->getAiPlugins(logged_id);

					$aiPlugins = [];

					if(!empty($getPlugins)):
						foreach($getPlugins as $plugin):
							if(str_starts_with($plugin["directory"], "ai-")):
								$aiPlugins[$plugin["directory"]] = [
									"id" => $plugin["directory"],
									"name" => $plugin["name"],
									"global" => true
								];
							endif;
						endforeach;
					endif;

					if(!empty($getAiPlugins)):
						foreach($getAiPlugins as $plugin):
							$aiPlugins[$plugin["id"]] = [
								"id" => $plugin["id"],
								"name" => $plugin["name"],
								"global" => false
							];
						endforeach;
					endif;

					$vars = [
						"template" => [
							"title" => __("form_autoreply_edittitle"),
							"data" => [
								"devices" => $this->widget->getDevices(logged_id),
								"accounts" => $this->widget->getWaAccounts(logged_id, "unique"),
								"ai_keys" => $this->widget->getAiKeys(logged_id),
								"ai_plugins" => $aiPlugins,
								"autoreply" => $action,
								"message" => $waMessage,
								"wa_meta" => [
									"type" => $waType
								]
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "update",
							"table" => "tools.actions",
							"loader" => __("widgets_editautoreply_loader"),
							"require" => "name|{$GLOBALS["__"]("require_action_name")}"
						]
					];
					
					break;
				case "add.template":
					$vars = [
						"template" => [
							"title" => __("modal_addtemplate_title"),
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "create",
							"table" => "messages.templates",
							"loader" => __("widgets_addtemplate_loader"),
							"require" => "name|{$GLOBALS["__"]("require_templatename")}<=>format|{$GLOBALS["__"]("require_templateformat")}"
						]
					];
					
					break;
				case "edit.template":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$template_ = $this->widget->getTemplate($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("modal_edittemplate_title"),
							"data" => [
								"template" => $template_
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "update",
							"table" => "messages.templates",
							"loader" => __("widgets_edittemplate_loader"),
							"require" => "name|{$GLOBALS["__"]("require_templatename")}<=>format|{$GLOBALS["__"]("require_templateformat")}"
						]
					];
					
					break;
				case "add.ai.key":
					$vars = [
						"template" => [
							"title" => __("widgets_addaikey_title")
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "md",
							"type" => "create",
							"table" => "ai.keys",
							"loader" => __("widgets_addaikey_loader"),
							"require" => "name|{$GLOBALS["__"]("widgets_addaikey_requirename")}<=>prompt|{$GLOBALS["__"]("widgets_addaikey_requiremprompt")}<=>apikey|{$GLOBALS["__"]("widgets_addaikey_requireapikey")}"
						]
					];
					
					break;
				case "edit.ai.key":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$apikey = $this->widget->getAiKey($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("widgets_editaikey_title"),
							"data" => [
								"key" => $apikey
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "md",
							"type" => "update",
							"table" => "ai.keys",
							"loader" => __("widgets_editaikey_loader"),
							"require" => "name|{$GLOBALS["__"]("widgets_addaikey_requirename")}<=>prompt|{$GLOBALS["__"]("widgets_addaikey_requiremprompt")}<=>apikey|{$GLOBALS["__"]("widgets_addaikey_requireapikey")}"
						]
					];
					
					break;
				case "add.ai.plugin":
					$vars = [
						"template" => [
							"title" => __("widgets_addaiplugin_title"),
							"data" => [
								"schema" => $this->file->get("uploads/system/ai.json")
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "create",
							"table" => "ai.plugins",
							"loader" => __("widgets_addaiplugin_loader"),
							"require" => "name|{$GLOBALS["__"]("widgets_addaiplugin_requirename")}"
						]
					];
					
					break;
				case "edit.ai.plugin":
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$pluginAi = $this->widget->getAiPlugin($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("widgets_editaiplugin_title"),
							"data" => [
								"plugin" => $pluginAi
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "update",
							"table" => "ai.plugins",
							"loader" => __("widgets_editaiplugin_loader"),
							"require" => "name|{$GLOBALS["__"]("widgets_addaiplugin_requirename")}"
						]
					];
					
					break;
				case "admin.gateway":
					if(!super_admin)
						response(500, __("response_no_permission"));

					$vars = [
						"template" => [
							"title" => __("admin_gateway_form_title"),
							"data" => [
								// The template reads $data.assets.*; it was handed
								// $data.firebase, so both badges always showed
								// "not uploaded" however many files were present.
								"assets" => [
									"firebase" => $this->file->exists("system/storage/temporary/firebase.json"),
									"google"   => $this->file->exists("system/storage/temporary/google-services.json")
								]
							]
						],
						"handler" => [
							"id" => 1,
							"tpl" => $tpl,
							"size" => "md",
							"type" => "update",
							"loader" => __("widgets_adminbuilder_loader")
						]
					];
					
					break;
				case "admin.settings":
					if(!super_admin)
						response(500, __("response_no_permission"));

					foreach(["facebook", "google"] as $social):
						if(in_array($social, explode(",", system_social_platforms)))
							$social_platforms[$social] = true;
						else
							$social_platforms[$social] = false;
					endforeach;

					$currencies = ["AED", "AFN", "ALL", "AMD", "ANG", "AOA", "ARS", "AUD", "AWG", "AZN", "BAM", "BBD", "BDT", "BGN", "BHD", "BIF", "BMD", "BND", "BOB", "BRL", "BSD", "BTC", "BTN", "BWP", "BYN", "BZD", "CAD", "CDF", "CHF", "CLF", "CLP", "CNH", "CNY", "COP", "CRC", "CUC", "CUP", "CVE", "CZK", "DJF", "DKK", "DOP", "DZD", "EGP", "ERN", "ETB", "EUR", "FJD", "FKP", "GBP", "GEL", "GGP", "GHS", "GIP", "GMD", "GNF", "GTQ", "GYD", "HKD", "HNL", "HRK", "HTG", "HUF", "IDR", "ILS", "IMP", "INR", "IQD", "IRR", "ISK", "JEP", "JMD", "JOD", "JPY", "KES", "KGS", "KHR", "KMF", "KPW", "KRW", "KWD", "KYD", "KZT", "LAK", "LBP", "LKR", "LRD", "LSL", "LYD", "MAD", "MDL", "MGA", "MKD", "MMK", "MNT", "MOP", "MRO", "MRU", "MUR", "MVR", "MWK", "MXN", "MYR", "MZN", "NAD", "NGN", "NIO", "NOK", "NPR", "NZD", "OMR", "PAB", "PEN", "PGK", "PHP", "PKR", "PLN", "PYG", "QAR", "RON", "RSD", "RUB", "RWF", "SAR", "SBD", "SCR", "SDG", "SEK", "SGD", "SHP", "SLL", "SOS", "SRD", "SSP", "STD", "STN", "SVC", "SYP", "SZL", "THB", "TJS", "TMT", "TND", "TOP", "TRY", "TTD", "TWD", "TZS", "UAH", "UGX", "USD", "UYU", "UZS", "VES", "VND", "VUV", "WST", "XAF", "XAG", "XAU", "XCD", "XDR", "XOF", "XPD", "XPF", "XPT", "YER", "ZAR", "ZMW", "ZWL"];

					$vars = [
						"template" => [
							"title" => __("modal_systemsettings_title"),
							"data" => [
								"system" => $this->system->getSettings(),
								"languages" => $this->widget->getLanguages(),
								"timezones" => $this->timezones->generate(),
								"countries" => \CountryCodes::get("alpha2", "country"),
								"platforms" => $social_platforms,
								"currencies" => $currencies
							]
						],
						"handler" => [
							"id" => 1,
							"tpl" => $tpl,
							"size" => "xl",
							"type" => "update",
							"loader" => __("widgets_adminsettings_loader")
						]
					];
					
					break;
				case "admin.theme":
					if(!super_admin)
						response(500, __("response_no_permission"));
					
					$vars = [
						"template" => [
							"title" => __("modal_themesettings_title"),
							"data" => [
								"system" => $this->system->getSettings(),
								"script" => $this->file->get("templates/_assets/js/custom.js"),
								"css" => $this->file->get("templates/_assets/css/custom.css"),
								"assets" => [
									"logo_light" => $this->file->exists("uploads/theme/logo-light.png"),
									"logo_dark" => $this->file->exists("uploads/theme/logo-dark.png"),
									"background" => $this->file->exists("uploads/theme/bg.png"),
									"favicon" => $this->file->exists("uploads/theme/favicon.png")
								]
							]
						],
						"handler" => [
							"id" => 1,
							"tpl" => $tpl,
							"size" => "xl",
							"type" => "update",
							"loader" => __("widgets_admintheme_loader")
						]
					];
					
					break;
				case "add.user":
					if(!permission("manage_users"))
						response(500, __("response_no_permission"));

					$vars = [
						"template" => [
							"title" => __("modal_adduser_title"),
							"data" => [
								"timezones" => $this->timezones->generate(),
								"countries" => \CountryCodes::get("alpha2", "country"),
								"roles" => $this->widget->getRoles(),
								"languages" => $this->widget->getLanguages()
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"type" => "create",
							"size" => "md",
							"table" => "administration.users",
							"loader" => __("widgets_adduser_loader"),
							"require" => "name|{$GLOBALS["__"]("require_name")}<=>email|{$GLOBALS["__"]("require_email")}<=>password|{$GLOBALS["__"]("require_password")}"
						]
					];
					
					break;
				case "edit.user":
					if(!permission("manage_users"))
						response(500, __("response_no_permission"));
					
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$user = $this->widget->getUser($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$formatting = isset($user["formatting"]) && !empty($user["formatting"]) ? json_decode($user["formatting"], true) : [
				        "clock" => "g:i A",
				        "date" => "n/j/Y",
				        "container" => [
        					"clock_format" => 1,
        					"date_format" => 1,
        					"date_separator" => 2,
        					"separator_selected" => "/" 
        				]
				    ]; 

					$vars = [
						"template" => [
							"title" => __("modal_edituser_title"),
							"data" => [
								"user" => $user,
								"formatting" => $formatting,
								"timezones" => $this->timezones->generate(),
								"countries" => \CountryCodes::get("alpha2", "country"),
								"roles" => $this->widget->getRoles(),
								"languages" => $this->widget->getLanguages()
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"type" => "update",
							"size" => "md",
							"table" => "administration.users",
							"loader" => __("widgets_edituser_loader"),
							"require" => "name|{$GLOBALS["__"]("require_name")}<=>email|{$GLOBALS["__"]("require_email")}"
						]
					];
					
					break;
				case "add.role":
					if(!permission("manage_roles"))
						response(500, __("response_no_permission"));
					
					$vars = [
						"template" => [
							"title" => __("widgets_addrole_title"),
							"data" => [
								"permissions" => permission_list()
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "md",
							"type" => "create",
							"table" => "administration.roles",
							"loader" => __("widgets_addrole_loader"),
							"require" => "name|{$GLOBALS["__"]("require_addrole_name")}<=>permissions|{$GLOBALS["__"]("require_addrole_permissions")}"
						]
					];
					
					break;
				case "edit.role":
					if(!permission("manage_roles"))
						response(500, __("response_no_permission"));

					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$role = $this->widget->getRole($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$permissions = permission_list();

					$vars = [
						"template" => [
							"title" => __("widget_editrole_title"),
							"data" => [
								"role" => $role,
								"permissions" => $permissions
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "md",
							"type" => "update",
							"table" => "administration.roles",
							"loader" => __("widgets_editrole_loader"),
							"require" => "name|{$GLOBALS["__"]("require_addrole_name")}<=>permissions|{$GLOBALS["__"]("require_addrole_permissions")}"
						]
					];
					
					break;
				case "add.package":
					if(!permission("manage_packages"))
						response(500, __("response_no_permission"));
					
					$vars = [
						"template" => [
							"title" => __("modal_addpackage_title")
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "create",
							"table" => "administration.packages",
							"loader" => __("widgets_addpackage_loader")
						]
					];
					
					break;
				case "edit.package":
					if(!permission("manage_packages"))
						response(500, __("response_no_permission"));
					
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$package = $this->widget->getPackage($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("modal_editpackage_title"),
							"data" => [
								"package" => $package
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "update",
							"table" => "administration.packages",
							"loader" => __("widgets_editpackage_loader")
						]
					];
					
					break;
				case "add.voucher":
					if(!permission("manage_vouchers"))
						response(500, __("response_no_permission"));
					
					$vars = [
						"template" => [
							"title" => __("form_title_addvoucher"),
							"data" => [
								"packages" => $this->widget->getPackages()
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "md",
							"type" => "create",
							"table" => "administration.vouchers",
							"loader" => __("widgets_addvoucher_loader"),
							"require" => "name|{$GLOBALS["__"]("require_voucher_name")}<=>count|{$GLOBALS["__"]("require_voucher_count")}<=>package|{$GLOBALS["__"]("require_voucher_package")}"
						]
					];
					
					break;
				case "add.subscription":
					if(!permission("manage_subscriptions"))
						response(500, __("response_no_permission"));
					
					$vars = [
						"template" => [
							"title" => __("form_title_addsubscription"),
							"data" => [
								"users" => $this->widget->getUsers(),
								"packages" => $this->widget->getPackages()
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "md",
							"type" => "create",
							"table" => "administration.subscriptions",
							"loader" => __("widgets_addsubscription_loader"),
							"require" => "user|{$GLOBALS["__"]("require_subscription_user")}<=>package|{$GLOBALS["__"]("require_subscription_user")}"
						]
					];
					
					break;
				case "add.widget":
					if(!permission("manage_widgets"))
						response(500, __("response_no_permission"));
					
					$vars = [
						"template" => [
							"title" => __("modal_addwidget_title")
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "create",
							"table" => "administration.widgets",
							"loader" => __("widgets_addwidgets_loader"),
							"require" => "name|{$GLOBALS["__"]("require_widgetname")}<=>size|{$GLOBALS["__"]("require_widgetsize")}<=>position|{$GLOBALS["__"]("require_widgetposition")}<=>type|{$GLOBALS["__"]("require_widgettype")}" 
						]
					];
					
					break;
				case "edit.widget":
					if(!permission("manage_widgets"))
						response(500, __("response_no_permission"));
					
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$widget = $this->widget->getWidget($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("modal_editwidget_title"),
							"data" => [
								"widget" => $widget,
								"content" => $widget["content"]
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "update",
							"table" => "administration.widgets",
							"loader" => __("widgets_editwidget_loader"),
							"require" => "name|{$GLOBALS["__"]("require_widgetname")}<=>size|{$GLOBALS["__"]("require_widgetsize")}<=>position|{$GLOBALS["__"]("require_widgetposition")}<=>type|{$GLOBALS["__"]("require_widgettype")}"
						]
					];
					
					break;
				case "add.page":
					if(!permission("manage_pages"))
						response(500, __("response_no_permission"));
					
					$vars = [
						"template" => [
							"title" => __("widget_addpage_title"),
							"data" => [
								"roles" => $this->widget->getRoles()
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "create",
							"table" => "administration.pages",
							"loader" => __("widgets_addpage_loader"),
							"require" => "name|{$GLOBALS["__"]("require_pagename")}<=>roles|{$GLOBALS["__"]("require_pageroles")}" 
						]
					];
					
					break;
				case "edit.page":
					if(!permission("manage_pages"))
						response(500, __("response_no_permission"));

					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$page = $this->widget->getPage($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("widget_editpage_title"),
							"data" => [
								"page" => $page,
								"roles" => $this->widget->getRoles()
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "update",
							"table" => "administration.pages",
							"loader" => __("widgets_editpage_loader"),
							"require" => "name|{$GLOBALS["__"]("require_pagename")}<=>roles|{$GLOBALS["__"]("require_pageroles")}"
						]
					];
					
					break;
				case "add.push":
					if(!permission("manage_marketing"))
						response(500, __("response_no_permission"));
					
					$vars = [
						"template" => [
							"title" => __("widgets_addpush_title"),
							"data" => [
								"users" => $this->widget->getUsers(),
								"roles" => $this->widget->getRoles()
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "md",
							"type" => "create",
							"table" => "administration.marketing",
							"loader" => __("widgets_addpush_loader"),
							"require" => "title|{$GLOBALS["__"]("widgets_addpush_requiretitle")}<=>message|{$GLOBALS["__"]("widgets_addpush_requiremessage")}"
						]
					];
					
					break;
				case "add.notify":
					if(!permission("manage_marketing"))
						response(500, __("response_no_permission"));
					
					$vars = [
						"template" => [
							"title" => __("widgets_addnotify_title"),
							"data" => [
								"users" => $this->widget->getUsers(),
								"roles" => $this->widget->getRoles()
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "md",
							"type" => "create",
							"table" => "administration.marketing",
							"loader" => __("widgets_addnotify_loader"),
							"require" => "title|{$GLOBALS["__"]("widgets_addnotify_requiretitle")}<=>message|{$GLOBALS["__"]("widgets_addnotify_requiremessage")}"
						]
					];
					
					break;
				case "add.mailer":
					if(!permission("manage_marketing"))
						response(500, __("response_no_permission"));
					
					$vars = [
						"template" => [
							"title" => __("widgets_addmailer_title"),
							"data" => [
								"users" => $this->widget->getUsers(),
								"roles" => $this->widget->getRoles()
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "create",
							"table" => "administration.marketing",
							"loader" => __("widgets_addmailer_loader"),
							"require" => "title|{$GLOBALS["__"]("widgets_addmailer_requiretitle")}"
						]
					];
					
					break;
				case "add.language":
					if(!permission("manage_languages"))
						response(500, __("response_no_permission"));

					$vars = [
						"template" => [
							"title" => __("modal_addlanguage_title"),
							"data" => [
								"countries" => \CountryCodes::get("alpha2", "country"),
								"strings" => $this->file->exists("system/storage/temporary/default.lang") ? $this->file->get("system/storage/temporary/default.lang") : false
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "create",
							"table" => "administration.languages",
							"loader" => __("widgets_addlanguage_loader"),
							"require" => "name|{$GLOBALS["__"]("require_languagename")}<=>iso|{$GLOBALS["__"]("require_languageiso")}<=>translations|{$GLOBALS["__"]("require_languagestr")}"
						]
					];
					
					break;
				case "edit.language":
					if(!permission("manage_languages"))
						response(500, __("response_no_permission"));
					
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$language = $this->widget->getLanguage($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("modal_editlanguage_title"),
							"data" => [
								"language" => $language,
								"countries" => \CountryCodes::get("alpha2", "country"),
								// Fall back to the English master rather than handing the
								// editor a blank box. Installed language files are no longer
								// shipped, so on a fresh install this file does not exist yet
								// — and saving an empty textarea would write a 0-byte language.
								"strings" => $this->file->exists("system/languages/" . md5($language["id"]) . ".lang")
									? $this->file->get("system/languages/" . md5($language["id"]) . ".lang")
									: ($this->file->exists("system/storage/temporary/default.lang")
										? $this->file->get("system/storage/temporary/default.lang")
										: false)
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "update",
							"table" => "administration.languages",
							"loader" => __("widgets_editlanguage_loader"),
							"require" => "name|{$GLOBALS["__"]("require_languagename")}<=>iso|{$GLOBALS["__"]("require_languageiso")}<=>translations|{$GLOBALS["__"]("require_languagestr")}"
						]
					];
					
					break;
				case "add.waserver":
					if(!permission("manage_waservers"))
						response(500, __("response_no_permission"));

					$vars = [
						"template" => [
							"title" => __("widget_waserver_addwatitle"),
							"data" => [
								"packages" => $this->system->getPackages()
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "create",
							"table" => "administration.waservers",
							"loader" => __("widget_waserver_addwatitleloader"),
							"require" => "name|{$GLOBALS["__"]("require_languagename")}<=>accounts|{$GLOBALS["__"]("widget_waserver_fieldmaxacc")}<=>url|{$GLOBALS["__"]("widget_waserver_fieldurl")}<=>port|{$GLOBALS["__"]("widget_waserver_fieldport")}<=>secret|{$GLOBALS["__"]("widget_waserver_fieldsecret")}"
						]
					];
					
					break;
				case "edit.waserver":
					if(!permission("manage_waservers"))
						response(500, __("response_no_permission"));
					
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$waserver = $this->widget->getWaServer($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("widget_waserver_editwatitle"),
							"data" => [
								"waserver" => $waserver,
								"packages" => $this->system->getPackages()
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "update",
							"table" => "administration.waservers",
							"loader" => __("widget_waserver_editwatitleloader"),
							"require" => "name|{$GLOBALS["__"]("require_languagename")}<=>accounts|{$GLOBALS["__"]("widget_waserver_fieldmaxacc")}<=>url|{$GLOBALS["__"]("widget_waserver_fieldurl")}<=>port|{$GLOBALS["__"]("widget_waserver_fieldport")}<=>secret|{$GLOBALS["__"]("widget_waserver_fieldsecret")}"
						]
					];
					
					break;
				case "setup.waserver":
					if(!permission("manage_waservers"))
						response(500, __("response_no_permission"));
					
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$waserver = $this->widget->getWaServer($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("widget_waserver_setupwatitle"),
							"data" => [
								"waserver" => $waserver
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "lg"
						]
					];
					
					break;
				case "setup.cron":
					$this->cache->container("system.cron");

					$vars = [
						"template" => [
							"title" => __("widget_setupcronjobs_title"),
							"data" => [
								"cron" => [
									"quota" => $this->cache->has("quota") ? $this->cache->get("quota") : "None",
									"sender" => $this->cache->has("sender") ? $this->cache->get("sender") : "None",
									"sms_scheduled" => $this->cache->has("sms.scheduled") ? $this->cache->get("sms.scheduled") : "None",
									"wa_scheduled" => $this->cache->has("wa.scheduled") ? $this->cache->get("wa.scheduled") : "None",
									"subscription" => $this->cache->has("subscription") ? $this->cache->get("subscription") : "None",
									"cleaner" => $this->cache->has("cleaner") ? $this->cache->get("cleaner") : "None"
								]
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg"
						]
					];
					
					break;
				case "add.gateway":
					if(!permission("manage_gateways"))
						response(500, __("response_no_permission"));

					$vars = [
						"template" => [
							"title" => __("widgets_addgateway_title"),
							"data" => [
								"pricing" => $this->file->get("uploads/system/gateway.json")
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "create",
							"table" => "administration.gateways",
							"loader" => __("widgets_addgateway_loader"),
							"require" => "name|{$GLOBALS["__"]("widgets_addgateway_requirename")}"
						]
					];
					
					break;
				case "edit.gateway":
					if(!permission("manage_gateways"))
						response(500, __("response_no_permission"));

					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$gateway = $this->widget->getGateway($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("widgets_editgateway_title"),
							"data" => [
								"gateway" => $gateway
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "update",
							"table" => "administration.gateways",
							"loader" => __("widgets_editgateway_loader"),
							"require" => "name|{$GLOBALS["__"]("widgets_editgateway_requirename")}"
						]
					];
					
					break;
				case "add.shortener":
					if(!permission("manage_shorteners"))
						response(500, __("response_no_permission"));

					$vars = [
						"template" => [
							"title" => __("widgets_addshortener_title"),
							"data" => [
								"packages" => $this->widget->getPackages()
							]
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "md",
							"type" => "create",
							"table" => "administration.shorteners",
							"loader" => __("widgets_addshortener_loader"),
							"require" => "name|{$GLOBALS["__"]("widgets_addshortener_requirename")}"
						]
					];
					
					break;
				case "edit.shortener":
					if(!permission("manage_shorteners"))
						response(500, __("response_no_permission"));

					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$shortener = $this->widget->getShortener($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}

					$vars = [
						"template" => [
							"title" => __("widgets_editshortener_title"),
							"data" => [
								"shortener" => $shortener,
								"packages" => $this->widget->getPackages()
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "md",
							"type" => "update",
							"table" => "administration.shorteners",
							"loader" => __("widgets_editshortener_loader"),
							"require" => "name|{$GLOBALS["__"]("widgets_editshortener_requirename")}"
						]
					];
					
					break;
				case "add.plugin":
					if(!permission("manage_plugins"))
						response(500, __("response_no_permission"));

					$vars = [
						"template" => [
							"title" => __("modal_addplugin_title")
						],
						"handler" => [
							"tpl" => $tpl,
							"size" => "sm",
							"type" => "create",
							"table" => "administration.plugins",
							"loader" => __("widgets_addplugin_loader"),
							"require" => "plugin|{$GLOBALS["__"]("require_pluginfile")}"
						]
					];
					
					break;
				case "edit.plugin":
					if(!permission("manage_plugins"))
						response(500, __("response_no_permission"));
					
					if(!$this->sanitize->isInt($id))
						response(500, __("response_invalid"));

					try {
						$plugin = $this->widget->getPlugin($id);
					} catch(Exception $e){
						response(500, __("response_invalid"));
					}
					
					$pluginJson = $this->file->get("system/plugins/installables/{$plugin["directory"]}/plugin.json");

					try {
						$decodedJson = json_decode($pluginJson, true, JSON_THROW_ON_ERROR);
					} catch(Exception $e){
						response(500, __("widget_editplugin_nojsonsettings"));
					}

					try {
						$pluginData = json_decode($plugin["data"], true, JSON_THROW_ON_ERROR);
					} catch(Exception $e){
						response(500, __("response_went_wrong"));
					}

					$require = [];
					
					foreach($decodedJson["data"] as $key => $value):
						foreach($pluginData as $k => $v):
							if($k == $key):
								$fields[$key] = $value;
								$fields[$key]["value"] = $v;
							endif;
						endforeach;

						if(isset($fields[$key]["required"]) && $fields[$key]["required"] == true):
							$require[] = "{$key}|{$value["label"]}";
						endif;
					endforeach;

					$vars = [
						"template" => [
							"title" => __("model_title_editplugin"),
							"data" => [
								"directory" => $plugin["directory"],
								"fields" => $fields
							]
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "lg",
							"type" => "update",
							"table" => "administration.plugins",
							"loader" => __("widgets_editplugin_loader"),
							"require" => implode("<=>", $require)
						]
					];
					
					break;
				case "update.plugin":
					if(!permission("manage_plugins"))
						response(500, __("response_no_permission"));

					$vars = [
						"template" => [
							"title" => __("widgets_updateplugin_title")
						],
						"handler" => [
							"id" => $id,
							"tpl" => $tpl,
							"size" => "sm",
							"type" => "update",
							"table" => "administration.plugins",
							"loader" => __("widgets_updateplugin_loader"),
							"require" => "plugin|{$GLOBALS["__"]("require_pluginfile")}"
						]
					];
					
					break;
				default:
					response(500, __("response_invalid"));

			endswitch;

	        $this->smarty->assign($vars["template"]);

	    	response(200, false, [
	    		"vars" => (isset($vars["handler"]) ? $vars["handler"] : false),
	    		"tpl" => $this->smarty->fetch(template . "/widgets/modals/{$tpl}.tpl")
	    	]);
		endif;

		response(500, __("response_invalid"));
	}

	public function chart()
	{
		$this->header->allow(site_url);

		if(!$this->session->has("logged"))
            response(401);

        set_template("dashboard");

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

        switch($type):
        	case "dashboard.messages":
        		$vars = [
        			"chart" => $type
        		];

        		break;
        	case "dashboard.events":
        		$vars = [
        			"chart" => $type
        		];

        		break;
        	case "dashboard.utilities":
        		$vars = [
        			"chart" => $type
        		];

        		break;
    		case "admin.countries":
        		if(!is_admin)
					response(500, __("response_invalid"));
					
        		$vars = [
        			"chart" => $type
        		];

        		break;
        	case "admin.browsers":
        		if(!is_admin)
					response(500, __("response_invalid"));
					
        		$vars = [
        			"chart" => $type
        		];

        		break;
        	case "admin.os":
        		if(!is_admin)
					response(500, __("response_invalid"));
					
        		$vars = [
        			"chart" => $type
        		];

        		break;
        	case "admin.messages":
        		if(!is_admin)
					response(500, __("response_invalid"));
					
        		$vars = [
        			"chart" => $type
        		];

        		break;
        	case "admin.utilities":
        		if(!is_admin)
					response(500, __("response_invalid"));
					
        		$vars = [
        			"chart" => $type
        		];

        		break;
        	case "admin.subscriptions":
        		if(!permission("manage_transactions"))
					response(500, __("response_no_permission"));
					
        		$vars = [
        			"chart" => $type
        		];

        		break;
        	case "admin.credits":
        		if(!permission("manage_transactions"))
					response(500, __("response_no_permission"));
					
        		$vars = [
        			"chart" => $type
        		];

        		break;

        	case "admin.commissions":
        		if(!permission("manage_transactions"))
					response(500, __("response_no_permission"));
					
        		$vars = [
        			"chart" => $type
        		];

        		break;
        	default:
        		response(500, __("response_invalid"));
        endswitch;

        $this->smarty->assign($vars);
		$this->smarty->display(template . "/widgets/charts/default.tpl");
	}
}