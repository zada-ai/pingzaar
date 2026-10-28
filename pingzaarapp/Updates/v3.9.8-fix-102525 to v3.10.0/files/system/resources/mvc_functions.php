<?php
/**
 * @desc Helper functions
 */

function get_configs($env = false, $ver = false)
{   
    if($env):
        if(!file_exists($env)):
            die("Environment config not found!");
        endif;

        $configs = explode("\n", file_get_contents($env));
    else:
        if(!file_exists("system/configurations/cc_env.inc")):
            die("Environment config not found!");
        endif;

        $configs = explode("\n", file_get_contents("system/configurations/cc_env.inc"));
    endif;

    foreach($configs AS $config):
        $line = explode("<=>", $config);
        $vals[$line[0]] = (isset($line[1]) ? trim($line[1]) : false);
    endforeach;

    $vals["siteurl"] = (isset($_SERVER["SERVER_NAME"]) ? $_SERVER["SERVER_NAME"] : php_uname("n"));
    $vals["port"] = (in_array($_SERVER["SERVER_PORT"], [80, 443]) ? false : ":{$_SERVER["SERVER_PORT"]}");
    $vals["subdir"] = (empty(explode("/", dirname($_SERVER["SCRIPT_NAME"]))[1]) ? false : dirname($_SERVER["SCRIPT_NAME"]));

    define("env", $vals);

    if(!isset(env["installed"]) && strpos($_SERVER["REQUEST_URI"], "install") === false): 
        header("location: ./install");
    endif;    

    return get_version($ver);
}

function get_version($ver)
{
    if($ver):
        if(!file_exists($ver)):
            die("Version config not found!");
        endif;

        $version = file_get_contents($ver);
    else:
        if(!file_exists("system/configurations/cc_ver.inc")):
            die("Version config not found!");
        endif;

        $version = file_get_contents("system/configurations/cc_ver.inc");
    endif;

    return define("version", $version);
}

function site_url($path = false, $protocol = false)
{
    return $protocol ? str_replace("//", (system_protocol < 2 ? "http://" : "https://"), rtrim(site_url, "/")) . "/" . ltrim($path, "/") : site_url . "/{$path}";
}

function set_template($name)
{
    return define("template", $name);
}

function parse_language_file($path)
{
    $strings = [];

    if(!file_exists($path))
        return $strings;

    foreach(explode("\n", file_get_contents($path)) as $line):
        if(strpos($line, "===") !== false):
            $columns = explode("===", trim($line));
            $strings["" . trim($columns[0])] = trim($columns[1]);
        endif;
    endforeach;

    return $strings;
}

function set_language($id, $rtl = 2)
{
    $GLOBALS["__"] = "__";
    $GLOBALS["___"] = "___";

    $rtl = $rtl ?: 2;

    $file = md5(is_null($id) ? 1 : $id);
    $default = "system/storage/temporary/default.lang";
    $path = file_exists("system/languages/{$file}.lang") ? "system/languages/{$file}.lang" : $default;

    $langStrings = parse_language_file($path);

    // Keep the English master alongside the active language so __() can fall back
    // to it per key. An installed language file is the customer's own — an update
    // never overwrites it — so any release that adds strings leaves every existing
    // install short of those keys, and without a fallback the user is shown the
    // literal text "lang_undefined". This also keeps a truncated or empty language
    // file from blanking the entire interface.
    if(!isset($GLOBALS["language_default"]))
        $GLOBALS["language_default"] = $path === $default ? $langStrings : parse_language_file($default);

    if(!isset($GLOBALS["language"])):
        $GLOBALS["language"] = $langStrings;
    endif;

    if(!defined("language_rtl"))
        define("language_rtl", $rtl < 2);

    return $langStrings;
}

function set_system($system)
{
    foreach($system as $key => $value):
        define("system_{$key}", $value);
    endforeach;
}

