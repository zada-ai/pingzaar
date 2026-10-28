<?php

class MVC_Library_Flow
{
	/**
	 * Log error messages to system error log
	 */
	private function logError($message)
	{
		$file = new MVC_Library_File();
		$file->put("system/storage/temporary/error.log", 
		          "Flow Error: {$message} (" . date("m/d/Y g:i A") . ")\n", 
		          FILE_APPEND);
	}

	/**
	 * Get system message mark safely
	 */
	private function getSystemMessageMark()
	{
		return defined('system_message_mark') ? system_message_mark : '';
	}

	/**
	 * Check if user is currently paused for specific context
	 */
	public function isUserPaused($uid, $phone, $context = "flow")
	{
		$cache = new MVC_Library_Cache();
		$cache->container("system.flows.{$uid}");
		
		$cacheKey = hash('sha256', "{$uid}_{$phone}_{$context}");
		return $cache->has($cacheKey);
	}

	/**
	 * Process pause node and set pause status
	 */
	private function processPauseNode($node, $uid, $phone, $nodeId, $duration, $context)
	{
		$cache = new MVC_Library_Cache();
		$cache->container("system.flows.{$uid}");
		
		$cacheKey = hash('sha256', "{$uid}_{$phone}_{$context}");
		$pauseData = [
			'uid' => $uid,
			'phone' => $phone,
			'context' => $context,
			'node_id' => $nodeId,
			'started_at' => time()
		];
		
		return $cache->set($cacheKey, $pauseData, $duration);
	}

	/**
	 * Process resume node and remove pause status
	 */
	private function processResumeNode($node, $uid, $phone, $nodeId, $context)
	{
		
		$cache = new MVC_Library_Cache();
		$cache->container("system.flows.{$uid}");
		
		$cacheKey = hash('sha256', "{$uid}_{$phone}_{$context}");
		
		// Check if pause exists before deletion
		$pauseExists = $cache->has($cacheKey);
		
		$deleted = $cache->delete($cacheKey);
		
		// Verify deletion
		$stillExists = $cache->has($cacheKey);
		
		return true;
	}

	/**
	 * Get pause status details
	 */
	public function getPauseStatus($uid, $phone, $context = "flow")
	{
		$cache = new MVC_Library_Cache();
		$cache->container("system.flows.{$uid}");
		
		$cacheKey = hash('sha256', "{$uid}_{$phone}_{$context}");
		return $cache->get($cacheKey);
	}

	/**
	 * Evaluate condition logic (extracted from condition node processing)
	 */
	private function evaluateCondition($conditionType, $value, $message)
	{
		switch($conditionType) {
			case "starts_with":
				return stripos($message, $value) === 0;
			case "ends_with":
				return substr_compare($message, $value, -strlen($value)) === 0;
			case "contains":
				return stripos($message, $value) !== false;
			case "equal":
				return strcasecmp($message, $value) === 0;
			case "not_contain":
				return stripos($message, $value) === false;
			case "regex":
				return $this->evaluateRegex($value, $message);
			default:
				return false;
		}
	}


	/**
	 * Check if the current message would lead to a resume node being executed
	 */
	public function messageLeadsToResume($flows, $message, $source, $extra = [])
	{
		
		if(!is_array($flows)) {
			return false;
		}
		
		foreach($flows as $flowId => $flow) {
			if(!isset($flow['flow_data']) || empty($flow['flow_data'])) {
				continue;
			}
			
			$flowData = json_decode($flow['flow_data'], true);
			if(json_last_error() !== JSON_ERROR_NONE || !isset($flowData['drawflow']['Home']['data'])) {
				continue;
			}
			
			
			// Find matching source nodes
			$sourceNodes = [];
			foreach($flowData['drawflow']['Home']['data'] as $nodeId => $node) {
				if($node['name'] === $source) {
					// For WhatsApp, check account match
					if($source === "whatsapp" && isset($node["data"]["whatsapp"])) {
						$nodeAccount = $node["data"]["whatsapp"];
						$extraAccount = $extra["account"] ?? '';
						if($nodeAccount !== $extraAccount) {
							continue;
						}
					}
					// For SMS, check device match. The builder writes this as
					// data.device (flow.js emits df-device); reading data["sms"]
					// meant the isset() never held and the flow fired for every
					// device rather than the one it was bound to.
					if($source === "sms" && isset($node["data"]["device"])) {
						$nodeDevice = $node["data"]["device"];
						$extraDevice = $extra["device"] ?? '';
						if($nodeDevice !== $extraDevice) {
							continue;
						}
					}
					$sourceNodes[] = $nodeId;
				}
			}
			
			
			// Trace path from each source node
			foreach($sourceNodes as $sourceNodeId) {
				if($this->tracePathToResume($flowData, $sourceNodeId, $message)) {
					return true;
				}
			}
		}
		
		return false;
	}

