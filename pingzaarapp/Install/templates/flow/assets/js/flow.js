// =============================================
// Global Variables and Initialization
// =============================================
window.editor = null;
window.flowData = {};

// =============================================
// Main Initialization
// =============================================
$.ajax({
    url: `${site_url}/flow/start`,
    type: 'GET',
    beforeSend: function () {
        system.loader(lang_flowjs_fetching_data);
    },
    success: function (http) {
        system.loader(false, false);

        try {
            var response = (typeof http === "string") ? JSON.parse(http) : JSON.parse(JSON.stringify(http));

            if (response.status == 200) {
                window.flowData = response.data;

                initializeEditor();
            } else {
                alert.danger(lang_flowjs_failedload_data, true);
            }
        } catch (e) {
            alert.danger(`${lang_flowjs_failedload_data}: ${e}`);
        }
    }
});

// =============================================
// Editor Initialization
// =============================================
function initializeEditor() {
    editor = new Drawflow(document.getElementById("drawflow"));
    editor.reroute = false;

    // Initialize with welcome node
    const devicesArray = Object.values(window.flowData.devices);

    // Create welcome node data
    const welcomeNode = {
        "id": 1,
        "name": "welcome",
        "data": {},
        "class": "welcome",
        "html": `
            <div>
                <div class="title-box">🤖 ${lang_flowjs_flowbuilder_guidetitle}</div>
                <div class="box">
                    <ol>
                        <li>${lang_flowjs_flowbuilder_guide1}</li>
                        <li>${lang_flowjs_flowbuilder_guide2}</li>
                        <li>${lang_flowjs_flowbuilder_guide3}</li>
                    </ol>
                    <p>${lang_flowjs_flowbuilder_note}</p>
                </div>
            </div>
        `,
        "typenode": false,
        "inputs": {},
        "outputs": {},
        "pos_x": 50,
        "pos_y": 50
    };

    if (Object.keys(window.dataToImport).length === 0) {
        window.dataToImport = {
            "drawflow": {
                "Home": {
                    "data": {
                        "1": welcomeNode,
                        "2": {
                            "id": 2,
                            "name": "sms",
                            "data": {
                                "device": devicesArray[0]?.did || ""
                            },
                            "class": "sms",
                            "html": `
                            <div>
                            <div class="title-box"><i class="las la-sms"></i> ${lang_flowjs_node_smstitle}</div>
                            <div class="box">
                                <p>${lang_flowjs_node_smsdevicesel}</p>
                                <select df-device>
                                ${devicesArray.map(device => `<option value="${device.did}">${device.name}</option>`).join('')}
                                </select>
                            </div>
                            </div>
                        `,
                            "typenode": false,
                            "inputs": {},
                            "outputs": {
                                "output_1": {
                                    "connections": [{
                                        "node": "3",
                                        "output": "input_1"
                                    }]
                                }
                            },
                            "pos_x": 350,
                            "pos_y": 100
                        },
                        "3": {
                            "id": 3,
                            "name": "send_text",
                            "data": {
                                "message": lang_flowjs_node_textexample,
                                "priority": "2"
                            },
                            "class": "send_text",
                            "html": `
                            <div>
                            <div class="title-box"><i class="las la-comment-alt"></i> ${lang_flowjs_node_texttitle}</div>
                            <div class="box">
                                <p>${lang_flowjs_node_textinputtitle}</p>
                                <textarea df-message placeholder="${lang_flowjs_node_textinputplaholder}">${lang_flowjs_node_textexample}</textarea>
                                <p>${lang_flowjs_node_textpriority}</p>
                                <select df-priority>
                                    <option value="1">${lang_flowjs_node_yes}</option>
                                    <option value="2">${lang_flowjs_node_no}</option>
                                </select>
                            </div>
                            </div>
                        `,
                            "typenode": false,
                            "inputs": {
                                "input_1": {
                                    "connections": [{
                                        "node": "2",
                                        "input": "output_1"
                                    }]
                                }
                            },
                            "outputs": {},
                            "pos_x": 650,
                            "pos_y": 150
                        }
                    }
                }
            }
        };
    } else {
        // Add welcome node to existing data if it doesn't exist
        if (!window.dataToImport.drawflow.Home.data["1"] || window.dataToImport.drawflow.Home.data["1"].name !== "welcome") {
            window.dataToImport.drawflow.Home.data["1"] = welcomeNode;
        }

        const devicesArray = Object.values(window.flowData.devices);
        const accountsArray = Object.values(window.flowData.wa_accounts);
        const keysArray = Object.values(window.flowData.ai_keys);
        const pluginsArray = Object.values(window.flowData.ai_plugins);
        const groupsArray = Object.values(window.flowData.contact_groups);

        // Update node data in dataToImport
        Object.entries(window.dataToImport.drawflow.Home.data).forEach(([nodeId, node]) => {
            switch (node.name) {
                case 'sms':
                    if (node.data.device) {
                        // Update HTML with current devices
                        node.html = `
                            <div>
                                <div class="title-box"><i class="las la-sms"></i> ${lang_flowjs_node_smstitle}</div>
                                <div class="box">
                                    <p>${lang_flowjs_node_smsdevicesel}</p>
                                    <select df-device>
                                        ${devicesArray.map(device => 
                                            `<option value="${device.did}" ${device.did === node.data.device ? 'selected' : ''}>${device.name}</option>`
                                        ).join('')}
                                    </select>
                                </div>
                            </div>
                        `;
                    }
                    break;

                case 'whatsapp':
                    if (node.data.whatsapp) {
                        // Update HTML with current accounts
                        node.html = `
                            <div>
                                <div class="title-box"><i class="lab la-whatsapp"></i> ${lang_flowjs_node_watitle}</div>
                                <div class="box">
                                    <p>${lang_flowjs_node_waselect}</p>
                                    <select df-whatsapp>
                                        ${accountsArray.map(account => 
                                            `<option value="${account.id}" ${account.id === node.data.whatsapp ? 'selected' : ''}>${account.phone}</option>`
                                        ).join('')}
                                    </select>
                                </div>
                            </div>
                        `;
                    }
                    break;

                case 'ai_reply':
                    if (node.data.key) {
                        // Update HTML with current AI keys and plugins
                        node.html = `
                            <div>
                                <div class="title-box"><i class="las la-robot"></i> ${lang_flowjs_node_aireply}</div>
                                <div class="box">
                                    <p>${lang_flowjs_node_aikey}</p>
                                    <select df-key>
                                        ${keysArray.map(key => 
                                            `<option value="${key.id}" ${key.id == node.data.key ? 'selected' : ''}>${key.name}</option>`
                                        ).join('')}
                                    </select>
                                    <p>${lang_flowjs_node_aiplugin}</p>
                                    <select df-plugin>
                                        <option value="0">${lang_flowjs_node_none}</option>
                                        ${pluginsArray.map(plugin => 
                                            `<option value="${plugin.id}" ${plugin.id == node.data.plugin ? 'selected' : ''}>${plugin.name}</option>`
                                        ).join('')}
                                    </select>
                                    <p>${lang_flowjs_node_textpriority}</p>
                                    <select df-priority>
                                        <option value="1" ${node.data.priority == "1" ? 'selected' : ''}>${lang_flowjs_node_yes}</option>
                                        <option value="2" ${node.data.priority == "2" ? 'selected' : ''}>${lang_flowjs_node_no}</option>
                                    </select>
                                </div>
                            </div>
                        `;
                    }
                    break;

                case 'save_contact':
                    if (node.data.group) {
                        // Update HTML with current groups
                        node.html = `
                            <div>
                                <div class="title-box"><i class="las la-address-book"></i> ${lang_flowjs_node_savecontact}</div>
                                <div class="box">
                                    <p>${lang_flowjs_node_contactgroup}</p>
                                    <select df-group>
                                        ${groupsArray.map(group => 
                                            `<option value="${group.id}" ${group.id === node.data.group ? 'selected' : ''}>${group.name}</option>`
                                        ).join('')}
                                    </select>
                                </div>
                            </div>
                        `;
                    }
                    break;
            }
        });
    }

    editor.start();
    editor.import(dataToImport);

    // =============================================
    // Event Handlers
    // =============================================
    // editor.on('nodeCreated', function (id) {
    //     console.log("Node created " + id);
    // });

    // editor.on('nodeRemoved', function (id) {
    //     console.log("Node removed " + id);
    // });

    // editor.on('nodeSelected', function (id) {
    //     console.log("Node selected " + id);
    // });

    // editor.on('moduleCreated', function (name) {
    //     console.log("Module Created " + name);
    // });

    // editor.on('moduleChanged', function (name) {
    //     console.log("Module Changed " + name);
    // });

    editor.on('connectionCreated', function (connection) {

        const sourceNode = editor.getNodeFromId(connection.output_id);
        const targetNode = editor.getNodeFromId(connection.input_id);

        // Validate condition node connections
        if (sourceNode.name === 'condition' && targetNode.name === 'condition') {
            editor.removeSingleConnection(
                connection.output_id,
                connection.input_id,
                connection.output_class,
                connection.input_class
            );

            alert.danger(lang_flowjs_node_nocondition);
        }

        // Check for WhatsApp-only nodes if trying to add SMS source
        if (sourceNode.name === 'sms') {
            const whatsappOnlyNodes = ['send_image', 'send_video', 'send_audio', 'send_document'];
            let hasWhatsappOnlyNodes = false;

            // Check each WhatsApp-only node type
            for (const nodeType of whatsappOnlyNodes) {
                const nodeIds = editor.getNodesFromName(nodeType);
                if (nodeIds.length > 0) {
                    hasWhatsappOnlyNodes = true;
                    break;
                }
            }

            if (hasWhatsappOnlyNodes) {
                editor.removeSingleConnection(
                    connection.output_id,
                    connection.input_id,
                    connection.output_class,
                    connection.input_class
                );

                alert.danger(lang_flowjs_node_nosmsmedia);

                return;
            }
        }

        // Validate WhatsApp media node connections
        const whatsappNodes = ['send_image', 'send_video', 'send_audio', 'send_document'];
        if (whatsappNodes.includes(targetNode.name)) {
            const source = findSourceNode(connection.output_id);
            if (source && source.name === 'sms') {
                editor.removeSingleConnection(
                    connection.output_id,
                    connection.input_id,
                    connection.output_class,
                    connection.input_class
                );
                alert.danger(lang_flowjs_node_wamediaonly);
            }
        }
    });

    // editor.on('connectionRemoved', function (connection) {
    //     console.log('Connection removed', connection);
    // });

    // editor.on('mouseMove', function (position) {
    //     // console.log('Position mouse x:' + position.x + ' y:' + position.y);
    // });

    // editor.on('nodeMoved', function (id) {
    //     console.log("Node moved " + id);
    // });

    // editor.on('zoom', function (zoom) {
    //     console.log('Zoom level ' + zoom);
    // });

    // editor.on('translate', function (position) {
    //     console.log('Translate x:' + position.x + ' y:' + position.y);
    // });

    // editor.on('addReroute', function (id) {
    //     console.log("Reroute added " + id);
    // });

    // editor.on('removeReroute', function (id) {
    //     console.log("Reroute removed " + id);
    // });

    // =============================================
    // Drag and Drop Initialization
    // =============================================
    const elements = document.getElementsByClassName('drag-drawflow');
    for (let i = 0; i < elements.length; i++) {
        elements[i].addEventListener('touchend', drop, false);
        elements[i].addEventListener('touchmove', positionMobile, false);
        elements[i].addEventListener('touchstart', drag, false);
    }
}