function set_logged($user = [])
{
    if(empty($user)):
        $user = [
            "id" => false,
            "admin" => false,
            "hash" => false,
            "email" => false,
            "name" => false,
            "rtl" => 2,
            "permissions" => false,
            "country" => false,
            "alertsound" => 2,
            "theme_color" => system_default_scheme,
            "timezone" => "asia/manila",
            "formatting" => []
        ];
    endif;

    date_default_timezone_set(
        $user["timezone"] ?: "UTC"
    );

    $user["language"] = isset($_SESSION["language"]) ? $_SESSION["language"] : (isset($_SESSION["logged"]["language"]) ? $_SESSION["logged"]["language"] : system_default_lang);

    $user["theme_color"] = isset($user["theme_color"]) && !empty($user["theme_color"]) ? $user["theme_color"] : system_default_scheme;

    $formatting = isset($user["formatting"]) && !empty($user["formatting"]) ? json_decode($user["formatting"], true) : [
        "clock" => "g:i A",
        "date" => "n/j/Y",
        "container" => [
            "clock_format" => 1,
            "date_format" => 1,
            "date_separator" => 2,
            "separator_selected" => "/"
        ]
    ];  

    $user["clock_format"] = $formatting["clock"];
    $user["date_format"] = $formatting["date"];

    $permissions = permission_list();

    $permissionArray = [];

    $userPermissions = explode(",", is_null($user["permissions"]) ? "" : $user["permissions"]);
    
    if($user["id"] < 2):
        define("super_admin", true);
    else:
        define("super_admin", false);
    endif;

    if(!empty($user["permissions"]) || $user["id"] < 2):
        define("is_admin", true);
    else:
        define("is_admin", false);
    endif;

    foreach($user as $key => $value):
        define("logged_{$key}", $value);
    endforeach;

    define("logged_avatar", file_exists("uploads/avatars/" . $user["hash"] . ".jpg") ? site_url . "/uploads/avatars/" . $user["hash"] . ".jpg?v=" . filemtime("uploads/avatars/" . $user["hash"] . ".jpg") : site_url . "/uploads/avatars/noavatar.png");

    foreach($permissions as $permission):
        if(in_array($permission, $userPermissions)):
            $permissionArray["perm_{$permission}"] = true;
        endif;
    endforeach;

    define("permissions", $permissionArray);

    define("impersonate", isset($_SESSION["impersonate"]) ? $_SESSION["impersonate"] : false);
}

function set_plugins($plugins)
{
    $scripts = [];
    $styles = [];

    foreach($plugins as $plugin):
        $json_file = "system/plugins/installables/{$plugin["directory"]}/plugin.json";

        if(file_exists($json_file)):
            $plugin_data = json_decode(file_get_contents($json_file), true);
            if(isset($plugin_data["assets"])):
                foreach($plugin_data["assets"] as $asset):
                    $file_path = "system/plugins/installables/{$plugin["directory"]}/{$asset}";
                    if(file_exists($file_path)):
                        if(pathinfo($file_path, PATHINFO_EXTENSION) === "js"):
                            $scripts[] = "\"" . site_url . "/{$file_path}?v=" . md5($plugin_data["version"]) . "\"";
                        elseif(pathinfo($file_path, PATHINFO_EXTENSION) === "css"):
                            $styles[] = "\"" . site_url . "/{$file_path}?v=" . md5($plugin_data["version"]) . "\"";
                        endif;
                    endif;
                endforeach;
            endif;
        endif;
    endforeach;

    define("plugin_scripts", implode(",", $scripts));
    define("plugin_styles", implode(",", $styles));
}

function set_subscription($check, $package, $default)
{
    if(!defined("super_admin")):
        define("super_admin", false);
    endif;

    $subscription = [];

    if($check > 0):
        $subscription = $package ? $package : $default;
    else:
        if(system_freemodel < 2 || super_admin):
            $subscription = $default;
		endif;   
    endif;

    if(super_admin):
        $subscription["services"] = "sms,whatsapp,android_ussd,api,webhooks,templates,actions,flows,ai";
    endif;

    if(!isset($subscription["services"])):
        $subscription["services"] = false;
    endif;

    return $subscription;
}

function __($lang)
{   
    return $GLOBALS["language"][$lang] ?? $GLOBALS["language_default"][$lang] ?? "lang_undefined";
}

