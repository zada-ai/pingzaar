<?php

class MVC_Library_Ai
{
    const MAX_TOOL_ITERATIONS = 5;

    private $providers = [
        "openai"     => ["adapter" => "openai_compatible", "base_url" => "https://api.openai.com/v1"],
        "deepseekai" => ["adapter" => "openai_compatible", "base_url" => "https://api.deepseek.com"],
        "grokai"     => ["adapter" => "openai_compatible", "base_url" => "https://api.x.ai/v1"],
        "claudeai"   => ["adapter" => "anthropic",         "base_url" => null],
        "geminiai"   => ["adapter" => "gemini",            "base_url" => null]
    ];

    /** Lazily-fetched database handle — see db() for why this can't just be $this->db. */
    private $dbConn = null;

    /** Installed plugins, loaded at most once per request — see plugins(). */
    private $pluginCache = null;

    public function __construct()
    {
        require_once "system/resources/libraries/ai/openai_compatible.php";
        require_once "system/resources/libraries/ai/anthropic.php";
        require_once "system/resources/libraries/ai/gemini.php";
    }

    /**
     * Library instances only get dependencies attached to the controller singleton
     * (system/resources/mvc_load.php's library()), never to sibling libraries — so
     * $this->db here is always an undefined property, not a real database handle.
     * MVC_Model hits the same gap and solves it the same way
     * (system/resources/mvc_model.php:32); mirrored here instead of inventing a new
     * pattern.
     */
    private function db()
    {
        if(!$this->dbConn)
            $this->dbConn = mvc::instance()->controller->load->database();

        return $this->dbConn;
    }

    /**
     * Coerce a string to valid UTF-8.
     *
     * Two places downstream reject invalid bytes outright, and neither failure is
     * recoverable once it happens:
     *
     * - Guzzle's "json" option runs the payload through GuzzleHttp\Utils::jsonEncode(),
     *   which throws InvalidArgumentException on malformed UTF-8. The adapters catch it,
     *   so the request survives, but the whole conversation turn is lost and the
     *   customer gets no reply.
     * - ai_conversations.content is utf8mb4 on a STRICT_TRANS_TABLES connection with
     *   PDO::ERRMODE_EXCEPTION, so an invalid byte raises SQLSTATE HY000 1366. Two of
     *   the four conversation() call sites have no try/catch around them, so that
     *   propagates up and aborts the inbound webhook.
     *
     * Inbound message text comes off an SMS or WhatsApp webhook, and tool results can be
     * raw bytes a plugin read off disk, so neither is guaranteed clean.
     */
    private function utf8($text)
    {
        if(!is_string($text) || $text === "" || mb_check_encoding($text, "UTF-8"))
            return $text;

        return mb_convert_encoding($text, "UTF-8",
            mb_detect_encoding($text, ["UTF-8", "Windows-1252", "ISO-8859-1"], true) ?: "Windows-1252");
    }

    private function log($message)
    {
        // Same rule as db() above: no injected ->file on library instances. Match the
        // precedent already used for this in mvc_library_titansys.php and the adapters.
        @file_put_contents("system/storage/temporary/error.log",
            "AI Error: {$message} (" . date("m/d/Y g:i A") . ")\n\n", FILE_APPEND);
    }

    /**
     * Every installed plugin's returned array, keyed by directory.
     *
     * plugin.php is require'd at most once per request. Not require_once — the
     * second require_once returns true rather than the array — but cached, because
     * requiring the same plugin twice would redeclare any top-level function it
     * defines (ai-context declares processContent()) and fatal the request.
     */
    private function plugins()
    {
        if($this->pluginCache !== null)
            return $this->pluginCache;

        $this->pluginCache = [];
        $dir = "system/plugins/installables";

        if(!is_dir($dir))
            return $this->pluginCache;

        // Driven by the plugins table, not by scandir: an uninstall that failed
        // partway, or a half-extracted upload, can leave a directory on disk with no
        // row behind it. Such a directory must not become a callable tool.
        foreach((array) $this->db()->query_all("SELECT directory FROM plugins") as $row):
            $file = "{$dir}/{$row["directory"]}/plugin.php";

            if(!file_exists($file)) continue;

            try {
                $plugin = require $file;
            } catch(Throwable $e){
                $this->log("plugin {$row["directory"]} failed to load: " . $e->getMessage());
                continue;
            }

            if(is_array($plugin))
                $this->pluginCache[$row["directory"]] = $plugin;
        endforeach;

        return $this->pluginCache;
    }