// =============================================
// Helper Functions
// =============================================
function findSourceNode(nodeId, visited = new Set()) {
    if (visited.has(nodeId)) return null;
    visited.add(nodeId);

    const node = editor.getNodeFromId(nodeId);
    if (node.name === 'sms' || node.name === 'whatsapp') {
        return node;
    }

    if (node.inputs) {
        for (const input of Object.values(node.inputs)) {
            if (input.connections) {
                for (const conn of input.connections) {
                    const source = findSourceNode(conn.node, visited);
                    if (source) return source;
                }
            }
        }
    }
    return null;
}

function hasSourceNode() {
    const smsNodes = editor.getNodesFromName('sms');
    const whatsappNodes = editor.getNodesFromName('whatsapp');
    return smsNodes.length > 0 || whatsappNodes.length > 0;
}

// =============================================
// Node Creation Functions
// =============================================
function addNodeToDrawFlow(name, pos_x, pos_y) {
    if (editor.editor_mode === 'fixed') return false;

    if ((name === 'sms' || name === 'whatsapp') && hasSourceNode()) {
        alert.danger(lang_flowjs_node_onlyonesource);
        return false;
    }

    pos_x = pos_x * (editor.precanvas.clientWidth / (editor.precanvas.clientWidth * editor.zoom)) -
        (editor.precanvas.getBoundingClientRect().x * (editor.precanvas.clientWidth / (editor.precanvas.clientWidth * editor.zoom)));
    pos_y = pos_y * (editor.precanvas.clientHeight / (editor.precanvas.clientHeight * editor.zoom)) -
        (editor.precanvas.getBoundingClientRect().y * (editor.precanvas.clientHeight / (editor.precanvas.clientHeight * editor.zoom)));

    switch (name) {
        case 'sms':
            createSMSNode(pos_x, pos_y);
            break;
        case 'whatsapp':
            createWhatsAppNode(pos_x, pos_y);
            break;
        case 'condition':
            createConditionNode(pos_x, pos_y);
            break;
        case 'ai_reply':
            createAIReplyNode(pos_x, pos_y);
            break;
        case 'send_text':
            createSendTextNode(pos_x, pos_y);
            break;
        case 'send_image':
            createSendImageNode(pos_x, pos_y);
            break;
        case 'send_video':
            createSendVideoNode(pos_x, pos_y);
            break;
        case 'send_audio':
            createSendAudioNode(pos_x, pos_y);
            break;
        case 'send_document':
            createSendDocumentNode(pos_x, pos_y);
            break;
        case 'save_contact':
            createSaveContactNode(pos_x, pos_y);
            break;
        case 'pause':
            createPauseNode(pos_x, pos_y);
            break;
        case 'resume':
            createResumeNode(pos_x, pos_y);
            break;
    }
}

