<?php
/**
 * OpenAI-compatible chat completions.
 * Serves openai, deepseekai and grokai — identical wire format, different base URL.
 */

/**
 * Translate the normalised message list into OpenAI's wire format.
 *
 * Two of the four normalised shapes need work: an assistant turn carrying
 * tool_calls, and a tool-result turn. Plain user/assistant/system turns pass
 * through untouched.
 */
function ai_openai_compatible_messages(array $messages): array
{
    $out = [];

    foreach($messages as $m):
        if($m["role"] === "assistant" && !empty($m["tool_calls"])):
            $calls = [];

            foreach($m["tool_calls"] as $call):
                $calls[] = [
                    "id"       => $call["id"],
                    "type"     => "function",
                    "function" => [
                        "name"      => $call["name"],
                        "arguments" => json_encode((object) $call["arguments"])
                    ]
                ];
            endforeach;

            $out[] = [
                "role"       => "assistant",
                "content"    => isset($m["content"]) && $m["content"] !== "" ? $m["content"] : null,
                "tool_calls" => $calls
            ];

            continue;
        endif;

        if($m["role"] === "tool"):
            $out[] = [
                "role"         => "tool",
                "tool_call_id" => $m["tool_call_id"],
                "content"      => (string) $m["content"]
            ];

            continue;
        endif;

        $out[] = ["role" => $m["role"], "content" => (string) $m["content"]];
    endforeach;

    return $out;
}

function ai_openai_compatible_send(array $req): array
{
    $client = new \GuzzleHttp\Client();

    $payload = [
        "model"    => $req["model"],
        "messages" => ai_openai_compatible_messages($req["messages"]),
    ];

    if(!empty($req["max_tokens"]))
        $payload["max_tokens"] = (int) $req["max_tokens"];

    if(!empty($req["tools"]))
        $payload["tools"] = $req["tools"];

    try {
        $res = $client->post(rtrim($req["base_url"], "/") . "/chat/completions", [
            "headers" => [
                "Authorization" => "Bearer " . $req["apikey"],
                "Content-Type"  => "application/json"
            ],
            "json"         => $payload,
            "http_errors"  => false,
            "timeout"      => 120
        ]);
    } catch(Exception $e){
        return ["ok" => false, "text" => null, "tool_calls" => [], "error" => $e->getMessage()];
    }

    $status = $res->getStatusCode();
    $body   = json_decode($res->getBody()->getContents(), true);

    if($status !== 200)
        return ["ok" => false, "text" => null, "tool_calls" => [],
                "error" => "HTTP {$status}: " . (isset($body["error"]["message"]) ? $body["error"]["message"] : "unknown")];

    $message = isset($body["choices"][0]["message"]) ? $body["choices"][0]["message"] : [];
    $calls   = [];

    if(!empty($message["tool_calls"])):
        foreach($message["tool_calls"] as $call):
            // The arguments field is a free-form string the model produced, so it can
            // decode to any JSON type. is_array() rather than ?: — a truthy scalar such
            // as `true` or `1` survives ?: and would then be passed to invokeTool()'s
            // array parameter, which is an uncatchable TypeError at the call site.
            $arguments = json_decode($call["function"]["arguments"], true);

            $calls[] = [
                "id"        => $call["id"],
                "name"      => $call["function"]["name"],
                "arguments" => is_array($arguments) ? $arguments : []
            ];
        endforeach;
    endif;

    return [
        "ok"         => true,
        "text"       => isset($message["content"]) ? $message["content"] : null,
        "tool_calls" => $calls,
        "error"      => null
    ];
}
