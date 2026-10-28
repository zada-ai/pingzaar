(function($) {
    "use strict";
    $(function() {
        /**
         * Initial Load
         */

        alert.setup();
        system.active();
        system.modals();
        system.delete();
        system.reorder();
        system.action();
        system.pages();
        system.build();
        system.clear();
        system.plugin();
        system.cookies();
        system.visitors();

        window.tableLoading = false;

        $(window).scroll(() => {
            if ($(this).scrollTop() > 1)
                $(".header").addClass("animated slideInDown fixed");
            else
                $(".header").removeClass("animated slideInDown fixed");
        });

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
                "[system-usernav]",
                "[system-wrapper]"
            ]
        });

        document.addEventListener("pjax:send", function() {
            NProgress.start();
        });

        document.addEventListener("pjax:complete", function() {
            system.active();
            system.ripple();
            system.iframe();
            system.tooltips();
            system.visitors();
            system.table_navigation();
            system.authenticate();
            NProgress.done();

            if (typeof _customSystem !== "undefined") {
                _customSystem.hookOnloaded();
            }
        });

        /**
         * Preloader
         */

        $("[system-preloader]").fadeOut("fast", () => {
            titansys.support();
            system.echo();
            system.tawk();
            system.table_navigation();
            system.ripple();
            system.iframe();
            system.tooltips();
            system.translate();
            system.authenticate();
            system.redocly();

            if (typeof _customSystem !== "undefined") {
                _customSystem.hookOnload();
            }
        });
    });
})(jQuery);