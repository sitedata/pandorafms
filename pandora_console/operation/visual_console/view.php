<?php
/**
 * Extension to manage a list of gateways and the node address where they should
 * point to.
 *
 * @category   Extensions
 * @package    Pandora FMS
 * @subpackage Community
 * @version    1.0.0
 * @license    See below
 *
 *    ______                 ___                    _______ _______ ________
 *   |   __ \.-----.--.--.--|  |.-----.----.-----. |    ___|   |   |     __|
 *  |    __/|  _  |     |  _  ||  _  |   _|  _  | |    ___|       |__     |
 * |___|   |___._|__|__|_____||_____|__| |___._| |___|   |__|_|__|_______|
 *
 * ============================================================================
 * Copyright (c) 2005-2021 Artica Soluciones Tecnologicas
 * Please see http://pandorafms.org for full contribution list
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation for version 2.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * ============================================================================
 */

global $config;

// Login check.
check_login();

require_once $config['homedir'].'/vendor/autoload.php';
// TODO: include file functions.
require_once $config['homedir'].'/include/functions_visual_map.php';


/**
 * Function for return button visual console edition.
 *
 * @param string  $idDiv    Id button.
 * @param string  $label    Label and title button.
 * @param string  $class    Class button.
 * @param boolean $disabled Disabled button.
 *
 * @return void Retun button.
 */
function visual_map_print_button_editor_refactor(
    $idDiv,
    $label,
    $class='',
    $disabled=false
) {
    global $config;

    html_print_button(
        $label,
        $idDiv,
        $disabled,
        '',
        'class=" sub visual_editor_button_toolbox '.$idDiv.' '.$class.'"',
        false,
        true
    );
}


ui_require_css_file('visual_maps');
ui_require_css_file('register');

// Query parameters.
$visualConsoleId = (int) get_parameter(!is_metaconsole() ? 'id' : 'id_visualmap');
// To hide the menus.
$pure = (bool) get_parameter('pure', $config['pure']);
// Refresh interval in seconds.
$refr = (int) get_parameter('refr', $config['vc_refr']);

// Load Visual Console.
use Models\VisualConsole\Container as VisualConsole;
$visualConsole = null;
try {
    $visualConsole = VisualConsole::fromDB(['id' => $visualConsoleId]);
} catch (Throwable $e) {
    db_pandora_audit(
        'ACL Violation',
        'Trying to access visual console without Id'
    );
    include 'general/noaccess.php';
    exit;
}

$visualConsoleData = $visualConsole->toArray();
$groupId = $visualConsoleData['groupId'];
$visualConsoleName = $visualConsoleData['name'];

// ACL.
$aclRead = check_acl_restricted_all($config['id_user'], $groupId, 'VR');
$aclWrite = check_acl_restricted_all($config['id_user'], $groupId, 'VW');
$aclManage = check_acl_restricted_all($config['id_user'], $groupId, 'VM');

if (!$aclRead && !$aclWrite && !$aclManage) {
    db_pandora_audit(
        'ACL Violation',
        'Trying to access visual console without group access'
    );
    include 'general/noaccess.php';
    exit;
}

// Render map.
$options = [];

$options['consoles_list']['text'] = '<a href="index.php?sec=network&sec2=godmode/reporting/map_builder">'.html_print_image(
    'images/visual_console.png',
    true,
    [
        'title' => __('Visual consoles list'),
        'class' => 'invert_filter',
    ]
).'</a>';

