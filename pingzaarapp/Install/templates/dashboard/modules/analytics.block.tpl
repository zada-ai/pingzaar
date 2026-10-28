{if !empty(system_analytics_key)}
<script async src="//www.googletagmanager.com/gtag/js?id={__s("system_analytics_key")}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){
        dataLayer.push(arguments);
    }
  gtag("js", new Date());

  gtag("config", "{__s("system_analytics_key")}");
</script>
{/if}