// =============================================
// Node Template Functions
// =============================================
function createSMSNode(pos_x, pos_y) {
    const devicesArray = Object.values(window.flowData.devices);
    const html = `
        <div>
            <div class="title-box"><i class="las la-sms"></i> ${lang_flowjs_node_smstitle}</div>
            <div class="box">
                <p>${lang_flowjs_node_smsdevicesel}</p>
                <select df-device>
                    ${devicesArray.map(device => `<option value="${device.did}">${device.name}</option>`).join('')}
                </select>
            </div>
        </div>
    `;
    editor.addNode('sms', 0, 1, pos_x, pos_y, 'sms', { "device": devicesArray[0]?.did || "" }, html);
}

function createWhatsAppNode(pos_x, pos_y) {
    const accountsArray = Object.values(window.flowData.wa_accounts);
    const html = `
        <div>
            <div class="title-box"><i class="lab la-whatsapp"></i> ${lang_flowjs_node_watitle}</div>
            <div class="box">
                <p>${lang_flowjs_node_waselect}</p>
                <select df-whatsapp>
                    ${accountsArray.map(account => `<option value="${account.id}">${account.phone}</option>`).join('')}
                </select>
            </div>
        </div>
    `;
    editor.addNode('whatsapp', 0, 1, pos_x, pos_y, 'whatsapp', { "whatsapp": accountsArray[0]?.id || "" }, html);
}