if ($aclWrite || $aclManage) {
    $action = get_parameterBetweenListValues(
        is_metaconsole() ? 'action2' : 'action',
        [
            'new',
            'save',
            'edit',
            'update',
            'delete',
        ],
        'edit'
    );

    $baseUrl = 'index.php?sec=network&sec2=godmode/reporting/visual_console_builder&action='.$action;

    $hash = md5($config['dbpass'].$visualConsoleId.$config['id_user']);

    $options['public_link']['text'] = '<a href="'.ui_get_full_url(
        'operation/visual_console/public_console.php?hash='.$hash.'&id_layout='.$visualConsoleId.'&refr='.$refr.'&id_user='.$config['id_user']
    ).'" target="_blank">'.html_print_image(
        'images/camera_mc.png',
        true,
        [
            'title' => __('Show link to public Visual Console'),
            'class' => 'invert_filter',
        ]
    ).'</a>';
    $options['public_link']['active'] = false;

    $options['data']['text'] = '<a href="'.$baseUrl.'&tab=data&id_visual_console='.$visualConsoleId.'">'.html_print_image(
        'images/op_reporting.png',
        true,
        [
            'title' => __('Main data'),
            'class' => 'invert_filter',
        ]
    ).'</a>';
    $options['list_elements']['text'] = '<a href="'.$baseUrl.'&tab=list_elements&id_visual_console='.$visualConsoleId.'">'.html_print_image(
        'images/list.png',
        true,
        [
            'title' => __('List elements'),
            'class' => 'invert_filter',
        ]
    ).'</a>';

    if (enterprise_installed()) {
        $options['wizard_services']['text'] = '<a href="'.$baseUrl.'&tab=wizard_services&id_visual_console='.$visualConsoleId.'">'.html_print_image(
            'images/wand_services.png',
            true,
            [
                'title' => __('Services wizard'),
                'class' => 'invert_filter',
            ]
        ).'</a>';
    }

    $options['wizard']['text'] = '<a href="'.$baseUrl.'&tab=wizard&id_visual_console='.$visualConsoleId.'">'.html_print_image(
        'images/wand.png',
        true,
        [
            'title' => __('Wizard'),
            'class' => 'invert_filter',
        ]
    ).'</a>';
}

$options['view']['text'] = '<a href="index.php?sec=network&sec2=operation/visual_console/render_view&id='.$visualConsoleId.'&refr='.$refr.'">'.html_print_image(
    'images/eye.png',
    true,
    [
        'title' => __('View'),
        'class' => 'invert_filter',
    ]
).'</a>';
$options['view']['active'] = true;

if (!is_metaconsole()) {
    if (!$config['pure']) {
        $options['pure']['text'] = '<a href="index.php?sec=network&sec2=operation/visual_console/render_view&id='.$visualConsoleId.'&pure=1&refr='.$refr.'">'.html_print_image(
            'images/full_screen.png',
            true,
            [
                'title' => __('Full screen mode'),
                'class' => 'invert_filter',
            ]
        ).'</a>';
        ui_print_page_header(
            $visualConsoleName,
            'images/visual_console.png',
            false,
            'visual_console_view',
            false,
            $options
        );
    }

    // Set the hidden value for the javascript.
    html_print_input_hidden('metaconsole', 0);
} else {
    // Set the hidden value for the javascript.
    html_print_input_hidden('metaconsole', 1);
}

$edit_capable = (bool) (
    check_acl($config['id_user'], 0, 'VM')
    || check_acl($config['id_user'], 0, 'VW')
);

