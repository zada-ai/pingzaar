<header id="home" class="wrapper bg-soft-primary">
  <nav class="navbar navbar-expand-lg classic transparent position-absolute navbar-dark">
    <div class="container flex-lg-row flex-nowrap align-items-center">
      <div class="navbar-brand w-100">
        <a href="{site_url(false, true)}" system-nav>
          <img class="logo-light" src="{get_image("logo_light")}">
          <img class="logo-dark" src="{get_image("logo_dark")}">
        </a>
      </div>
      
      <div class="navbar-collapse offcanvas-nav">
        <div class="offcanvas-header d-lg-none d-xl-none">
          <a href="{site_url(false, true)}">
            <img src="{get_image("logo_light")}" alt="{__s("system_site_name")}">
          </a>
          <button type="button" class="btn-close btn-close-white offcanvas-close offcanvas-nav-close" aria-label="Close"></button>
        </div>

        <ul class="navbar-nav">
          <li class="nav-item">
            <a class="nav-link scroll" href="#home">{__("and_head_blck_21")}</a>
          </li>
          <li class="nav-item">
            <a class="nav-link scroll" href="#features">{__("and_head_blck_24")}</a>
          </li>
          <li class="nav-item">
            <a class="nav-link scroll" href="#pricing">{__("table_admingateways_pricingtitle")}</a>
          </li>
          <li class="nav-item">
            <a class="nav-link scroll" href="#clients">{__("and_head_blck_30")}</a>
          </li> 
        </ul>
      </div>

      <div class="navbar-other ms-lg-4">
        <ul class="navbar-nav flex-row align-items-center ms-auto">
            {if __s("logged_id")} 
            <li class="nav-item d-md-block">
                <a href="{site_url("dashboard")}" class="btn btn-sm btn-primary text-capitalize">{__("and_head_blck_39")}</a>
            </li> 
            <li class="nav-item d-none d-md-block">
                <button class="btn btn-sm btn-danger text-capitalize" system-action="logout">{__("dashboard_nav_menulogout")}</button>
            </li>
            {else}
            <li class="nav-item d-md-block">
                <a href="{site_url("dashboard/authenticate/login")}" class="btn btn-sm btn-white text-capitalize">{__("landing_nav_login")}</a>
            </li> 
            {/if} 

            <li class="nav-item d-lg-none">
                <div class="navbar-hamburger">
                    <button class="hamburger animate plain" data-toggle="offcanvas-nav">
                        <span></span>
                    </button>
                </div>
            </li>
        </ul>
      </div>
    </div>
  </nav>
</header>