function createConditionNode(pos_x, pos_y) {
    const html = `
        <div>
            <div class="title-box"><i class="las la-filter"></i> ${lang_flowjs_node_condition}</div>
            <div class="box">
                <p>${lang_flowjs_node_conditiontype}</p>
                <select df-condition>
                    <option value="starts_with">${lang_flowjs_node_condition1}</option>
                    <option value="ends_with">${lang_flowjs_node_condition2}</option>
                    <option value="contains">${lang_flowjs_node_condition3}</option>
                    <option value="equal">${lang_flowjs_node_condition4}</option>
                    <option value="not_contain">${lang_flowjs_node_condition5}</option>
                    <option value="regex">${lang_flowjs_node_condition6}</option>
                </select>
                <p>${lang_flowjs_node_conditionval}</p>
                <input type="text" df-value placeholder="${lang_flowjs_node_conditionvalph}">
            </div>
        </div>
    `;
    editor.addNode('condition', 1, 1, pos_x, pos_y, 'condition', { "value": "" }, html);
}

function createAIReplyNode(pos_x, pos_y) {
    const keysArray = Object.values(window.flowData.ai_keys);
    const pluginsArray = Object.values(window.flowData.ai_plugins);
    const html = `
        <div>
            <div class="title-box"><i class="las la-robot"></i> ${lang_flowjs_node_aireply}</div>
            <div class="box">
                <p>${lang_flowjs_node_aikey}</p>
                <select df-key>
                    ${keysArray.map(key => `<option value="${key.id}">${key.name}</option>`).join('')}
                </select>
                <p>${lang_flowjs_node_aiplugin}</p>
                <select df-plugin>
                    <option value="0">${lang_flowjs_node_none}</option>
                    ${pluginsArray.map(plugin => `<option value="${plugin.id}">${plugin.name}</option>`).join('')}
                </select>
                <p>${lang_flowjs_node_textpriority}</p>
                <select df-priority>
                    <option value="1">${lang_flowjs_node_yes}</option>
                    <option value="2">${lang_flowjs_node_no}</option>
                </select>
            </div>
        </div>
    `;
    editor.addNode('ai_reply', 1, 0, pos_x, pos_y, 'ai_reply', {
        "key": keysArray[0]?.id || 0,
        "plugin": 0,
        "priority": "2"
    }, html);
}

