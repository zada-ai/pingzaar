/* NAV drives the sidebar link list. Each page's own header-pretitle/icon is
   baked directly into its markup (matching how the real dashboard renders
   header.tpl blocks server-side, not via JS) using the same grp/icon values
   as here -- this array stays the single place that maps a page to its
   group and icon. Extend it (new pages, re-grouping) rather than replacing
   the rendering logic below. */
var NAV = [
  {grp:"Getting started", items:[
    {t:"Overview",              h:"index.html",                    icon:"la-book"},
    {t:"Requirements",          h:"pages/requirements.html",       icon:"la-list-ul"},
    {t:"Installation",          h:"pages/installation.html",       icon:"la-download"},
    {t:"Configuration",         h:"pages/configuration.html",      icon:"la-cogs"}
  ]},
  {grp:"Messaging", items:[
    {t:"Sending SMS",           h:"pages/sms.html",                icon:"la-comment"},
    {t:"Sending WhatsApp",      h:"pages/whatsapp.html",           icon:"la-whatsapp"},
    {t:"Contacts",              h:"pages/contacts.html",           icon:"la-address-book"},
    {t:"Campaigns",             h:"pages/campaigns.html",          icon:"la-bullhorn"}
  ]},
  {grp:"Servers & devices", items:[
    {t:"WhatsApp server",       h:"pages/whatsapp-server.html",    icon:"la-server"},
    {t:"Android gateway",       h:"pages/android-gateway.html",    icon:"la-android"},
    {t:"Cron jobs",             h:"pages/cron.html",               icon:"la-clock"},
    {t:"Realtime",h:"pages/realtime.html",                         icon:"la-bolt"}
  ]},
  {grp:"Automation & API", items:[
    {t:"Flow builder",          h:"pages/flow-builder.html",       icon:"la-project-diagram"},
    {t:"AI features",           h:"pages/ai.html",                 icon:"la-robot"},
    {t:"REST API",              h:"pages/api.html",                icon:"la-code"},
    {t:"Webhooks",              h:"pages/webhooks.html",           icon:"la-code-branch"}
  ]},
  {grp:"Administration", items:[
    {t:"Users & roles",         h:"pages/admin-users.html",        icon:"la-users"},
    {t:"Billing",               h:"pages/admin-billing.html",      icon:"la-money-bill-wave"},
    {t:"System settings",       h:"pages/admin-system.html",       icon:"la-sliders-h"},
    {t:"Plugins",               h:"pages/plugins.html",            icon:"la-plug"}
  ]},
  {grp:"Maintenance", items:[
    {t:"Updating",              h:"pages/updating.html",           icon:"la-sync"},
    {t:"Troubleshooting",       h:"pages/troubleshooting.html",    icon:"la-life-ring"}
  ]}
];

