<!DOCTYPE html>
<html lang="en">

<head>
	<link rel="dns-prefetch" href="//fonts.googleapis.com">

	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
	<link rel="icon" href="{get_image("favicon")}">

	<title>Installation &middot; Zender</title>

	<link rel="stylesheet" href="{site_url("templates/dashboard/assets/css/fonts/feather/feather.css")}">
	<link rel="stylesheet" href="{site_url("templates/_assets/css/libs/line-awesome.min.css")}">
	<link rel="stylesheet" href="{site_url("templates/_assets/css/libs/flag-icon.min.css")}">
	<link rel="stylesheet" href="{site_url("templates/dashboard/assets/css/libs/bootstrap.min.css")}" />
	<link rel="stylesheet" href="{site_url("templates/dashboard/assets/css/style.min.css")}" />

	<script>
		window.site_url = "{site_url}";
	</script>
</head>

<body class="d-flex align-items-center bg-dark">
	<div class="container">
		<div class="row justify-content-center">
			<div class="col-12 col-md-9 my-5">
				<h1 class="display-4 text-center text-white mb-3 install-title">
					Installation
				</h1>

				<!-- Subheading -->
				<p class="text-muted text-center mb-5 install-tagline">
					Please fill-up the form below to install Zender
				</p>

				<!-- Form -->
				<form system-install>
					<div class="card">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="row">
										<div class="col-md-12">
											<div class="form-group">
												<h2 class="text-uppercase">System</h2>
											</div>
										</div>

										<div class="col-md-6">
											<div class="form-group">
												<label>
													Site Name
												</label>
												<input type="text" name="site_name" class="form-control" placeholder="eg. Zender">
											</div>
										</div>

										<div class="col-md-6">
											<div class="form-group">
												<label>
													Protocol
												</label>
												<select name="protocol" class="form-control">
													<option value="1" selected>HTTP</option>
													<option value="2">HTTPS</option>
												</select>
											</div>
										</div>

										<div class="col-md-12">
											<div class="form-group">
												<label>
													Site Description
												</label>
												<input type="text" name="site_desc" class="form-control" placeholder="eg. This is my marketing platform">
											</div>
										</div>

										<div class="col-md-12">
											<div class="form-group">
												<label>
													Purchase Code
												</label>
												<input type="text" name="purchase_code" class="form-control" placeholder="eg. pt64c343-c7yq-4f59-853e-h754301675ed">
											</div>
										</div>
									</div>
								</div>

								<div class="col-md-6">
									<div class="row">
										<div class="col-md-12">
											<div class="form-group">
												<h2 class="text-uppercase">Database</h2>
											</div>
										</div>

										<div class="col-md-8">
											<div class="form-group">
												<label>
													Host
												</label>
												<input type="text" name="dbhost" class="form-control" placeholder="eg. localhost" value="localhost">
											</div>
										</div>

										<div class="col-md-4">
											<div class="form-group">
												<label>
													Port
												</label>
												<input type="number" name="dbport" class="form-control" placeholder="eg. 3306" value="3306">
											</div>
										</div>

										<div class="col-md-12">
											<div class="form-group">
												<label>
													Name
												</label>
												<input type="text" name="dbname" class="form-control" placeholder="eg. zender_db">
											</div>
										</div>

										<div class="col-md-6">
											<div class="form-group">
												<label>
													Username
												</label>
												<input type="text" name="dbuser" class="form-control" placeholder="eg. root">
											</div>
										</div>

										<div class="col-md-6">
											<div class="form-group">
												<label>
													Password
												</label>
												<input type="password" name="dbpass" class="form-control" placeholder="eg. password">
											</div>
										</div>
									</div>
								</div>

								<div class="col-12">
									<div class="row">
										<div class="col-md-12">
											<div class="form-group">
												<h2 class="text-uppercase">Account</h2>
											</div>
										</div>

										<div class="col-md-6">
											<div class="form-group">
												<label>
													Email Address
												</label>
												<input type="text" name="email" class="form-control" placeholder="eg. user@mail.com">
											</div>
	
											<div class="form-group">
												<label>
													Password
												</label>
												<input type="text" name="password" class="form-control" placeholder="eg. Password">
											</div>
										</div>

										<div class="col-md-6">
											<div class="form-group">
												<label>
													Full Name
												</label>
												<input type="text" name="name" class="form-control" placeholder="eg. John Doe">
											</div>
											
											<div class="row">
												<div class="col-md-6">
													<div class="form-group">
														<label>
															Timezone
														</label>
														<select name="timezone" class="form-control" data-live-search="true">
															{foreach $data.timezones as $timezone}
															<option value="{strtolower($timezone)}" {if strtolower($timezone) eq "asia/manila"}selected{/if}>{strtoupper($timezone)}</option>
															{/foreach}
														</select>
													</div>
												</div>
												<div class="col-md-6">
													<div class="form-group">
														<label>
															Country
														</label>
														<select name="country" class="form-control" data-live-search="true">
															{foreach $data.countries as $country}
															<option value="{$country@key}" data-tokens="{strtolower($country)}" {if strtolower($country) eq "philippines"}selected{/if}>{$country} ({$country@key})</option>
															{/foreach}
														</select>
													</div>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>

						<!-- Submit -->
						<div class="card-footer text-center">
							<button type="submit" class="btn btn-lg btn-primary lift">
								<i class="la la-wrench la-lg"></i> Install
							</button>
						</div>
					</div>
				</form>
			</div>
		</div> <!-- / .row -->

		<div class="preloader-light" system-preloader>
			<div class="loadingio loadingio-spinner-ripple-c4xwekkbyc9">
				<div class="ldio-k6xrhuhg6o">
					<div></div>
					<div></div>
				</div>
			</div>
		</div>

		<script src="{site_url("templates/_assets/js/libs/fetch.min.js")}"></script>
		<script>
			window.lang_alert_attention = "Attention";
			window.lang_js_loader_pleasewait = "Please wait";
			window.alert_position = "topLeft";
			window.color_primary = "#333";
			window.theme_color = "light";
			window.overlap_alert_position = "bottomLeft";

			fetchInject([
				"{site_url("templates/_install/assets/js/install.js")}"
			],
			fetchInject([
				"{site_url("templates/_assets/js/system.js")}"
			],
			fetchInject([
				"{site_url("templates/_assets/js/libs/pjax.min.js")}",
				"{site_url("templates/_assets/js/libs/waves.min.js")}",
				"{site_url("templates/_assets/js/libs/topbar.min.js")}",
				"{site_url("templates/_assets/js/libs/izitoast.min.js")}",
				"{site_url("templates/_assets/js/libs/bootstrap-select.min.js")}",
				"{site_url("templates/_assets/js/libs/jquery.loading.min.js")}"
			],
			fetchInject([
				"{site_url("templates/dashboard/assets/js/libs/bootstrap.min.js")}"
			],
			fetchInject([
				"{site_url("templates/_assets/js/libs/jquery.min.js")}",
				"{site_url("templates/_assets/css/libs/waves.min.css")}",
				"{site_url("templates/_assets/css/libs/izitoast.min.css")}",
				"{site_url("templates/_assets/css/libs/bootstrap-select.min.css")}"
			])))));
		</script>
</body>

</html>