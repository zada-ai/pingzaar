<div class="modal-content">
    <div class="modal-header">
        <h3 class="modal-title">
            <i class="la la-comments-dollar la-lg"></i> {$title}
        </h3>

        <button type="button" class="close" data-dismiss="modal">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>

    <div class="modal-body">
        <table class="table">
            <thead>
                <tr>
                    <th>{__("and_rate_gate_10")}</th>
                    <th>{__("and_rate_gate_11")} <span class="badge badge-success">{__s("system_currency")}</span></th>
                </tr>
            </thead>
            <tbody>
                {foreach $data.pricing.countries as $country}
                    <tr>
                        <td class="text-uppercase"><i class="flag-icon flag-icon-{$country@key}"></i>
                            {$country@key}</td>
                        <td>{$country}</td>
                    </tr>
                {/foreach}
                <tr>
                    <td class="text-uppercase"><i class="flag-icon flag-icon-un"></i>
                        {__("and_rate_gate_22")}</td>
                    <td>{$data.pricing.default}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>