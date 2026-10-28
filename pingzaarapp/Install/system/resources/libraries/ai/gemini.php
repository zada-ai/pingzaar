<?php
/**
 * Google Gemini generateContent.
 * Model is in the URL; turns are contents/parts; assistant role is "model".
 */

/**
 * Translate the normalised message list into Gemini's contents array.
 *
 * A system turn is not a content entry — it is lifted out and returned separately
 * through $system for the caller to place in system_instruction.
 *
 * Kept as its own function, like the other two adapters' translators, so the shape
 * can be asserted without making a network call.
 */
function ai_gemini_contents(array $messages, &$system = null): array
{
    $contents = [];
    $system   = null;

    foreach($messages as $m):
        if($m["role"] === "system"):
            $system = $m["content"];
            continue;
        endif;

        // Gemini carries a tool result as a functionResponse part on a user turn,
        // matched to the call by name rather than by id — which is why the adapter
        // synthesises ids below instead of reading them off the response.
        if($m["role"] === "tool"):
            $part = [
                "functionResponse" => [
                    "name"     => $m["name"],
                    "response" => ["result" => (string) $m["content"]]
                ]
            ];

            // Coalesced, as with Anthropic: when one model turn requests several tools,
            // all their responses belong to a single user turn. Emitting one turn each
            // would put two user turns back to back, which Gemini rejects.
            $last = count($contents) - 1;

            if($last >= 0 && $contents[$last]["role"] === "user"
               && isset($contents[$last]["parts"][0]["functionResponse"])):
                $contents[$last]["parts"][] = $part;
            else:
                $contents[] = ["role" => "user", "parts" => [$part]];
            endif;

            continue;
        endif;

        if($m["role"] === "assistant" && !empty($m["tool_calls"])):
            $parts = [];

            if(isset($m["content"]) && $m["content"] !== "" && $m["content"] !== null)
                $parts[] = ["text" => (string) $m["content"]];

            foreach($m["tool_calls"] as $call):
                $parts[] = ["functionCall" => [
                    "name" => $call["name"],
                    "args" => (object) $call["arguments"]
                ]];
            endforeach;

            $contents[] = ["role" => "model", "parts" => $parts];

            continue;
        endif;

        $contents[] = [
            "role"  => $m["role"] === "assistant" ? "model" : "user",
            "parts" => [["text" => (string) $m["content"]]]
        ];
    endforeach;

    return $contents;
}

function ai_gemini_send(array $req): array
{
    $client = new \GuzzleHttp\Client();

    $system   = null;
    $contents = ai_gemini_contents($req["messages"], $system);

    $payload = ["contents" => $contents];

    if(!empty($req["system"]) || $system !== null)
        $payload["system_instruction"] = ["parts" => [["text" => !empty($req["system"]) ? $req["system"] : $system]]];

    if(!empty($req["tools"])):
        $declarations = [];
        foreach($req["tools"] as $tool):
            $fn = $tool["function"];
            $declarations[] = [
                "name"        => $fn["name"],
                "description" => isset($fn["description"]) ? $fn["description"] : "",
                // See the matching note in anthropic.php — "parameters" is optional in a
                // plugin schema, and a null here is rejected by the API.
                "parameters"  => isset($fn["parameters"])
                               ? $fn["parameters"]
                               : ["type" => "object", "properties" => new stdClass]
            ];
        endforeach;
        $payload["tools"] = [["function_declarations" => $declarations]];
    endif;

    $url = "https://generativelanguage.googleapis.com/v1beta/models/"
         . rawurlencode($req["model"]) . ":generateContent?key=" . urlencode($req["apikey"]);

    try {
        $res = $client->post($url, [
            "headers"     => ["Content-Type" => "application/json"],
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
    $parts = isset($body["candidates"][0]["content"]["parts"]) ? $body["candidates"][0]["content"]["parts"] : [];

    foreach($parts as $part):
        if(isset($part["text"])):
            $text = ($text === null ? "" : $text) . $part["text"];
        elseif(isset($part["functionCall"])):
            $calls[] = [
                "id"        => "gemini_" . $part["functionCall"]["name"],
                "name"      => $part["functionCall"]["name"],
                // is_array, not isset — see the matching note in anthropic.php.
                "arguments" => isset($part["functionCall"]["args"]) && is_array($part["functionCall"]["args"])
                             ? $part["functionCall"]["args"] : []
            ];
        endif;
    endforeach;

    return ["ok" => true, "text" => $text, "tool_calls" => $calls, "error" => null];
}