function createSendTextNode(pos_x, pos_y) {
    const html = `
        <div>
            <div class="title-box"><i class="las la-comment-alt"></i> ${lang_flowjs_node_texttitle}</div>
            <div class="box">
                <p>${lang_flowjs_node_textinputtitle}</p>
                <textarea df-message placeholder="${lang_flowjs_node_textinputplaholder}"></textarea>
                <p>${lang_flowjs_node_textpriority}</p>
                <select df-priority>
                    <option value="1">${lang_flowjs_node_yes}</option>
                    <option value="2">${lang_flowjs_node_no}</option>
                </select>
            </div>
        </div>
    `;
    editor.addNode('send_text', 1, 0, pos_x, pos_y, 'send_text', { "message": "", "priority": "2" }, html);
}

function createSendImageNode(pos_x, pos_y) {
    const inputId = 'input_' + Date.now() + '_' + Math.random().toString(36).substring(2, 11);
    const html = `
        <div>
            <div class="title-box"><i class="las la-image"></i> ${lang_flowjs_node_imagetitle}</div>
            <div class="box">
                <p>${lang_flowjs_node_imageurl}</p>
                <input type="text" df-value placeholder="${lang_flowjs_node_imageurlph}" id="${inputId}">
                <button class="btn-upload" onclick="showUploadModal('${inputId}')">
                    <i class="las la-cloud-upload-alt"></i> ${lang_flowjs_node_uploadbtn}
                </button>
                <p>${lang_flowjs_node_caption}</p>
                <textarea df-caption placeholder="${lang_flowjs_node_captionph}"></textarea>
                <p>${lang_flowjs_node_textpriority}</p>
                <select df-priority>
                    <option value="1">${lang_flowjs_node_yes}</option>
                    <option value="2">${lang_flowjs_node_no}</option>
                </select>
            </div>
        </div>
    `;
    editor.addNode('send_image', 1, 0, pos_x, pos_y, 'send_image', { "value": "", "caption": "", "priority": "2" }, html);
}

function createSendVideoNode(pos_x, pos_y) {
    const inputId = 'input_' + Date.now() + '_' + Math.random().toString(36).substring(2, 11);
    const html = `
        <div>
            <div class="title-box"><i class="las la-video"></i> ${lang_flowjs_node_video}</div>
            <div class="box">
                <p>${lang_flowjs_node_videourl}</p>
                <input type="text" df-value placeholder="${lang_flowjs_node_videoph}" id="${inputId}">
                <button class="btn-upload" onclick="showUploadModal('${inputId}')">
                    <i class="las la-cloud-upload-alt"></i> ${lang_flowjs_node_uploadbtn}
                </button>
                <p>${lang_flowjs_node_caption}</p>
                <textarea df-caption placeholder="${lang_flowjs_node_captionph}"></textarea>
                <p>${lang_flowjs_node_textpriority}</p>
                <select df-priority>
                    <option value="1">${lang_flowjs_node_yes}</option>
                    <option value="2">${lang_flowjs_node_no}</option>
                </select>
            </div>
        </div>
    `;
    editor.addNode('send_video', 1, 0, pos_x, pos_y, 'send_video', { "value": "", "caption": "", "priority": "2" }, html);
}

function createSendAudioNode(pos_x, pos_y) {
    const inputId = 'input_' + Date.now() + '_' + Math.random().toString(36).substring(2, 11);
    const html = `
        <div>
            <div class="title-box"><i class="las la-volume-up"></i> ${lang_flowjs_node_audio}</div>
            <div class="box">
                <p>${lang_flowjs_node_audiourl}</p>
                <input type="text" df-value placeholder="${lang_flowjs_node_audioph}" id="${inputId}">
                <button class="btn-upload" onclick="showUploadModal('${inputId}')">
                    <i class="las la-cloud-upload-alt"></i> ${lang_flowjs_node_uploadbtn}
                </button>
                <p>${lang_flowjs_node_textpriority}</p>
                <select df-priority>
                    <option value="1">${lang_flowjs_node_yes}</option>
                    <option value="2">${lang_flowjs_node_no}</option>
                </select>
            </div>
        </div>
    `;
    editor.addNode('send_audio', 1, 0, pos_x, pos_y, 'send_audio', { "value": "", "priority": "2" }, html);
}

