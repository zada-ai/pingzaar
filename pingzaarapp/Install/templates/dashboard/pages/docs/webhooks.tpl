<div class="main-content" system-wrapper>
    {include "../../modules/header.block.tpl"}

    <div class="header">
        <div class="container">
            <div class="header-body">
                <div class="row align-items-end">
                    <div class="col">
                        <h6 class="header-pretitle">
                            {__("and_dash_pg_doc_line10")}
                        </h6>
                        <h1 class="header-title">
                            <i class="la la-code-branch la-lg"></i> {__("and_doc_webhook_3")}
                        </h1>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <div class="card-body">

                <h3 class="mb-3">{__("and_doc_webhook_intro_title")}</h3>
                <p>{__("and_doc_webhook_intro_line1")}</p>
                <p>{__("and_doc_webhook_intro_line2")}</p>

                <h3 class="mt-5 mb-3">{__("and_doc_webhook_usecases_title")}</h3>
                <ul class="list-group mb-3">
                    <li class="list-group-item">{__("and_doc_webhook_usecase1")}</li>
                    <li class="list-group-item">{__("and_doc_webhook_usecase2")}</li>
                    <li class="list-group-item">{__("and_doc_webhook_usecase3")}</li>
                    <li class="list-group-item">{__("and_doc_webhook_usecase4")}</li>
                    <li class="list-group-item">{__("and_doc_webhook_usecase5")}</li>
                    <li class="list-group-item">{__("and_doc_webhook_usecase6")}</li>
                    <li class="list-group-item">{__("and_doc_webhook_usecase7")}</li>
                </ul>

                <h3 class="mt-5 mb-3">{__("and_doc_webhook_how_title")}</h3>
                <p>{__("and_doc_webhook_how_line1")}</p>

                <div class="my-4">
                    <img src="{_assets("images/flow.png")}" alt="{__('and_doc_webhook_flow_image_alt')}" class="img-fluid w-50 rounded shadow-sm">
                </div>

                <h3 class="mt-5 mb-3">{__("and_doc_webhook_payload_title")}</h3>
                <p>{__("and_doc_webhook_payload_line1")}</p>

                <h5 class="mt-4">{__("and_doc_webhook_payload_sms")}</h5>
                <pre class="bg-primary p-3 rounded">[
    "type" => "sms",
    "data" => [
        "id" => 2,
        "rid" => 10593,
        "sim" => 1,
        "device" => "00000000-0000-0000-d57d-f30cb6a89289",
        "phone" => "+639760713666",
        "message" => "Hello World!",
        "timestamp" => 1645684231
    ]
]</pre>

                <h5 class="mt-4">{__("and_doc_webhook_payload_whatsapp")}</h5>
                <pre class="bg-primary p-3 rounded">[
    "type" => "whatsapp",
    "data" => [
        "id" => 2,
        "wid" => "+639760713666",
        "phone" => "+639760666713",
        "message" => "Hello World!",
        "attachment" => "http://imageurl.com/image.jpg",
        "timestamp" => 1645684231
    ]
]</pre>

                <h5 class="mt-4">{__("and_doc_webhook_payload_ussd")}</h5>
                <pre class="bg-primary p-3 rounded">[
    "type" => "ussd",
    "data" => [
        "id" => 98,
        "sim" => 1,
        "device" => "00000000-0000-0000-d57d-f30cb6a89289",
        "code" => "*143#",
        "response" => "Sorry! You are not allowed to use this service.",
        "timestamp" => 1645684231
    ]
]</pre>

                <h5 class="mt-4">{__("and_doc_webhook_payload_notification")}</h5>
                <pre class="bg-primary p-3 rounded">[
    "type" => "notification",
    "data" => [
        "id" => 77,
        "device" => "00000000-0000-0000-d57d-f30cb6a89289",
        "package" => "com.facebook.katana",
        "title" => "Someone commented on your post!",
        "content" => "Someone commented on your post!",
        "timestamp" => 1645684231
    ]
]</pre>

                <h3 class="mt-5 mb-3">{__("and_doc_webhook_code_title")}</h3>
                <pre class="bg-dark text-white p-3 rounded">&lt;?php

$request = $_REQUEST;

$secret = "WEBHOOK_SECRET"; // get this from (Tools → Webhooks)

// Check webhook secret
if (isset($request["secret"]) &amp;&amp; $request["secret"] == $secret):
    $payloadType = $request["type"];
    $payloadData = $request["data"];

    // Handle the payload
    print_r($payloadType);
    print_r($payloadData);
else:
    // Invalid secret
endif;
</pre>

            </div>
        </div>

        {include "../../modules/footer.block.tpl"}
    </div>
</div>