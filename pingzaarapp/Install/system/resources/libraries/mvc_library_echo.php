<?php
/**
 * Realtime
 * @author Titan Systems <mail@titansystems.ph>
 * @description Pusher-protocol client. The same wire protocol is spoken by
 *              Pusher Channels, Sockety, Soketi and Laravel Reverb, so the
 *              provider is host configuration rather than a code path.
 */

use GuzzleHttp\Client;

class MVC_Library_Echo
{
	private $guzzle;
	private $credentials = null;

	public function __construct()
	{
		$this->guzzle = new Client();
	}

	/**
	 * Overrides the stored settings for the calls that follow. The settings
	 * screen uses this to prove a configuration before it is saved, so that
	 * one signing implementation covers both the live path and the test.
	 */
	public function using($credentials)
	{
		$this->credentials = $credentials;

		return $this;
	}

	/**
	 * Reads a credential from the override when one is set, otherwise from the
	 * system settings that set_system() turned into constants.
	 */
	private function credential($name)
	{
		if(is_array($this->credentials))
			return isset($this->credentials[$name]) ? trim($this->credentials[$name]) : "";

		return defined("system_realtime_{$name}") ? trim(constant("system_realtime_{$name}")) : "";
	}

	/**
	 * Realtime is on only when all three credentials are present. Callers use
	 * this to skip work entirely rather than pay for a doomed HTTP round-trip.
	 */
	public function enabled()
	{
		foreach(["app_id", "key", "secret"] as $name):
			if($this->credential($name) === "")
				return false;
		endforeach;

		return true;
	}

	/**
	 * The non-secret half of the credentials, safe to hand to a browser.
	 */
	public function config()
	{
		if(!$this->enabled())
			return false;

		$endpoint = $this->endpoint();

		return [
			"key" => $this->credential("key"),
			"cluster" => $this->credential("cluster"),
			"host" => $endpoint["ws_host"],
			"port" => $endpoint["port"],
			"tls" => $endpoint["scheme"] === "https"
		];
	}

	public function trigger($channel, $event, $payload = [])
	{
		if(!$this->enabled())
			return false;

		$endpoint = $this->endpoint();
		$path = "/apps/" . $this->credential("app_id") . "/events";

		// Pusher nests the payload as a JSON string inside the envelope.
		$body = json_encode([
			"name" => $event,
			"channel" => $channel,
			"data" => json_encode($payload)
		]);

		$query = [
			"auth_key" => $this->credential("key"),
			"auth_timestamp" => (string) time(),
			"auth_version" => "1.0",
			"body_md5" => md5($body)
		];

		$query["auth_signature"] = $this->sign("POST", $path, $query);

		try {
			$response = $this->guzzle->post(
				"{$endpoint["scheme"]}://{$endpoint["host"]}:{$endpoint["port"]}{$path}?" . http_build_query($query), [
					"body" => $body,
					"headers" => [
						"Content-Type" => "application/json"
					],
					"timeout" => 3,
					"connect_timeout" => 3,
					// The Pusher REST API never redirects. Following one would
					// only let a malicious host bounce this request to an
					// unrelated path on an unrelated target.
					"allow_redirects" => false,
					"http_errors" => false
				]
			);

			if($response->getStatusCode() === 200)
				return true;

			$this->log("{$channel}/{$event} returned HTTP " . $response->getStatusCode() . " " . $response->getBody()->getContents());

			return false;
		} catch(Exception $e){
			$this->log("{$channel}/{$event} failed: " . $e->getMessage());

			return false;
		}
	}

	/**
	 * Signs a private-channel subscription. The caller is responsible for
	 * deciding whether this session may hold the channel at all -- this only
	 * produces the signature once that decision has been made.
	 */
	public function authorize($socket_id, $channel)
	{
		if(!$this->enabled())
			return false;

		return $this->credential("key") . ":" . hash_hmac("sha256", "{$socket_id}:{$channel}", $this->credential("secret"));
	}

	/**
	 * Rejects a host that could never be a realtime server but is a classic
	 * server-side-request-forgery target -- above all the cloud metadata
	 * services on the link-local range.
	 *
	 * Private and loopback addresses are deliberately ALLOWED: running the
	 * realtime server on the same box or the same private network is the
	 * normal self-hosted deployment, so blocking them would break the common
	 * case to defend against a much narrower one.
	 *
	 * Called for credentials supplied in a request (the settings screen's test
	 * button) rather than on the hot path, where the host is saved
	 * configuration an administrator has already reviewed.
	 */
	public function reachable($host)
	{
		$host = trim($host);

		if($host === "")
			return true;

		if(!preg_match("#^https?://#i", $host))
			$host = "https://{$host}";

		$parts = parse_url($host);

		if(!isset($parts["host"]) || $parts["host"] === "")
			return false;

		$name = trim($parts["host"], "[]");
		$address = filter_var($name, FILTER_VALIDATE_IP) ? $name : gethostbyname($name);

		// gethostbyname() hands back the input unchanged when it cannot resolve.
		if(!filter_var($address, FILTER_VALIDATE_IP))
			return false;

		// 169.254.0.0/16 and fe80::/10 -- link-local, where every major cloud
		// provider parks its instance metadata service.
		if(filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
			return strpos($address, "169.254.") !== 0;

		$packed = @inet_pton($address);

		if($packed === false)
			return false;

		return (ord($packed[0]) !== 0xfe) || ((ord($packed[1]) & 0xc0) !== 0x80);
	}

	/**
	 * Pusher canonicalises a REST request as "METHOD\n/path\nquery", where the
	 * query is sorted by key, values are unencoded, and auth_signature itself
	 * is excluded.
	 */
	private function sign($method, $path, $query)
	{
		ksort($query);

		$pairs = [];

		foreach($query as $key => $value):
			if($key !== "auth_signature")
				$pairs[] = "{$key}={$value}";
		endforeach;

		return hash_hmac("sha256", $method . "\n" . $path . "\n" . implode("&", $pairs), $this->credential("secret"));
	}

	/**
	 * Pusher's hosted service splits REST and WebSocket across different
	 * subdomains of the cluster. Any compatible server -- Sockety, Soketi,
	 * Reverb -- serves both from one origin, so the driver decides which shape
	 * to build. A compatible driver with no host falls back to the hosted
	 * service rather than producing an unusable empty host.
	 */
	private function endpoint()
	{
		$host = $this->credential("host");

		if($this->credential("driver") !== "compatible" || $host === ""):
			$cluster = $this->credential("cluster");

			if($cluster === "")
				$cluster = "mt1";

			return [
				"scheme" => "https",
				"host" => "api-{$cluster}.pusher.com",
				"ws_host" => "ws-{$cluster}.pusher.com",
				"port" => 443
			];
		endif;

		if(!preg_match("#^https?://#i", $host))
			$host = "https://{$host}";

		$parts = parse_url($host);
		$scheme = isset($parts["scheme"]) ? strtolower($parts["scheme"]) : "https";

		return [
			"scheme" => $scheme,
			"host" => isset($parts["host"]) ? $parts["host"] : "",
			"ws_host" => isset($parts["host"]) ? $parts["host"] : "",
			"port" => (int) (isset($parts["port"]) ? $parts["port"] : ($scheme === "https" ? 443 : 80))
		];
	}

	private function log($message)
	{
		@file_put_contents("system/storage/temporary/error.log",
			"Realtime Error: {$message} (" . date("m/d/Y g:i A") . ")\n\n", FILE_APPEND);
	}
}