function ___($string, $array = [])
{
    foreach($array as $key => $value):
        $string = str_replace("_888{$key}_", $value, $string);
    endforeach;

    return $string;
}

function __s($staticVar, $fallback = null)
{
    return defined($staticVar) ? constant($staticVar) : $fallback;
}

function _assets($path, $force = false)
{
    $isVersionedFile = strpos($path, ".js") !== false || strpos($path, ".css") !== false;
    $filePath = "templates/_assets/{$path}";
    $version = $force ? md5(filemtime($filePath)) : md5(version);

    return site_url . "/" . $filePath . ($isVersionedFile ? "?v={$version}" : "");
}

function assets($path, $template = template)
{
    $isVersionedFile = strpos($path, ".js") !== false || strpos($path, ".css") !== false;
    $filePath = "templates/{$template}/assets/{$path}";
    $version = md5(filemtime($filePath));

    return site_url . "/" . $filePath . ($isVersionedFile ? "?v={$version}" : "");
}

function response($status = 200, $msg = false, $data = false)
{
    return die(json_encode([
        "status" => (int) $status, 
        "message" => $msg, 
        "data" => $data
    ]));
}

/**
 * Like response(), but sets a real HTTP status code.
 *
 * response() always returns HTTP 200 and puts the status in the body, which is
 * fine for this app's own JavaScript. Third-party clients read the HTTP status
 * instead -- pusher-js, for one, treats any 200 as a successful channel
 * authorisation -- so endpoints they consume have to answer properly.
 */
function deny($status, $message = false)
{
    http_response_code((int) $status);

    return die(json_encode([
        "status" => (int) $status,
        "message" => $message,
        "data" => false
    ]));
}

function responseTable($data)
{
    return die(json_encode($data));
}

function get_image($type)
{
    switch($type):
        case "bg":
            return file_exists("uploads/theme/bg.png") ? site_url . "/uploads/theme/bg.png" : site_url . "/uploads/theme/default-bg.jpg";

            break;
        case "logo_light":
            return file_exists("uploads/theme/logo-light.png") ? site_url . "/uploads/theme/logo-light.png" : site_url . "/uploads/theme/default-logo-light.png";

            break;
        case "logo_dark":
            return file_exists("uploads/theme/logo-dark.png") ? site_url . "/uploads/theme/logo-dark.png" : site_url . "/uploads/theme/default-logo-dark.png";

            break;
        case "favicon":
            return file_exists("uploads/theme/favicon.png") ? site_url . "/uploads/theme/favicon.png" : site_url . "/uploads/theme/default-favicon.png";

            break;
        default:
            return false;
    endswitch;
}

function mail_title($title)
{
    return stripslashes(html_entity_decode($title, ENT_QUOTES, "UTF-8"));
}

/**
 * The complete set of admin permissions.
 *
 * This is the only place the list is written down. permission() can only ever
 * return true for a name that appears here, so anything checked in a controller
 * but missing from this array is silently ungrantable — which is exactly what
 * happened to manage_waservers: seven server-side checks that no role could
 * ever satisfy, leaving WhatsApp Servers reachable only by the super admin.
 * The role add/edit modals build their option lists from this function too, so
 * the three copies that used to drift apart cannot diverge again.
 *
 * @return array
 */
function permission_list()
{
    return [
        "manage_users",
        "manage_roles",
        "manage_packages",
        "manage_vouchers",
        "manage_subscriptions",
        "manage_transactions",
        "manage_payouts",
        "manage_widgets",
        "manage_pages",
        "manage_marketing",
        "manage_languages",
        "manage_gateways",
        "manage_shorteners",
        "manage_waservers",
        "manage_plugins",
        "manage_templates",
        "manage_api"
    ];
}

/**
 * The per-user feature restrictions the admin API accepts alongside permissions.
 *
 * Kept beside permission_list() for the same reason: the admin role endpoints
 * validate submitted names against restrictions and permissions together, and
 * every name absent from the combined set is rejected outright.
 *
 * @return array
 */