function createSendDocumentNode(pos_x, pos_y) {
    const inputId = 'input_' + Date.now() + '_' + Math.random().toString(36).substring(2, 11);
    const html = `
        <div>
            <div class="title-box"><i class="las la-file"></i> ${lang_flowjs_node_documennt}</div>
            <div class="box">
                <p>${lang_flowjs_node_documenturl}</p>
                <input type="text" df-value placeholder="${lang_flowjs_node_documentph}" id="${inputId}">
                <button class="btn-upload" onclick="showUploadModal('${inputId}')">
                    <i class="las la-cloud-upload-alt"></i> ${lang_flowjs_node_uploadbtn}
                </button>
                <p>${lang_flowjs_node_textpriority}</p>
                <select df-priority>
                    <option value="1">${lang_flowjs_node_yes}</option>
                    <option value="2">${lang_flowjs_node_no}</option>
                </select>
            </div>
        </div>
    `;
    editor.addNode('send_document', 1, 0, pos_x, pos_y, 'send_document', { "value": "", "priority": "2" }, html);
}

function createSaveContactNode(pos_x, pos_y) {
    const groupsArray = Object.values(window.flowData.contact_groups);
    const html = `
        <div>
            <div class="title-box"><i class="las la-address-book"></i> ${lang_flowjs_node_savecontact}</div>
            <div class="box">
                <p>${lang_flowjs_node_contactgroup}</p>
                <select df-group>
                    ${groupsArray.map(group => `<option value="${group.id}">${group.name}</option>`).join('')}
                </select>
            </div>
        </div>
    `;
    editor.addNode('save_contact', 1, 0, pos_x, pos_y, 'save_contact', { "group": groupsArray[0]?.id || "" }, html);
}

function createPauseNode(pos_x, pos_y) {
    var html = `
        <div>
            <div class="title-box"><i class="las la-pause"></i> ${lang_flowjs_node_pause}</div>
            <div class="box">
                <p>${lang_flowjs_node_pause_duration}</p>
                <input type="number" df-duration min="0" value="60" placeholder="${lang_flowjs_node_pause_duration_placeholder}">
                <p><small>${lang_flowjs_node_pause_duration_help}</small></p>
                
                <p>${lang_flowjs_node_pause_context}</p>
                <select df-context>
                    <option value="flow">${lang_flowjs_node_context_flow}</option>
                    <option value="autoreply">${lang_flowjs_node_context_autoreply}</option>
                    <option value="all">${lang_flowjs_node_context_all}</option>
                </select>
                
                <p><small>${lang_flowjs_node_pause_description}</small></p>
            </div>
        </div>
    `;
    editor.addNode('pause', 1, 0, pos_x, pos_y, 'pause', { "duration": "60", "context": "flow" }, html);
}

function createResumeNode(pos_x, pos_y) {
    var html = `
        <div>
            <div class="title-box"><i class="las la-play"></i> ${lang_flowjs_node_resume}</div>
            <div class="box">
                <p>${lang_flowjs_node_resume_context}</p>
                <select df-context>
                    <option value="flow">${lang_flowjs_node_context_flow}</option>
                    <option value="autoreply">${lang_flowjs_node_context_autoreply}</option>
                    <option value="all">${lang_flowjs_node_context_all}</option>
                </select>
                
                <p><small>${lang_flowjs_node_resume_description}</small></p>
            </div>
        </div>
    `;
    editor.addNode('resume', 1, 0, pos_x, pos_y, 'resume', { "context": "flow" }, html);
}

// =============================================
// Drag and Drop Functions
// =============================================
let mobile_item_selec = '';
let mobile_last_move = null;

function positionMobile(ev) {
    mobile_last_move = ev;
}

function allowDrop(ev) {
    ev.preventDefault();
}

function drag(ev) {
    if (ev.type === "touchstart") {
        mobile_item_selec = ev.target.closest(".drag-drawflow").getAttribute('data-node');
    } else {
        ev.dataTransfer.setData("node", ev.target.getAttribute('data-node'));
    }
}

function drop(ev) {
    if (ev.type === "touchend") {
        const parentdrawflow = document.elementFromPoint(
            mobile_last_move.touches[0].clientX,
            mobile_last_move.touches[0].clientY
        ).closest("#drawflow");
        if (parentdrawflow != null) {
            addNodeToDrawFlow(
                mobile_item_selec,
                mobile_last_move.touches[0].clientX,
                mobile_last_move.touches[0].clientY
            );
        }
        mobile_item_selec = '';
    } else {
        ev.preventDefault();
        const data = ev.dataTransfer.getData("node");
        addNodeToDrawFlow(data, ev.clientX, ev.clientY);
    }
}

