(function($) {
    "use strict";
    $(function() {
        /**
         * Initial Load
         */

        alert.setup();
        system.pages();
        system.ripple();
        system.action();
        system.cookies();

        /**
         * PJAX Handler
         */

        window.pjax = new Pjax({
            scrollTo: false,
            cacheBust: false,
            elements: "[system-nav]",
            selectors: [
                "title",
                "[system-navbar]",
                "[system-wrapper]"
            ]
        });

        document.addEventListener("pjax:send", function() {
            NProgress.start();

            if (typeof _template !== "undefined") {
                _template.hookOnload();
            }
        });

        document.addEventListener("pjax:complete", function() {
            system.ripple();
            NProgress.done();

            if (typeof _template !== "undefined") {
                _template.hookOnloaded();
            }

            if (typeof _customSystem !== "undefined") {
                _customSystem.hookOnloaded();
            }
        });

        $("[system-preloader]").fadeOut("fast", () => {
            system.translate(true);
            system.echo();

            if (typeof _customSystem !== "undefined") {
                _customSystem.hookOnload();
            }
        });
    });
})(jQuery);