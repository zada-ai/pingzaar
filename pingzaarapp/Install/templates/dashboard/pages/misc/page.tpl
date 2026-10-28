<div class="main-content" system-wrapper>
    {include "../../modules/header.block.tpl"}

    <div class="header">
        <div class="container">
            <div class="header-body">
                <div class="row align-items-end">
                    <div class="col">
                        <h1 class="header-title">
                            <i class="la la-stream la-lg"></i> {$data.page.name}
                        </h1>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <div class="card-body">
                <div class="pb-3">
                    {$data.content}
                </div>
            </div>
        </div>

        {include "../../modules/footer.block.tpl"}
    </div>
</div>