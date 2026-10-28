window.system = {
    active: () => {
        $("li [system-nav][href=\"" + "//" + location.hostname + location.pathname + "\"]").addClass("active");
    },

    cookies: () => {
        window.cookieconsent.initialise({
            "theme": "classic",
            "position": consent_position,
            "content": {
                "message": lang_cookieconsent_message,
                "link": lang_cookieconsent_link,
                "dismiss": lang_cookieconsent_dismiss
            }
        });
    },

    tooltips: () => {
        $("[title]").tooltipster({
            theme: "tooltipster-borderless",
            side: "bottom",
            debug: false,
            maxWidth: 300
        });
    },

    countdown: (duration, element, seconds = false, callback = () => { }) => {
        var timer = duration,
            mins, secs;

        window.timerStart = setInterval(function () {
            mins = parseInt(timer / 60, 10)
            secs = parseInt(timer % 60, 10);

            mins = mins < 10 ? "0" + mins : mins;
            secs = secs < 10 ? "0" + secs : secs;

            if (seconds) {
                element.text(secs);
            } else {
                element.text(mins + ":" + secs);
            }

            if (--timer < 0) {
                timer = duration;
            }

            if (timer >= duration) {
                clearInterval(timerStart);
                callback();
            }
        }, 1000);
    },

    loader: (msg, state = true, element = "body") => {
        if (state) {
            $(element).loading({
                theme: theme_color,
                stoppable: false,
                zIndex: 1100,
                message: `
                <div class="loadingio-spinner-ripple-c4xwekkbyc9">
                    <div class="ldio-k6xrhuhg6o">
                        <div></div>
                        <div></div>
                    </div>
                </div><br>
                ` + msg + `<br>
                ` + lang_js_loader_pleasewait
            });
        } else {
            $(element).loading("stop");
        }
    },

    redocly: () => {
        const redocly = $("#redocly-container");

        if (redocly.length) {
            $('html').prepend(`
                <style>
                    div a[href="https://redocly.com/redoc/"] {
                        display: none !important;
                    }
                </style>
            `);

            Redoc.init(`${site_url}/${redocly.attr("spec")}`, {
                hideDownloadButton: true
            }, document.getElementById('redocly-container'));
        }
    },

    echo: () => {
        $.get(site_url + "/requests/echo", function (http) {
            try {
                var response = (typeof http === "string") ? JSON.parse(http) : http;

                // 401 just means realtime is not configured. The dashboard
                // works without it, so there is nothing to report.
                if (response.status != 200) {
                    return;
                }

                var cfg = response.data;

                window.echo = new Pusher(cfg.key, {
                    // pusher-js throws "Options object must provide a cluster"
                    // if this is absent, even when wsHost makes it meaningless.
                    cluster: cfg.cluster || "",
                    wsHost: cfg.host,
                    wsPort: cfg.port,
                    wssPort: cfg.port,
                    forceTLS: cfg.tls,
                    enabledTransports: ["ws", "wss"],
                    authEndpoint: site_url + "/requests/echo/auth"
                });

                var mine = echo.subscribe("private-user-" + cfg.hash);

                mine.bind("reload", () => {
                    $.get(site_url + "/requests/echo/reload", function (http) {
                        var response = (typeof http === "string") ? JSON.parse(http) : http;

                        if (response.status == 200) {
                            location.reload();
                        }
                    });
                });

                mine.bind("message", (payload) => {
                    if (alertsound) {
                        playAlert("submarine");
                    }

                    if (payload.status == 1) {
                        alert.success(payload.content, false, true);
                    }

                    if (payload.status == 2) {
                        alert.warning(payload.content, false, true);
                    }

                    if (payload.status == 3) {
                        alert.danger(payload.content, false, true);
                    }
                });

                mine.bind("ussd", (payload) => {
                    if (alertsound) {
                        playAlert("submarine");
                    }

                    if (payload.status < 2) {
                        alert.success(payload.content, false, true);
                    } else {
                        alert.danger(payload.content, false, true);
                    }
                });

                mine.bind("notification", (payload) => {
                    if (alertsound) {
                        playAlert("submarine");
                    }

                    if (payload.status < 2) {
                        alert.success(payload.content, false, true);
                    } else {
                        alert.danger(payload.content, false, true);
                    }
                });

                mine.bind("whatsapp", (payload) => {
                    if (alertsound) {
                        playAlert("submarine");
                    }

                    if (!tableLoading) {
                        system.tables(true);
                    }

                    alert.success(payload.content);

                    $("system-modal").modal("hide");
                });

                mine.bind("table", (payload) => {
                    if (!tableLoading) {
                        system.tables(true);
                    }

                    if (payload.modal) {
                        $("system-modal").modal("hide");
                    }
                });

                // The ping carries no content -- only a row id. Each browser
                // fetches the row over its own session and non-recipients get
                // a 204, so nothing addressed elsewhere is ever delivered here.
                // The jitter stops every connected browser hitting the app in
                // the same second on a large install.
                echo.subscribe("broadcast").bind("ping", (ping) => {
                    setTimeout(() => {
                        $.get(site_url + "/requests/echo/broadcast/" + ping.id, function (http) {
                            var response = (typeof http === "string") ? JSON.parse(http) : http;

                            if (response.status != 200) {
                                return;
                            }

                            if (alertsound) {
                                playAlert("submarine");
                            }

                            switch (ping.color) {
                                case "2":
                                    alert.success(response.data.content, false, true, response.data.title, true, response.data.image);
                                    break;
                                case "3":
                                    alert.warning(response.data.content, false, true, response.data.title, true, response.data.image);
                                    break;
                                case "4":
                                    alert.danger(response.data.content, false, true, response.data.title, true, response.data.image);
                                    break;
                                default:
                                    alert.primary(response.data.content, false, true, response.data.title, true, response.data.image);
                            }
                        });
                    }, Math.random() * 3000);
                });
            } catch (e) {
                console.error('System.js error:', e);
            }
        });
    },

    whatsapp: () => {
        if ($("[system-whatsapp-link]").length) {
            $("[system-whatsapp-link]").on("click", function (e) {
                e.preventDefault();

                var waServer = $("select[system-wa-server]").val();

                system.disabled();
                system.loader(lang_response_whatsapp_creatingqr);

                $.post(site_url + "/requests/whatsapp/" + waLinkUrl, {
                    "wa_server": waServer,
                    "unique": waRelinkUnique
                }, function (http) {
                    system.loader(false, false);

                    try {
                        var response = (typeof http === "string") ? JSON.parse(http) : http;

                        if (response.status == 200) {
                            $("#wa_intro").fadeOut("fast", () => {
                                system.disabled(false);

                                if ($("#wa_link").length) {
                                    $("#wa_link").fadeIn("fast", () => {
                                        $("#wa_qrcode").empty();

                                        new QRCode(
                                            document.getElementById("wa_qrcode"), {
                                            text: response.data,
                                            border: 2
                                        }
                                        );

                                        system.countdown(15, $("#wa_countdown"), true, () => {
                                            $("#wa_countdown").fadeOut("fast", () => {
                                                $("#wa_qrcode").fadeOut("slow", () => {
                                                    $("system-modal").modal("hide");
                                                });
                                            });
                                        });
                                    });
                                }
                            });
                        } else {
                            system.disabled(false);
                            $("system-modal").modal("hide");
                            alert.danger(response.message);
                        }
                    } catch (e) {
                        console.error('System.js error:', e);
                        alert.danger(lang_response_went_wrong);
                    }
                });
            });
        }
    },

    download: () => {
        if ($("[system-download-gateway]").length) {
            $("[system-download-gateway]").on("click", function (e) {
                e.preventDefault();

                $.get(site_url + "/requests/index/download/gateway", function (http) {
                    try {
                        var response = (typeof http === "string") ? JSON.parse(http) : http;

                        if (response.status == 200) {
                            alert.success(response.message);

                            setTimeout(function () {
                                window.open(response.data.link);
                            }, 3000);
                        } else {
                            alert.warning(response.message);
                        }
                    } catch (e) {
                        console.error('System.js error:', e);
                        alert.danger(lang_response_went_wrong);
                    }
                });
            });
        }

        if ($("[system-download-showqr]").length) {
            $("[system-download-showqr]").on("click", function (e) {
                e.preventDefault();

                $("#system-qrcode-download").removeClass("d-none");
                $("#system-qrcode-download").css("display", "none");

                $(this).fadeOut("fast", function () {
                    $(".download-showqr-container").remove();
                    $("#system-qrcode-download").fadeIn("fast");
                });
            });
        }
    },

    plugin: () => {
        $(document).on("click", "[system-plugin-action]", function (e) {
            e.preventDefault();

            var directory = $(this).attr("system-plugin-directory");
            var action = $(this).attr("system-plugin-action");

            $.get(`${site_url}/plugin?name=${directory}&action=${action}&json=true`, function (http) {
                try {
                    var response = (typeof http === "string") ? JSON.parse(http) : http;

                    if (response.status == 200) {
                        alert.success(response.message);
                    } else if (response.status == 301) {
                        setTimeout(function () {
                            window.open(response.data.link);
                        }, 1000);
                    } else if (response.status == 401) {
                        location.reload();
                    } else {
                        alert.warning(response.message);
                    }
                } catch {
                    alert.danger(lang_response_went_wrong);
                }
            });
        });

        $(document).on("click", "[system-plugin-delete]", function (e) {
            e.preventDefault();
            var param = ($(this).attr("system-plugin-delete") ? $(this).attr("system-plugin-delete") : false);
            var pluginDirectory = $(this).attr("system-plugin-directory");
            var deleteEndpoint = `${site_url}/plugin?name=${pluginDirectory}&action=request&type=delete&tpl=${param}&json=true`;
            iziToast.question({
                title: lang_delete_title,
                message: lang_delete_tagline,
                titleLineHeight: "25px",
                backgroundColor: "#E82753",
                position: "center",
                icon: false,
                drag: false,
                timeout: false,
                close: false,
                overlay: true,
                layout: 1,
                zindex: 1024,
                buttons: [
                    ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                        $.ajax({
                            url: deleteEndpoint,
                            type: "GET",
                            beforeSend: function () {
                                instance.hide({
                                    transitionOut: "fadeOut"
                                }, toast, "button");

                                system.loader(lang_requests_deleteitem_loader);
                            },
                            success: function (http) {
                                system.loader(false, false);

                                try {
                                    var response = (typeof http === "string") ? JSON.parse(http) : http;

                                    switch (response.status) {
                                        case 200:
                                            if (response.data.vars.table)
                                                system.tables(true);

                                            alert.success(response.message);
                                            break;
                                        case 302:
                                            alert.warning(lang_response_session_false, true);
                                            break;
                                        default:
                                            alert.danger(response.message);
                                    }
                                } catch (e) {
                                    console.error('System.js error:', e);
                                    alert.danger(lang_response_went_wrong);
                                }
                            }
                        });
                    }, true],
                    ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                        instance.hide({
                            transitionOut: "fadeOut"
                        }, toast, "button");
                    }]
                ]
            });
        });
    },

    clear: () => {
        $(document).on("click", "[system-clear-pending]", function (e) {
            e.preventDefault();

            var type = $(this).attr("system-clear-pending");

            iziToast.question({
                title: lang_js_clearpending_title,
                message: lang_js_clearpending_desc,
                titleLineHeight: "25px",
                backgroundColor: "#FE9431",
                position: "center",
                icon: false,
                drag: false,
                timeout: false,
                close: false,
                overlay: true,
                layout: 1,
                zindex: 1024,
                buttons: [
                    ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                        system.loader(lang_js_clearpending_processing);

                        $.get(site_url + "/requests/clear/" + type, function (http) {
                            system.loader(false, false);

                            try {
                                var response = (typeof http === "string") ? JSON.parse(http) : http;

                                if (response.status == 200) {
                                    system.tables(true);
                                    alert.success(response.message);
                                } else {
                                    alert.warning(response.message);
                                }
                            } catch (e) {
                                console.error('System.js error:', e);
                                alert.danger(lang_response_went_wrong);
                            }

                            instance.hide({
                                transitionOut: "fadeOut"
                            }, toast, "button");
                        });
                    }, true],
                    ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                        instance.hide({
                            transitionOut: "fadeOut"
                        }, toast, "button");
                    }]
                ]
            });
        });

        $(document).on("click", "[system-sms-start]", function (e) {
            e.preventDefault();

            var attrData = $(this).attr("system-sms-start").split("/");

            iziToast.question({
                title: lang_campaign_popup_titleresume,
                message: lang_campaign_popup_descsmsresume,
                titleLineHeight: "25px",
                backgroundColor: "#00c52c",
                position: "center",
                icon: false,
                drag: false,
                timeout: false,
                close: false,
                overlay: true,
                layout: 1,
                zindex: 1024,
                buttons: [
                    ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                        system.loader(lang_js_clearpending_processing);

                        $.post(site_url + "/requests/remote/start_sms", {
                            cid: attrData[0],
                            did: attrData[1],
                            name: attrData[2]
                        }, function (http) {
                            system.loader(false, false);

                            try {
                                var response = (typeof http === "string") ? JSON.parse(http) : http;

                                if (response.status == 200) {
                                    system.tables(true);
                                    alert.success(response.message);
                                } else {
                                    alert.warning(response.message);
                                }
                            } catch (e) {
                                console.error('System.js error:', e);
                                alert.danger(lang_response_went_wrong);
                            }

                            instance.hide({
                                transitionOut: "fadeOut"
                            }, toast, "button");
                        });
                    }, true],
                    ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                        instance.hide({
                            transitionOut: "fadeOut"
                        }, toast, "button");
                    }]
                ]
            });
        });

        $(document).on("click", "[system-sms-stop]", function (e) {
            e.preventDefault();

            var attrData = $(this).attr("system-sms-stop").split("/");

            iziToast.question({
                title: lang_campaign_popup_titlestop,
                message: lang_campaign_popup_descsmsstop,
                titleLineHeight: "25px",
                backgroundColor: "#FE9431",
                position: "center",
                icon: false,
                drag: false,
                timeout: false,
                close: false,
                overlay: true,
                layout: 1,
                zindex: 1024,
                buttons: [
                    ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                        system.loader(lang_js_clearpending_processing);

                        $.post(site_url + "/requests/remote/stop_sms", {
                            cid: attrData[0],
                            did: attrData[1],
                            name: attrData[2]
                        }, function (http) {
                            system.loader(false, false);

                            try {
                                var response = (typeof http === "string") ? JSON.parse(http) : http;

                                if (response.status == 200) {
                                    system.tables(true);
                                    alert.warning(response.message);
                                } else {
                                    alert.danger(response.message);
                                }
                            } catch (e) {
                                console.error('System.js error:', e);
                                alert.danger(lang_response_went_wrong);
                            }

                            instance.hide({
                                transitionOut: "fadeOut"
                            }, toast, "button");
                        });
                    }, true],
                    ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                        instance.hide({
                            transitionOut: "fadeOut"
                        }, toast, "button");
                    }]
                ]
            });
        });

        $(document).on("click", "[system-whatsapp-start]", function (e) {
            e.preventDefault();

            var attrData = $(this).attr("system-whatsapp-start").split("/");

            iziToast.question({
                title: lang_campaign_popup_titleresume,
                message: lang_campaign_popup_descwaresume,
                titleLineHeight: "25px",
                backgroundColor: "#00c52c",
                position: "center",
                icon: false,
                drag: false,
                timeout: false,
                close: false,
                overlay: true,
                layout: 1,
                zindex: 1024,
                buttons: [
                    ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                        system.loader(lang_js_clearpending_processing);

                        $.post(site_url + "/requests/remote/start_chats", {
                            cid: attrData[0]
                        }, function (http) {
                            system.loader(false, false);

                            try {
                                var response = (typeof http === "string") ? JSON.parse(http) : http;

                                if (response.status == 200) {
                                    system.tables(true);
                                    alert.success(response.message);
                                } else {
                                    alert.warning(response.message);
                                }
                            } catch (e) {
                                console.error('System.js error:', e);
                                alert.danger(lang_response_went_wrong);
                            }

                            instance.hide({
                                transitionOut: "fadeOut"
                            }, toast, "button");
                        });
                    }, true],
                    ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                        instance.hide({
                            transitionOut: "fadeOut"
                        }, toast, "button");
                    }]
                ]
            });
        });

        $(document).on("click", "[system-whatsapp-stop]", function (e) {
            e.preventDefault();

            var attrData = $(this).attr("system-whatsapp-stop").split("/");

            iziToast.question({
                title: lang_campaign_popup_titlestop,
                message: lang_campaign_popup_descwastop,
                titleLineHeight: "25px",
                backgroundColor: "#FE9431",
                position: "center",
                icon: false,
                drag: false,
                timeout: false,
                close: false,
                overlay: true,
                layout: 1,
                zindex: 1024,
                buttons: [
                    ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                        system.loader(lang_js_clearpending_processing);

                        $.post(site_url + "/requests/remote/stop_chats", {
                            cid: attrData[0]
                        }, function (http) {
                            system.loader(false, false);

                            try {
                                var response = (typeof http === "string") ? JSON.parse(http) : http;

                                if (response.status == 200) {
                                    system.tables(true);
                                    alert.warning(response.message);
                                } else {
                                    alert.danger(response.message);
                                }
                            } catch (e) {
                                console.error('System.js error:', e);
                                alert.danger(lang_response_went_wrong);
                            }

                            instance.hide({
                                transitionOut: "fadeOut"
                            }, toast, "button");
                        });
                    }, true],
                    ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                        instance.hide({
                            transitionOut: "fadeOut"
                        }, toast, "button");
                    }]
                ]
            });
        });
    },

    tawk: () => {
        $.get(site_url + "/requests/index/livechat", function (http) {
            try {
                var response = (typeof http === "string") ? JSON.parse(http) : http;

                if (response.status == 200) {
                    var Tawk_API = Tawk_API || {};
                    Tawk_API.visitor = {
                        name: response.data
                    };

                    var Tawk_LoadStart = new Date();
                    (function () {
                        var s1 = document.createElement("script"),
                            s0 = document.getElementsByTagName("script")[0];
                        s1.async = true;
                        s1.src = "https://embed.tawk.to/" + tawk_id;
                        s1.charset = "UTF-8";
                        s1.setAttribute("crossorigin", "*");
                        s0.parentNode.insertBefore(s1, s0);
                    })();
                }
            } catch (e) {
                console.error('System.js error:', e);
                alert.danger(lang_response_went_wrong);
            }
        });
    },

    authenticate: () => {
        if ($("[system-authenticate-login]").length) {
            $("[system-authenticate-login]").on("submit", function (e) {
                e.preventDefault();

                var data = new FormData(this);

                var input_require = "email|" + lang_require_email + "<=>password|" + lang_require_password;

                $.ajax({
                    url: site_url + "/requests/index/login",
                    type: "POST",
                    data: data,
                    contentType: false,
                    processData: false,
                    beforeSend: () => {
                        if (input_require) {
                            var filter = input_require.split("<=>");
                            for (var i = 0; i <= filter.length; i++) {
                                if (typeof filter[i] !== "undefined") {
                                    var values = filter[i].split("|");
                                }
                                try {
                                    if (data.get(values[0]).length < 1) {
                                        alert.warning(values[1] + ", " + lang_validate_cannotemp);
                                        return false;
                                    }
                                } catch (e) {
                                    if (data.getAll(values[0] + "[]").length < 1) {
                                        alert.warning(values[1] + ", " + lang_validate_cannotemp);
                                        return false;
                                    }
                                }
                            }
                        }

                        system.disabled();
                        system.loader(lang_js_authenticate_processing);
                    },
                    success: (http) => {
                        system.loader(false, false);

                        try {
                            var response = (typeof http === "string") ? JSON.parse(http) : http;

                            switch (response.status) {
                                case 200:
                                    $("[system-login-confirm]").fadeOut("fast", function () {
                                        $("[system-login-confirm]").html(`
                                            <div class="alert alert-primary text-center mb-0 pb-0">
                                                <p>` + response.message + `</p>
                                            </div>
                                        `);

                                        $("[system-login-confirm]").fadeIn("slow");
                                    });

                                    break;
                                case 301:
                                    alert.success(response.message, true);

                                    break;
                                case 302:
                                    alert.warning(lang_response_session_false, true);

                                    break;
                                default:
                                    if (recaptcha_status)
                                        grecaptcha.reset();

                                    alert.danger(response.message);
                            }
                        } catch (e) {
                            alert.danger(lang_response_went_wrong);
                        }

                        system.disabled(false);
                    }
                });
            });
        }

        if ($("[system-authenticate-forgot]").length) {
            $("[system-authenticate-forgot]").on("submit", function (e) {
                e.preventDefault();

                var data = new FormData(this);

                var input_require = "email|" + lang_require_email;

                $.ajax({
                    url: site_url + "/requests/index/forgot",
                    type: "POST",
                    data: data,
                    contentType: false,
                    processData: false,
                    beforeSend: () => {
                        if (input_require) {
                            var filter = input_require.split("<=>");
                            for (var i = 0; i <= filter.length; i++) {
                                if (typeof filter[i] !== "undefined") {
                                    var values = filter[i].split("|");
                                }
                                try {
                                    if (data.get(values[0]).length < 1) {
                                        alert.warning(values[1] + ", " + lang_validate_cannotemp);
                                        return false;
                                    }
                                } catch (e) {
                                    if (data.getAll(values[0] + "[]").length < 1) {
                                        alert.warning(values[1] + ", " + lang_validate_cannotemp);
                                        return false;
                                    }
                                }
                            }
                        }

                        system.disabled();
                        system.loader(lang_js_authenticate_processing);
                    },
                    success: (http) => {
                        system.loader(false, false);

                        try {
                            var response = (typeof http === "string") ? JSON.parse(http) : http;

                            switch (response.status) {
                                case 200:
                                    $("[system-authenticate-forgot]").replaceWith(`
                                        <div class="alert alert-primary text-center mb-0 pb-0">
                                            ` + response.message + `
                                        </div>
                                    `);

                                    break;
                                case 301:
                                    alert.warning(response.message, true);

                                    break;
                                default:
                                    if (recaptcha_status)
                                        grecaptcha.reset();

                                    alert.danger(response.message);
                            }
                        } catch (e) {
                            alert.danger(lang_response_went_wrong);
                        }

                        system.disabled(false);
                    }
                });
            });
        }

        if ($("[system-authenticate-register]").length) {
            $("select").selectpicker();

            $("[system-authenticate-register]").on("submit", function (e) {
                e.preventDefault();

                var data = new FormData(this);

                var input_require = "name|" + lang_require_name + "<=>email|" + lang_require_email + "<=>password|" + lang_require_password + "<=>cpassword|" + lang_require_cpassword;

                $.ajax({
                    url: site_url + "/requests/index/register",
                    type: "POST",
                    data: data,
                    contentType: false,
                    processData: false,
                    beforeSend: () => {
                        if (input_require) {
                            var filter = input_require.split("<=>");
                            for (var i = 0; i <= filter.length; i++) {
                                if (typeof filter[i] !== "undefined") {
                                    var values = filter[i].split("|");
                                }
                                try {
                                    if (data.get(values[0]).length < 1) {
                                        alert.warning(values[1] + ", " + lang_validate_cannotemp);
                                        return false;
                                    }
                                } catch (e) {
                                    if (data.getAll(values[0] + "[]").length < 1) {
                                        alert.warning(values[1] + ", " + lang_validate_cannotemp);
                                        return false;
                                    }
                                }
                            }
                        }

                        system.disabled();
                        system.loader(lang_js_authenticate_processing);
                    },
                    success: (http) => {
                        system.loader(false, false);

                        try {
                            var response = (typeof http === "string") ? JSON.parse(http) : http;

                            switch (response.status) {
                                case 200:
                                    $("[system-register-confirm]").fadeOut("fast", function () {
                                        $("[system-register-confirm]").html(`
                                            <div class="alert alert-primary text-center mb-0 pb-0">
                                                <p>` + response.message + `</p>
                                            </div>
                                        `);

                                        $("[system-register-confirm]").fadeIn("slow");
                                    });
                                    break;
                                case 301:
                                    alert.success(response.message, true);
                                    break;
                                case 302:
                                    alert.warning(lang_response_session_false, true);
                                    break;
                                default:
                                    if (recaptcha_status)
                                        grecaptcha.reset();

                                    alert.danger(response.message);
                            }
                        } catch (e) {
                            alert.danger(lang_response_went_wrong);
                        }

                        system.disabled(false);
                    }
                });
            });
        }
    },

    duration: () => {
        if ($("[system-duration]").length) {
            $("[system-duration]").on("change paste keyup cut select", function () {
                var value = $(this).val();

                if ($("[system-duration-price]").length) {
                    var price = $("input[name=price]").val();

                    if (value < 0) {
                        $(this).val(1);
                    } else if (value < 1) {
                        $(this).val(false);
                        var value = 1;
                        $("[system-duration-price]").text(price);
                    } else {
                        var price = $("input[name=price]").val() * value;
                        $("[system-duration-price]").text(price);
                    }

                    $("[system-duration-button]").attr("system-toggle", "payment/" + $("input[name=id]").val() + "/" + value);
                } else {
                    if (value < 0) {
                        $(this).val(1);
                    } else if (value < 1) {
                        $(this).val(false);
                    }
                }
            });
        }

        if ($("[system-credits]").length) {
            $("[system-credits]").on("change paste keyup cut select", function () {
                var value = $(this).val();

                $("[system-credits-button]").attr("system-toggle", "payment/credits/" + value);
            });
        }
    },

    action: () => {
        $(document).on("click", "[system-action]", function (e) {
            e.preventDefault();

            var action = $(this).attr("system-action");

            switch (action) {
                case "refresh":
                    system.tables(true);

                    break;
                case "impersonate":
                    var authType = $(this).attr("auth-type");

                    if (authType == "entry") {
                        var uid = $(this).attr("user-id");

                        iziToast.question({
                            title: lang_impersonate_userentry_title,
                            message: lang_impersonate_userentry_desc,
                            titleLineHeight: "25px",
                            backgroundColor: "#FE9431",
                            position: "center",
                            icon: false,
                            drag: false,
                            timeout: false,
                            close: false,
                            overlay: true,
                            layout: 1,
                            zindex: 1051,
                            buttons: [
                                ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                                    system.loader(lang_impersonate_userentry_loader);

                                    $.post(site_url + "/requests/index/impersonate", {
                                        uid: uid,
                                        type: authType
                                    }, function (http) {
                                        system.loader(false, false);

                                        try {
                                            var response = (typeof http === "string") ? JSON.parse(http) : http;

                                            if (response.status == 200) {
                                                alert.success(response.message, true);
                                            } else {
                                                alert.danger(response.message);
                                            }
                                        } catch {
                                            alert.danger(lang_response_went_wrong);
                                        }

                                        instance.hide({
                                            transitionOut: "fadeOut"
                                        }, toast, "button");
                                    });
                                }, true],
                                ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                                    instance.hide({
                                        transitionOut: "fadeOut"
                                    }, toast, "button");
                                }]
                            ]
                        });
                    } else {
                        system.loader(lang_impersonate_userexit_loader);

                        $.post(site_url + "/requests/index/impersonate", {
                            uid: 0,
                            type: authType
                        }, function (http) {
                            system.loader(false, false);

                            try {
                                var response = (typeof http === "string") ? JSON.parse(http) : http;

                                if (response.status == 200) {
                                    alert.success(response.message, true);
                                } else {
                                    alert.danger(response.message);
                                }
                            } catch {
                                alert.danger(lang_response_went_wrong);
                            }
                        });
                    }

                    break;
                case "trigger":
                    var triggerLink = $(this).attr("webhook-link");
                    var triggerSecret = $(this).attr("webhook-secret");
                    var triggerType = "sms";

                    iziToast.question({
                        title: lang_js_actiontrigger_title,
                        titleLineHeight: "25px",
                        backgroundColor: color_primary,
                        position: "center",
                        icon: false,
                        drag: false,
                        timeout: false,
                        close: false,
                        overlay: true,
                        layout: 1,
                        zindex: 1051,
                        animateInside: false,
                        inputs: [
                            ["<select class=\"text-white\" data-live-search=\"true\" system-trigger><option value=\"#\" selected disabled>" + lang_js_actiontrigger_typetitle + "</option><option value=\"sms\">" + lang_js_actiontrigger_typesms + "</option><option value=\"whatsapp\">" + lang_js_actiontrigger_typewa + "</option><option value=\"ussd\">" + lang_js_actiontrigger_typeussd + "</option><option value=\"notification\">" + lang_js_actiontrigger_typenoti + "</option></select>", "change", function (instance, toast, select, e) {
                                if (select.options[select.selectedIndex].value != "#") {
                                    triggerType = select.options[select.selectedIndex].value;
                                }
                            }]
                        ],
                        buttons: [
                            ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                                system.loader(lang_js_actiontrigger_loader);

                                $.post(site_url + "/requests/index/trigger", {
                                    secret: triggerSecret,
                                    type: triggerType,
                                    url: triggerLink
                                }, (http) => {
                                    system.loader(false, false);

                                    try {
                                        var response = (typeof http === "string") ? JSON.parse(http) : http;

                                        if (response.status == 200) {
                                            alert.success(response.message);
                                        } else {
                                            alert.warning(response.message);
                                        }
                                    } catch (e) {
                                        console.error('System.js error:', e);
                                        alert.danger(lang_response_went_wrong);
                                    }

                                    instance.hide({
                                        transitionOut: "fadeOut"
                                    }, toast, "button");
                                });
                            }, true],
                            ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                                instance.hide({
                                    transitionOut: "fadeOut"
                                }, toast, "button");
                            }]
                        ],
                        onOpening: () => {
                            $("[system-trigger]").selectpicker();
                        }
                    });

                    break;
                case "translate":
                    var transFrom = "en";
                    var transTo = "de";

                    iziToast.question({
                        title: lang_js_actiontranslator_title,
                        titleLineHeight: "25px",
                        backgroundColor: color_primary,
                        position: "center",
                        icon: false,
                        drag: false,
                        timeout: false,
                        close: false,
                        overlay: true,
                        layout: 1,
                        zindex: 1051,
                        animateInside: false,
                        maxWidth: "497px",
                        inputs: [
                            ["<select class=\"text-white\" data-live-search=\"true\" system-translate><option value=\"#\" selected disabled>" + lang_js_actiontranslator_from + "</option><option value=\"af\" data-tokens=\"af Afrikaans\">Afrikaans</option><option value=\"sq\" data-tokens=\"sq Albanian\">Albanian</option><option value=\"am\" data-tokens=\"am Amharic\">Amharic</option><option value=\"ar\" data-tokens=\"ar Arabic\">Arabic</option><option value=\"hy\" data-tokens=\"hy Armenian\">Armenian</option><option value=\"az\" data-tokens=\"az Azerbaijani\">Azerbaijani</option><option value=\"eu\" data-tokens=\"eu Basque\">Basque</option><option value=\"be\" data-tokens=\"be Belarusian\">Belarusian</option><option value=\"bn\" data-tokens=\"bn Bengali\">Bengali</option><option value=\"bs\" data-tokens=\"bs Bosnian\">Bosnian</option><option value=\"bg\" data-tokens=\"bg Bulgarian\">Bulgarian</option><option value=\"ca\" data-tokens=\"ca Catalan\">Catalan</option><option value=\"ceb\" data-tokens=\"ceb Cebuano\">Cebuano</option><option value=\"ny\" data-tokens=\"ny Chichewa\">Chichewa</option><option value=\"zh-CN\" data-tokens=\"zh-CN Chinese\">Chinese</option><option value=\"co\" data-tokens=\"co Corsican\">Corsican</option><option value=\"hr\" data-tokens=\"hr Croatian\">Croatian</option><option value=\"cs\" data-tokens=\"cs Czech\">Czech</option><option value=\"da\" data-tokens=\"da Danish\">Danish</option><option value=\"nl\" data-tokens=\"nl Dutch\">Dutch</option><option value=\"en\" data-tokens=\"en English\">English</option><option value=\"eo\" data-tokens=\"eo Esperanto\">Esperanto</option><option value=\"et\" data-tokens=\"et Estonian\">Estonian</option><option value=\"tl\" data-tokens=\"tl Filipino\">Filipino</option><option value=\"fi\" data-tokens=\"fi Finnish\">Finnish</option><option value=\"fr\" data-tokens=\"fr French\">French</option><option value=\"fy\" data-tokens=\"fy Frisian\">Frisian</option><option value=\"gl\" data-tokens=\"gl Galician\">Galician</option><option value=\"ka\" data-tokens=\"ka Georgian\">Georgian</option><option value=\"de\" data-tokens=\"de German\">German</option><option value=\"el\" data-tokens=\"el Greek\">Greek</option><option value=\"gu\" data-tokens=\"gu Gujarati\">Gujarati</option><option value=\"ht\" data-tokens=\"ht Haitian Creole\">Haitian Creole</option><option value=\"ha\" data-tokens=\"ha Hausa\">Hausa</option><option value=\"haw\" data-tokens=\"haw Hawaiian\">Hawaiian</option><option value=\"iw\" data-tokens=\"iw Hebrew\">Hebrew</option><option value=\"hi\" data-tokens=\"hi Hindi\">Hindi</option><option value=\"hmn\" data-tokens=\"hmn Hmong\">Hmong</option><option value=\"hu\" data-tokens=\"hu Hungarian\">Hungarian</option><option value=\"is\" data-tokens=\"is Icelandic\">Icelandic</option><option value=\"ig\" data-tokens=\"ig Igbo\">Igbo</option><option value=\"id\" data-tokens=\"id Indonesian\">Indonesian</option><option value=\"ga\" data-tokens=\"ga Irish\">Irish</option><option value=\"it\" data-tokens=\"it Italian\">Italian</option><option value=\"ja\" data-tokens=\"ja Japanese\">Japanese</option><option value=\"jw\" data-tokens=\"jw Javanese\">Javanese</option><option value=\"kn\" data-tokens=\"kn Kannada\">Kannada</option><option value=\"kk\" data-tokens=\"kk Kazakh\">Kazakh</option><option value=\"km\" data-tokens=\"km Khmer\">Khmer</option><option value=\"rw\" data-tokens=\"rw Kinyarwanda\">Kinyarwanda</option><option value=\"ko\" data-tokens=\"ko Korean\">Korean</option><option value=\"ku\" data-tokens=\"ku Kurdish (Kurmanji)\">Kurdish (Kurmanji)</option><option value=\"ky\" data-tokens=\"ky Kyrgyz\">Kyrgyz</option><option value=\"lo\" data-tokens=\"lo Lao\">Lao</option><option value=\"la\" data-tokens=\"la Latin\">Latin</option><option value=\"lv\" data-tokens=\"lv Latvian\">Latvian</option><option value=\"lt\" data-tokens=\"lt Lithuanian\">Lithuanian</option><option value=\"lb\" data-tokens=\"lb Luxembourgish\">Luxembourgish</option><option value=\"mk\" data-tokens=\"mk Macedonian\">Macedonian</option><option value=\"mg\" data-tokens=\"mg Malagasy\">Malagasy</option><option value=\"ms\" data-tokens=\"ms Malay\">Malay</option><option value=\"ml\" data-tokens=\"ml Malayalam\">Malayalam</option><option value=\"mt\" data-tokens=\"mt Maltese\">Maltese</option><option value=\"mi\" data-tokens=\"mi Maori\">Maori</option><option value=\"mr\" data-tokens=\"mr Marathi\">Marathi</option><option value=\"mn\" data-tokens=\"mn Mongolian\">Mongolian</option><option value=\"my\" data-tokens=\"my Myanmar (Burmese)\">Myanmar (Burmese)</option><option value=\"ne\" data-tokens=\"ne Nepali\">Nepali</option><option value=\"no\" data-tokens=\"no Norwegian\">Norwegian</option><option value=\"or\" data-tokens=\"or Odia (Oriya)\">Odia (Oriya)</option><option value=\"ps\" data-tokens=\"ps Pashto\">Pashto</option><option value=\"fa\" data-tokens=\"fa Persian\">Persian</option><option value=\"pl\" data-tokens=\"pl Polish\">Polish</option><option value=\"pt\" data-tokens=\"pt Portuguese\">Portuguese</option><option value=\"pa\" data-tokens=\"pa Punjabi\">Punjabi</option><option value=\"ro\" data-tokens=\"ro Romanian\">Romanian</option><option value=\"ru\" data-tokens=\"ru Russian\">Russian</option><option value=\"sm\" data-tokens=\"sm Samoan\">Samoan</option><option value=\"gd\" data-tokens=\"gd Scots Gaelic\">Scots Gaelic</option><option value=\"sr\" data-tokens=\"sr Serbian\">Serbian</option><option value=\"st\" data-tokens=\"st Sesotho\">Sesotho</option><option value=\"sn\" data-tokens=\"sn Shona\">Shona</option><option value=\"sd\" data-tokens=\"sd Sindhi\">Sindhi</option><option value=\"si\" data-tokens=\"si Sinhala\">Sinhala</option><option value=\"sk\" data-tokens=\"sk Slovak\">Slovak</option><option value=\"sl\" data-tokens=\"sl Slovenian\">Slovenian</option><option value=\"so\" data-tokens=\"so Somali\">Somali</option><option value=\"es\" data-tokens=\"es Spanish\">Spanish</option><option value=\"su\" data-tokens=\"su Sundanese\">Sundanese</option><option value=\"sw\" data-tokens=\"sw Swahili\">Swahili</option><option value=\"sv\" data-tokens=\"sv Swedish\">Swedish</option><option value=\"tg\" data-tokens=\"tg Tajik\">Tajik</option><option value=\"ta\" data-tokens=\"ta Tamil\">Tamil</option><option value=\"tt\" data-tokens=\"tt Tatar\">Tatar</option><option value=\"te\" data-tokens=\"te Telugu\">Telugu</option><option value=\"th\" data-tokens=\"th Thai\">Thai</option><option value=\"tr\" data-tokens=\"tr Turkish\">Turkish</option><option value=\"tk\" data-tokens=\"tk Turkmen\">Turkmen</option><option value=\"uk\" data-tokens=\"uk Ukrainian\">Ukrainian</option><option value=\"ur\" data-tokens=\"ur Urdu\">Urdu</option><option value=\"ug\" data-tokens=\"ug Uyghur\">Uyghur</option><option value=\"uz\" data-tokens=\"uz Uzbek\">Uzbek</option><option value=\"vi\" data-tokens=\"vi Vietnamese\">Vietnamese</option><option value=\"cy\" data-tokens=\"cy Welsh\">Welsh</option><option value=\"xh\" data-tokens=\"xh Xhosa\">Xhosa</option><option value=\"yi\" data-tokens=\"yi Yiddish\">Yiddish</option><option value=\"yo\" data-tokens=\"yo Yoruba\">Yoruba</option><option value=\"zu\" data-tokens=\"zu Zulu\">Zulu</option></select>", "change", function (instance, toast, select, e) {
                                if (select.options[select.selectedIndex].value != "#") {
                                    transFrom = select.options[select.selectedIndex].value;
                                }
                            }],
                            ["<select class=\"text-white\" data-live-search=\"true\" system-translate><option value=\"#\" selected disabled>" + lang_js_actiontranslator_to + "</option><option value=\"af\" data-tokens=\"af Afrikaans\">Afrikaans</option><option value=\"sq\" data-tokens=\"sq Albanian\">Albanian</option><option value=\"am\" data-tokens=\"am Amharic\">Amharic</option><option value=\"ar\" data-tokens=\"ar Arabic\">Arabic</option><option value=\"hy\" data-tokens=\"hy Armenian\">Armenian</option><option value=\"az\" data-tokens=\"az Azerbaijani\">Azerbaijani</option><option value=\"eu\" data-tokens=\"eu Basque\">Basque</option><option value=\"be\" data-tokens=\"be Belarusian\">Belarusian</option><option value=\"bn\" data-tokens=\"bn Bengali\">Bengali</option><option value=\"bs\" data-tokens=\"bs Bosnian\">Bosnian</option><option value=\"bg\" data-tokens=\"bg Bulgarian\">Bulgarian</option><option value=\"ca\" data-tokens=\"ca Catalan\">Catalan</option><option value=\"ceb\" data-tokens=\"ceb Cebuano\">Cebuano</option><option value=\"ny\" data-tokens=\"ny Chichewa\">Chichewa</option><option value=\"zh-CN\" data-tokens=\"zh-CN Chinese\">Chinese</option><option value=\"co\" data-tokens=\"co Corsican\">Corsican</option><option value=\"hr\" data-tokens=\"hr Croatian\">Croatian</option><option value=\"cs\" data-tokens=\"cs Czech\">Czech</option><option value=\"da\" data-tokens=\"da Danish\">Danish</option><option value=\"nl\" data-tokens=\"nl Dutch\">Dutch</option><option value=\"en\" data-tokens=\"en English\">English</option><option value=\"eo\" data-tokens=\"eo Esperanto\">Esperanto</option><option value=\"et\" data-tokens=\"et Estonian\">Estonian</option><option value=\"tl\" data-tokens=\"tl Filipino\">Filipino</option><option value=\"fi\" data-tokens=\"fi Finnish\">Finnish</option><option value=\"fr\" data-tokens=\"fr French\">French</option><option value=\"fy\" data-tokens=\"fy Frisian\">Frisian</option><option value=\"gl\" data-tokens=\"gl Galician\">Galician</option><option value=\"ka\" data-tokens=\"ka Georgian\">Georgian</option><option value=\"de\" data-tokens=\"de German\">German</option><option value=\"el\" data-tokens=\"el Greek\">Greek</option><option value=\"gu\" data-tokens=\"gu Gujarati\">Gujarati</option><option value=\"ht\" data-tokens=\"ht Haitian Creole\">Haitian Creole</option><option value=\"ha\" data-tokens=\"ha Hausa\">Hausa</option><option value=\"haw\" data-tokens=\"haw Hawaiian\">Hawaiian</option><option value=\"iw\" data-tokens=\"iw Hebrew\">Hebrew</option><option value=\"hi\" data-tokens=\"hi Hindi\">Hindi</option><option value=\"hmn\" data-tokens=\"hmn Hmong\">Hmong</option><option value=\"hu\" data-tokens=\"hu Hungarian\">Hungarian</option><option value=\"is\" data-tokens=\"is Icelandic\">Icelandic</option><option value=\"ig\" data-tokens=\"ig Igbo\">Igbo</option><option value=\"id\" data-tokens=\"id Indonesian\">Indonesian</option><option value=\"ga\" data-tokens=\"ga Irish\">Irish</option><option value=\"it\" data-tokens=\"it Italian\">Italian</option><option value=\"ja\" data-tokens=\"ja Japanese\">Japanese</option><option value=\"jw\" data-tokens=\"jw Javanese\">Javanese</option><option value=\"kn\" data-tokens=\"kn Kannada\">Kannada</option><option value=\"kk\" data-tokens=\"kk Kazakh\">Kazakh</option><option value=\"km\" data-tokens=\"km Khmer\">Khmer</option><option value=\"rw\" data-tokens=\"rw Kinyarwanda\">Kinyarwanda</option><option value=\"ko\" data-tokens=\"ko Korean\">Korean</option><option value=\"ku\" data-tokens=\"ku Kurdish (Kurmanji)\">Kurdish (Kurmanji)</option><option value=\"ky\" data-tokens=\"ky Kyrgyz\">Kyrgyz</option><option value=\"lo\" data-tokens=\"lo Lao\">Lao</option><option value=\"la\" data-tokens=\"la Latin\">Latin</option><option value=\"lv\" data-tokens=\"lv Latvian\">Latvian</option><option value=\"lt\" data-tokens=\"lt Lithuanian\">Lithuanian</option><option value=\"lb\" data-tokens=\"lb Luxembourgish\">Luxembourgish</option><option value=\"mk\" data-tokens=\"mk Macedonian\">Macedonian</option><option value=\"mg\" data-tokens=\"mg Malagasy\">Malagasy</option><option value=\"ms\" data-tokens=\"ms Malay\">Malay</option><option value=\"ml\" data-tokens=\"ml Malayalam\">Malayalam</option><option value=\"mt\" data-tokens=\"mt Maltese\">Maltese</option><option value=\"mi\" data-tokens=\"mi Maori\">Maori</option><option value=\"mr\" data-tokens=\"mr Marathi\">Marathi</option><option value=\"mn\" data-tokens=\"mn Mongolian\">Mongolian</option><option value=\"my\" data-tokens=\"my Myanmar (Burmese)\">Myanmar (Burmese)</option><option value=\"ne\" data-tokens=\"ne Nepali\">Nepali</option><option value=\"no\" data-tokens=\"no Norwegian\">Norwegian</option><option value=\"or\" data-tokens=\"or Odia (Oriya)\">Odia (Oriya)</option><option value=\"ps\" data-tokens=\"ps Pashto\">Pashto</option><option value=\"fa\" data-tokens=\"fa Persian\">Persian</option><option value=\"pl\" data-tokens=\"pl Polish\">Polish</option><option value=\"pt\" data-tokens=\"pt Portuguese\">Portuguese</option><option value=\"pa\" data-tokens=\"pa Punjabi\">Punjabi</option><option value=\"ro\" data-tokens=\"ro Romanian\">Romanian</option><option value=\"ru\" data-tokens=\"ru Russian\">Russian</option><option value=\"sm\" data-tokens=\"sm Samoan\">Samoan</option><option value=\"gd\" data-tokens=\"gd Scots Gaelic\">Scots Gaelic</option><option value=\"sr\" data-tokens=\"sr Serbian\">Serbian</option><option value=\"st\" data-tokens=\"st Sesotho\">Sesotho</option><option value=\"sn\" data-tokens=\"sn Shona\">Shona</option><option value=\"sd\" data-tokens=\"sd Sindhi\">Sindhi</option><option value=\"si\" data-tokens=\"si Sinhala\">Sinhala</option><option value=\"sk\" data-tokens=\"sk Slovak\">Slovak</option><option value=\"sl\" data-tokens=\"sl Slovenian\">Slovenian</option><option value=\"so\" data-tokens=\"so Somali\">Somali</option><option value=\"es\" data-tokens=\"es Spanish\">Spanish</option><option value=\"su\" data-tokens=\"su Sundanese\">Sundanese</option><option value=\"sw\" data-tokens=\"sw Swahili\">Swahili</option><option value=\"sv\" data-tokens=\"sv Swedish\">Swedish</option><option value=\"tg\" data-tokens=\"tg Tajik\">Tajik</option><option value=\"ta\" data-tokens=\"ta Tamil\">Tamil</option><option value=\"tt\" data-tokens=\"tt Tatar\">Tatar</option><option value=\"te\" data-tokens=\"te Telugu\">Telugu</option><option value=\"th\" data-tokens=\"th Thai\">Thai</option><option value=\"tr\" data-tokens=\"tr Turkish\">Turkish</option><option value=\"tk\" data-tokens=\"tk Turkmen\">Turkmen</option><option value=\"uk\" data-tokens=\"uk Ukrainian\">Ukrainian</option><option value=\"ur\" data-tokens=\"ur Urdu\">Urdu</option><option value=\"ug\" data-tokens=\"ug Uyghur\">Uyghur</option><option value=\"uz\" data-tokens=\"uz Uzbek\">Uzbek</option><option value=\"vi\" data-tokens=\"vi Vietnamese\">Vietnamese</option><option value=\"cy\" data-tokens=\"cy Welsh\">Welsh</option><option value=\"xh\" data-tokens=\"xh Xhosa\">Xhosa</option><option value=\"yi\" data-tokens=\"yi Yiddish\">Yiddish</option><option value=\"yo\" data-tokens=\"yo Yoruba\">Yoruba</option><option value=\"zu\" data-tokens=\"zu Zulu\">Zulu</option></select>", "change", function (instance, toast, select, e) {
                                if (select.options[select.selectedIndex].value != "#") {
                                    transTo = select.options[select.selectedIndex].value;
                                }
                            }]
                        ],
                        buttons: [
                            ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                                var transMessage = $("textarea[name=message]").val();

                                system.loader(lang_js_actiontranslator_loader);

                                $.post(site_url + "/requests/translate/" + transFrom + "/" + transTo, {
                                    message: transMessage
                                }, function (http) {
                                    system.loader(false, false);

                                    try {
                                        var response = (typeof http === "string") ? JSON.parse(http) : http;

                                        if (response.status == 200) {
                                            $("textarea[name=message]").val(response.data);
                                            $("textarea[name=message]").trigger("change.counter");
                                        } else {
                                            alert.danger(response.message);
                                        }
                                    } catch (e) {
                                        console.error('System.js error:', e);
                                        alert.danger(lang_response_went_wrong);
                                    }

                                    instance.hide({
                                        transitionOut: "fadeOut"
                                    }, toast, "button");
                                });
                            }, true],
                            ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                                instance.hide({
                                    transitionOut: "fadeOut"
                                }, toast, "button");
                            }]
                        ],
                        onOpening: () => {
                            $("[system-translate]").selectpicker();
                        }
                    });

                    break;
                case "regenerate_admintoken":
                    iziToast.question({
                        title: lang_js_regenerate_admintoken,
                        message: lang_js_regenerate_admintoken_desc,
                        titleLineHeight: "25px",
                        backgroundColor: "#E82753",
                        position: "center",
                        icon: false,
                        drag: false,
                        timeout: false,
                        close: false,
                        overlay: true,
                        layout: 1,
                        zindex: 1051,
                        buttons: [
                            ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                                system.loader(lang_js_actionregen_loader);

                                $.get(site_url + "/requests/index/regenerate_admintoken", function (http) {
                                    system.loader(false, false);

                                    try {
                                        var response = (typeof http === "string") ? JSON.parse(http) : http;

                                        if (response.status == 200) {
                                            $("[admin-api-token]").text(response.data);

                                            alert.success(response.message);
                                        } else {
                                            alert.danger(response.message);
                                        }
                                    } catch (e) {
                                        console.error('System.js error:', e);
                                        alert.danger(lang_response_went_wrong);
                                    }

                                    instance.hide({
                                        transitionOut: "fadeOut"
                                    }, toast, "button");
                                });
                            }, true],
                            ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                                instance.hide({
                                    transitionOut: "fadeOut"
                                }, toast, "button");
                            }]
                        ]
                    });

                    break;
                case "regenerate_systoken":
                    iziToast.question({
                        title: lang_js_actionregen_title,
                        message: lang_js_actionregen_desc,
                        titleLineHeight: "25px",
                        backgroundColor: "#E82753",
                        position: "center",
                        icon: false,
                        drag: false,
                        timeout: false,
                        close: false,
                        overlay: true,
                        layout: 1,
                        zindex: 1051,
                        buttons: [
                            ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                                system.loader(lang_js_actionregen_loader);

                                $.get(site_url + "/requests/index/regenerate_systoken", function (http) {
                                    system.loader(false, false);

                                    try {
                                        var response = (typeof http === "string") ? JSON.parse(http) : http;

                                        if (response.status == 200) {
                                            $("[sys-token]").text(response.data);

                                            alert.success(response.message);
                                        } else {
                                            alert.danger(response.message);
                                        }
                                    } catch (e) {
                                        console.error('System.js error:', e);
                                        alert.danger(lang_response_went_wrong);
                                    }

                                    instance.hide({
                                        transitionOut: "fadeOut"
                                    }, toast, "button");
                                });
                            }, true],
                            ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                                instance.hide({
                                    transitionOut: "fadeOut"
                                }, toast, "button");
                            }]
                        ]
                    });

                    break;
                case "clear":
                    iziToast.question({
                        title: lang_js_actionclear_title,
                        message: lang_js_actionclear_desc,
                        titleLineHeight: "25px",
                        backgroundColor: "#FE9431",
                        position: "center",
                        icon: false,
                        drag: false,
                        timeout: false,
                        close: false,
                        overlay: true,
                        layout: 1,
                        zindex: 1024,
                        buttons: [
                            ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                                system.loader(lang_js_processing_dataloader);

                                $.get(site_url + "/requests/clear/cache", function (http) {
                                    system.loader(false, false);

                                    try {
                                        var response = (typeof http === "string") ? JSON.parse(http) : http;

                                        if (response.status == 200) {
                                            alert.success(response.message);
                                        } else {
                                            alert.danger(response.message);
                                        }
                                    } catch (e) {
                                        console.error('System.js error:', e);
                                        alert.danger(lang_response_went_wrong);
                                    }

                                    instance.hide({
                                        transitionOut: "fadeOut"
                                    }, toast, "button");
                                });
                            }, true],
                            ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                                instance.hide({
                                    transitionOut: "fadeOut"
                                }, toast, "button");
                            }]
                        ]
                    });

                    break;
                case "payout_confirm":
                    system.loader(lang_js_processing_dataloader);

                    var payoutId = $(this).attr("payout-id");

                    $.get(site_url + "/requests/payout/confirm/" + payoutId, (http) => {
                        try {
                            var response = (typeof http === "string") ? JSON.parse(http) : http;

                            if (response.status == 200) {
                                system.tables(true);
                                setTimeout(() => {
                                    system.loader(false, false);
                                    alert.success(response.message);
                                }, 2000);
                            } else {
                                system.loader(false, false);
                                alert.danger(response.message);
                            }
                        } catch (e) {
                            console.error('System.js error:', e);
                            system.loader(false, false);
                            alert.danger(lang_response_went_wrong);
                        }
                    });

                    break;
                case "payout_reject":
                    system.loader(lang_js_processing_dataloader);

                    var payoutId = $(this).attr("payout-id");

                    $.get(site_url + "/requests/payout/reject/" + payoutId, (http) => {
                        try {
                            var response = (typeof http === "string") ? JSON.parse(http) : http;

                            if (response.status == 200) {
                                system.tables(true);
                                setTimeout(() => {
                                    system.loader(false, false);
                                    alert.success(response.message);
                                }, 2000);
                            } else {
                                system.loader(false, false);
                                alert.danger(response.message);
                            }
                        } catch (e) {
                            console.error('System.js error:', e);
                            system.loader(false, false);
                            alert.danger(lang_response_went_wrong);
                        }
                    });

                    break;
                case "resend":
                    system.loader(lang_js_processing_dataloader);

                    var resendId = $(this).attr("resend-id");

                    $.get(site_url + "/requests/resend/sms/" + resendId, (http) => {
                        system.loader(false, false);

                        try {
                            var response = (typeof http === "string") ? JSON.parse(http) : http;

                            if (response.status == 200) {
                                system.tables(true);
                                alert.success(response.message);
                            } else {
                                alert.danger(response.message);
                            }
                        } catch (e) {
                            alert.danger(lang_response_went_wrong);
                        }
                    });

                    break;
                case "suspend":
                    var uid = $(this).attr("user-id");

                    iziToast.question({
                        title: lang_suspend_user_title,
                        message: lang_suspend_user_desc,
                        titleLineHeight: "25px",
                        backgroundColor: "#FE9431",
                        position: "center",
                        icon: false,
                        drag: false,
                        timeout: false,
                        close: false,
                        overlay: true,
                        layout: 1,
                        zindex: 1024,
                        buttons: [
                            ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                                $.ajax({
                                    url: site_url + "/requests/update/admin.suspend",
                                    type: "POST",
                                    data: "id=" + uid,
                                    beforeSend: function () {
                                        instance.hide({
                                            transitionOut: "fadeOut"
                                        }, toast, "button");
                                    },
                                    success: function (http) {
                                        try {
                                            var response = (typeof http === "string") ? JSON.parse(http) : http;
                                            if (response.status == 200) {
                                                system.tables(true);
                                                alert.success(response.message);
                                            } else {
                                                alert.danger(response.message);
                                            }
                                        } catch (e) {
                                            console.error('System.js error:', e);
                                            alert.danger(lang_response_went_wrong);
                                        }
                                    }
                                });
                            }, true],
                            ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                                instance.hide({
                                    transitionOut: "fadeOut"
                                }, toast, "button");
                            }]
                        ]
                    });

                    break;
                case "unsuspend":
                    var uid = $(this).attr("user-id");

                    iziToast.question({
                        title: lang_unsuspend_user_title,
                        message: lang_unsuspend_user_desc,
                        titleLineHeight: "25px",
                        backgroundColor: color_primary,
                        position: "center",
                        icon: false,
                        drag: false,
                        timeout: false,
                        close: false,
                        overlay: true,
                        layout: 1,
                        zindex: 1024,
                        buttons: [
                            ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                                $.ajax({
                                    url: site_url + "/requests/update/admin.unsuspend",
                                    type: "POST",
                                    data: "id=" + uid,
                                    beforeSend: function () {
                                        instance.hide({
                                            transitionOut: "fadeOut"
                                        }, toast, "button");
                                    },
                                    success: function (http) {
                                        try {
                                            var response = (typeof http === "string") ? JSON.parse(http) : http;
                                            if (response.status == 200) {
                                                system.tables(true);
                                                alert.success(response.message);
                                            } else {
                                                alert.danger(response.message);
                                            }
                                        } catch (e) {
                                            console.error('System.js error:', e);
                                            alert.danger(lang_response_went_wrong);
                                        }
                                    }
                                });
                            }, true],
                            ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                                instance.hide({
                                    transitionOut: "fadeOut"
                                }, toast, "button");
                            }]
                        ]
                    });

                    break;
                case "logout":
                    $.get(site_url + "/requests/index/logout", (http) => {
                        try {
                            var response = (typeof http === "string") ? JSON.parse(http) : http;

                            if (response.status == 200) {
                                alert.success(response.message, true);
                            } else {
                                location.reload();
                            }
                        } catch (e) {
                            alert.danger(lang_response_went_wrong);
                        }
                    });

                    break;
                default:
                    alert.danger(lang_unknown_action_method);
            }
        });
    },

    translate: (homepage = false) => {
        $("body").append(
            `<ul class="mfb-component--br mfb-slidein-spring" data-mfb-toggle="hover">
                <li class="mfb-component__wrap">
                    <a href="#" class="mfb-component__button--main" system-scroll-top>
                        <i class="mfb-component__main-icon--resting la la-language la-lg text-white"></i>
                        <i class="mfb-component__main-icon--active la la-arrow-up la-lg text-white"></i>
                    </a>
                    <ul class="mfb-component__list" system-languages></ul>
                </li>
            </ul>`
        ).ready(() => {
            $.get(site_url + "/requests/languages/list", function (http) {
                try {
                    var response = (typeof http === "string") ? JSON.parse(http) : http;

                    $("[system-languages]").html(response.data);

                    if (homepage) {
                        $(document).on("click", "[system-toggle=\"languages\"]", function (e) {
                            e.preventDefault();

                            $.get(site_url + "/widget/modal/default/languages", function (http) {
                                try {
                                    var response = (typeof http === "string") ? JSON.parse(http) : http;

                                    $("body").append(response.data.tpl);

                                    var langModal = new bootstrap.Modal(document.getElementById("lang-modal"), {
                                        keyboard: false
                                    });

                                    langModal.show();
                                } catch (e) {
                                    console.error('System.js error:', e);
                                    alert.danger(lang_response_went_wrong);
                                }
                            });
                        });
                    }

                    $(document).on("click", "[system-language]", function (e) {
                        e.preventDefault();
                        system.loader(lang_js_processing_dataloader);

                        $.get(site_url + "/requests/languages/change/" + $(this).attr("system-language"), function (http) {
                            system.loader(false, false);

                            try {
                                var response = (typeof http === "string") ? JSON.parse(http) : http;

                                if (response.status == 200) {
                                    alert.success(response.message, true);
                                } else {
                                    alert.warning(response.message);
                                }
                            } catch (e) {
                                console.error('System.js error:', e);
                                alert.danger(lang_response_went_wrong);
                            }
                        });
                    });
                } catch (e) {
                    console.error('System.js error:', e);
                    alert.danger(lang_response_went_wrong);
                }
            });

            system.scroll();
        });
    },

    codeflask: () => {
        if ($("[system-codeflask]").length) {
            window.codeflask = new CodeFlask("[system-codeflask]", {
                language: "html",
                lineNumbers: true
            });

            codeflask.updateCode(he.decode(codeflask.getCode()));
        }
    },

    clipboard: (destroy = false) => {
        if (!destroy) {
            if ($("[system-clipboard]").length) {
                window.clipboard = new ClipboardJS("[system-clipboard]");

                clipboard.on("success", (e) => {
                    e.clearSelection();
                    alert.primary(lang_copy_data);
                });
            }
        } else {
            if (window.clipboard)
                clipboard.destroy();
        }
    },

    disabled: (disabled = true) => {
        if (disabled)
            $(".form-control, .input, button[type=submit]").attr("disabled", "");
        else
            $(".form-control, .input, button[type=submit]").removeAttr("disabled");
    },

    scroll: () => {
        $("[system-scroll-top]").on("click", function (e) {
            e.preventDefault();
            $("html, body").animate({
                scrollTop: "0px"
            }, 300);
        });

        $("[system-scroll]").on("click", function (e) {
            e.preventDefault();
            $(window).stop(true).scrollTo(this.hash);
        });
    },

    qrcode: (data, width, height, element = "system-qrcode") => {
        new QRCode(element, {
            text: data,
            width: width,
            height: height
        });
    },

    redirect: (path) => {
        window.location.href = path;
    },

    ripple: () => {
        Waves.init();
        Waves.attach(".btn");
    },

    iframe: () => {
        if ($("[system-iframe]").length) {
            $("[system-iframe]").each(function (i) {
                $(this).attr("src", $(this).attr("system-iframe"));
                $(this).on("load", function () {
                    setTimeout(() => {
                        $(this).iFrameResize({
                            log: false,
                            resizeFrom: "child",
                            warningTimeout: 0,
                            heightCalculationMethod: "bodyScroll"
                        });
                    }, 500);
                });
            });
        }
    },

    datepicker: () => {
        $(".daterangepicker").remove();

        var ranges = [];
        ranges[lang_js_calendarall_option] = ["1/1/1975", moment()];
        ranges[lang_date_today] = [moment(), moment()];
        ranges[lang_date_yesterday] = [moment().subtract(1, "days"), moment().subtract(1, "days")];
        ranges[lang_date_7days] = [moment().subtract(6, "days"), moment()];
        ranges[lang_date_30days] = [moment().subtract(29, "days"), moment()];
        ranges[lang_date_month] = [moment().startOf("month"), moment().endOf("month")];
        ranges[lang_date_lmonth] = [moment().subtract(1, "month").startOf("month"), moment().subtract(1, "month").endOf("month")];

        $("[system-datepicker]").daterangepicker({
            opens: "left",
            startDate: "1/1/1975",
            endDate: moment(),
            ranges: ranges,
            locale: {
                applyLabel: lang_js_calendarall_apply,
                cancelLabel: lang_js_calendarall_cancel,
                fromLabel: lang_js_calendarall_from,
                toLabel: lang_js_calendarall_to,
                customRangeLabel: lang_date_custom,
                weekLabel: lang_js_calendarall_week,
                daysOfWeek: [
                    lang_js_calendarall_daysu,
                    lang_js_calendarall_daymo,
                    lang_js_calendarall_daytu,
                    lang_js_calendarall_daywe,
                    lang_js_calendarall_dayth,
                    lang_js_calendarall_dayfr,
                    lang_js_calendarall_daysa
                ],
                monthNames: [
                    lang_js_calendarall_monjan,
                    lang_js_calendarall_monfeb,
                    lang_js_calendarall_monmar,
                    lang_js_calendarall_monapr,
                    lang_js_calendarall_monmay,
                    lang_js_calendarall_monjun,
                    lang_js_calendarall_monjul,
                    lang_js_calendarall_monaug,
                    lang_js_calendarall_monsep,
                    lang_js_calendarall_monoct,
                    lang_js_calendarall_monnov,
                    lang_js_calendarall_mondec
                ]
            }
        });

        $("[system-datepicker-schedule]").daterangepicker({
            opens: "left",
            timePicker: true,
            singleDatePicker: true,
            locale: {
                format: "MM/DD/YYYY hh:mm A",
                applyLabel: lang_js_calendarall_apply,
                cancelLabel: lang_js_calendarall_cancel,
                fromLabel: lang_js_calendarall_from,
                toLabel: lang_js_calendarall_to,
                customRangeLabel: lang_date_custom,
                weekLabel: lang_js_calendarall_week,
                daysOfWeek: [
                    lang_js_calendarall_daysu,
                    lang_js_calendarall_daymo,
                    lang_js_calendarall_daytu,
                    lang_js_calendarall_daywe,
                    lang_js_calendarall_dayth,
                    lang_js_calendarall_dayfr,
                    lang_js_calendarall_daysa
                ],
                monthNames: [
                    lang_js_calendarall_monjan,
                    lang_js_calendarall_monfeb,
                    lang_js_calendarall_monmar,
                    lang_js_calendarall_monapr,
                    lang_js_calendarall_monmay,
                    lang_js_calendarall_monjun,
                    lang_js_calendarall_monjul,
                    lang_js_calendarall_monaug,
                    lang_js_calendarall_monsep,
                    lang_js_calendarall_monoct,
                    lang_js_calendarall_monnov,
                    lang_js_calendarall_mondec
                ]
            }
        });
    },

    autocomplete: () => {
        if ($("[system-autocomplete]").length) {
            $(".autocomplete-suggestions").remove();

            $.get(site_url + "/requests/autocomplete/" + ($("[system-autocomplete]").attr("system-autocomplete") ? $("[system-autocomplete]").attr("system-autocomplete") : false), function (http) {
                try {
                    var response = (typeof http === "string") ? JSON.parse(http) : http;

                    switch (response.status) {
                        case 200:
                            $("[system-autocomplete]").autocomplete({
                                lookup: response.data,
                                onSelect: function (suggestion) {
                                    $(this).val(suggestion.data);
                                }
                            });
                            break;
                        case 302:
                            alert.warning(lang_response_session_false, true);
                            break;
                        default:
                            alert.danger(response.message);
                    }
                } catch (e) {
                    console.error('System.js error:', e);
                    alert.danger(lang_response_went_wrong);
                }
            });
        }

        if ($("[system-whatsapp-autocomplete]").length) {
            $(".autocomplete-suggestions").remove();

            $.post(site_url + "/requests/autocomplete/wa.contacts", {
                account: $("[system-wa-account-select]").find(":selected").val()
            }, function (http) {
                try {
                    var response = (typeof http === "string") ? JSON.parse(http) : http;

                    switch (response.status) {
                        case 200:
                            $("[system-whatsapp-autocomplete]").autocomplete({
                                lookup: response.data,
                                onSelect: function (suggestion) {
                                    $(this).val(suggestion.data);
                                }
                            });
                            break;
                        case 302:
                            alert.warning(lang_response_session_false, true);
                            break;
                        default:
                            alert.danger(response.message);
                    }
                } catch (e) {
                    console.error('System.js error:', e);
                    alert.danger(lang_response_went_wrong);
                }
            });
        }
    },

    counter: () => {
        if ($("[system-counter]").length) {
            $("[system-counter]").counter({
                goal: message_max > 0 ? message_max : "sky",
                target: "[system-counter-view]"
            });
        }
    },

    _debounce: (func, wait) => {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    _handleMultiSelect: (selector) => {
        if ($(selector).length) {
            $(selector).on("changed.bs.select", function (e, clickedIndex, isSelected, previousValue) {
                var items = $(this).find("option:selected"), i;

                if (clickedIndex > 0) {
                    for (i = 0; i < items.length; i++) {
                        if (items[i].value == "0")
                            items[i].selected = false;
                    }
                } else {
                    for (i = 0; i < items.length; i++) {
                        if (items[i].value != "0")
                            items[i].selected = false;
                    }
                }

                $(selector).selectpicker("refresh");
            });
        }
    },

    select: () => {
        if ($("[system-form] select").length) {
            $("[system-form] select").selectpicker();
        }

        if ($("[system-wa-account-select]").length) {
            $("[system-wa-account-select]").on("changed.bs.select", function (e, clickedIndex, isSelected, previousValue) {
                $(".autocomplete-suggestions").remove();

                $.post(site_url + "/requests/autocomplete/wa.contacts", {
                    account: $(this).find(":selected").val()
                }, function (http) {
                    try {
                        var response = (typeof http === "string") ? JSON.parse(http) : http;

                        switch (response.status) {
                            case 200:
                                $("[system-whatsapp-autocomplete]").autocomplete({
                                    lookup: response.data,
                                    onSelect: function (suggestion) {
                                        $(this).val(suggestion.data);
                                    }
                                });
                                break;
                            case 302:
                                alert.warning(lang_response_session_false, true);
                                break;
                            default:
                                alert.danger(response.message);
                        }
                    } catch (e) {
                        console.error('System.js error:', e);
                        alert.danger(lang_response_went_wrong);
                    }
                });
            });
        }

        if ($("[system-select-template]").length) {
            $("[system-select-template]").on("changed.bs.select", function (e, clickedIndex, isSelected, previousValue) {
                var temp = $(this).find("option:selected");
                var format = (temp.data("format") ? temp.data("format") : false);

                if (format)
                    $("textarea[name=message]").val(format);
                else
                    $("textarea[name=message]").val("");
            });
        }

        system._handleMultiSelect("[system-select-groups]");
        system._handleMultiSelect("[system-select-users]");
        system._handleMultiSelect("[system-select-roles]");
        system._handleMultiSelect("[system-select-adminapi]");
        system._handleMultiSelect("[system-select-mailing]");

        if ($("[system-select-mode]").length) {
            var defaultSelVal = $("[system-select-mode]").find(":selected").val();

            if (defaultSelVal > 1) {
                $("[system-device-mode]").hide();
                $("[system-credits-mode]").show();
            } else {
                $("[system-device-mode]").show();
                $("[system-credits-mode]").hide();
            }

            $("[system-select-mode]").on("changed.bs.select", function (e, clickedIndex, isSelected, previousValue) {
                var methodMode = $(this).find("option:selected");

                if (methodMode[0].value > 1) {
                    $("[system-device-mode]").hide();
                    $("[system-credits-mode]").show();
                } else {
                    $("[system-device-mode]").show();
                    $("[system-credits-mode]").hide();
                }
            });
        }

        if ($("[system-wa-type]").length) {
            var initialMessageType = $("[system-wa-type]").val();

            if (initialMessageType == "text") {
                $("[system-wa-type-media]").hide();
                $("[system-wa-type-document]").hide();
                $("[system-wa-type-button]").hide();
                $("[system-wa-type-template]").hide();
                $("[system-wa-type-list]").hide();
            } else if (initialMessageType == "media") {
                $("[system-wa-type-media]").show();
                $("[system-wa-type-document]").hide();
                $("[system-wa-type-button]").hide();
                $("[system-wa-type-template]").hide();
                $("[system-wa-type-list]").hide();
            } else if (initialMessageType == "document") {
                $("[system-wa-type-document]").show();
                $("[system-wa-type-media]").hide();
                $("[system-wa-type-button]").hide();
                $("[system-wa-type-template]").hide();
                $("[system-wa-type-list]").hide();
            } else {
                $("[system-wa-type-document]").hide();
                $("[system-wa-type-media]").hide();
                $("[system-wa-type-button]").hide();
                $("[system-wa-type-template]").hide();
                $("[system-wa-type-list]").hide();
            }

            window.typeMode = [{
                value: initialMessageType
            }];

            $("[system-wa-type]").on("changed.bs.select", function (e, clickedIndex, isSelected, previousValue) {
                typeMode = $(this).find("option:selected");

                if (typeMode[0].value == "text") {
                    $("[system-wa-type-media]").hide();
                    $("[system-wa-type-document]").hide();
                    $("[system-wa-type-button]").hide();
                    $("[system-wa-type-template]").hide();
                    $("[system-wa-type-list]").hide();
                } else if (typeMode[0].value == "media") {
                    $("[system-wa-type-media]").show();
                    $("[system-wa-type-document]").hide();
                    $("[system-wa-type-button]").hide();
                    $("[system-wa-type-template]").hide();
                    $("[system-wa-type-list]").hide();
                } else if (typeMode[0].value == "document") {
                    $("[system-wa-type-document]").show();
                    $("[system-wa-type-media]").hide();
                    $("[system-wa-type-button]").hide();
                    $("[system-wa-type-template]").hide();
                    $("[system-wa-type-list]").hide();
                } else {
                    $("[system-wa-type-document]").hide();
                    $("[system-wa-type-media]").hide();
                    $("[system-wa-type-button]").hide();
                    $("[system-wa-type-template]").hide();
                    $("[system-wa-type-list]").hide();
                }
            });
        }
    },

    visitors: () => {
        window.visitorTracking = false;

        setTimeout(() => {
            if (!visitorTracking) {
                visitorTracking = true;
                $.get(site_url + "/requests/visitors");
            }
        }, 5000);
    },

    pages: () => {
        $(document).on("click", "[system-page]", function (e) {
            var page = $(this).attr("system-page").split("/");

            if (page.length > 1)
                pjax.loadUrl(site_url + "/pages/" + page[0] + "/" + page[1]);
        });
    },

    build: () => {
        $(document).on("click", "[system-build]", function (e) {
            iziToast.question({
                title: lang_js_build_title,
                message: lang_js_build_desc,
                titleLineHeight: "25px",
                backgroundColor: color_primary,
                position: "center",
                icon: false,
                drag: false,
                timeout: false,
                close: false,
                overlay: true,
                layout: 1,
                zindex: 1024,
                buttons: [
                    ["<button class=\"text-white\">" + lang_js_build_btnbuild + "</button>", function (instance, toast, button, e, inputs) {
                        system.loader(lang_requests_build_submitrequest);

                        $.get(site_url + "/requests/build", function (http) {
                            system.loader(false, false);

                            try {
                                var response = (typeof http === "string") ? JSON.parse(http) : http;

                                switch (response.status) {
                                    case 200:
                                        alert.success(response.message);
                                        pjax.loadUrl(window.location.href);

                                        break;
                                    case 302:
                                        alert.warning(lang_response_session_false, true);
                                        break;
                                    default:
                                        alert.danger(response.message);
                                }
                            } catch (e) {
                                console.error('System.js error:', e);
                                alert.danger(lang_response_went_wrong);
                            }

                            instance.hide({
                                transitionOut: "fadeOut"
                            }, toast, "button");
                        });
                    }, true],
                    ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                        instance.hide({
                            transitionOut: "fadeOut"
                        }, toast, "button");
                    }]
                ]
            });
        });
    },

    reorder: () => {
        $(document).on("click", "[system-reorder]", function (e) {
            e.preventDefault();

            iziToast.question({
                title: lang_js_reorder_title,
                message: lang_js_reorder_desc,
                titleLineHeight: "25px",
                backgroundColor: color_primary,
                position: "center",
                icon: false,
                drag: false,
                timeout: false,
                close: false,
                overlay: true,
                layout: 1,
                zindex: 1024,
                buttons: [
                    ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                        $.ajax({
                            url: site_url + "/requests/reorder",
                            type: "POST",
                            data: {
                                rows: tableSelected
                            },
                            beforeSend: function () {
                                instance.hide({
                                    transitionOut: "fadeOut"
                                }, toast, "button");

                                system.loader(lang_requests_reorder_loader);
                            },
                            success: function (http) {
                                system.loader(false, false);
                                $("[system-reorder]").attr("disabled", true);

                                try {
                                    var response = (typeof http === "string") ? JSON.parse(http) : http;

                                    switch (response.status) {
                                        case 200:
                                            tableSelected = [];
                                            system.tables(true);
                                            alert.success(response.message, true);
                                            break;
                                        default:
                                            alert.danger(response.message);
                                    }
                                } catch (e) {
                                    console.error('System.js error:', e);
                                    alert.danger(lang_response_went_wrong);
                                }
                            }
                        });
                    }, true],
                    ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                        instance.hide({
                            transitionOut: "fadeOut"
                        }, toast, "button");
                    }]
                ]
            });
        });
    },

    delete: () => {
        $(document).on("click", "[system-delete]", function (e) {
            e.preventDefault();
            var param = ($(this).attr("system-delete") ? $(this).attr("system-delete") : false);
            iziToast.question({
                title: lang_delete_title,
                message: lang_delete_tagline,
                titleLineHeight: "25px",
                backgroundColor: "#E82753",
                position: "center",
                icon: false,
                drag: false,
                timeout: false,
                close: false,
                overlay: true,
                layout: 1,
                zindex: 1024,
                buttons: [
                    ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                        $.ajax({
                            url: site_url + "/requests/delete/" + param,
                            type: "GET",
                            beforeSend: function () {
                                instance.hide({
                                    transitionOut: "fadeOut"
                                }, toast, "button");

                                system.loader(lang_requests_deleteitem_loader);
                            },
                            success: function (http) {
                                system.loader(false, false);

                                try {
                                    var response = (typeof http === "string") ? JSON.parse(http) : http;

                                    switch (response.status) {
                                        case 200:
                                            system.tables(true);

                                            alert.success(response.message);
                                            break;
                                        case 302:
                                            alert.warning(lang_response_session_false, true);
                                            break;
                                        default:
                                            alert.danger(response.message);
                                    }
                                } catch (e) {
                                    console.error('System.js error:', e);
                                    alert.danger(lang_response_went_wrong);
                                }
                            }
                        });
                    }, true],
                    ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                        instance.hide({
                            transitionOut: "fadeOut"
                        }, toast, "button");
                    }]
                ]
            });
        });

        $(document).on("click", "[system-trash]", function (e) {
            e.preventDefault();

            var type = $(this).attr("system-trash");

            iziToast.question({
                title: lang_js_deletetrash_title,
                message: lang_js_deletetrash_desc,
                titleLineHeight: "25px",
                backgroundColor: "#E82753",
                position: "center",
                icon: false,
                drag: false,
                timeout: false,
                close: false,
                overlay: true,
                layout: 1,
                zindex: 1024,
                buttons: [
                    ["<button class=\"text-white\">" + lang_btn_confirm + "</button>", function (instance, toast, button, e, inputs) {
                        $.ajax({
                            url: site_url + "/requests/trash/" + type,
                            type: "POST",
                            data: {
                                rows: tableSelected
                            },
                            beforeSend: function () {
                                instance.hide({
                                    transitionOut: "fadeOut"
                                }, toast, "button");

                                system.loader(lang_requests_deleteitem_loader);
                            },
                            success: function (http) {
                                system.loader(false, false);
                                $("[system-trash]").attr("disabled", true);

                                try {
                                    var response = (typeof http === "string") ? JSON.parse(http) : http;

                                    switch (response.status) {
                                        case 200:
                                            tableSelected = [];
                                            system.tables(true);
                                            alert.success(response.message);
                                            break;
                                        default:
                                            alert.danger(response.message);
                                    }
                                } catch (e) {
                                    console.error('System.js error:', e);
                                    alert.danger(lang_response_went_wrong);
                                }
                            }
                        });
                    }, true],
                    ["<button class=\"text-white\">" + lang_btn_cancel + "</button>", function (instance, toast, button, e) {
                        instance.hide({
                            transitionOut: "fadeOut"
                        }, toast, "button");
                    }]
                ]
            });
        });
    },

    charts: (chart, update = false) => {
        if (update) {
            parent.jQuery.get(parent.site_url + "/requests/chart/" + chart, function (http) {
                try {
                    var response = (typeof http === "string") ? JSON.parse(http) : http;

                    apex.updateSeries(response.data.vars.series);
                } catch {
                    // Ignore
                }
            });
        } else {
            parent.jQuery.get(parent.site_url + "/requests/chart/" + chart, function (http) {
                try {
                    var response = (typeof http === "string") ? JSON.parse(http) : http;

                    window.apex = new ApexCharts(
                        document.querySelector("#chart"), {
                        chart: {
                            type: "area",
                            height: 300,
                            foreColor: "#999",
                            stacked: true,
                            dropShadow: {
                                enabled: true,
                                enabledSeries: [0],
                                top: -2,
                                left: 2,
                                blur: 5,
                                opacity: 0.06
                            },
                            toolbar: {
                                show: true,
                                tools: {
                                    download: false
                                }
                            },
                            locales: [{
                                "name": "en",
                                "options": {
                                    "months": [parent.lang_apexcharts_locale_monthjanuary, parent.lang_apexcharts_locale_monthfebruary, parent.lang_apexcharts_locale_monthmarch, parent.lang_apexcharts_locale_monthapril, parent.lang_apexcharts_locale_monthmay, parent.lang_apexcharts_locale_monthjune, parent.lang_apexcharts_locale_monthjuly, parent.lang_apexcharts_locale_monthaugust, parent.lang_apexcharts_locale_monthseptember, parent.lang_apexcharts_locale_monthoctober, parent.lang_apexcharts_locale_monthnovember, parent.lang_apexcharts_locale_monthdecember],
                                    "shortMonths": [parent.lang_apexcharts_locale_monthjanshort, parent.lang_apexcharts_locale_monthfebshort, parent.lang_apexcharts_locale_monthmarshort, parent.lang_apexcharts_locale_monthaprshort, parent.lang_apexcharts_locale_monthmayshort, parent.lang_apexcharts_locale_monthjunshort, parent.lang_apexcharts_locale_monthjulshort, parent.lang_apexcharts_locale_monthaugshort, parent.lang_apexcharts_locale_monthsepshort, parent.lang_apexcharts_locale_monthoctshort, parent.lang_apexcharts_locale_monthnovshort, parent.lang_apexcharts_locale_monthdecshort],
                                    "days": [parent.lang_apexcharts_locale_daysunday, parent.lang_apexcharts_locale_daymonday, parent.lang_apexcharts_locale_daytuesday, parent.lang_apexcharts_locale_daywednesday, parent.lang_apexcharts_locale_daythursday, parent.lang_apexcharts_locale_dayfriday, parent.lang_apexcharts_locale_daysaturday],
                                    "shortDays": [parent.lang_apexcharts_locale_daysundayshort, parent.lang_apexcharts_locale_daymondayshort, parent.lang_apexcharts_locale_daytuesdayshort, parent.lang_apexcharts_locale_daywednesdayshort, parent.lang_apexcharts_locale_daythursdayshort, parent.lang_apexcharts_locale_dayfridayshort, parent.lang_apexcharts_locale_daysaturdayshort],
                                    "toolbar": {
                                        "exportToSVG": parent.lang_apexcharts_locale_exportsvg,
                                        "exportToPNG": parent.lang_apexcharts_locale_exportpng,
                                        "menu": parent.lang_apexcharts_locale_menu,
                                        "selection": parent.lang_apexcharts_locale_selection,
                                        "selectionZoom": parent.lang_apexcharts_locale_selectionzoom,
                                        "zoomIn": parent.lang_apexcharts_locale_zin,
                                        "zoomOut": parent.lang_apexcharts_locale_zout,
                                        "pan": parent.lang_apexcharts_locale_pan,
                                        "reset": parent.lang_apexcharts_locale_reset
                                    }
                                }
                            }],
                            defaultLocale: "en"
                        },
                        colors: response.data.vars.colors,
                        stroke: {
                            curve: "smooth",
                            width: 4
                        },
                        dataLabels: {
                            enabled: false
                        },
                        series: response.data.vars.series,
                        markers: {
                            size: 0,
                            strokeColor: "#fff",
                            strokeWidth: 3,
                            strokeOpacity: 1,
                            fillOpacity: 1,
                            hover: {
                                size: 6
                            }
                        },
                        xaxis: {
                            type: "datetime",
                            axisBorder: {
                                show: false
                            },
                            axisTicks: {
                                show: false
                            },
                            labels: {
                                datetimeUTC: false
                            }
                        },
                        yaxis: {
                            labels: {
                                offsetX: -10,
                                offsetY: 0
                            },
                            tooltip: {
                                enabled: true
                            }
                        },
                        grid: {
                            padding: {
                                left: -5,
                                right: 5
                            }
                        },
                        tooltip: {
                            x: {
                                format: "MMMM dd, yyyy"
                            }
                        },
                        legend: {
                            position: "top",
                            horizontalAlign: "left"
                        },
                        fill: {
                            type: "gradient",
                            gradient: {
                                shadeIntensity: 1,
                                opacityFrom: 0.7,
                                opacityTo: 0.9,
                                stops: [0, 90, 100]
                            }
                        },
                    });

                    apex.render();
                } catch {
                    // Ignore
                }
            });
        }
    },

    tables: (reload = false) => {
        let table = $("[system-table]").attr("system-table");
        let tableEndpoint = `${site_url}/table/${table}`;

        if ($("[system-plugin-directory]").length) {
            var pluginDirectory = $("[system-plugin-directory]").attr("system-plugin-directory");
            tableEndpoint = `${site_url}/plugin?name=${pluginDirectory}&action=tables&table=${table}&json=true`;
        }

        if (reload) {
            if ($("[system-table]").length && typeof zenderTable !== "undefined") {
                zenderTable.ajax.reload(null, false);
            }
        } else {
            $.post(tableEndpoint, {
                structure: true
            }, function (http) {
                try {
                    var response = (typeof http === "string") ? JSON.parse(http) : http;

                    window.tableSelected = [];

                    system.clipboard(true);

                    if ($("[system-trash]").length) {
                        $("[system-trash]").attr("disabled", true);
                    }

                    if ($("[system-reorder]").length) {
                        $("[system-reorder]").attr("disabled", true);
                    }

                    if ($.fn.DataTable.isDataTable("[system-table]")) {
                        $("[system-table]").DataTable().destroy();
                    }

                    if (response.data.export) {
                        var tableDom = "l<\"dtrangesearch\">fBrtip";
                    } else {
                        var tableDom = "l<\"dtrangesearch\">frtip";
                    }

                    switch (response.status) {
                        case 200:
                            window.zenderTable = $("[system-table]").DataTable({
                                processing: true,
                                serverSide: true,
                                ajax: {
                                    url: tableEndpoint,
                                    type: "POST",
                                    data: (d) => {
                                        if ($("[system-datepicker]").length && response.data.history) {
                                            d.history_date = $("[system-datepicker]").val();
                                            d.history_column = response.data.history.column;
                                        }
                                    }
                                },
                                responsive: true,
                                dom: tableDom,
                                searching: response.data.search.status,
                                lengthMenu: [
                                    parseInt(response.data.limit),
                                    parseInt(response.data.limit) + 10,
                                    parseInt(response.data.limit) + 25,
                                    parseInt(response.data.limit) + 50,
                                    parseInt(response.data.limit) + 75,
                                    parseInt(response.data.limit) + 100
                                ],
                                pageLength: parseInt(response.data.limit),
                                sPaginationType: "simple_numbers",
                                oLanguage: {
                                    sSearch: `<i class="la la-search la-lg" title="${response.data.search.text}"></i>`,
                                    sSearchPlaceholder: response.data.search.placeholder
                                },
                                aaSorting: [
                                    [0, "desc"]
                                ],
                                columns: response.data.columns,
                                language: {
                                    infoPostFix: "",
                                    processing: lang_datatable_processing,
                                    lengthMenu: lang_datatable_length,
                                    info: lang_datatable_info,
                                    infoEmpty: lang_datatable_empty,
                                    infoFiltered: lang_datatable_filtered,
                                    loadingRecords: lang_datatable_loading,
                                    zeroRecords: lang_datatable_zero,
                                    emptyTable: lang_datatable_null,
                                    paginate: {
                                        first: `<i class="la la-angle-double-left la-lg" title="${lang_datatable_first}"></i>`,
                                        previous: `<i class="la la-angle-left la-lg" title="${lang_datatable_prev}"></i>`,
                                        next: `<i class="la la-angle-right la-lg" title="${lang_datatable_next}"></i>`,
                                        last: `<i class="la la-angle-double-right la-lg" title="${lang_datatable_last}"></i>`
                                    },
                                    buttons: {
                                        copyTitle: lang_datatable_addedtoclipboard,
                                        copyKeys: lang_datatable_addedtoclipboardkeys,
                                        copySuccess: {
                                            _: lang_datatable_addedtoclipboardmulti + " %d",
                                            1: lang_datatable_addedtoclipboardsingle
                                        }
                                    },
                                    processing: `<div class="loadingio-spinner-magnify-tjzjco4pgun">
                                        <div class="ldio-kqy42eczvc9">
                                            <div>
                                                <div>
                                                    <div></div>
                                                    <div></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    `
                                },
                                buttons: [{
                                    extend: "copy",
                                    className: "btn-sm btn-primary",
                                    text: `<i class="la la-copy la-lg" title="${lang_js_tablesexport_copy}"></i>`,
                                    exportOptions: {
                                        columns: response.data.export.export_columns,
                                        format: {
                                            body: function (data, row, column, node) {
                                                return $(node).find("[export-data]").attr("export-data") ? $(node).find("[export-data]").attr("export-data").replace(/[\r\n]+/g, " ") : $(node).text().trim().replace(/[\r\n]+/g, " ");
                                            }
                                        }
                                    },
                                    title: response.data.export.copy_title
                                }, {
                                    extend: "excel",
                                    className: "btn-sm btn-primary",
                                    text: `<i class="la la-table la-lg" title="${lang_js_tablesexport_excel}"></i>`,
                                    exportOptions: {
                                        columns: response.data.export.export_columns,
                                        format: {
                                            body: function (data, row, column, node) {
                                                return $(node).find("[export-data]").attr("export-data") ? $(node).find("[export-data]").attr("export-data") : $(node).text().trim();
                                            }
                                        }
                                    },
                                    title: response.data.export.excel_filename
                                }, {
                                    extend: "pdf",
                                    className: "btn-sm btn-primary",
                                    text: `<i class="la la-file la-lg" title="${lang_js_tablesexport_pdf}"></i>`,
                                    exportOptions: {
                                        columns: response.data.export.export_columns,
                                        format: {
                                            body: function (data, row, column, node) {
                                                return $(node).find("[export-data]").attr("export-data") ? $(node).find("[export-data]").attr("export-data") : $(node).text().trim();
                                            }
                                        }
                                    },
                                    title: response.data.export.pdf_filename
                                }],
                                createdRow: (row, data, index) => {
                                    if (response.data.multiselect) {
                                        if (tableSelected.includes(data.id)) {
                                            $(row).removeClass("dtrow-selected");
                                            $(row).addClass("dtrow-selected");
                                        }
                                    }
                                },
                                preDrawCallback: (settings) => {
                                    tableLoading = true;
                                },
                                drawCallback: (settings) => {
                                    tableLoading = false;
                                    system.tooltips();
                                    system.clipboard();
                                }
                            });

                            if ($("[system-table]").length) {
                                zenderTable.on("error.dt", () => {
                                    // Ignore
                                });

                                zenderTable.on("processing.dt", (e, settings, processing) => {
                                    if (processing) {
                                        $("table.dataTable").css("opacity", "0.5");
                                        $("div.dataTables_wrapper div.dataTables_info").css("opacity", "0.5");
                                        $("div.dataTables_wrapper div.dataTables_paginate").css("opacity", "0.5");
                                    } else {
                                        $("table.dataTable").css("opacity", "1");
                                        $("div.dataTables_wrapper div.dataTables_info").css("opacity", "1");
                                        $("div.dataTables_wrapper div.dataTables_paginate").css("opacity", "1");
                                    }
                                });
                            }

                            if ($("[system-table]").length && response.data.multiselect) {
                                $("[system-table] tbody").on("click", "tr", function (e) {
                                    var targetSelected = e.target.outerHTML.toString();

                                    if (targetSelected.startsWith("<a") || targetSelected.startsWith("<i") || targetSelected.startsWith("<button")) {
                                        return;
                                    }

                                    var rowId = $("[system-table]").DataTable().row(this).data().id;

                                    if ($(this).hasClass("dtrow-selected")) {
                                        const tableSelectedIndex = tableSelected.indexOf(rowId);
                                        if (tableSelectedIndex > -1) {
                                            tableSelected.splice(tableSelectedIndex, 1);
                                        }
                                        $(this).removeClass("dtrow-selected");
                                    } else {
                                        tableSelected.push(rowId);
                                        $(this).addClass("dtrow-selected");
                                    }

                                    if (tableSelected.length < 1) {
                                        $("[system-trash]").attr("disabled", "");
                                    } else {
                                        $("[system-trash]").removeAttr("disabled", "");
                                    }

                                    if (tableSelected.length < 1) {
                                        $("[system-reorder]").attr("disabled", "");
                                    } else {
                                        $("[system-reorder]").removeAttr("disabled", "");
                                    }
                                });
                            }

                            if ($(".dtrangesearch").length && response.data.history) {
                                $(".dtrangesearch").html(`<input type="text" class="form-control form-control-sm" placeholder="Date range" system-datepicker>`);
                                system.datepicker();
                            }

                            if ($("[system-datepicker]").length && response.data.history) {
                                const debouncedDraw = system._debounce(() => {
                                    $("[system-table]").DataTable().draw();
                                }, 300);

                                $("[system-datepicker]").on("input change paste keyup cut select", function () {
                                    debouncedDraw();
                                });
                            }

                            break;
                        default:
                            alert.warning(response.message, true);
                    }
                } catch (e) {
                    console.error('System.js error:', e);
                    alert.danger(lang_response_went_wrong);
                }
            });
        }
    },

    table_navigation: () => {
        if ($("[system-table]").length || $("[system-plugin-table]").length) {
            NProgress.start();

            system.tables();
            system.ripple();
            system.select();
            system.iframe();
            system.tooltips();

            NProgress.done();
        }
    },

    modals: () => {
        $("body").append(
            `<system-modal class="modal fade" data-backdrop="static">
                <div class="modal-dialog" role="document">
                    <system-modal-content></system-modal-content>
                </div>
            </system-modal>`
        ).ready(() => {
            $(document).on("click", "[system-toggle]", function (e) {
                e.preventDefault();
                NProgress.start();

                var loaderModal = $(this).attr("system-loader");

                if (loaderModal)
                    system.loader(loaderModal);

                if ($("[system-reply]").length) {
                    var replyNumber = $(this).attr("system-reply");
                }

                if ($("[wa-link-title]").length) {
                    var linkTitle = $(this).attr("wa-link-title");
                }

                const modalWidget = $(this).attr("system-toggle") ? $(this).attr("system-toggle") : false;
                const pluginDirectory = $(this).attr("system-plugin-directory");

                let modalEndpoint = `${site_url}/widget/modal/${template}/${modalWidget}?_=` + new Date().getTime();

                if (pluginDirectory) {
                    modalEndpoint = `${site_url}/plugin?name=${pluginDirectory}&action=widget&tpl=${modalWidget}&json=true&_=` + new Date().getTime();
                }

                $.get(modalEndpoint, (http) => {
                    try {
                        var modal = (typeof http === "string") ? JSON.parse(http) : http;

                        switch (modal.status) {
                            case 200:
                                if (loaderModal)
                                    system.loader(false, false);

                                $("system-modal .modal-dialog").removeClass("modal-sm modal-md modal-lg modal-xl");
                                $("system-modal .modal-dialog").addClass("modal-dialog-centered");

                                if (modal.data.vars.size) {
                                    $("system-modal .modal-dialog").addClass("modal-" + modal.data.vars.size);
                                } else {
                                    $("system-modal .modal-dialog").addClass("modal-sm");
                                }

                                $("system-modal-content").html(modal.data.tpl);

                                if ($("[system-reply]").length) {
                                    $("input[name=phone]").val(replyNumber);
                                }

                                if ($("#wa_path").length) {
                                    $("#wa_path").on("input", function () {
                                        var pathInputVal = $(this).val();
                                        $("#final_path, #final_path_cron").text(pathInputVal);
                                    });

                                    $("#wa_arch").on("change", function () {
                                        var selectedArch = $(this).val();
                                        $(".final_arch").text(selectedArch);
                                    });
                                }

                                if ($("[system-realtime-driver]").length) {
                                    $("[system-realtime-driver]").on("change", function () {
                                        var compatible = $(this).val() === "compatible";

                                        $("[system-realtime-pusher]").toggle(!compatible);
                                        $("[system-realtime-compatible]").toggle(compatible);
                                    });

                                    // A wrong signature is a silent, permanent 401 -- the event
                                    // simply never arrives. This proves the credentials before
                                    // anyone relies on them.
                                    $("[system-realtime-test]").on("click", function () {
                                        system.loader(lang_js_realtime_testing);

                                        $.post(site_url + "/requests/echo/test", {
                                            driver: $("[system-realtime-driver]").val(),
                                            app_id: $("input[name=realtime_app_id]").val(),
                                            key: $("input[name=realtime_key]").val(),
                                            secret: $("input[name=realtime_secret]").val(),
                                            cluster: $("select[name=realtime_cluster]").val(),
                                            host: $("input[name=realtime_host]").val()
                                        }, function (http) {
                                            system.loader(false, false);

                                            try {
                                                var response = (typeof http === "string") ? JSON.parse(http) : http;

                                                if (response.status == 200) {
                                                    alert.success(response.message);
                                                } else {
                                                    alert.danger(response.message);
                                                }
                                            } catch (e) {
                                                console.error('System.js error:', e);
                                                alert.danger(lang_response_went_wrong);
                                            }
                                        });
                                    });
                                }

                                if ($("[wa-link-title]").length) {
                                    $("[system-wa-link-title]").html(`<i class="la la-whatsapp la-lg"></i> ` + linkTitle);
                                    window.waLinkUrl = $(this).attr("wa-link-url");
                                    window.waRelinkUnique = $(this).attr("relink-unique");
                                }

                                if ($("select[system-wa-server]").length) {
                                    $("select[system-wa-server]").selectpicker();
                                }

                                if ($("[system-wa-type]").length) {
                                    $("[system-wa-type]").change(() => {
                                        try {
                                            var messageType = $("[system-wa-type]").val();
                                            var messageContent = $("textarea[name=message]").val();

                                            if (messageType != "media") {
                                                $("[system-wa-audio-hide]").show();
                                                $("textarea[name=message]").attr("placeholder", lang_js_wa_form_audiotype_entermsg);
                                            } else {
                                                var mediaExtension = $("input[name=media_file]").val().split(".")[1];

                                                if (mediaExtension == "mp3" || mediaExtension == "ogg") {
                                                    if (messageContent.length < 1) {
                                                        $("textarea[name=message]").val(lang_js_wa_form_audionotavail_disinput);
                                                    }
                                                    $("[system-wa-audio-hide]").hide();
                                                    $("textarea[name=message]").attr("placeholder", lang_js_wa_form_audionotavail_disinput);
                                                } else {
                                                    $("[system-wa-audio-hide]").show();
                                                    $("textarea[name=message]").attr("placeholder", lang_js_wa_form_audiotype_entermsg);
                                                }
                                            }
                                        } catch {
                                            // Ignore
                                        }
                                    });

                                    $("input[name=media_file]").change(() => {
                                        try {
                                            var mediaExtension = $("input[name=media_file]").val().split(".")[1];
                                            var messageContent = $("textarea[name=message]").val();

                                            if (mediaExtension == "mp3" || mediaExtension == "ogg") {
                                                if (messageContent.length < 1) {
                                                    $("textarea[name=message]").val(lang_js_wa_form_audionotavail_disinput);
                                                }
                                                $("[system-wa-audio-hide]").hide();
                                                $("textarea[name=message]").attr("placeholder", lang_js_wa_form_audionotavail_disinput);
                                            } else {
                                                $("[system-wa-audio-hide]").show();
                                                $("textarea[name=message]").attr("placeholder", lang_js_wa_form_audiotype_entermsg);
                                            }
                                        } catch {
                                            // Ignore
                                        }
                                    });
                                }

                                if ($("[system-autoreply-type]").length) {
                                    var autoreplyType = $("[system-autoreply-type]").val();

                                    if (autoreplyType == "5") {
                                        $("[system-autoreply-triggers]").hide();
                                    } else {
                                        $("[system-autoreply-triggers]").show();
                                    }

                                    if (autoreplyType == "6") {
                                        $("[system-autoreply-ai]").show();
                                        $("[system-wa-audio-hide]").hide();
                                        $("[system-autoreply-triggers]").hide();
                                        $("[system-ai-hide]").hide();
                                    } else {
                                        $("[system-autoreply-ai]").hide();
                                        $("[system-wa-audio-hide]").show();
                                        $("[system-autoreply-triggers]").show();
                                        $("[system-ai-hide]").show();
                                    }

                                    $("[system-autoreply-type]").change(() => {
                                        try {
                                            autoreplyType = $("[system-autoreply-type]").val();

                                            if (autoreplyType == "6") {
                                                $("[system-autoreply-ai]").show();
                                                $("[system-wa-audio-hide]").hide();
                                                $("[system-autoreply-triggers]").hide();
                                                $("[system-ai-hide]").hide();
                                            } else {
                                                $("[system-autoreply-ai]").hide();
                                                $("[system-wa-audio-hide]").show();
                                                $("[system-ai-hide]").show();

                                                if (autoreplyType == "5") {
                                                    $("[system-autoreply-triggers]").hide();
                                                } else {
                                                    $("[system-autoreply-triggers]").show();
                                                }
                                            }
                                        } catch {
                                            // Ignore
                                        }
                                    });
                                }

                                if ($("[system-ai-provider]").length) {
                                    var aiProvider = $("[system-ai-provider]").val();

                                    if (aiProvider == "openai") {
                                        $("[system-models-openai]").show();
                                        $("[system-transcription-openai]").show();
                                        $("[system-models-geminiai]").hide();
                                        $("[system-models-claudeai]").hide();
                                        $("[system-models-deepseekai]").hide();
                                        $("[system-models-grokai]").hide();
                                        $("[system-vision-ai]").show();
                                    }

                                    if (aiProvider == "geminiai") {
                                        $("[system-models-openai]").hide();
                                        $("[system-transcription-openai]").hide();
                                        $("[system-models-geminiai]").show();
                                        $("[system-models-claudeai]").hide();
                                        $("[system-models-deepseekai]").hide();
                                        $("[system-models-grokai]").hide();
                                        $("[system-vision-ai]").show();
                                    }

                                    if (aiProvider == "claudeai") {
                                        $("[system-models-openai]").hide();
                                        $("[system-transcription-openai]").hide();
                                        $("[system-models-geminiai]").hide();
                                        $("[system-models-claudeai]").show();
                                        $("[system-models-deepseekai]").hide();
                                        $("[system-models-grokai]").hide();
                                        $("[system-vision-ai]").show();
                                    }

                                    if (aiProvider == "grokai") {
                                        $("[system-models-openai]").hide();
                                        $("[system-transcription-openai]").hide();
                                        $("[system-models-geminiai]").hide();
                                        $("[system-models-claudeai]").hide();
                                        $("[system-models-deepseekai]").hide();
                                        $("[system-models-grokai]").show();
                                        $("[system-vision-ai]").hide();
                                    }

                                    if (aiProvider == "deepseekai") {
                                        $("[system-models-openai]").hide();
                                        $("[system-transcription-openai]").hide();
                                        $("[system-models-geminiai]").hide();
                                        $("[system-models-claudeai]").hide();
                                        $("[system-models-deepseekai]").show();
                                        $("[system-models-grokai]").hide();
                                        $("[system-vision-ai]").hide();
                                    }

                                    $("[system-ai-provider]").change(() => {
                                        try {
                                            aiProvider = $("[system-ai-provider]").val();

                                            if (aiProvider == "openai") {
                                                $("[system-models-openai]").show();
                                                $("[system-transcription-openai]").show();
                                                $("[system-models-geminiai]").hide();
                                                $("[system-models-claudeai]").hide();
                                                $("[system-models-deepseekai]").hide();
                                                $("[system-models-grokai]").hide();
                                                $("[system-vision-ai]").show();
                                            }

                                            if (aiProvider == "geminiai") {
                                                $("[system-models-openai]").hide();
                                                $("[system-transcription-openai]").hide();
                                                $("[system-models-geminiai]").show();
                                                $("[system-models-claudeai]").hide();
                                                $("[system-models-deepseekai]").hide();
                                                $("[system-models-grokai]").hide();
                                                $("[system-vision-ai]").show();
                                            }

                                            if (aiProvider == "claudeai") {
                                                $("[system-models-openai]").hide();
                                                $("[system-transcription-openai]").hide();
                                                $("[system-models-geminiai]").hide();
                                                $("[system-models-claudeai]").show();
                                                $("[system-models-deepseekai]").hide();
                                                $("[system-models-grokai]").hide();
                                                $("[system-vision-ai]").show();
                                            }

                                            if (aiProvider == "grokai") {
                                                $("[system-models-openai]").hide();
                                                $("[system-transcription-openai]").hide();
                                                $("[system-models-geminiai]").hide();
                                                $("[system-models-claudeai]").hide();
                                                $("[system-models-deepseekai]").hide();
                                                $("[system-models-grokai]").show();
                                                $("[system-vision-ai]").hide();
                                            }

                                            if (aiProvider == "deepseekai") {
                                                $("[system-models-openai]").hide();
                                                $("[system-transcription-openai]").hide();
                                                $("[system-models-geminiai]").hide();
                                                $("[system-models-claudeai]").hide();
                                                $("[system-models-deepseekai]").show();
                                                $("[system-models-grokai]").hide();
                                                $("[system-vision-ai]").hide();
                                            }
                                        } catch {
                                            // Ignore
                                        }
                                    });
                                }

                                if ($("select[name=source]").length) {
                                    $("[system-autoreply-whatsapp]").hide();

                                    try {
                                        var autoreplySource = $("select[name=source]").val();

                                        if (autoreplySource > 1) {
                                            $("[system-autoreply-device]").hide();
                                            $("[system-autoreply-whatsapp]").show();
                                        } else {
                                            $("[system-autoreply-whatsapp]").hide();
                                            $("[system-autoreply-device]").show();
                                        }
                                    } catch {
                                        // Ignore
                                    }

                                    $("select[name=source]").change(() => {
                                        try {
                                            var autoreplySource = $("select[name=source]").val();

                                            if (autoreplySource > 1) {
                                                $("[system-autoreply-device]").hide();
                                                $("[system-autoreply-whatsapp]").show();
                                            } else {
                                                $("[system-autoreply-whatsapp]").hide();
                                                $("[system-autoreply-device]").show();
                                            }
                                        } catch {
                                            // Ignore
                                        }
                                    });
                                }

                                document.dispatchEvent(new CustomEvent("pluginModalEvent"));

                                $("system-modal").modal({
                                    keyboard: false
                                });

                                if (modal.data.vars.iframe)
                                    system.iframe();

                                system.whatsapp();
                                system.download();
                                system.duration();
                                system.ripple();
                                system.tooltips();
                                system.select();

                                if (modal.data.vars.type) {
                                    system.autocomplete();
                                    system.datepicker();
                                    system.codeflask();
                                    system.counter();

                                    $("[system-form]").on("submit", function (e) {
                                        e.preventDefault();

                                        var data = new FormData(this);

                                        if (modal.data.vars.id)
                                            data.append("id", modal.data.vars.id);

                                        if (window.codeflask) {
                                            data.append("content", codeflask.getCode());
                                        }

                                        let requestEndpoint = `${site_url}/requests/${modal.data.vars.type}/${modal.data.vars.tpl}`;

                                        if (pluginDirectory) {
                                            requestEndpoint = `${site_url}/plugin?name=${pluginDirectory}&action=request&type=${modal.data.vars.type}&tpl=${modalWidget}&json=true&_=` + new Date().getTime();
                                        }

                                        $.ajax({
                                            url: requestEndpoint,
                                            type: "POST",
                                            data: data,
                                            contentType: false,
                                            processData: false,
                                            beforeSend: () => {
                                                if (modal.data.vars.require) {
                                                    var filter = modal.data.vars.require.split("<=>");
                                                    for (var i = 0; i <= filter.length; i++) {
                                                        if (typeof filter[i] !== "undefined") {
                                                            var values = filter[i].split("|");
                                                        }
                                                        try {
                                                            if (data.get(values[0]).length < 1) {
                                                                alert.warning(values[1] + ", " + lang_validate_cannotemp);
                                                                return false;
                                                            }
                                                        } catch (e) {
                                                            if (data.getAll(values[0] + "[]").length < 1) {
                                                                alert.warning(values[1] + ", " + lang_validate_cannotemp);
                                                                return false;
                                                            }
                                                        }
                                                    }
                                                }

                                                system.disabled();

                                                if (modal.data.vars.loader)
                                                    system.loader(modal.data.vars.loader);
                                            },
                                            success: (http) => {
                                                if (modal.data.vars.loader) {
                                                    setTimeout(() => {
                                                        system.loader(false, false);

                                                        try {
                                                            var response = (typeof http === "string") ? JSON.parse(http) : http;

                                                            switch (response.status) {
                                                                case 200:
                                                                    if (modal.data.vars.table)
                                                                        system.tables(true);

                                                                    $("system-modal").modal("hide");
                                                                    alert.success(response.message);
                                                                    break;
                                                                case 301:
                                                                    $("system-modal").modal("hide");
                                                                    alert.success(response.message, true);
                                                                    break;
                                                                case 302:
                                                                    $("system-modal").modal("hide");
                                                                    alert.warning(lang_response_session_false, true);
                                                                    break;
                                                                case 303:
                                                                    $("system-modal").modal("hide");
                                                                    alert.warning(response.message);

                                                                    setTimeout(() => {
                                                                        system.redirect(response.data);
                                                                    }, 3000);
                                                                    break;
                                                                default:
                                                                    if (modal.data.vars.recaptcha)
                                                                        grecaptcha.reset();

                                                                    alert.danger(response.message);
                                                            }
                                                        } catch (e) {
                                                            console.error('System.js error:', e);
                                                            alert.danger(lang_response_went_wrong);
                                                        }
                                                    }, 1000);
                                                }

                                                system.disabled(false);
                                            }
                                        });
                                    });
                                }

                                break;
                            case 302:
                                alert.warning(lang_response_session_false, true);
                                break;
                            default:
                                alert.danger(modal.message);
                        }
                    } catch (e) {
                        console.error('System.js error:', e);
                        alert.danger(lang_response_went_wrong);
                    }

                    NProgress.done();
                });
            });
        });
    }
}