(function(){
  function esc(t){
    return String(t).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  var inPages = /\/pages\//.test(location.pathname);
  var base = inPages ? "../" : "";
  var here = location.pathname.split("/").slice(-2).join("/");
  var THEME_KEY = "zender-docs-theme";

  /* ---------- sidebar nav + search ----------
     Emits the same navbar-heading / navbar-nav / nav-item / nav-link markup
     the real dashboard sidebar (sidebar.block.tpl) uses, so it's styled
     entirely by the vendored bootstrap.min.css -- no bespoke nav CSS. */

  function render(filter){
    var out = "";
    var any = false;
    NAV.forEach(function(g){
      var items = g.items.filter(function(i){
        return !filter || i.t.toLowerCase().indexOf(filter) !== -1;
      });
      if(!items.length) return;
      any = true;
      out += '<h6 class="navbar-heading">' + g.grp + '</h6><ul class="navbar-nav">';
      items.forEach(function(i){
        var active = here.indexOf(i.h.split("/").pop()) !== -1 ? " active" : "";
        out += '<li class="nav-item"><a class="nav-link' + active + '" href="' + base + i.h + '">' +
               '<i class="la ' + i.icon + ' la-lg"></i> ' + i.t + '</a></li>';
      });
      out += '</ul>';
    });
    if(!any) out = '<div class="no-results">No pages match &ldquo;' + esc(filter) + '&rdquo;</div>';
    document.getElementById("navlist").innerHTML = out;
  }

  /* ---------- "On this page" TOC, built from the page's own h2/h3 ----------
     A docs-only affordance the dashboard shell has no equivalent for, so it
     stays JS-built like before -- just targets the static #doc-toc-col grid
     column instead of the old flex layout. */

  function slugify(text, i){
    var s = text.trim().toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/(^-+|-+$)/g, "");
    return "sec-" + i + (s ? "-" + s : "");
  }

  function buildTOC(){
    var main = document.querySelector(".doc-main");
    var tocCol = document.getElementById("doc-toc-col");
    if(!main || !tocCol) return;
    var contentCol = tocCol.previousElementSibling;
    var headers = main.querySelectorAll("h2, h3");

    if(!headers.length){
      tocCol.parentNode.removeChild(tocCol);
      if(contentCol){ contentCol.classList.remove("col-lg-9"); contentCol.classList.add("col-lg-12"); }
      return;
    }

    var toc = document.createElement("div");
    toc.className = "doc-toc";

    var title = document.createElement("div");
    title.className = "doc-toc-title";
    title.textContent = "On this page";
    toc.appendChild(title);

    var list = document.createElement("ul");
    var links = [];
    headers.forEach(function(h, i){
      if(!h.id) h.id = slugify(h.textContent, i);
      var li = document.createElement("li");
      li.className = "toc-" + h.tagName.toLowerCase();
      var a = document.createElement("a");
      a.href = "#" + h.id;
      a.textContent = h.textContent;
      li.appendChild(a);
      list.appendChild(li);
      links.push({heading: h, link: a});
    });
    toc.appendChild(list);
    tocCol.appendChild(toc);

    if("IntersectionObserver" in window){
      var observer = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
          var match = links.filter(function(l){ return l.heading === entry.target; })[0];
          if(!match) return;
          if(entry.isIntersecting) match.link.classList.add("active");
          else match.link.classList.remove("active");
        });
      }, {rootMargin: "-10% 0px -70% 0px"});
      links.forEach(function(l){ observer.observe(l.heading); });
    }
  }

  /* ---------- mobile sidebar collapse ----------
     No bootstrap.bundle.js is vendored (CSS only), so the collapse plugin's
     show/hide toggle is reimplemented here in a few lines: bootstrap.min.css
     already ships `.collapse:not(.show){display:none}`, this just flips the
     class the toggler button targets. */

  function initSidebarToggle(){
    var btn = document.querySelector(".navbar-toggler");
    var target = document.getElementById("sidebarCollapse");
    if(!btn || !target) return;
    btn.addEventListener("click", function(){
      var showing = target.classList.toggle("show");
      btn.setAttribute("aria-expanded", showing ? "true" : "false");
    });
  }

  /* ---------- light/dark toggle ----------
     docs.css already switches on prefers-color-scheme via a media-gated
     @import, so the page is correctly themed even with JS disabled. This
     toggle (a static button baked into each page's header, see
     .doc-theme-toggle) is a progressive enhancement on top: it force-loads
     the matching Bootstrap bundle (disabled by default, so it costs nothing
     until used) and flips [data-theme] on <html>. The chosen theme is
     remembered in localStorage. The sidebar itself stays permanently dark
     (navbar-dark navbar-vibrant), matching the real dashboard -- only the
     main content theme toggles. */

  function injectForceStylesheets(){
    if(document.getElementById("bs-force-light")) return;
    [["bs-force-light", "bootstrap.min.css"], ["bs-force-dark", "bootstrap.dark.min.css"]].forEach(function(pair){
      var link = document.createElement("link");
      link.rel = "stylesheet";
      link.id = pair[0];
      link.href = base + "assets/vendor/css/libs/" + pair[1];
      link.disabled = true;
      document.head.appendChild(link);
    });
  }

  function applyTheme(mode){
    var light = document.getElementById("bs-force-light");
    var dark = document.getElementById("bs-force-dark");
    if(mode === "dark"){
      if(dark) dark.disabled = false;
      if(light) light.disabled = true;
      document.documentElement.setAttribute("data-theme", "dark");
    } else if(mode === "light"){
      if(light) light.disabled = false;
      if(dark) dark.disabled = true;
      document.documentElement.setAttribute("data-theme", "light");
    } else {
      if(light) light.disabled = true;
      if(dark) dark.disabled = true;
      document.documentElement.removeAttribute("data-theme");
    }
  }

  function effectiveTheme(){
    var stored = null;
    try { stored = localStorage.getItem(THEME_KEY); } catch(e) {}
    if(stored === "light" || stored === "dark") return stored;
    return (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) ? "dark" : "light";
  }

  function initTheme(){
    injectForceStylesheets();
    var stored = null;
    try { stored = localStorage.getItem(THEME_KEY); } catch(e) {}
    if(stored === "light" || stored === "dark") applyTheme(stored);
  }

  function initThemeToggle(){
    var btn = document.querySelector(".doc-theme-toggle");
    if(!btn) return;
    btn.addEventListener("click", function(){
      var next = effectiveTheme() === "dark" ? "light" : "dark";
      try { localStorage.setItem(THEME_KEY, next); } catch(e) {}
      applyTheme(next);
    });
  }

  /* Run stylesheet setup as early as possible (script executes at the end
     of body, so document.head already exists) to avoid any visible flash
     for visitors with a stored preference. */
  initTheme();

  document.addEventListener("DOMContentLoaded", function(){
    render("");
    initSidebarToggle();
    initThemeToggle();
    buildTOC();

    var box = document.getElementById("navsearch");
    if(box) box.addEventListener("input", function(){
      render(this.value.trim().toLowerCase());
    });
  });
})();
