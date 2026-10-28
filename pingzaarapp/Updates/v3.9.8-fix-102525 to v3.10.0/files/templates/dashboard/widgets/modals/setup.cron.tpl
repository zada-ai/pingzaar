<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-broom la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="alert alert-info d-flex mt-3 mb-0">
                <i class="la la-info-circle la-lg mr-2 my-auto"></i> This is not translated because it is only for setup purposes.
            </div>

            <h2 class="mt-4">Quota Cleaner <span class="badge badge-primary mt-2 mt-lg-0">Last Run: {$data.cron.quota}</span></h2>
            <p class="mt-3">Cron Command:</p>
            <div class="bg-dark text-white p-3 pt-0 overflow-auto text-nowrap rounded">
                <p class="m-0">
                curl -m 180 -s "{site_url("cron/quota/{__s("system_token")}", true)}" > /dev/null 2>&1
                </p>
            </div>
            <p class="mt-3">Cron Settings:</p>
            <div class="alert alert-primary d-flex mt-3 mb-0">
                <i class="la la-info-circle la-lg mr-2 my-auto"></i> 
                This cron settings depends on the Package Reset Mode you choose in the system settings.<br>
                You choosed {if __s("system_reset_mode") < 2}Daily{else}Monthly{/if} reset mode.
            </div>
            <div class="mt-3">
                <img src="{site_url}/uploads/system/cron/{if __s("system_reset_mode") < 2}quota_daily{else}quota_monthly{/if}.png" class="img-fluid" alt="Cron Job">
            </div>
            <h2 class="mt-4">Sender <span class="badge badge-primary mt-2 mt-lg-0">Last Run: {$data.cron.sender}</span></h2>
            <p class="mt-3">Cron Command:</p>
            <div class="bg-dark text-white p-3 pt-0 overflow-auto text-nowrap rounded">
                <p class="m-0">
                curl -m 180 -s "{site_url("cron/sender/{__s("system_token")}", true)}" > /dev/null 2>&1
                </p>
            </div>
            <p class="mt-3">Cron Settings:</p>
            <div class="mt-3">
                <img src="{site_url}/uploads/system/cron/sender.png" class="img-fluid" alt="Cron Job">
            </div>
            <h2 class="mt-4">SMS Scheduled <span class="badge badge-primary mt-2 mt-lg-0">Last Run: {$data.cron.sms_scheduled}</span></h2>
            <p class="mt-3">Cron Command:</p>
            <div class="bg-dark text-white p-3 pt-0 overflow-auto text-nowrap rounded">
                <p class="m-0">
                curl -m 300 -s "{site_url("cron/sms.scheduled/{__s("system_token")}", true)}" > /dev/null 2>&1
                </p>
            </div>
            <p class="mt-3">Cron Settings:</p>
            <div class="mt-3">
                <img src="{site_url}/uploads/system/cron/sms_scheduled.png" class="img-fluid" alt="Cron Job">
            </div>
            <h2 class="mt-4">WhatsApp Scheduled <span class="badge badge-primary mt-2 mt-lg-0">Last Run: {$data.cron.wa_scheduled}</span></h2>
            <p class="mt-3">Cron Command:</p>
            <div class="bg-dark text-white p-3 pt-0 overflow-auto text-nowrap rounded">
                <p class="m-0">
                curl -m 300 -s "{site_url("cron/wa.scheduled/{__s("system_token")}", true)}" > /dev/null 2>&1
                </p>
            </div>
            <p class="mt-3">Cron Settings:</p>
            <div class="mt-3">
                <img src="{site_url}/uploads/system/cron/wa_scheduled.png" class="img-fluid" alt="Cron Job">
            </div>
            <h2 class="mt-4">Subscription Checker <span class="badge badge-primary mt-2 mt-lg-0">Last Run: {$data.cron.subscription}</span></h2>
            <p class="mt-3">Cron Command:</p>
            <div class="bg-dark text-white p-3 pt-0 overflow-auto text-nowrap rounded">
                <p class="m-0">
                curl -m 300 -s "{site_url("cron/subscription/{__s("system_token")}", true)}" > /dev/null 2>&1
                </p>
            </div>
            <p class="mt-3">Cron Settings:</p>
            <div class="mt-3">
                <img src="{site_url}/uploads/system/cron/subscription_checker.png" class="img-fluid" alt="Cron Job">
            </div>
            <h2 class="mt-4">Garbage Cleaner <span class="badge badge-primary mt-2 mt-lg-0">Last Run: {$data.cron.cleaner}</span></h2>
            <p class="mt-3">Cron Command:</p>
            <div class="bg-dark text-white p-3 pt-0 overflow-auto text-nowrap rounded">
                <p class="m-0">
                curl -m 300 -s "{site_url("cron/cleaner/{__s("system_token")}", true)}" > /dev/null 2>&1
                </p>
            </div>
            <p class="mt-3">Cron Settings:</p>
            <div class="mt-3">
                <img src="{site_url}/uploads/system/cron/cleaner.png" class="img-fluid" alt="Cron Job">
            </div>
        </div>
    </div>
</form>