window.alert = {
    setup: () => {
        iziToast.settings({
            title: lang_alert_attention,
            titleSize: "18px",
            titleLineHeight: "25px",
            messageSize: "17px",
            messageLineHeight: "20px",
            icon: false,
            timeout: 3000,
            animateInside: true,
            titleColor: "#f5f5f5",
            messageColor: "#f5f5f5",
            iconColor: "#f5f5f5",
            transitionIn: "fadeInRight",
            transitionOut: "fadeOutRight",
            position: alert_position,
            displayMode: "replace",
            layout: 2,
            maxWidth: "300px",
            close: false
        });
    },

    primary: (message, reload = false, overlap = false, title = false, notify = false, image = false) => {
        if (overlap) {
            iziToast.info({
                title: !title ? false : title,
                backgroundColor: color_primary,
                displayMode: 0,
                image: !image || image == "0" ? false : image,
                imageWidth: 85,
                timeout: !notify ? 3000 : 8000,
                close: notify ? true : false,
                maxWidth: notify ? "600px" : "300px",
                message: message,
                position: overlap_alert_position,
                onClosed: () => {
                    if (reload)
                        location.reload();
                }
            });
        } else {
            iziToast.info({
                backgroundColor: color_primary,
                message: message,
                onClosed: () => {
                    if (reload)
                        location.reload();
                }
            });
        }
    },

    success: (message, reload = false, overlap = false, title = false, notify = false, image = false) => {
        if (overlap) {
            iziToast.success({
                title: !title ? false : title,
                backgroundColor: "#00c52c",
                displayMode: 0,
                image: !image || image == "0" ? false : image,
                imageWidth: 85,
                timeout: !notify ? 3000 : false,
                close: notify ? true : false,
                maxWidth: notify ? "600px" : "300px",
                message: message,
                position: overlap_alert_position,
                onClosed: () => {
                    if (reload)
                        location.reload();
                }
            });
        } else {
            iziToast.success({
                backgroundColor: "#00c52c",
                message: message,
                onClosed: () => {
                    if (reload)
                        location.reload();
                }
            });
        }
    },

    warning: (message, reload = false, overlap = false, title = false, notify = false, image = false) => {
        if (overlap) {
            iziToast.warning({
                title: !title ? false : title,
                backgroundColor: "#FE9431",
                displayMode: 0,
                image: !image || image == "0" ? false : image,
                imageWidth: 85,
                timeout: !notify ? 3000 : false,
                close: notify ? true : false,
                maxWidth: notify ? "600px" : "300px",
                message: message,
                position: overlap_alert_position,
                onClosed: () => {
                    if (reload)
                        location.reload();
                }
            });
        } else {
            iziToast.warning({
                backgroundColor: "#FE9431",
                message: message,
                onClosed: () => {
                    if (reload)
                        location.reload();
                }
            });
        }
    },

    danger: (message, reload = false, overlap = false, title = false, notify = false, image = false) => {
        if (overlap) {
            iziToast.error({
                title: !title ? false : title,
                backgroundColor: "#E82753",
                displayMode: 0,
                image: !image || image == "0" ? false : image,
                imageWidth: 85,
                timeout: !notify ? 3000 : false,
                close: notify ? true : false,
                maxWidth: notify ? "600px" : "300px",
                message: message,
                position: overlap_alert_position,
                onClosed: () => {
                    if (reload)
                        location.reload();
                }
            });
        } else {
            iziToast.error({
                backgroundColor: "#E82753",
                message: message,
                onClosed: () => {
                    if (reload)
                        location.reload();
                }
            });
        }
    }
}

window.titansys = {
    support: () => {
        $(document).on("click", "[system-support]", function (e) {
            $.get(site_url + "/requests/index/support", function (http) {
                try {
                    var response = (typeof http === "string") ? JSON.parse(http) : http;

                    switch (response.status) {
                        case 200:
                            setTimeout(() => {
                                system.redirect(response.data);
                            }, 1500);
                            break;
                        default:
                            alert.danger(response.message);
                    }
                } catch (e) {
                    console.error('System.js error:', e);
                    alert.danger(lang_response_went_wrong);
                }
            });
        });
    }
}