// =============================================
// File Upload Functions
// =============================================
const uploadModal = document.getElementById('uploadModal');
const fileInput = document.getElementById('fileInput');
const uploadButton = document.getElementById('uploadButton');
const uploadProgress = document.getElementById('uploadProgress');
const progressBar = document.getElementById('progressBar');
const progressText = document.getElementById('progressText');
const fileInfo = document.getElementById('fileInfo');
const closeModal = document.getElementById('closeModal');
const startUpload = document.getElementById('startUpload');

let selectedFile = null;
let targetNodeId = null;

function showUploadModal(nodeId) {
    targetNodeId = nodeId;
    uploadModal.style.display = 'flex';
}

function hideUploadModal() {
    uploadModal.style.display = 'none';
    resetUploadState();
}

function resetUploadState() {
    selectedFile = null;
    targetNodeId = null;
    fileInput.value = '';
    progressBar.style.width = '0%';
    progressText.textContent = '';
    fileInfo.innerHTML = '';
    fileInfo.style.display = 'none';
    startUpload.disabled = true;
}

function generateFilePreview(file) {
    const fileInfo = document.getElementById('fileInfo');
    
    // Clear previous content
    fileInfo.innerHTML = '';
    
    // Create preview container
    const previewContainer = document.createElement('div');
    previewContainer.className = 'file-preview-container';
    
    // Check if image
    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = document.createElement('img');
            img.src = e.target.result;
            img.className = 'file-preview-image';
            img.style.maxWidth = '200px';
            img.style.maxHeight = '150px';
            img.style.borderRadius = '4px';
            img.style.marginBottom = '10px';
            previewContainer.appendChild(img);
            
            // Add file info below image
            const info = document.createElement('div');
            info.className = 'file-info-text';
            info.innerHTML = `<strong>${file.name}</strong><br>Size: ${formatFileSize(file.size)}`;
            previewContainer.appendChild(info);
            
            fileInfo.appendChild(previewContainer);
        };
        reader.readAsDataURL(file);
    } else {
        // Show icon for non-image files
        const icon = document.createElement('i');
        icon.style.fontSize = '48px';
        icon.style.marginBottom = '10px';
        
        // Determine icon based on file type
        if (file.type.startsWith('video/')) {
            icon.className = 'las la-video';
        } else if (file.type.startsWith('audio/')) {
            icon.className = 'las la-volume-up';
        } else if (file.type === 'application/pdf') {
            icon.className = 'las la-file-pdf';
        } else {
            icon.className = 'las la-file';
        }
        
        previewContainer.appendChild(icon);
        
        // Add file info
        const info = document.createElement('div');
        info.className = 'file-info-text';
        info.innerHTML = `<strong>${file.name}</strong><br>Size: ${formatFileSize(file.size)}`;
        previewContainer.appendChild(info);
        
        fileInfo.appendChild(previewContainer);
    }
    
    fileInfo.style.display = 'block';
}

fileInput.addEventListener('change', (e) => {
    selectedFile = e.target.files[0];
    if (selectedFile) {
        generateFilePreview(selectedFile);
        startUpload.disabled = false;
    } else {
        fileInfo.innerHTML = '';
        fileInfo.style.display = 'none';
        startUpload.disabled = true;
    }
});

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

startUpload.addEventListener('click', async () => {
    if (!selectedFile) return;

    const formData = new FormData();
    formData.append('file', selectedFile);
    formData.append('node_id', targetNodeId);

    $.ajax({
        url: `${site_url}/flow/upload`,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        beforeSend: function () {
            system.loader(lang_flowjs_upload_uploading);
        },
        success: function (http) {
            system.loader(false, false);

            try {
                var response = (typeof http === "string") ? JSON.parse(http) : JSON.parse(JSON.stringify(http));

                if (response.status == 200) {
                    $(`#${targetNodeId}`).val(response.data.fileUrl);

                    const nodeElement = $(`#${targetNodeId}`).closest('.drawflow-node');

                    if (nodeElement.length) {
                        const nodeId = nodeElement.attr('id').replace('node-', '');
                        const node = editor.getNodeFromId(nodeId);
                        if (node) {
                            node.data.value = response.data.fileUrl;
                            editor.updateNodeDataFromId(nodeId, node.data);
                        }
                    }

                    hideUploadModal();
                    
                    alert.success(response.message);
                } else {
                    alert.danger(response.message);
                }
            } catch (e) {
                alert.danger(`${lang_flowjs_upload_failed}: ${e}`);
            }
        }
    });
});