    /**
     * Tool schemas from every installed plugin that declares one *and* exposes a
     * callable "tool" handler.
     *
     * The "tool" requirement is deliberate. A plugin's "default" key is an HTTP
     * request handler — it calls response(), which writes headers and terminates the
     * request. Advertising such a plugin to the model would guarantee a dead
     * conversation the moment the model chose it, so a plugin without "tool" is
     * simply not offered.
     */
    private function tools()
    {
        $tools = [];

        foreach($this->plugins() as $plugin):
            if(!isset($plugin["schema"]["name"])) continue;
            if(!isset($plugin["tool"]) || !($plugin["tool"] instanceof Closure)) continue;

            $tools[] = ["type" => "function", "function" => $plugin["schema"]];
        endforeach;

        return $tools;
    }

    /** Invoke one plugin's tool handler and return its result as a string. */
    private function invokeTool($name, array $arguments, array $context)
    {
        foreach($this->plugins() as $directory => $plugin):
            if(!isset($plugin["schema"]["name"]) || $plugin["schema"]["name"] !== $name) continue;
            if(!isset($plugin["tool"]) || !($plugin["tool"] instanceof Closure)) continue;

            // Plugin closures are written against the controller ($this->guzzle,
            // $this->system, ...). A closure binds $this where it is created, and
            // plugin.php is require'd from inside this library — so without rebinding,
            // $this would be MVC_Library_Ai, which has none of those properties.
            $controller = mvc::instance()->controller;
            $handler    = Closure::bind($plugin["tool"], $controller, get_class($controller));

            // Closure::bind returns null rather than throwing when it cannot rebind.
            if(!$handler):
                $this->log("tool {$name} ({$directory}) could not be bound to the controller");
                return json_encode(["error" => "tool execution failed"]);
            endif;

            try {
                // Throwable, not Exception: a TypeError or ArgumentCountError raised by
                // a third-party plugin would otherwise take down the whole request.
                $result = $handler($arguments, $context);
            } catch(Throwable $e){
                $this->log("tool {$name} ({$directory}) failed: " . $e->getMessage());
                return json_encode(["error" => "tool execution failed"]);
            }

            // A plugin returning raw file bytes as a string skips the json_encode below
            // entirely, so it needs the same protection the array path gets.
            if(is_string($result))
                return $this->utf8($result);

            // JSON_INVALID_UTF8_SUBSTITUTE, because json_encode() returns false — not a
            // string, and with no warning — the moment any value holds invalid UTF-8.
            // That false becomes "" when an adapter casts it, so the model would be
            // handed an empty tool result and would answer as though the tool found
            // nothing. A plugin returning bytes read off disk makes this ordinary.
            $encoded = json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);

            if($encoded === false):
                $this->log("tool {$name} ({$directory}) returned a value that could not be encoded: " . json_last_error_msg());
                return json_encode(["error" => "tool returned an unencodable result"]);
            endif;

            return $encoded;
        endforeach;