if ($pure === false) {
    if ($edit_capable === true) {
        echo '<div id ="edit-vc">';
        echo '<div id ="edit-controls" class="visual-console-edit-controls" style="visibility:hidden">';
        echo '<div>';
        $class_camera = 'camera_min link-create-item';
        $class_percentile = 'percentile_item_min link-create-item';
        $class_module_graph = 'graph_min link-create-item';
        $class_donut = 'donut_graph_min link-create-item';
        $class_bars = 'bars_graph_min link-create-item';
        $class_value = 'binary_min link-create-item';
        $class_sla = 'auto_sla_graph_min link-create-item';
        $class_label = 'label_min link-create-item';
        $class_icon = 'icon_min link-create-item';
        $class_clock = 'clock_min link-create-item';
        $class_group = 'group_item_min link-create-item';
        $class_box = 'box_item link-create-item';
        $class_line = 'line_item link-create-item';
        $class_cloud = 'color_cloud_min link-create-item';
        $class_nlink = 'network_link_min link-create-item';
        $class_delete = 'delete_item delete_min';
        $class_copy = 'copy_item';
        if ($config['style'] === 'pandora_black') {
            $class_camera = 'camera_min_white link-create-item';
            $class_percentile = 'percentile_item_min_white link-create-item';
            $class_module_graph = 'graph_min_white link-create-item';
            $class_donut = 'donut_graph_min_white link-create-item';
            $class_bars = 'bars_graph_min_white link-create-item';
            $class_value = 'binary_min_white link-create-item';
            $class_sla = 'auto_sla_graph_min_white link-create-item';
            $class_label = 'label_min_white link-create-item';
            $class_icon = 'icon_min_white link-create-item';
            $class_clock = 'clock_min_white link-create-item';
            $class_group = 'group_item_min_white link-create-item';
            $class_box = 'box_item_white link-create-item';
            $class_line = 'line_item_white link-create-item';
            $class_cloud = 'color_cloud_min_white link-create-item';
            $class_nlink = 'network_link_min_white link-create-item';
            $class_delete = 'delete_item_white delete_min_white';
            $class_copy = 'copy_item_white';
        }

        visual_map_print_button_editor_refactor(
            'STATIC_GRAPH',
            __('Static Image'),
            $class_camera
        );
        visual_map_print_button_editor_refactor(
            'PERCENTILE_BAR',
            __('Percentile Item'),
            $class_percentile
        );
        visual_map_print_button_editor_refactor(
            'MODULE_GRAPH',
            __('Module Graph'),
            $class_module_graph
        );
        visual_map_print_button_editor_refactor(
            'DONUT_GRAPH',
            __('Serialized pie graph'),
            $class_donut
        );
        visual_map_print_button_editor_refactor(
            'BARS_GRAPH',
            __('Bars Graph'),
            $class_bars
        );
        visual_map_print_button_editor_refactor(
            'AUTO_SLA_GRAPH',
            __('Event history graph'),
            $class_sla
        );
        visual_map_print_button_editor_refactor(
            'SIMPLE_VALUE',
            __('Simple Value'),
            $class_value
        );
        visual_map_print_button_editor_refactor(
            'LABEL',
            __('Label'),
            $class_label
        );
        visual_map_print_button_editor_refactor(
            'ICON',
            __('Icon'),
            $class_icon
        );
        visual_map_print_button_editor_refactor(
            'CLOCK',
            __('Clock'),
            $class_clock
        );
        visual_map_print_button_editor_refactor(
            'GROUP_ITEM',
            __('Group'),
            $class_group
        );
        visual_map_print_button_editor_refactor(
            'BOX_ITEM',
            __('Box'),
            $class_box
        );
        visual_map_print_button_editor_refactor(
            'LINE_ITEM',
            __('Line'),
            $class_line
        );
        visual_map_print_button_editor_refactor(
            'COLOR_CLOUD',
            __('Color cloud'),
            $class_cloud
        );
        visual_map_print_button_editor_refactor(
            'NETWORK_LINK',
            __('Network link'),
            $class_nlink
        );
        enterprise_include_once('include/functions_visual_map_editor.php');
        enterprise_hook(
            'enterprise_visual_map_editor_print_toolbox_refactor'
        );
        echo '</div>';
        echo '<div class="visual-console-copy-delete">';
            visual_map_print_button_editor_refactor(
                'button_delete',
                __('Delete Item'),
                $class_delete,
                true
            );
            visual_map_print_button_editor_refactor(
                'button_copy',
                __('Copy Item'),
                $class_copy,
                true
            );
        echo '</div>';
        echo '</div>';

        if ($aclWrite || $aclManage) {
            echo html_print_checkbox_switch('edit-mode', 1, false, true);
        }

        echo '</div>';
    }
}

$bg_color = '';
if ($config['style'] === 'pandora_black') {
    $bg_color = 'style="background-color: #222"';
}

echo '<div class="external-visual-console-container">';
echo '<div id="visual-console-container"></div>';
echo '</div>';

if ($pure === true) {
    // Floating menu - Start.
    echo '<div id="vc-controls" class="zindex999" '.$bg_color.'>';

    echo '<div id="menu_tab">';
    echo '<ul class="mn white-box-content box-shadow flex-row">';

    // Quit fullscreen.
    echo '<li class="nomn">';
    if (is_metaconsole()) {
        $urlNoFull = 'index.php?sec=screen&sec2=screens/screens&action=visualmap&pure=0&id_visualmap='.$visualConsoleId.'&refr='.$refr;
    } else {
        $urlNoFull = 'index.php?sec=network&sec2=operation/visual_console/render_view&id='.$visualConsoleId.'&refr='.$refr;
    }

    echo '<a class="vc-btn-no-fullscreen" href="'.$urlNoFull.'">';
    echo html_print_image('images/normal_screen.png', true, ['title' => __('Back to normal mode'), 'class' => 'invert_filter']);
    echo '</a>';
    echo '</li>';

    // Countdown.
    echo '<li class="nomn">';
    if (is_metaconsole()) {
        echo '<div class="vc-refr-meta">';
    } else {
        echo '<div class="vc-refr">';
    }

    echo '<div id="vc-refr-form">';
    echo __('Refresh').':';
    echo html_print_select(
        get_refresh_time_array(),
        'vc-refr',
        $refr,
        '',
        '',
        0,
        true,
        false,
        false
    );
    echo '</div>';
    echo '</div>';
    echo '</li>';

    // Console name.
    echo '<li class="nomn">';
    if (is_metaconsole()) {
        echo '<div class="vc-title-meta">'.$visualConsoleName.'</div>';
    } else {
        echo '<div class="vc-title">'.$visualConsoleName.'</div>';
    }

    echo '</li>';

    echo '</ul>';
    echo '</div>';

    echo '</div>';
    // Floating menu - End.
    ?>
<style type="text/css">
    /* Avoid the main_pure container 1000px height */
    body.pure {
        min-height: 100px;
        margin: 0px;
        height: 100%;
        background-color: <?php echo $visualConsoleData['backgroundColor']; ?>;
    }
    div#main_pure {
        height: 100%;
        margin: 0px;
        background-color: <?php echo $visualConsoleData['backgroundColor']; ?>;
    }
