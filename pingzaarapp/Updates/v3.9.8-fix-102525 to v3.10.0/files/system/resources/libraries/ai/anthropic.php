<?php
/**
 * Anthropic messages API.
 * System prompt is top-level; tools use input_schema; tool calls arrive as
 * content blocks of type "tool_use".
 */

/**
 * Translate the normalised message list into Anthropic's wire format.
 *
 * Anthropic differs from the other two in one way that matters: tool results are
 * not their own role. They are tool_result blocks inside a *user* message, and
 * consecutive results must be merged into a single message — the API rejects two
 * user turns in a row. So tool results are coalesced here rather than emitted
 * one-per-message.
 *
 * A system turn is dropped: the caller carries the system prompt top-level.
 */
function ai_anthropic_messages(array $messages): array
{
    $out = [];

    foreach($messages as $m):
        if($m["role"] === "system")
            continue;

        if($m["role"] === "tool"):
            $block = [
                "type"        => "tool_result",
                "tool_use_id" => $m["tool_call_id"],
                "content"     => (string) $m["content"]
            ];

            // Merge into the previous user message when that message is itself a
            // run of tool results, otherwise start a new one.
            $last = count($out) - 1;

            if($last >= 0 && $out[$last]["role"] === "user" && is_array($out[$last]["content"])
               && isset($out[$last]["content"][0]["type"]) && $out[$last]["content"][0]["type"] === "tool_result"):
                $out[$last]["content"][] = $block;
            else:
                $out[] = ["role" => "user", "content" => [$block]];
            endif;

            continue;
        endif;

        if($m["role"] === "assistant" && !empty($m["tool_calls"])):
            $blocks = [];

            if(isset($m["content"]) && $m["content"] !== "" && $m["content"] !== null)
                $blocks[] = ["type" => "text", "text" => (string) $m["content"]];

            foreach($m["tool_calls"] as $call):
                $blocks[] = [
                    "type"  => "tool_use",
                    "id"    => $call["id"],
                    "name"  => $call["name"],
                    // Cast so an argument-less call serialises as {} and not [].
                    "input" => (object) $call["arguments"]
                ];
            endforeach;

            $out[] = ["role" => "assistant", "content" => $blocks];

            continue;
        endif;

        $out[] = ["role" => $m["role"], "content" => (string) $m["content"]];
    endforeach;

    return $out;
}

function ai_anthropic_send(array $req): array
{
    $client = new \GuzzleHttp\Client();

    $payload = [
        "model"      => $req["model"],
        "messages"   => ai_anthropic_messages($req["messages"]),
        "max_tokens" => !empty($req["max_tokens"]) ? (int) $req["max_tokens"] : 1024
    ];

    if(!empty($req["system"]))
        $payload["system"] = $req["system"];

    if(!empty($req["tools"])):
        $tools = [];
        foreach($req["tools"] as $tool):
            $fn = $tool["function"];
            $tools[] = [
                "name"         => $fn["name"],
                "description"  => isset($fn["description"]) ? $fn["description"] : "",
                // Only "name" is required of a plugin schema, so a no-argument tool may
                // legitimately omit "parameters". OpenAI treats an absent value as "no
                // arguments"; Anthropic and Gemini reject a null, so supply the empty
                // object form rather than letting the same schema work under one
                // provider and fail under another.
                "input_schema" => isset($fn["parameters"])
                                ? $fn["parameters"]
                                : ["type" => "object", "properties" => new stdClass]
            ];
        endforeach;
        $payload["tools"] = $tools;
    endif;

    try {
        $res = $client->post("https://api.anthropic.com/v1/messages", [
            "headers" => [
                "x-api-key"         => $req["apikey"],
                "anthropic-version" => "2023-06-01",
                "Content-Type"      => "application/json"
            ],
            "json"        => $payload,
            "http_errors" => false,
            "timeout"     => 120
        ]);
    } catch(Exception $e){
        return ["ok" => false, "text" => null, "tool_calls" => [], "error" => $e->getMessage()];
    }

    $status = $res->getStatusCode();
    $body   = json_decode($res->getBody()->getContents(), true);

    if($status !== 200)
        return ["ok" => false, "text" => null, "tool_calls" => [],
                "error" => "HTTP {$status}: " . (isset($body["error"]["message"]) ? $body["error"]["message"] : "unknown")];

    $text  = null;
    $calls = [];

    if(!empty($body["content"])):
        foreach($body["content"] as $block):
            if($block["type"] === "text"):
                $text = ($text === null ? "" : $text) . $block["text"];
            elseif($block["type"] === "tool_use"):
                $calls[] = [
                    "id"        => $block["id"],
                    "name"      => $block["name"],
                    // is_array, not isset: a non-array here reaches invokeTool()'s
                    // `array $arguments` parameter, and that TypeError is raised at the
                    // call boundary — before the function body — so invokeTool()'s own
                    // catch(Throwable) never sees it and the request dies.
                    "arguments" => isset($block["input"]) && is_array($block["input"]) ? $block["input"] : []
                ];
            endif;
        endforeach;
    endif;

    return ["ok" => true, "text" => $text, "tool_calls" => $calls, "error" => null];
}
