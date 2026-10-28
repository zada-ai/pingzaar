<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-terminal la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="alert alert-info d-flex mt-3 mb-0">
                <i class="la la-info-circle la-lg mr-2 my-auto"></i> This is not translated because it is only for setup purposes.
            </div>

            <ul class="nav nav-tabs nav-fill" id="myTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="tab1-tab" data-toggle="tab" href="#tab1" role="tab" aria-controls="tab1" aria-selected="true">Linux</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab2-tab" data-toggle="tab" href="#tab2" role="tab" aria-controls="tab2" aria-selected="false">macOS</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab3-tab" data-toggle="tab" href="#tab3" role="tab" aria-controls="tab3" aria-selected="false">Windows</a>
                </li>
            </ul>
            
            <div class="tab-content p-3">
                <div class="tab-pane fade show active" id="tab1" role="tabpanel" aria-labelledby="tab1-tab">
                    <p>1. Select your server architecture:</p>
                    <div class="mb-3">
                        <select id="wa_arch" class="form-control">
                            <option value="linux">AMD64 (Ubuntu, CentOS)</option>
                            <option value="arm64">ARM64 (Raspberry Pi, Android)</option>
                        </select>
                    </div>
                    <p class="mt-3">2. Copy <strong>titansys-whatsapp-<span class="final_arch">linux</span></strong> out of your release ZIP (<code>WhatsApp/prebuilt/</code>) into a folder of its own, then make it executable:</p>
                    <div class="bg-dark text-white p-3 overflow-auto text-nowrap rounded">
                        <p class="m-0">
                            chmod +x ./titansys-whatsapp-<span class="final_arch">linux</span>
                        </p>
                    </div>
                    <div class="alert alert-primary d-flex mt-3 mb-0">
                        <i class="la la-info-circle la-lg mr-2 my-auto"></i> To upgrade later, stop the server, copy the newer binary over this one, and run the same command again.
                    </div>
                    <p class="mt-3">3. Start the WhatsApp Server by running this command:</p>
                    <div class="bg-dark text-white p-3 pt-0 overflow-auto text-nowrap rounded">
                        <p class="m-0">
                            ./titansys-whatsapp-<span class="final_arch">linux</span> --key="{$data.waserver.secret}" --host="0.0.0.0" --port="{if empty($data.waserver.port)}8899{else}{$data.waserver.port}{/if}"
                        </p>
                    </div>
                    <p class="mt-3">4. If you want to run the WhatsApp server in the background, please read <a class="font-weight-bold" href="Documentation/pages/whatsapp-server.html" target="_blank">this</a> before you proceed below.</p>
                    <p class="mt-3">5. Generate your background script:</p>
                    <div class="mb-3">
                        <label for="wa_path">WhatsApp Binary Folder</label>
                        <input type="text" id="wa_path" class="form-control" placeholder="eg. /your/whatsapp/server/folder">
                    </div>
                    <div class="bg-dark text-white p-3 pt-0 overflow-auto text-nowrap rounded">
                        <p class="m-0">
                            #!/bin/bash<br><br>
                            if ! pgrep -f "titansys-whatsapp-<span class="final_arch">linux</span>" > /dev/null<br>
                            then<br>
                            &nbsp;&nbsp;cd <span id="final_path">/your/whatsapp/server/folder</span><br>
                            &nbsp;&nbsp;./titansys-whatsapp-<span class="final_arch">linux</span> --key="{$data.waserver.secret}" --host="0.0.0.0" --port="{if empty($data.waserver.port)}8899{else}{$data.waserver.port}{/if}" &<br>
                            fi
                        </p>
                    </div>
                    <p class="mt-3">6. Save that as <code>start-whatsapp.sh</code> in the same folder, make it executable with <code>chmod +x start-whatsapp.sh</code>, and schedule it with <code>crontab -e</code>:</p>
                    <div class="bg-dark text-white p-3 pt-0 overflow-auto text-nowrap rounded">
                        <p class="m-0">
                            */5 * * * * <span id="final_path_cron">/your/whatsapp/server/folder</span>/start-whatsapp.sh > /dev/null 2>&amp;1
                        </p>
                    </div>
                    <div class="alert alert-primary d-flex mt-3 mb-0">
                        <i class="la la-info-circle la-lg mr-2 my-auto"></i> The script starts the server only when it isn't already running, so scheduling it every few minutes both restarts it after a crash and brings it back after a reboot.
                    </div>
                </div>
                <div class="tab-pane fade" id="tab2" role="tabpanel" aria-labelledby="tab2-tab">
                    <div class="alert alert-danger text-white d-flex mt-3 mb-0">
                        <i class="la la-exclamation-triangle la-lg mr-2 my-auto"></i> This binary only supports Apple Silicon (M1/M2/M3/M4). Intel Macs are not supported.
                    </div>
                    <p class="mt-3">1. Copy <strong>titansys-whatsapp-macos</strong> out of your release ZIP (<code>WhatsApp/prebuilt/</code>) into a folder of its own, open Terminal in that folder, and make it executable:</p>
                    <div class="bg-dark text-white p-3 overflow-auto text-nowrap rounded">
                        <p class="m-0">
                            chmod +x ./titansys-whatsapp-macos
                        </p>
                    </div>
                    <p class="mt-3">2. macOS quarantines anything downloaded from the internet and will refuse to run it. Clear that flag once:</p>
                    <div class="bg-dark text-white p-3 overflow-auto text-nowrap rounded">
                        <p class="m-0">
                            xattr -d com.apple.quarantine ./titansys-whatsapp-macos
                        </p>
                    </div>
                    <div class="alert alert-primary d-flex mt-3 mb-0">
                        <i class="la la-info-circle la-lg mr-2 my-auto"></i> If you skip this you'll see <strong>"cannot be opened because the developer cannot be verified"</strong>. If that already happened, run the command above and try again.
                    </div>
                    <p class="mt-3">3. Start the WhatsApp Server:</p>
                    <div class="bg-dark text-white p-3 pt-0 overflow-auto text-nowrap rounded">
                        <p class="m-0">
                            ./titansys-whatsapp-macos --key="{$data.waserver.secret}" --host="0.0.0.0" --port="{if empty($data.waserver.port)}8899{else}{$data.waserver.port}{/if}"
                        </p>
                    </div>
                    <div class="alert alert-primary d-flex mt-3 mb-0">
                        <i class="la la-info-circle la-lg mr-2 my-auto"></i> Keep the Terminal window open while the server is in use — you can minimise it, but closing it stops the server and takes every linked account offline. To replace the binary later, stop it with Ctrl+C, copy the new file over, and repeat steps 1 to 3.
                    </div>
                </div>
                <div class="tab-pane fade" id="tab3" role="tabpanel" aria-labelledby="tab3-tab">
                    <div class="alert alert-warning d-flex mt-3 mb-0">
                        <i class="la la-exclamation-triangle la-lg mr-2 my-auto"></i> Windows is the only platform without a ready-to-run binary, so you build it yourself. Everything you need ships in the release.
                    </div>
                    <p class="mt-3">1. Install <a class="font-weight-bold" href="https://go.dev/dl/" target="_blank" rel="noopener noreferrer">Go 1.25 or newer</a>, then build the server from the source in your release ZIP:</p>
                    <div class="bg-dark text-white p-3 overflow-auto text-nowrap rounded">
                        <p class="m-0">
                            cd WhatsApp\src<br>make build-windows
                        </p>
                    </div>
                    <p class="mt-3">2. That produces <strong>dist\titansys-whatsapp-win.exe</strong>. Copy it into a folder of its own.</p>
                    <p class="mt-3">3. Open Command Prompt in that folder and start the server:</p>
                    <div class="bg-dark text-white p-3 pt-0 overflow-auto text-nowrap rounded">
                        <p class="m-0">
                            titansys-whatsapp-win.exe --key="{$data.waserver.secret}" --host="0.0.0.0" --port="{if empty($data.waserver.port)}8899{else}{$data.waserver.port}{/if}"
                        </p>
                    </div>
                    <div class="alert alert-primary d-flex mt-3 mb-0">
                        <i class="la la-info-circle la-lg mr-2 my-auto"></i> Allow it through Windows Firewall when prompted, and keep the window open while the server is in use. This build is not tested by Titan Systems as part of the release — verify it on your own hardware before relying on it.
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>