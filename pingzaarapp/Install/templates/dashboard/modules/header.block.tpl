<nav class="navbar navbar-expand-md navbar-light d-none d-md-flex" id="topbar">
    <div class="container">
        <div class="me-4">
            {if __s("system_freemodel") < 2}
            <a class="btn btn-md btn-primary mb-1 lift" href="#" system-toggle="user.subscription">
                <i class="la la-crown la-lg me-1"></i> {__("dashboard_nav_menusubscription")}
            </a>
            {else}
                {if !empty($data.package)}
                    <a class="btn btn-md btn-primary mb-1 lift" href="#" system-toggle="user.subscription">
                        <i class="la la-crown la-lg me-1"></i> {__("dashboard_nav_menusubscription")}
                    </a>
                {/if}
            {/if}

            <a class="btn btn-md btn-primary mb-1 lift" href="{site_url("dashboard/misc/packages")}" system-nav>
                <i class="la la-cubes la-lg me-1"></i> {__("btn_packages")}
            </a>

            <a class="btn btn-md btn-primary mb-1 lift" href="#" system-toggle="redeem">
                <i class="la la-ticket la-lg me-1"></i> {__("btn_redeem")}
            </a>
        </div>

        <div class="navbar-user" system-usernav>
            <div class="dropdown">
                <a href="#" class="avatar avatar-sm avatar-online dropdown-toggle" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <img src="{__s("logged_avatar")}" class="avatar-img rounded-circle">
                </a>

                <div class="dropdown-menu dropdown-menu-end dropdown-menu-right">
                    <a href="#" class="dropdown-item" system-toggle="user.settings">
                        <i class="la la-user-cog"></i> {__("dashboard_nav_menusettings")}
                    </a>

                    {if __s("impersonate")}
                    <a href="#" class="dropdown-item" auth-type="exit" system-action="impersonate">
                        <i class="la la-times-circle"></i> {__("impersonate_exit_btn")}
                    </a>
                    {else}
                    <a href="#" class="dropdown-item" system-action="logout">
                        <i class="la la-sign-out"></i> {__("dashboard_nav_menulogout")}
                    </a>
                    {/if}
                </div>
            </div>
        </div>
    </div>
</nav>