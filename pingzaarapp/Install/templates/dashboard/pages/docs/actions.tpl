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
                            <i class="la la-robot la-lg"></i> {__("and_doc_act_3")}
                        </h1>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <div class="card-body">
                <h3 class="mb-3">{__("and_doc_actions_intro_title")}</h3>
                <p>{__("and_doc_actions_intro_line1")}</p>
                <p>{__("and_doc_actions_intro_line2")}</p>

                <h3 class="mt-4">{__("and_doc_actions_types_title")}</h3>
                <ul class="list-group mb-3">
                    <li class="list-group-item">
                        <strong>{__("and_doc_actions_type_hooks")}</strong><br>
                        {__("and_doc_actions_type_hooks_desc")}
                    </li>
                    <li class="list-group-item">
                        <strong>{__("and_doc_actions_type_autoreplies")}</strong><br>
                        {__("and_doc_actions_type_autoreplies_desc")}
                    </li>
                </ul>

                <h3 class="mt-5 mb-3">{__("and_doc_actions_usecases_title")}</h3>
                <ul class="list-group mb-3">
                    <li class="list-group-item">{__("and_doc_actions_usecase1")}</li>
                    <li class="list-group-item">{__("and_doc_actions_usecase2")}</li>
                    <li class="list-group-item">{__("and_doc_actions_usecase3")}</li>
                </ul>

                <h3 class="mt-5 mb-3">{__("and_doc_actions_how_title")}</h3>
                <p>{__("and_doc_actions_how_line1")}</p>

                <div class="mt-4">
                    <h5>{__("and_doc_actions_flow_hooks")}</h5>
                    <div class="my-4">
                        <img src="{_assets("images/flow1.png")}" alt="{__('and_doc_actions_flow_hooks_alt')}" class="img-fluid w-50 rounded shadow-sm mb-3">
                    </div>

                    <h5>{__("and_doc_actions_flow_autoreplies")}</h5>
                    <div class="my-4">
                        <img src="{_assets("images/flow2.png")}" alt="{__('and_doc_actions_flow_autoreplies_alt')}" class="img-fluid w-50 rounded shadow-sm">
                    </div>
                </div>

                <h3 class="mt-5 mb-3">{__("and_doc_actions_code_title")}</h3>
                <pre class="bg-dark text-white p-3 rounded">&lt;?php

// Hooks will always use GET method.
// Example structured link:
{literal}// http://someremoteurl.com/test.php?phone={{phone}}&message={{message}}&time={{date.time}}{/literal}

$request = $_GET;

echo $request["phone"];
echo $request["message"];
echo $request["time"];

// Do anything you need with these values
</pre>
            </div>
        </div>

        {include "../../modules/footer.block.tpl"}
    </div>
</div>