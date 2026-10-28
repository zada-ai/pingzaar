(function($) {
    "use strict";
    $(function() {
        /**
         * Initial Load
         */

        alert.setup();
        system.select();
        $("form select").selectpicker();

        $("[system-install]").on("submit", function(e) {
            e.preventDefault();

            $.ajax({
                url: `${site_url}/install/request`,
                type: "POST",
                data: new FormData(this),
                contentType: false,
                processData: false,
                beforeSend: () => {
                    system.disabled();
                    system.loader("Installing System");
                },
                success: (http) => {
                    var response = (typeof http === "string") ? JSON.parse(http) : JSON.parse(JSON.stringify(http));

                    try {
                        if (response.status == 200) {
                            $(".card").fadeOut("slow", function() {
                                $(".install-title").remove();
                                $(".install-tagline").remove();

                                $(".card-footer").html(`
                                    <a href="${site_url}" class="btn btn-lg btn-primary lift"><i class="la la-check-circle"></i> Done</a>
                                `);

                                $(".card-body").html(`
                                <div class="text-center">
                                    <div class="alert alert-success pt-4" style="margin: 0 auto; max-width: 500px">
                                        <p>The system has been successfully installed and all residual installation files have been removed.</p>
                                        <p>You can now start using the platform!</p>
                                    </div>
                                </div>
                                `);

                                $(".card").css("margin", "0 auto");
                                $(".card").css("max-width", "500px");
                                $(".card").fadeIn("fast");
                            });
                        } else {
                            alert.danger(response.message);
                        }
                    } catch (e) {
                        alert.danger("Something went wrong!");
                    }

                    system.disabled(false);
                    system.loader(false, false);
                }
            });
        });

        /**
         * Preloader
         */

        $("[system-preloader]").fadeOut("slow", () => {
            system.ripple();
        });
    });
})(jQuery);