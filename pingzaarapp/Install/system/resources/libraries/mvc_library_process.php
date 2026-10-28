<?php

use GuzzleHttp\Client;
use \Lex\Parser;

class MVC_Library_Process 
{
	use DynamicProperties;
	
	private $guzzle;
	private $lex;
	public $_sanitize = false;

	public function __construct()
	{
		$this->guzzle = new Client();
		$this->lex = new Parser();
	}

	public function webhooks($uid, $type, $payload, $webhooks)
	{
		$webhookArray = [];

		if(!empty($webhooks)):
			foreach($webhooks as $webhook):
				$form = [
					"secret" => $webhook["secret"],
					"type" => $type,
					"data" => $payload
				];

				if($this->_sanitize->isUrl($webhook["url"])):
					try {
						$webhookResponse = $this->guzzle->post($webhook["url"], [
				            "form_params" => $form,
							"timeout" => 5,
							"connect_timeout" => 5,
				            "allow_redirects" => true,
				            "http_errors" => false,
							"verify" => false
				        ])->getStatusCode();
						
						if($webhookResponse == 200):
							$webhookArray[] = $webhook["id"];
							
							$webhookArray[] = [
								"logger" => [
									"type" => "call_webhook",
									"reason" => "Webhook Triggered!",
									"content" => "Webhook call to {$webhook["url"]} with POST: " . json_encode($form)
								]
							];
						else:
							$webhookArray[] = [
								"logger" => [
									"type" => "call_webhook",
									"reason" => "Webhook Failed!",
									"content" => "Webhook call failed to {$webhook["url"]} with POST: " . json_encode($form)
								]
							];
						endif;
					} catch(Exception $e){
						$webhookArray[] = [
							"logger" => [
								"type" => "call_webhook",
								"reason" => "Webhook Failed!",
								"content" => "Webhook call failed to {$webhook["url"]} with POST: " . json_encode($form)
							]
						];
					}
				endif;
			endforeach;
		endif;

		return $webhookArray;
	}

	public function actionHooks($uid, $source, $event, $phone, $message, $hooks)
	{
		$actionArray = [];

		if(!empty($hooks)):
			foreach($hooks as $hook):
				if($source == $hook["source"] && $event == $hook["event"]):
					if($this->_sanitize->isUrl($hook["link"])):
						try {
							$this->guzzle->get($this->lex->parse($hook["link"], [
		        				"phone" => urlencode($phone),
		        				"message" => urlencode($message),
		        				"date" => [
		        					"now" => urlencode(date("F j, Y")),
		        					"time" => urlencode(date("h:i A"))
		        				]
		        			]), [
					            "allow_redirects" => true,
					            "http_errors" => false
					        ]);

					        $actionArray[] = $hook["id"];
						} catch(Exception $e){
							// Ignore
						}
					endif;
				endif;
			endforeach;
		endif;

		return $actionArray;
	}