	/**
	 * Trace path from a source node to see if it leads to resume
	 */
	private function tracePathToResume($flowData, $startNodeId, $message, $visitedNodes = [])
	{
		
		// Prevent infinite loops
		if(in_array($startNodeId, $visitedNodes)) {
			return false;
		}
		$visitedNodes[] = $startNodeId;
		
		$currentNode = $flowData['drawflow']['Home']['data'][$startNodeId] ?? null;
		if(!$currentNode) {
			return false;
		}
		
		
		// If this is a resume node, we found our target
		if($currentNode['name'] === 'resume') {
			return true;
		}
		
		// If this is a condition node, evaluate it
		if($currentNode['name'] === 'condition') {
			$conditionType = $currentNode["data"]["condition"] ?? "contains";
			$value = $currentNode["data"]["value"] ?? "";
			
			// If condition fails, this path is blocked
			$conditionResult = $this->evaluateCondition($conditionType, $value, $message);
			if(!$conditionResult) {
				return false;
			}
		}
		
		// Follow all output connections
		if(isset($currentNode['outputs'])) {
			foreach($currentNode['outputs'] as $outputKey => $output) {
				if(isset($output['connections'])) {
					foreach($output['connections'] as $connection) {
						$nextNodeId = $connection['node'];
						if($this->tracePathToResume($flowData, $nextNodeId, $message, $visitedNodes)) {
							return true;
						}
					}
				}
			}
		} else {
		}
		
		return false;
	}

	public function start($uid, $phone, $message, $flows, $subscription = [], $extra = [])
	{
		
		// Input validation
		if(empty($uid) || empty($phone) || !isset($message)) {
			$this->logError("Missing required parameters - uid: " . ($uid ?: 'empty') . ", phone: " . ($phone ?: 'empty') . ", message: " . (isset($message) ? 'set' : 'not set'));
			return [];
		}
		
		
		// Get flow configuration
		if(isset($flows)):
			if(is_string($flows)):
				$flows = json_decode($flows, true);
				if(json_last_error() !== JSON_ERROR_NONE):
					$this->logError("JSON decode error: " . json_last_error_msg());
					return [];
				endif;
			elseif(is_array($flows)):
				// Handle flows from database (array with IDs as keys)
				$allFlowSteps = [];
				foreach($flows as $id => $flow):
					// Validate flow structure
					if(!is_array($flow) || !isset($flow['flow_data']) || empty($flow['flow_data'])):
						$this->logError("Invalid flow structure for flow ID: {$id}");
						continue;
					endif;
					
					if(isset($flow['flow_data'])):
						$flowData = json_decode($flow['flow_data'], true);
						if(json_last_error() === JSON_ERROR_NONE && !empty($flowData) && isset($flowData['drawflow']['Home']['data'])):
							// Find source node
							$sourceNode = false;
							foreach($flowData['drawflow']['Home']['data'] as $node) {
								if($node['name'] === 'sms' || $node['name'] === 'whatsapp') {
									$sourceNode = $node['name'];
									break;
								}
							}

							// Process flow if valid source node found
							if($sourceNode) {
								$flowSteps = $this->processFlow(
									$uid,
									$sourceNode,
									$phone,
									$message,
									$flowData,
									$subscription,
									$extra
								);
								
								if(!empty($flowSteps)) {
									$allFlowSteps[] = [
										'id' => $id,
										'name' => $flow['name'],
										'steps' => $flowSteps
									];
								}
							}
						endif;
					endif;
				endforeach;
				return $allFlowSteps;
			endif;
		endif;

		return [];
	}

