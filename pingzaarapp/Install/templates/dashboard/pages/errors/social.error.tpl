<div class="container">
    <div class="row justify-content-center">
        <div class="col-12 col-md-5 col-xl-4 my-5">
            <div class="text-center">

                <h6 class="text-uppercase text-muted mb-4">
                    {$title}
                </h6>

                <h1 class="display-4 mb-3">
                    {__("page_socialerror_authuhoh")}
                </h1>

                <p class="text-muted mb-4">
                    {$message}
                </p>

                <a href="{site_url("dashboard/auth")}" class="btn btn-primary lift">
                    <i class="la la-arrow-circle-left"></i> {__("page_socialerror_authreturn")}
                </a>
            </div>
        </div>
    </div>
</div>