	public function actionAutoreplies($uid, $source, $phone, $message, $autoreplies, $subscription = [], $extra = [])
	{
		$actionArray = [];

		if(!empty($autoreplies)):
			$noneWhatsApp = true;
			$noneSms = true;

			foreach($autoreplies as $autoreply):
				if($source == $autoreply["source"] && $autoreply["match"] < 5):
					$detected = false;

					if($this->checkMatch($message, $autoreply["keywords"], $autoreply["match"])):
						$detected = true;
					endif;

					if($detected):
						$rejected = false;
						$loggerType = [
							1 => "Exact (Case Insensitive)",
							2 => "Exact (Case Sensitive)",
							3 => "Contains The Keywords",
							4 => "Regular Expression"
						];

						if(isset($extra["account"]) && $extra["account"] == $autoreply["account"]):
							$noneWhatsApp = false;
							$continueResponse = false;

							if($autoreply["group_trigger"] > 1):
								$checkPhoneIfGroup = explode("@", $phone);

								if(count($checkPhoneIfGroup) < 2):
									$continueResponse = true;
								endif;
							else:
								$continueResponse = true;
							endif;

							if($continueResponse):
								$loggerMessage = false;
								
								try {
									$msgDecode = json_decode($autoreply["message"], true, JSON_THROW_ON_ERROR);
				
									if(isset($msgDecode["caption"])):
										$msgDecode["caption"] = $this->lex->parse(footermark($subscription["footermark"], $msgDecode["caption"], system_message_mark), [
											"phone" => $phone,
											"message" => $message,
											"date" => [
												"now" => date("F j, Y"),
												"time" => date("h:i A") 
											]
										]);

										$loggerMessage = $msgDecode["caption"];
									endif;

									if(isset($msgDecode["text"])):
										$msgDecode["text"] = $this->lex->parse(footermark($subscription["footermark"], $msgDecode["text"], system_message_mark), [
											"phone" => $phone,
											"message" => $message,
											"date" => [
												"now" => date("F j, Y"),
												"time" => date("h:i A") 
											]
										]);

										$loggerMessage = $msgDecode["text"];
									endif;
								} catch(Exception $e){
									$rejected = true;
								}

								if(!$rejected):
									$actionArray[] = [
										"priority" => $autoreply["priority"],
										"account" => $extra["account"],
										"message" => json_encode($msgDecode)
									];
									
									$actionArray[] = [
										"logger" => [
											"type" => "whatsapp_autoreply",
											"reason" => $loggerType[$autoreply["match"]],
											"content" => "Recipient: {$phone}, Message: {$loggerMessage}"
										]
									];
								endif;
							endif;
						else:
							if(isset($extra["sim"]) && $extra["sim"] == $autoreply["sim"] && $extra["device"] == $autoreply["device"]):
								$noneSms = false;

								$actionArray[] = [
									"priority" => $autoreply["priority"],
									"device" => $extra["device"],
									"message" => $this->lex->parse(footermark($subscription["footermark"], $autoreply["message"], system_message_mark), [
										"phone" => $phone,
										"message" => $message,
										"date" => [
											"now" => date("F j, Y"),
											"time" => date("h:i A") 
										]
									])
								];

								$actionArray[] = [
									"logger" => [
										"type" => "sms_autoreply",
										"reason" => $loggerType[$autoreply["match"]],
										"content" => "Recipient: {$phone}, Message: {$autoreply["message"]}"
									]
								];
							endif;
						endif;
					endif;
				endif;
			endforeach;

			foreach($autoreplies as $autoreply):
				if($source == $autoreply["source"] && $autoreply["match"] == 5):
					$rejected = false;

					if(isset($extra["account"]) && $noneWhatsApp && $extra["account"] == $autoreply["account"]):
						$continueResponse = false;

						if($autoreply["group_trigger"] > 1):
							$checkPhoneIfGroup = explode("@", $phone);

							if(count($checkPhoneIfGroup) < 2):
								$continueResponse = true;
							endif;
						else:
							$continueResponse = true;
						endif;

						if($continueResponse):
							$loggerMessage = false;

							try {
								$msgDecode = json_decode($autoreply["message"], true, JSON_THROW_ON_ERROR);
			
								if(isset($msgDecode["caption"])):
									$msgDecode["caption"] = $this->lex->parse(footermark($subscription["footermark"], $msgDecode["caption"], system_message_mark), [
										"phone" => $phone,
										"message" => $message,
										"date" => [
											"now" => date("F j, Y"),
											"time" => date("h:i A") 
										]
									]);

									$loggerMessage = $msgDecode["caption"];
								endif;

								if(isset($msgDecode["text"])):
									$msgDecode["text"] = $this->lex->parse(footermark($subscription["footermark"], $msgDecode["text"], system_message_mark), [
										"phone" => $phone,
										"message" => $message,
										"date" => [
											"now" => date("F j, Y"),
											"time" => date("h:i A") 
										]
									]);

									$loggerMessage = $msgDecode["text"];
								endif;
							} catch(Exception $e){
								$rejected = true;
							}

							if(!$rejected):
								$actionArray[] = [
									"priority" => $autoreply["priority"],
									"account" => $extra["account"],
									"message" => json_encode($msgDecode)
								];

								$actionArray[] = [
									"logger" => [
										"type" => "whatsapp_autoreply",
										"reason" => "None-Matching",
										"content" => "Recipient: {$phone}, Message: {$loggerMessage}"
									]
								];
							endif;
						endif;
					else:
						if(isset($extra["sim"]) && $noneSms && $extra["sim"] == $autoreply["sim"] && $extra["device"] == $autoreply["device"]):
							$actionArray[] = [
								"priority" => $autoreply["priority"],
								"device" => $extra["device"],
								"message" => $this->lex->parse(footermark($subscription["footermark"], $autoreply["message"], system_message_mark), [
									"phone" => $phone,
									"message" => $message,
									"date" => [
										"now" => date("F j, Y"),
										"time" => date("h:i A") 
									]
								])
							];

							$actionArray[] = [
								"logger" => [
									"type" => "sms_autoreply",
									"reason" => "None-Matching",
									"content" => "Recipient: {$phone}, Message: {$autoreply["message"]}"
								]
							];
						endif;
					endif;
				endif;

				if($source == $autoreply["source"] && $autoreply["match"] > 5):
					if(isset($extra["account"]) && $extra["account"] == $autoreply["account"]):
						$continueResponse = false;

						if($autoreply["group_trigger"] > 1):
							$checkPhoneIfGroup = explode("@", $phone);

							if(count($checkPhoneIfGroup) < 2):
								$continueResponse = true;
							endif;
						else:
							$continueResponse = true;
						endif;

						if($continueResponse):
							try {
								$responseFromAi = $extra["aiprovider"]->conversation(
									$uid,
									sha1($phone) . "_" . md5($uid) . "_" . sha1(site_url),
									$autoreply["provider"],
									$autoreply["ai_apikey"],
									$autoreply["ai_model"],
									"{$autoreply["ai_prompt"]} (User Phone Number: {$phone})",
									$message,
									$autoreply["ai_max_tokens"],
									$autoreply["ai_history"]
								);

								if($responseFromAi !== false && $responseFromAi !== ""):
									$actionArray[] = [
										"priority" => $autoreply["priority"],
										"account" => $extra["account"],
										"message" => json_encode([
											"message_type" => "text",
											"text" => $responseFromAi
										])
									];
								else:
									$actionArray[] = [
										"logger" => [
											"type" => "whatsapp_autoreply",
											"reason" => "AI Response Failed",
											"content" => "Recipient: {$phone}"
										]
									];
								endif;
							} catch(Exception $e){
								// Ignore
							}
						endif;
					else:
						if(isset($extra["sim"]) && $extra["sim"] == $autoreply["sim"] && $extra["device"] == $autoreply["device"]):
							try {
								$responseFromAi = $extra["aiprovider"]->conversation(
									$uid,
									sha1($phone) . "_" . md5($uid) . "_" . sha1(site_url),
									$autoreply["provider"],
									$autoreply["ai_apikey"],
									$autoreply["ai_model"],
									"{$autoreply["ai_prompt"]} (User Phone Number: {$phone})",
									$message,
									$autoreply["ai_max_tokens"],
									$autoreply["ai_history"]
								);

								if($responseFromAi !== false && $responseFromAi !== ""):
									$actionArray[] = [
										"priority" => $autoreply["priority"],
										"device" => $extra["device"],
										"message" => $responseFromAi
									];
								else:
									$actionArray[] = [
										"logger" => [
											"type" => "sms_autoreply",
											"reason" => "AI Response Failed",
											"content" => "Recipient: {$phone}"
										]
									];
								endif;
							} catch(Exception $e){
								// Ignore
							}
						endif;
					endif;
				endif;
			endforeach;
		endif;

		return $actionArray;
	}

	private function checkMatch($string, $trigger, $match) : bool
	{
		switch($match):
			case 1:
				return strcasecmp($string, $trigger) === 0;

				break;
			case 2:
				return strcmp($string, $trigger) === 0;

				break;
			case 3:
				$keywords = array_map("trim", explode(",", $this->_sanitize->cleanAutoreplyKeywordCommas($trigger)));

				foreach ($keywords as $keyword):
					$escapedKeyword = preg_quote($keyword, '/');
					$pattern = "/\b{$escapedKeyword}\b/iu";

					if (preg_match($pattern, $string)):
						return true;
					endif;
				endforeach;

				break;
			default:
				return $this->isRegularExpressionValid($trigger) ? preg_match($trigger, $string) === 1 : false;
		endswitch;

		return false;
	}

	private function isRegularExpressionValid($pattern) : bool
	{
		set_error_handler(function($severity, $message, $file, $line) {
			throw new Exception($message);
		});
	
		try {
			preg_match($pattern, '');
			restore_error_handler();
			return true;
		} catch (Exception $e) {
			restore_error_handler();
			return false;
		}
	}
}