function restriction_list()
{
    return [
        "disallow_sms",
        "disallow_ussd",
        "disallow_devices",
        "disallow_wa_chats",
        "disallow_wa_accounts",
        "disallow_contacts",
        "disallow_groups",
        "disallow_keys",
        "disallow_webhooks",
        "disallow_actions",
        "disallow_templates",
        "disallow_extensions",
        "disallow_redeem",
        "disallow_subscribe",
        "disallow_topup",
        "disallow_withdraw",
        "disallow_convert",
        "disallow_api"
    ];
}

/**
 * Whether a parsed number is one an SMS could plausibly reach.
 *
 * The call sites used to write this as
 * `!$number->getNumberType(PhoneNumberType::MOBILE)`, which never rejected
 * anything: getNumberType() takes no argument and returns an enum case, and an
 * object is always truthy. The filter had therefore been inert for the life of
 * the product.
 *
 * Restoring it as a strict MOBILE test would have been far too blunt.
 * libphonenumber cannot tell mobile from landline anywhere in the NANP, so
 * every US and Canadian number — mobile ones included — reports
 * FIXED_LINE_OR_MOBILE and would have been rejected outright. UNKNOWN covers
 * ranges the bundled metadata has no entry for, and VOIP covers services such
 * as Google Voice that do receive SMS. All three have to pass.
 *
 * What is left are the types that genuinely cannot take a message: landlines,
 * toll-free, premium rate, pagers, voicemail and the rest.
 *
 * @param Brick\PhoneNumber\PhoneNumber $number
 * @return boolean
 */
function is_messagable_number($number)
{
    return in_array($number->getNumberType(), [
        Brick\PhoneNumber\PhoneNumberType::MOBILE,
        Brick\PhoneNumber\PhoneNumberType::FIXED_LINE_OR_MOBILE,
        Brick\PhoneNumber\PhoneNumberType::VOIP,
        Brick\PhoneNumber\PhoneNumberType::PERSONAL_NUMBER,
        Brick\PhoneNumber\PhoneNumberType::UNKNOWN
    ], true);
}

function permission($permission)
{
    if(logged_id < 2):
        return true;
    else:
        if(isset(permissions["perm_{$permission}"]))
            return true;
        else
            return false;
    endif;
}

function limitation($limit, $used)
{
    if($limit < 1):
        return false;
    else:
        return $used >= $limit ? true : false;
    endif;
}

function smsStatusParser($status)
{
    switch($status):
        case 1:
            return "queued";

            break;
        case 2:
            return "pending";

            break;
        case 3:
            return "sent";

            break;
        default:
            return "failed";
    endswitch;
}

function trimLongWords($sentence, $maxLength = 15, $urlThreshold = "https://platform.openai.com/account/api-keys") 
{
    $urlLengthLimit = mb_strlen($urlThreshold);
    
    preg_match_all('/<a\b[^>]*>.*?<\/a>/is', $sentence, $matches);
    $anchors = $matches[0];
    $placeholders = [];

    foreach ($anchors as $i => $aTag):
        $placeholder = "__ANCHOR_PLACEHOLDER_{$i}__";
        $sentence = str_replace($aTag, $placeholder, $sentence);
        $placeholders[$placeholder] = $aTag;
    endforeach;

    $words = explode(' ', $sentence);
    foreach ($words as &$word):
        $isUrl = filter_var($word, FILTER_VALIDATE_URL);

        if ($isUrl && mb_strlen($word) <= $urlLengthLimit)
            continue;

        if (mb_strlen($word) > $maxLength)
            $word = mb_substr($word, 0, $maxLength) . '...';
    endforeach;

    $sentence = implode(' ', $words);

    foreach ($placeholders as $ph => $original):
        $sentence = str_replace($ph, $original, $sentence);
    endforeach;

    return $sentence;
}


function footermark($state, $message, $mark)
{   
    if($state < 2 && !empty($mark)):
        return "{$message}\n\n{$mark}";
    else:
        return $message;
    endif;
}

function find($find, $string)
{
    return strpos($string, $find) !== false;
}