        $this->log("tool {$name} requested but no installed plugin provides it");
        return json_encode(["error" => "unknown tool"]);
    }

    private function history($conversationKey)
    {
        $rows = $this->db()->query_all("SELECT role, content FROM ai_conversations
                          WHERE conversation_key = ? ORDER BY id ASC", [$conversationKey]);

        $messages = [];
        foreach((array) $rows as $row):
            $messages[] = ["role" => $row["role"], "content" => $row["content"]];
        endforeach;

        return $messages;
    }

    private function remember($uid, $conversationKey, $role, $content)
    {
        $this->db()->query("INSERT INTO ai_conversations (uid, conversation_key, role, content)
                          VALUES (?, ?, ?, ?)", [$uid, $conversationKey, $role, $this->utf8($content)]);
    }

    /** Reset one recipient's conversation with one site (the existing per-phone scope). */
    public function reset($conversationKey)
    {
        return $this->db()->query("DELETE FROM ai_conversations WHERE conversation_key = ?",
            [$conversationKey]);
    }

    /**
     * Reset every conversation belonging to one Zender user.
     *
     * Why per-user, not per-AI-key: the two former aiConversationReset() callers
     * (requests.php ~7667, editing an ai_keys row, and ~8333, editing an autoreply that
     * references one) both pass an ai_keys.id. But conversation_key is derived as
     * sha1(phone)_md5(uid)_sha1(site_url) — it has no ai_key component at all, and
     * ai_conversations has no column for one either (db.sql: id, uid, conversation_key,
     * role, content, created_at). So conversations aren't segregated per AI key in the
     * first place: if a user's phone talks through two different autoreplies using two
     * different ai_keys, both share the exact same conversation_key and row set today.
     * A reset scoped to "this one ai_key" is therefore not something the data model can
     * express — the only unit that actually exists is "this user's conversations".
     *
     * Both call sites already operate strictly within the editing user's own scope
     * (system->update(..., logged_id, "ai_keys"/"actions", ...)), so the ai_keys.id
     * they used to pass was never needed to find the owner — it's the same logged_id
     * already in hand. Callers now pass that directly instead of an ai_keys.id.
     */
    public function resetForUser($uid)
    {
        return $this->db()->query("DELETE FROM ai_conversations WHERE uid = ?", [$uid]);
    }

    /**
     * Moderate text (and optionally one image) against OpenAI's moderation endpoint.
     * Returns true only when content is flagged. Fails OPEN on any error — an
     * unreachable/invalid-key moderation call must never block a customer's message,
     * so every failure path returns false and logs instead.
     */
    public function moderate($apikey, $text, $attachment = false)
    {
        if(empty($apikey)) return false;

        $input = [];

        if(!empty($text))
            $input[] = ["type" => "text", "text" => (string) $text];

        if(!empty($attachment) && is_string($attachment))
            $input[] = ["type" => "image_url", "image_url" => ["url" => $attachment]];

        if(empty($input)) return false;

        try {
            $client = new \GuzzleHttp\Client();

            $res = $client->post("https://api.openai.com/v1/moderations", [
                "headers" => [
                    "Authorization" => "Bearer " . $apikey,
                    "Content-Type"  => "application/json"
                ],
                // Plain string input for the common text-only case (verified live
                // against OpenAI); the typed array form is only used when an image is
                // attached, since a bare string can't carry an image_url part.
                "json" => ["input" => count($input) === 1 && $input[0]["type"] === "text" ? $text : $input],
                "http_errors"    => false,
                "timeout"        => 10,
                "connect_timeout" => 5
            ]);
        } catch(Exception $e){
            $this->log("moderation: " . $e->getMessage());
            return false;
        }

        $status = $res->getStatusCode();
        $body   = json_decode($res->getBody()->getContents(), true);

        if($status !== 200):
            $this->log("moderation: HTTP {$status}: " .
                (isset($body["error"]["message"]) ? $body["error"]["message"] : "unknown"));
            return false;
        endif;

        return !empty($body["results"][0]["flagged"]);
    }

    /**
     * Run one conversational turn.
     * Returns the reply string, or false with the reason written to error.log.
     */
    public function conversation($uid, $conversationKey, $provider, $apikey, $model,
                                 $systemPrompt, $message, $maxTokens = 1024, $useHistory = true)
    {
        if(!isset($this->providers[$provider])):
            $this->log("unknown provider '{$provider}'");
            return false;
        endif;

        // Normalised once, here, so every downstream use of it — the provider payload,
        // the history INSERT, the error log — is working with valid UTF-8. The text
        // arrives off an SMS or WhatsApp webhook and is not guaranteed to be.
        $message = $this->utf8($message);

        $config  = $this->providers[$provider];
        $tools   = $this->tools();
        $history = $useHistory ? $this->history($conversationKey) : [];

        $messages = $history;
        $messages[] = ["role" => "user", "content" => $message];

        $request = [
            "base_url"   => $config["base_url"],
            "apikey"     => $apikey,
            "model"      => $model,
            "system"     => $systemPrompt,
            "tools"      => $tools,
            "max_tokens" => $maxTokens
        ];

        // OpenAI-compatible carries the system prompt as a message; the others do not.
        if($config["adapter"] === "openai_compatible" && !empty($systemPrompt))
            array_unshift($messages, ["role" => "system", "content" => $systemPrompt]);

        for($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++):
            $request["messages"] = $messages;

            switch($config["adapter"]):
                case "anthropic": $response = ai_anthropic_send($request); break;
                case "gemini":    $response = ai_gemini_send($request);    break;
                default:          $response = ai_openai_compatible_send($request);
            endswitch;

            if(!$response["ok"]):
                $this->log("{$provider}: " . $response["error"]);
                return false;
            endif;

            if(empty($response["tool_calls"])):
                $reply = (string) $response["text"];
                $this->remember($uid, $conversationKey, "user", $message);
                $this->remember($uid, $conversationKey, "assistant", $reply);
                return $reply;
            endif;

            // Echo the model's own tool-call turn back verbatim, then answer each call
            // with a matching tool-result turn. Both are normalised shapes; each
            // adapter translates them into its provider's native format.
            $messages[] = [
                "role"       => "assistant",
                "content"    => $response["text"],
                "tool_calls" => $response["tool_calls"]
            ];

            foreach($response["tool_calls"] as $call):
                $messages[] = [
                    "role"         => "tool",
                    "tool_call_id" => $call["id"],
                    "name"         => $call["name"],
                    "content"      => $this->invokeTool($call["name"], $call["arguments"], ["uid" => $uid])
                ];
            endforeach;
        endfor;

        $this->log("{$provider}: tool-call loop exceeded " . self::MAX_TOOL_ITERATIONS . " iterations");
        return false;
    }
}