</style>
    <?php
}

// Check groups can access user.
$aclUserGroups = [];
if (!users_can_manage_group_all('AR')) {
    $aclUserGroups = array_keys(users_get_groups(false, 'AR'));
}

$ignored_params['refr'] = '';
ui_require_javascript_file('tiny_mce', 'include/javascript/tiny_mce/');
ui_require_javascript_file('pandora_visual_console');
include_javascript_d3();
visual_map_load_client_resources();

// Load Visual Console Items.
$visualConsoleItems = VisualConsole::getItemsFromDB(
    $visualConsoleId,
    $aclUserGroups
);
ui_require_css_file('modal');
ui_require_css_file('form');
?>
<div id="modalVCItemForm"></div>
<div id="modalVCItemFormMsg"></div>

<script type="text/javascript">
    var container = document.getElementById("visual-console-container");
    var props = <?php echo (string) $visualConsole; ?>;
    var items = <?php echo '['.implode($visualConsoleItems, ',').']'; ?>;
    var baseUrl = "<?php echo ui_get_full_url('/', false, false, false); ?>";
    var controls = document.getElementById('vc-controls');
    autoHideElement(controls, 1000);
    var handleUpdate = function (prevProps, newProps) {
        if (!newProps) return;

        //Remove spinner change VC.
        document
            .getElementById("visual-console-container")
            .classList.remove("is-updating");

        var div = document
            .getElementById("visual-console-container")
            .querySelector(".div-visual-console-spinner");

        if (div !== null) {
            var parent = div.parentElement;
            if (parent !== null) {
                parent.removeChild(div);
            }
        }

        // Change the background color when the fullscreen mode is enabled.
        if (prevProps
            && prevProps.backgroundColor != newProps.backgroundColor
            && <?php echo json_encode($pure); ?>
        ) {
            var pureBody = document.querySelector("body.pure");
            var pureContainer = document.querySelector("div#main_pure");

            if (pureBody !== null) {
                pureBody.style.backgroundColor = newProps.backgroundColor
            }
            if (pureContainer !== null) {
                pureContainer.style.backgroundColor = newProps.backgroundColor
            }
        }

        // Change the title.
        if (prevProps && prevProps.name != newProps.name) {
            // View title.
            var title = document.querySelector(
                "div#menu_tab_frame_view > div#menu_tab_left span"
            );
            if (title !== null) {
                title.textContent = newProps.name;
            }
            // Fullscreen view title.
            var fullscreenTitle = document.querySelector("div.vc-title");
            if (fullscreenTitle !== null) {
                fullscreenTitle.textContent = newProps.name;
            }
            // TODO: Change the metaconsole title.
        }

        // Change the links.
        if (prevProps && prevProps.id !== newProps.id) {
            var regex = /(id=|id_visual_console=|id_layout=|id_visualmap=)\d+(&?)/gi;
            var replacement = '$1' + newProps.id + '$2';

            var regex_hash = /(hash=)[^&]+(&?)/gi;
            var replacement_hash = '$1' + newProps.hash + '$2';
            // Tab links.
            var menuLinks = document.querySelectorAll("div#menu_tab a");
            if (menuLinks !== null) {
                menuLinks.forEach(function (menuLink) {
                    menuLink.href = menuLink.href.replace(regex, replacement);
                    menuLink.href = menuLink.href.replace(
                        regex_hash,
                        replacement_hash
                    );
                });
            }

            // Go back from fullscreen button.
            var btnNoFull = document.querySelector("a.vc-btn-no-fullscreen");
            if (btnNoFull !== null) {
                btnNoFull.href = btnNoFull.href.replace(regex, replacement);
            }

            // Change the URL (if the browser has support).
            if ("history" in window) {
                var href = window.location.href.replace(regex, replacement);
                href = href.replace(regex_hash, replacement_hash);
                window.history.replaceState({}, document.title, href);
            }
        }
    }

    // Add the datetime when the item was received.
    var receivedAt = new Date();
    items.map(function(item) {
        item["receivedAt"] = receivedAt;
        return item;
    });

    var visualConsoleManager = createVisualConsole(
        container,
        props,
        items,
        baseUrl,
        <?php echo ($refr * 1000); ?>,
        handleUpdate
    );