	public function processFlow($uid, $source, $phone, $message, $flowData, $subscription = [], $extra = [])
	{
		
		$flowSteps = [];
		$maxNodes = 50; // Prevent excessive processing
		$processedCount = 0;

		// Input validation
		if(empty($flowData) || !isset($flowData["drawflow"]["Home"]["data"])):
			$this->logError("Invalid flowData structure in processFlow");
			return $flowSteps;
		endif;
		
		if(!in_array($source, ['sms', 'whatsapp'])):
			$this->logError("Invalid source type: {$source}");
			return $flowSteps;
		endif;


		// Find source nodes (SMS or WhatsApp)
		$sourceNodes = [];
		foreach($flowData["drawflow"]["Home"]["data"] as $nodeId => $node):
			if($node["name"] === $source):
				// For WhatsApp, check if the account matches
				if($source === "whatsapp" && isset($node["data"]["whatsapp"])):
					$nodeAccount = $node["data"]["whatsapp"];
					$extraAccount = $extra["account"] ?? '';
					if($nodeAccount !== $extraAccount):
						continue;
					endif;
				endif;

				// For SMS, check if the device matches. See the note on the same
				// check in the traversal above: the builder key is data.device.
				if($source === "sms" && isset($node["data"]["device"])):
					$nodeDevice = $node["data"]["device"];
					$extraDevice = $extra["device"] ?? '';
					if($nodeDevice !== $extraDevice):
						continue;
					endif;
				endif;

				$sourceNodes[] = $node;
			endif;
		endforeach;

		
		if(empty($sourceNodes)) {
			return $flowSteps;
		}

		// Process each matching source node
		foreach($sourceNodes as $sourceNode):
			$nodesToProcess = [[
				"node" => $sourceNode["id"],
				"parent" => null,
				"pathValid" => true
			]];
			$processedNodes = [];


			// Process all nodes in the queue
			while(!empty($nodesToProcess) && $processedCount < $maxNodes):
				$current = array_shift($nodesToProcess);
				$currentNodeId = $current["node"];
				$processedCount++;
				

				// Skip if already processed in this path (prevent cycles)
				if(in_array($currentNodeId, $processedNodes)):
					$this->logError("Flow cycle detected at node: {$currentNodeId}");
					continue;
				endif;

				$currentNode = $flowData["drawflow"]["Home"]["data"][$currentNodeId] ?? null;
				if(!$currentNode || !isset($currentNode["name"])):
					$this->logError("Invalid node data for node ID: {$currentNodeId}");
					continue;
				endif;
				

				$processedNodes[] = $currentNodeId;

				// If the path is invalid, skip processing this node
				if(!$current["pathValid"]):
					continue;
				endif;

				// Check if user is paused
				$isUserPaused = $this->isUserPaused($uid, $phone, "all");
				
				// Safety check: Skip non-resume nodes when user is paused (failsafe)
				// Allow source nodes (whatsapp/sms), condition nodes, and resume nodes to be processed when paused
				if($isUserPaused && !in_array($currentNode["name"], ["resume", "condition", "whatsapp", "sms"])) {
					// Skip processing action nodes when paused, but allow source nodes to traverse
					continue;
				}

				// Process node based on its type
				switch($currentNode["name"]):
					case "send_text":
						try {
							$replyMessage = $currentNode["data"]["message"] ?? "";
							if($replyMessage):
							if($source === "whatsapp"):
								$flowSteps[] = [
									"type" => "send_text",
									"priority" => $currentNode["data"]["priority"] ?? 2,
									"account" => $extra["account"] ?? '',
									"message" => json_encode([
										"text" => (new \Lex\Parser())->parse(footermark($subscription["footermark"] ?? 1, $replyMessage, $this->getSystemMessageMark()), [
											"phone" => $phone,
											"message" => $message,
											"date" => [
												"now" => date("F j, Y"),
												"time" => date("h:i A")
											]
										])
									])
								];
							else:
								$flowSteps[] = [
									"type" => "send_text",
									"priority" => $currentNode["data"]["priority"] ?? 2,
									"device" => $extra["device"] ?? '',
									"message" => (new \Lex\Parser())->parse(footermark($subscription["footermark"] ?? 1, $replyMessage, $this->getSystemMessageMark()), [
										"phone" => $phone,
										"message" => $message,
										"date" => [
											"now" => date("F j, Y"),
											"time" => date("h:i A")
										]
									])
								];
							endif;
						endif;
						} catch(Exception $e) {
							$this->logError("Error processing send_text node {$currentNodeId}: " . $e->getMessage());
						}
						break;

					case "send_image":
						try {
							if($source === "whatsapp"):
							$imageUrl = $currentNode["data"]["value"] ?? "";
							$caption = $currentNode["data"]["caption"] ?? "";
							if($imageUrl):
								$flowSteps[] = [
									"type" => "send_image",
									"priority" => $currentNode["data"]["priority"] ?? 2,
									"account" => $extra["account"] ?? '',
									"message" => json_encode([
										"image" => [
											"url" => filter_var($imageUrl, FILTER_VALIDATE_URL) === false ? site_url($imageUrl, true) : $imageUrl
										],
										"caption" => (new \Lex\Parser())->parse(footermark($subscription["footermark"] ?? 1, $caption, $this->getSystemMessageMark()), [
											"phone" => $phone,
											"message" => $message,
											"date" => [
												"now" => date("F j, Y"),
												"time" => date("h:i A")
											]
										])
									])
								];
							endif;
						endif;
						} catch(Exception $e) {
							$this->logError("Error processing send_image node {$currentNodeId}: " . $e->getMessage());
						}
						break;

					case "send_video":
						if($source === "whatsapp"):
							$videoUrl = $currentNode["data"]["value"] ?? "";
							$caption = $currentNode["data"]["caption"] ?? "";
							if($videoUrl):
								$flowSteps[] = [
									"type" => "send_video",
									"priority" => $currentNode["data"]["priority"] ?? 2,
									"account" => $extra["account"] ?? '',
									"message" => json_encode([
										"video" => [
											"url" => filter_var($videoUrl, FILTER_VALIDATE_URL) === false ? site_url($videoUrl, true) : $videoUrl
										],
										"caption" => (new \Lex\Parser())->parse(footermark($subscription["footermark"] ?? 1, $caption, $this->getSystemMessageMark()), [
											"phone" => $phone,
											"message" => $message,
											"date" => [
												"now" => date("F j, Y"),
												"time" => date("h:i A")
											]
										])
									])
								];
							endif;
						endif;
						break;

					case "send_audio":
						if($source === "whatsapp"):
							$audioUrl = $currentNode["data"]["value"] ?? "";
							if($audioUrl):
								$flowSteps[] = [
									"type" => "send_audio",
									"priority" => $currentNode["data"]["priority"] ?? 2,
									"account" => $extra["account"] ?? '',
									"message" => json_encode([
										"audio" => [
											"url" => filter_var($audioUrl, FILTER_VALIDATE_URL) === false ? site_url($audioUrl, true) : $audioUrl
										]
									])
								];
							endif;
						endif;
						break;

					case "send_document":
						if($source === "whatsapp"):
							$file = $currentNode["data"]["value"] ?? "";
							$filename = basename($file);
							if($file):
								$flowSteps[] = [
									"type" => "send_document",
									"priority" => $currentNode["data"]["priority"] ?? 2,
									"account" => $extra["account"] ?? '',
									"message" => json_encode([
										"message_type" => "document",
										"document" => filter_var($file, FILTER_VALIDATE_URL) === false ? site_url($file, true) : $file,
										"filename" => $filename
									])
								];
							endif;
						endif;
						break;

					case "ai_reply":
						try {
							$aiKey = $currentNode["data"]["key"] ?? "";
							$aiPlugin = $currentNode["data"]["plugin"] ?? "";
							
							$flowSteps[] = [
								"type" => "ai_reply",
								"priority" => $currentNode["data"]["priority"] ?? 2,
								"ai_key" => $aiKey,
								"ai_plugin" => (int) $aiPlugin,
								"message" => $message
							];
						} catch(Exception $e) {
							$this->logError("Error processing ai_reply node {$currentNodeId}: " . $e->getMessage());
						}
						break;

					case "save_contact":
						$group = $currentNode["data"]["group"] ?? "";

						$flowSteps[] = [
							"type" => "save_contact",
							"group" => $group
						];
						break;

					case "condition":
						try {
							$conditionType = $currentNode["data"]["condition"] ?? "contains";
							$value = $currentNode["data"]["value"] ?? "";
							$result = false;

						switch($conditionType):
							case "starts_with":
								$result = stripos($message, $value) === 0;
								break;
							case "ends_with":
								$result = substr_compare($message, $value, -strlen($value)) === 0;
								break;
							case "contains":
								$result = stripos($message, $value) !== false;
								break;
							case "equal":
								$result = strcasecmp($message, $value) === 0;
								break;
							case "not_contain":
								$result = stripos($message, $value) === false;
								break;
							case "regex":
								$result = $this->evaluateRegex($value, $message);
								break;
						endswitch;

						if(!$result):
							$current["pathValid"] = false;
						endif;
						} catch(Exception $e) {
							$this->logError("Error processing condition node {$currentNodeId}: " . $e->getMessage());
							$current["pathValid"] = false;
						}
						break;

					case "pause":
						try {
							$duration = (int)($currentNode["data"]["duration"] ?? 0);
							$context = $currentNode["data"]["context"] ?? "flow";
							
							// Validate context
							$validContexts = ["flow", "autoreply", "all"];
							if (!in_array($context, $validContexts)) {
								$context = "flow";
							}
							
							// Validate duration (1 second minimum, 0 = infinite)
							if($duration >= 0) {
								$pause_processed = $this->processPauseNode($currentNode, $uid, $phone, $currentNodeId, $duration, $context);
								if ($pause_processed) {
									$current["pathValid"] = false;
								}
							}
						} catch(Exception $e) {
							$this->logError("Error processing pause node {$currentNodeId}: " . $e->getMessage());
							$current["pathValid"] = false;
						}
						break;

					case "resume":
						try {
							$context = $currentNode["data"]["context"] ?? "flow";
							
							// Validate context
							$validContexts = ["flow", "autoreply", "all"];
							if (!in_array($context, $validContexts)) {
								$context = "flow";
							}
							
							$resume_processed = $this->processResumeNode($currentNode, $uid, $phone, $currentNodeId, $context);
						} catch(Exception $e) {
							$this->logError("Error processing resume node {$currentNodeId}: " . $e->getMessage());
						}
						break;
				endswitch;

				if(isset($currentNode["outputs"])):
					foreach($currentNode["outputs"] as $output):
						if(isset($output["connections"])):
							foreach($output["connections"] as $connection):
								$nodesToProcess[] = [
									"node" => $connection["node"],
									"parent" => $currentNodeId,
									"pathValid" => $current["pathValid"]
								];
							endforeach;
						endif;
					endforeach;
				endif;
			endwhile;
		endforeach;

		// Log if max nodes reached
		if($processedCount >= $maxNodes):
			$this->logError("Flow processing stopped: maximum nodes ({$maxNodes}) reached");
		endif;
		
		
		return $flowSteps;
	}
	
	/**
	 * Safely evaluate regex patterns
	 */
	private function evaluateRegex($pattern, $message)
	{
		if(empty($pattern)) {
			return false;
		}
		
		// Add delimiters if not present
		if(!preg_match('/^[\/~#!@%^&*].*[\/~#!@%^&*][gimuxXADSUJ]*$/', $pattern)) {
			$pattern = '/' . preg_quote($pattern, '/') . '/i';
		}
		
		// Validate pattern safety
		try {
			// Test pattern with empty string first
			if(@preg_match($pattern, '') === false) {
				$this->logError("Invalid regex pattern: {$pattern}");
				return false;
			}
			
			// Apply to actual message
			return preg_match($pattern, $message) === 1;
			
		} catch(Exception $e) {
			$this->logError("Regex evaluation error: " . $e->getMessage());
			return false;
		}
	}
}