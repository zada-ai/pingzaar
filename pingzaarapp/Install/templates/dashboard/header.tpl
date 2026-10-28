<!DOCTYPE html>
<html lang="en" {if !in_array($page, ["docs/default", "docs/admin"]) && language_rtl}dir="rtl"{/if}>

<head>
    <link rel="dns-prefetch" href="//fonts.googleapis.com">

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="description" content="{__s("system_site_desc")}">
    <link rel="icon" href="{get_image("favicon")}">

    <title>{$title} &middot; {__s("system_site_name")}</title>
    
    <link rel="stylesheet" href="{_assets("css/libs/line-awesome.min.css")}">
    <link rel="stylesheet" href="{assets("css/fonts/feather/feather.css")}">
    <link rel="stylesheet" href="{_assets("css/libs/flag-icon.min.css")}">
    <link rel="stylesheet" href="{if !in_array($page, ["docs/default", "docs/admin"]) && language_rtl}{if !in_array($page, ["docs/default", "docs/admin"]) && logged_theme_color eq "dark"}{assets("css/libs/bootstrap.dark.rtl.min.css")}{else}{assets("css/libs/bootstrap.rtl.min.css")}{/if}{else}{if !in_array($page, ["docs/default", "docs/admin"]) && logged_theme_color eq "dark"}{assets("css/libs/bootstrap.dark.min.css")}{else}{assets("css/libs/bootstrap.min.css")}{/if}{/if}" />
    <link rel="stylesheet" href="{if !in_array($page, ["docs/default", "docs/admin"]) && language_rtl}{assets("css/style.rtl.min.css")}{else}{assets("css/style.min.css")}{/if}">

    {if in_array($page, ["docs/default", "docs/admin"])}
    <style>
        pre {
            color: inherit !important;
        }
    </style>
    {/if}
</head>

<body {if in_array($page, ["auth/default", "auth/forgot", "auth/register"])}class="d-flex align-items-center bg-auth border-top border-top-2 border-primary"{/if} {if in_array($page, ["auth/social.error", "plugin/plugin.page", "plugin/plugin.error",  "errors/404.error", "misc/payment", "misc/unsubscribe"])}class="d-flex align-items-center bg-auth border-top border-top-2 border-primary"{/if}>
    {if logged_id && !in_array($page, ["docs/default", "docs/admin", "auth/default", "auth/social.error", "plugin/plugin.page", "plugin/plugin.error", "errors/404.error", "misc/payment", "misc/unsubscribe"])}
    {include "./modules/sidebar.block.tpl"}
    {/if}