<?php
if ($edit_capable === true) {
    ?>
    // Enable/disable the edition mode.
    $('input[name=edit-mode]').change(function(event) {
        if ($(this).prop('checked')) {
            visualConsoleManager.visualConsole.enableEditMode();
            visualConsoleManager.changeUpdateInterval(0);
            $('#edit-controls').css('visibility', '');
        } else {
            visualConsoleManager.visualConsole.disableEditMode();
            visualConsoleManager.visualConsole.unSelectItems();
            visualConsoleManager.changeUpdateInterval(<?php echo ($refr * 1000); ?>); // To ms.
            $('#edit-controls').css('visibility', 'hidden');
        }
    });
    <?php
}
?>

    // Update the data fetch interval.
    $('select#vc-refr').change(function(event) {
        var refr = Number.parseInt(event.target.value);

        if (!Number.isNaN(refr)) {
            visualConsoleManager.changeUpdateInterval(refr * 1000); // To ms.

            // Change the URL (if the browser has support).
            if ("history" in window) {
                var regex = /(refr=)\d+(&?)/gi;
                var replacement = '$1' + refr + '$2';
                var href = window.location.href.replace(regex, replacement);
                window.history.replaceState({}, document.title, href);
            }
        }
    });

    visualConsoleManager.visualConsole.onItemSelectionChanged(function (e) {
        if (e.selected === true) {
            $('#button-button_delete').prop('disabled', false);
            $('#button-button_copy').prop('disabled', false);
        } else {
            $('#button-button_delete').prop('disabled', true);
            $('#button-button_copy').prop('disabled', true);
        }
    });

    $('#button-button_delete').click(function (event){
        confirmDialog({
            title: "<?php echo __('Delete'); ?>",
            message: "<?php echo __('Are you sure'); ?>"+"?",
            onAccept: function() {
                visualConsoleManager.visualConsole.elements.forEach(item => {
                    if (item.meta.isSelected === true) {
                        visualConsoleManager.deleteItem(item);
                    }
                });
            }
        });
    });

    $('#button-button_copy').click(function (event){
        visualConsoleManager.visualConsole.elements.forEach(item => {
            if (item.meta.isSelected === true) {
                visualConsoleManager.copyItem(item);
            }
        });
    });

    $('.link-create-item').click(function (event){
        var type = event.target.id.substr(7);
        visualConsoleManager.createItem(type);
    });

    /**
     * Process ajax responses and shows a dialog with results.
     */
    function handleFormResponse(data) {
        var title = "<?php echo __('Success'); ?>";
        var text = '';
        var failed = 0;
        try {
            data = JSON.parse(data);
            text = data['result'];
        } catch (err) {
            title =  "<?php echo __('Failed'); ?>";
            text = err.message;
            failed = 1;
        }
        if (!failed && data['error'] != undefined) {
            title =  "<?php echo __('Failed'); ?>";
            text = data['error'];
            failed = 1;
        }
        if (data['report'] != undefined) {
            data['report'].forEach(function (item){
                text += '<br>'+item;
            });
        }

        if (failed == 1) {
            $('#modalVCItemFormMsg').empty();
            $('#modalVCItemFormMsg').html(text);
            $('#modalVCItemFormMsg').dialog({
                width: 450,
                position: {
                    my: 'center',
                    at: 'center',
                    of: window,
                    collision: 'fit'
                },
                title: title,
                buttons: [
                    {
                        class: "ui-widget ui-state-default ui-corner-all ui-button-text-only sub ok submit-next",
                        text: 'OK',
                        click: function(e) {
                            $(this).dialog('close');
                        }
                    }
                ]
            });
            // Failed.
            return false;
        }
        
        // Success, return result.
        return data['result'];
    }
    
</script>