function rmrf($dir)
{
    // Handle both files and directories
    if (is_file($dir)) {
        return @unlink($dir);
    }

    if (!is_dir($dir)) {
        return false;
    }

    // Get all items in directory (including hidden files)
    $items = array_diff(scandir($dir), array('.', '..'));

    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rmrf($path); // Recursive call for subdirectories
        } else {
            @unlink($path); // Delete file
        }
    }

    // Remove the directory itself
    return @rmdir($dir);
}

function copyDirectory($src, $destination)
{
    // opendir() returns false for a missing or unreadable source, and readdir(false)
    // is a TypeError on PHP 8 — an Error, so the @ suppression on the recursive call
    // below never caught it and a single bad path took the whole request down.
    if (!is_dir($src)):
        return false;
    endif;

    $dir = @opendir($src);

    if ($dir === false):
        return false;
    endif;

    @mkdir($destination);

    while($file = readdir($dir)):
        if (($file != '.') && ($file != '..')):  
            if (is_dir("{$src}/{$file}")):
                @copyDirectory("{$src}/{$file}", "{$destination}/{$file}");  
            else: 
                @copy("{$src}/{$file}", "{$destination}/{$file}");  
            endif;
        endif;
    endwhile;

    closedir($dir);

    return true;
}

function checkFile($filename, $directory)
{
    $files = @scandir($directory);

    if ($files !== false):
        foreach ($files as $entry):
            $basename = pathinfo($entry, PATHINFO_FILENAME);
            if ($basename == $filename):
                $extension = pathinfo($entry, PATHINFO_EXTENSION);
                return "{$basename}.{$extension}";
            endif;
        endforeach;
    else:
        return false;
    endif;

    return false;
}

function maskEmail($email)
{
    $mail_parts = explode("@", $email);
    $length = strlen($mail_parts[0]);
    $show = floor($length / 2);
    $hide = $length - $show;
    $replace = str_repeat("*", $hide);

    return substr_replace($mail_parts[0] , $replace , $show, $hide) . "@" . substr_replace($mail_parts[1], "***", 1, 3);
}

function formatMexicoNumWa($number)
{
    return substr_replace($number, '1', 3, 0);
}

function isPluginSchemaJsonValid($jsonString) {
    try {
        $data = json_decode($jsonString, true, JSON_THROW_ON_ERROR);
    } catch (Exception $e) {
        return false;
    }

    return true;
}

function encodeBraces($string)
{
    $string = str_replace("{", "<++", $string);
    $string = str_replace("}", "++>", $string);
    $string = str_replace("|", "<+>", $string);

    return $string;
}

function decodeBraces($string)
{
    $string = str_replace("<++", "{", $string);
    $string = str_replace("++>", "}", $string);
    $string = str_replace("<+>", "|", $string);

    return $string;
}

/**
 * Render one of the bundled OpenAPI specs (system/resources/specs/*.json) and send it.
 *
 * These used to be EJS templates fetched over HTTP from a Titan-operated host on
 * every page load of the in-app API reference, gated behind a purchase-code check.
 * They now ship with the application, so the reference works offline and on any
 * install.
 *
 * The placeholders are the same five the remote renderer substituted, so the spec
 * bodies were ported verbatim. Values are JSON-escaped rather than interpolated raw:
 * the spec is a JSON document, and a site name containing a quote would otherwise
 * produce a malformed one.
 */
function renderApiSpec($spec, array $vars)
{
    $file = "system/resources/specs/{$spec}.json";

    if(!file_exists($file))
        response(500);

    $replace = [];

    foreach($vars as $key => $value):
        // trim() strips json_encode's surrounding quotes — the placeholders already
        // sit inside quoted strings in the spec.
        $replace["{{{$key}}}"] = trim(json_encode((string) $value), '"');
    endforeach;

    header("Content-Type: application/json; charset=utf-8");

    die(strtr(file_get_contents($file), $replace));
}

function countDays($date1, $date2) {
    $date1 = date_create($date1);
    $date2 = date_create($date2);
    $interval = date_diff($date1, $date2);
    $days = $interval->days;

    return $days;
}