uploadModal.addEventListener('click', (e) => {
    if (e.target === uploadModal) {
        hideUploadModal();
    }
});

closeModal.addEventListener('click', hideUploadModal);
uploadButton.addEventListener('click', () => {
    fileInput.click();
});

// =============================================
// Flow Export Functions
// =============================================
function exportFlow() {
    // Get current state
    const currentData = editor.export();

    // Remove welcome node from the data
    if (currentData.drawflow.Home.data) {
        const filteredData = Object.fromEntries(
            Object.entries(currentData.drawflow.Home.data)
                .filter(([_, node]) => node.name !== 'welcome')
        );
        currentData.drawflow.Home.data = filteredData;

        // Check if there's at least one source node
        const hasSourceNode = Object.values(filteredData).some(node =>
            node.name === 'sms' || node.name === 'whatsapp'
        );

        if (!hasSourceNode) {
            alert.danger(lang_flowjs_export_addsourceflow);
            return;
        }

        // Check for unconnected nodes and source node connections
        const unconnectedNodes = [];
        const sourceNodes = [];

        for (const [id, node] of Object.entries(filteredData)) {
            // Check if node has any connections
            const hasInputConnections = Object.values(node.inputs || {}).some(input =>
                input.connections && input.connections.length > 0
            );
            const hasOutputConnections = Object.values(node.outputs || {}).some(output =>
                output.connections && output.connections.length > 0
            );

            // Source nodes must have output connections
            if ((node.name === 'sms' || node.name === 'whatsapp') && !hasOutputConnections) {
                sourceNodes.push(id);
            }
            // Other nodes must have both input and output connections (except end nodes)
            else if (node.name !== 'sms' && node.name !== 'whatsapp' && !hasInputConnections) {
                unconnectedNodes.push(id);
            }
        }

        if (sourceNodes.length > 0) {
            alert.danger(lang_flowjs_export_sourcemustconnode);
            return;
        }

        if (unconnectedNodes.length > 0) {
            alert.danger(lang_flowjs_export_nodesmustconnctflow);
            return;
        }

        // Validate source nodes
        for (const [id, node] of Object.entries(filteredData)) {
            if (node.name === 'sms' && (!node.data.device || node.data.device === '')) {
                alert.danger(lang_flowjs_export_mustselsmssource);
                return;
            }

            if (node.name === 'whatsapp' && (!node.data.whatsapp || node.data.whatsapp === '')) {
                alert.danger(lang_flowjs_export_mustselwasource);
                return;
            }
        }
    }

    // Create form data with flow data
    const formData = new FormData();
    formData.append('flow_data', JSON.stringify(currentData));

    // Add source field based on the source node type
    const sourceNode = Object.values(currentData.drawflow.Home.data).find(node =>
        node.name === 'sms' || node.name === 'whatsapp'
    );

    if (sourceNode) {
        formData.append('source', sourceNode.name);
    }

    if (window.flow_id != null) {
        formData.append('id', window.flow_id);
    }

    // Send data to server using jQuery
    $.ajax({
        url: `${site_url}/flow/save`,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        beforeSend: function () {
            system.loader(lang_flowjs_export_savingflow);
        },
        success: function (http) {
            system.loader(false, false);
            try {
                var response = (typeof http === "string") ? JSON.parse(http) : JSON.parse(JSON.stringify(http));
                if (response.status == 200) {
                    window.flow_id = response.data.flow_id;
                    history.pushState(null, '', `${site_url}/flow/${response.data.flow_id}`);
                    alert.success(response.message, true);
                } else {
                    alert.danger(response.message);
                }
            } catch (e) {
                alert.danger(`${lang_flowjs_export_failedsave}: ${e}`);
            }
        }
    });
}

function clearFlow() {
    // Get current state
    const currentData = editor.export();

    // Find and keep the welcome node
    const welcomeNode = Object.values(currentData.drawflow.Home.data).find(node => node.name === 'welcome');

    // Create new data with only the welcome node
    const newData = {
        drawflow: {
            Home: {
                data: welcomeNode ? {
                    [welcomeNode.id]: welcomeNode
                } : {}
            }
        }
    };

    // Clear and re-import with welcome node
    editor.clear();
    editor.import(newData);
}