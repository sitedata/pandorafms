<?php
/**
 * Netflow live view
 *
 * @package    Pandora FMS open.
 * @subpackage UI file.
 *
 * Pandora FMS - http://pandorafms.com
 * ==================================================
 * Copyright (c) 2005-2021 Artica Soluciones Tecnologicas
 * Please see http://pandorafms.org for full contribution list
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; version 2
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

global $config;

require_once $config['homedir'].'/include/functions_graph.php';
require_once $config['homedir'].'/include/functions_ui.php';
require_once $config['homedir'].'/include/functions_netflow.php';

ui_require_javascript_file('calendar');

// ACL.
check_login();
if (! check_acl($config['id_user'], 0, 'AR') && ! check_acl($config['id_user'], 0, 'AW')) {
    db_pandora_audit(
        'ACL Violation',
        'Trying to access event viewer'
    );
    include 'general/noaccess.php';
    return;
}

$pure = get_parameter('pure', 0);

// Ajax callbacks.
if (is_ajax()) {
    $get_filter_type = get_parameter('get_filter_type', 0);
    $get_filter_values = get_parameter('get_filter_values', 0);

    // Get filter of the current netflow filter.
    if ($get_filter_type) {
        $id = get_parameter('id');

        $advanced_filter = db_get_value_filter('advanced_filter', 'tnetflow_filter', ['id_sg' => $id]);

        if (empty($advanced_filter)) {
            $type = 0;
        } else {
            $type = 1;
        }

        echo $type;
    }

    // Get values of the current netflow filter.
    if ($get_filter_values) {
        $id = get_parameter('id');

        $filter_values = db_get_row_filter('tnetflow_filter', ['id_sg' => $id]);

        // Decode HTML entities.
        $filter_values['advanced_filter'] = io_safe_output($filter_values['advanced_filter']);


        echo json_encode($filter_values);
    }

    return;
}

// Read filter configuration.
$filter_id = (int) get_parameter('filter_id', 0);
$filter['id_name'] = get_parameter('name', '');
$filter['id_group'] = (int) get_parameter('assign_group', 0);
$filter['aggregate'] = get_parameter('aggregate', '');
$filter['ip_dst'] = get_parameter('ip_dst', '');
$filter['ip_src'] = get_parameter('ip_src', '');
$filter['dst_port'] = get_parameter('dst_port', '');
$filter['src_port'] = get_parameter('src_port', '');
$filter['advanced_filter'] = get_parameter('advanced_filter', '');
$filter['router_ip'] = get_parameter('router_ip');

// Read chart configuration.
$chart_type = get_parameter('chart_type', 'netflow_area');
$max_aggregates = (int) get_parameter('max_aggregates', 10);
$update_date = (int) get_parameter('update_date', 0);
$connection_name = get_parameter('connection_name', '');
$interval_length = get_parameter('interval_length', NETFLOW_RES_MEDD);
$address_resolution = (int) get_parameter('address_resolution', $config['netflow_get_ip_hostname']);
$filter_selected = (int) get_parameter('filter_selected', 0);

// Read time values.
$date = get_parameter_post('date', date(DATE_FORMAT, get_system_time()));
$time = get_parameter_post('time', date(TIME_FORMAT, get_system_time()));
$end_date = strtotime($date.' '.$time);
$is_period = (bool) get_parameter('is_period', false);
$period = (int) get_parameter('period', SECONDS_1DAY);
$time_lower = get_parameter('time_lower', date(TIME_FORMAT, ($end_date - $period)));
$date_lower = get_parameter('date_lower', date(DATE_FORMAT, ($end_date - $period)));
$start_date = ($is_period) ? ($end_date - $period) : strtotime($date_lower.' '.$time_lower);
if (!$is_period) {
    $period = ($end_date - $start_date);
} else {
    $time_lower = date(TIME_FORMAT, $start_date);
    $date_lower = date(DATE_FORMAT, $start_date);
}

// Read buttons.
$draw = get_parameter('draw_button', '');
$save = get_parameter('save_button', '');
$update = get_parameter('update_button', '');

if (!is_metaconsole()) {
    // Header.
    ui_print_page_header(
        __('Netflow live view'),
        'images/op_netflow.png',
        false,
        '',
        false,
        []
    );

    $is_windows = strtoupper(substr(PHP_OS, 0, 3)) == 'WIN';
    if ($is_windows) {
        ui_print_error_message(__('Not supported in Windows systems'));
    } else {
        netflow_print_check_version_error();
    }
} else {
    $nav_bar = [
        [
            'link' => 'index.php?sec=main',
            'text' => __('Main'),
        ],
        [
            'link' => 'index.php?sec=netf&sec2=operation/netflow/nf_live_view',
            'text' => __('Netflow live view'),
        ],
    ];

    ui_meta_print_page_header($nav_bar);

    ui_meta_print_header(__('Netflow live view'));
}

// Save user defined filter.
if ($save != '' && check_acl($config['id_user'], 0, 'AW')) {
    // Save filter args.
    $filter['filter_args'] = netflow_get_filter_arguments($filter, true);

    $filter_id = db_process_sql_insert('tnetflow_filter', $filter);
    if ($filter_id === false) {
        $filter_id = 0;
        ui_print_error_message(__('Error creating filter'));
    } else {
        ui_print_success_message(__('Filter created successfully'));
    }
} else if ($update != '' && check_acl($config['id_user'], 0, 'AW')) {
    // Update current filter.
    // Do not update the filter name and group.
    $filter_copy = $filter;
    unset($filter_copy['id_name']);
    unset($filter_copy['id_group']);

    // Save filter args.
    $filter_copy['filter_args'] = netflow_get_filter_arguments($filter_copy, true);

    $result = db_process_sql_update(
        'tnetflow_filter',
        $filter_copy,
        ['id_sg' => $filter_id]
    );
    ui_print_result_message(
        $result,
        __('Filter updated successfully'),
        __('Error updating filter')
    );
}


// The filter name will not be needed anymore.
$filter['id_name'] = '';

$netflow_disable_custom_lvfilters = false;
if (isset($config['netflow_disable_custom_lvfilters'])) {
    $netflow_disable_custom_lvfilters = $config['netflow_disable_custom_lvfilters'];
}

enterprise_hook('open_meta_frame');

$class = 'databox filters';

echo '<form method="post" action="'.$config['homeurl'].'index.php?sec=netf&sec2=operation/netflow/nf_live_view&pure='.$pure.'">';

    echo "<table class='".$class."' width='100%'>";
if (is_metaconsole()) {
    echo '<thead>
			<tr>
				<th align=center colspan=6>
					'.__('Draw live filter').'
				</th>
			</tr>
		</thead>';

    $list_servers = [];

    $servers = db_get_all_rows_sql(
        'SELECT *
			FROM tmetaconsole_setup'
    );
    if ($servers === false) {
        $servers = [];
    }

    foreach ($servers as $server) {
        // If connection was good then retrieve all data server.
        if (metaconsole_load_external_db($server)) {
            $connection = true;
        } else {
            $connection = false;
        }

        $row = db_get_row('tconfig', 'token', 'activate_netflow');


        if ($row['value']) {
            $list_servers[$server['server_name']] = $server['server_name'];
        }

        metaconsole_restore_db();
    }

    echo '<tr>';
    echo '<td><b>'.__('Connection').'</b></td>';
    echo '<td>'.html_print_select(
        $list_servers,
        'connection_name',
        $connection_name,
        '',
        '',
        0,
        true,
        false,
        false
    ).'</td>';
    echo '</tr>';
}

    echo '<tr>';

    $class_not_period = ($is_period) ? 'nf_hidden' : 'nf_display';
    $class_period = ($is_period) ? 'nf_display' : 'nf_hidden';
    echo '<td>';
    echo '<b class="'.$class_period.'">'.__('Interval').'</b>';
    echo '<b class="'.$class_not_period.'">'.__('Start date').'</b>';
    echo '</td>';
    echo '<td>';
    echo html_print_extended_select_for_time('period', $period, '', '', 0, false, true, false, true, $class_period);
    echo html_print_input_text('date_lower', $date_lower, false, 13, 10, true, false, false, '', $class_not_period);
    echo html_print_image(
        'images/calendar_view_day.png',
        true,
        [
            'alt'   => 'calendar',
            'class' => $class_not_period,
        ]
    ).html_print_input_text('time_lower', $time_lower, false, 10, 8, true, false, false, '', $class_not_period);
    echo html_print_checkbox(
        'is_period',
        1,
        ($is_period === true) ? 1 : 0,
        true,
        false,
        'nf_view_click_period(event)'
    );
    echo ui_print_help_tip(__('Select this checkbox to write interval instead a date.'), true);
    echo '</td>';

    echo '<td><b>'.__('End date').'</b></td>';
    echo '<td>'.html_print_input_text('date', $date, false, 13, 10, true).html_print_image(
        'images/calendar_view_day.png',
        true,
        ['alt' => 'calendar']
    ).html_print_input_text('time', $time, false, 10, 8, true);
    echo '</td>';

    echo '<td><b>'.__('Resolution').ui_print_help_tip(__('The interval will be divided in chunks the length of the resolution.'), true).'</b></td>';
    echo '<td>'.html_print_select(
        netflow_resolution_select_params(),
        'interval_length',
        $interval_length,
        '',
        '',
        0,
        true,
        false,
        false
    ).'</td>';

    echo '</tr>';
    echo '<tr>';

    echo '<td><b>'.__('Type').'</b></td>';
    echo '<td>'.html_print_select(
        netflow_get_chart_types(),
        'chart_type',
        $chart_type,
        '',
        '',
        0,
        true
    ).'</td>';

    echo '<td><b>'.__('Max. values').'</b></td>';
    $max_values = [
        '2'             => '2',
        '5'             => '5',
        '10'            => '10',
        '15'            => '15',
        '20'            => '20',
        '25'            => '25',
        '50'            => '50',
        $max_aggregates => $max_aggregates,
    ];
    echo '<td>'.html_print_select($max_values, 'max_aggregates', $max_aggregates, '', '', 0, true).'<a id="max_values" href="#" onclick="javascript: edit_max_value();">'.html_print_image('images/pencil.png', true, ['id' => 'pencil']).'</a>';
    echo '</td>';

    echo '<td><b>'.__('Aggregate by').'</b></td>';
    $aggregate_list = [];
    $aggregate_list = [
        'srcip'   => __('Src Ip Address'),
        'dstip'   => __('Dst Ip Address'),
        'srcport' => __('Src Port'),
        'dstport' => __('Dst Port'),
    ];
    echo '<td>'.html_print_select($aggregate_list, 'aggregate', $filter['aggregate'], '', '', 0, true, false, true, '', false).'</td>';

    echo '</tr>';

    // Read filter type.
    if ($filter['advanced_filter'] != '') {
        $filter_type = 1;
    } else {
        $filter_type = 0;
    }

    echo "<tr class='filter_save invisible'>";

    echo "<td colspan='6'>".ui_print_error_message('Define a name for the filter and click on Save as new filter again', '', true).'</td>';

    echo '</tr>';
    echo "<tr class='filter_save invisible'>";

    echo '<td><span id="filter_name_color"><b>'.__('Name').'</b></span></td>';
    echo "<td colspan='2'>".html_print_input_text(
        'name',
        $filter['id_name'],
        false,
        20,
        80,
        true
    ).'</td>';
    $own_info = get_user_info($config['id_user']);
    echo '<td><span id="filter_group_color"><b>'.__('Group').'</b></span></td>';
    echo "<td colspan='2'>".html_print_select_groups($config['id_user'], 'IW', $own_info['is_admin'], 'assign_group', $filter['id_group'], '', '', -1, true, false, false).'</td>';
    echo '</tr>';

    $advanced_toggle = '<table class="w100p">';

    $advanced_toggle .= '<tr>';
    if ($netflow_disable_custom_lvfilters) {
        $advanced_toggle .= '<td></td>';
        $advanced_toggle .= '<td></td>';
    } else {
        $advanced_toggle .= '<td><b>'.__('Filter').'</b></td>';
        $advanced_toggle .= '<td colspan="2">'.__('Normal').' '.html_print_radio_button_extended('filter_type', 0, '', $filter_type, false, 'displayNormalFilter();', 'class="mrgn_right_40px"', true).__('Custom').' '.html_print_radio_button_extended('filter_type', 1, '', $filter_type, false, 'displayAdvancedFilter();', 'class="mrgn_right_40px"', true).'</td>';
    }



    $advanced_toggle .= '<td><b>'.__('Load filter').'</b></td>';
    $user_groups = users_get_groups($config['id_user'], 'AR', $own_info['is_admin'], true);
    $user_groups[0] = 0;
    // Add all groups.
    $sql = 'SELECT *
		FROM tnetflow_filter
		WHERE id_group IN ('.implode(',', array_keys($user_groups)).')';
    $advanced_toggle .= "<td colspan='3'>".html_print_select_from_sql($sql, 'filter_id', $filter_id, '', __('Select a filter'), 0, true);
    $advanced_toggle .= html_print_input_hidden('filter_selected', $filter_selected, false);
    $advanced_toggle .= '</td>';
    $advanced_toggle .= '</tr>';

    $advanced_toggle .= "<tr class='filter_normal'>";
    if ($netflow_disable_custom_lvfilters) {
        $advanced_toggle .= '<td></td>';
        $advanced_toggle .= '<td></td>';
    } else {
        $advanced_toggle .= "<td class='bolder'>".__('Dst Ip').ui_print_help_tip(__('Destination IP. A comma separated list of destination ip. If we leave the field blank, will show all ip. Example filter by ip:<br>25.46.157.214,160.253.135.249'), true).'</td>';
        $advanced_toggle .= '<td colspan="2">'.html_print_input_text('ip_dst', $filter['ip_dst'], false, 40, 80, true).'</td>';
    }

    if ($netflow_disable_custom_lvfilters) {
        $advanced_toggle .= '<td></td>';
        $advanced_toggle .= '<td></td>';
    } else {
        $advanced_toggle .= "<td class='bolder'>".__('Src Ip').ui_print_help_tip(__('Source IP. A comma separated list of source ip. If we leave the field blank, will show all ip. Example filter by ip:<br>25.46.157.214,160.253.135.249'), true).'</td>';
        $advanced_toggle .= '<td colspan="2">'.html_print_input_text('ip_src', $filter['ip_src'], false, 40, 80, true).'</td>';
    }

    $advanced_toggle .= '</tr>';

    $advanced_toggle .= "<tr class='filter_normal'>";
    if ($netflow_disable_custom_lvfilters) {
        $advanced_toggle .= '<td></td>';
        $advanced_toggle .= '<td></td>';
    } else {
        $advanced_toggle .= "<td class='bolder'>".__('Dst Port').ui_print_help_tip(__('Destination port. A comma separated list of destination ports. If we leave the field blank, will show all ports. Example filter by ports 80 and 22:<br>80,22'), true).'</td>';
        $advanced_toggle .= '<td colspan="2">'.html_print_input_text('dst_port', $filter['dst_port'], false, 40, 80, true).'</td>';
    }

    if ($netflow_disable_custom_lvfilters) {
        $advanced_toggle .= '<td></td>';
        $advanced_toggle .= '<td></td>';
    } else {
        $advanced_toggle .= "<td class='bolder'>".__('Src Port').ui_print_help_tip(__('Source port. A comma separated list of source ports. If we leave the field blank, will show all ports. Example filter by ports 80 and 22:<br>80,22'), true).'</td>';
        $advanced_toggle .= '<td colspan="2">'.html_print_input_text('src_port', $filter['src_port'], false, 40, 80, true).'</td>';
    }

    $advanced_toggle .= '</tr>';

    $advanced_toggle .= "<tr class='filter_advance invisible'>";
    if ($netflow_disable_custom_lvfilters) {
        $advanced_toggle .= '<td></td>';
        $advanced_toggle .= '<td></td>';
    } else {
        $advanced_toggle .= '<td>'.ui_print_help_icon('pcap_filter', true).'</td>';
        $advanced_toggle .= "<td colspan='5'>".html_print_textarea('advanced_filter', 4, 40, $filter['advanced_filter'], "class='min-height-0px w90p'", true).'</td>';
    }

    $advanced_toggle .= '</tr>';
    $advanced_toggle .= '<tr>';

    $onclick = "if (!confirm('".__('Warning').'. '.__('IP address resolution can take a lot of time')."')) return false;";
    $radio_buttons = __('Yes').'&nbsp;&nbsp;'.html_print_radio_button_extended(
        'address_resolution',
        1,
        '',
        $address_resolution,
        false,
        $onclick,
        '',
        true
    ).'&nbsp;&nbsp;&nbsp;';
    $radio_buttons .= __('No').'&nbsp;&nbsp;'.html_print_radio_button(
        'address_resolution',
        0,
        '',
        $address_resolution,
        true
    );
    $advanced_toggle .= '<td><b>'.__('IP address resolution').'</b>'.ui_print_help_tip(__('Resolve the IP addresses to get their hostnames.'), true).'</td>';
    $advanced_toggle .= '<td colspan="2">'.$radio_buttons.'</td>';

    $advanced_toggle .= '<td><b>'.__('Source ip').'</b></td>';
    $advanced_toggle .= '<td colspan="2">'.html_print_input_text('router_ip', $filter['router_ip'], false, 40, 80, true).'</td>';

    $advanced_toggle .= '</tr>';

    $advanced_toggle .= '</table>';

    echo '<tr><td colspan="6">';
    echo ui_toggle(
        $advanced_toggle,
        __('Advanced'),
        '',
        '',
        true,
        true,
        'white_box white_box_opened',
        'no-border flex-row'
    );
    echo '</td></tr>';
    echo '</table>';

    echo "<table width='100%' class='min-height-0px right'><tr><td>";

    echo html_print_submit_button(__('Draw'), 'draw_button', false, 'class="sub upd"', true);

    if (!$netflow_disable_custom_lvfilters) {
        if (check_acl($config['id_user'], 0, 'AW')) {
            html_print_submit_button(__('Save as new filter'), 'save_button', false, ' class="sub upd mrgn_lft_5px" onClick="return defineFilterName();"');
            html_print_submit_button(__('Update current filter'), 'update_button', false, 'class="sub upd mrgn_lft_5px"');
        }
    }

    echo '</td></tr></table>';

    echo '</form>';

    if ($draw != '') {
        // Draw.
        echo '<br/>';

        // No filter selected.
        if ($netflow_disable_custom_lvfilters && $filter_selected == 0) {
            ui_print_error_message(__('No filter selected'));
        } else {
            // Draw the netflow chart.
            echo netflow_draw_item(
                $start_date,
                $end_date,
                $interval_length,
                $chart_type,
                $filter,
                $max_aggregates,
                $connection_name,
                'HTML',
                $address_resolution
            );
        }
    }

    enterprise_hook('close_meta_frame');

    ui_include_time_picker();
    ?>

<script type="text/javascript">
    function edit_max_value () {
        if ($("#max_values img").attr("id") == "pencil") {
            $("#max_values img").attr("src", "images/default_list.png");
            $("#max_values img").attr("id", "select");
            var value = $("#max_aggregates").val();
            $("#max_aggregates").replaceWith("<input id='max_aggregates' name='max_aggregates' type='text'>");
            $("#max_aggregates").val(value);
        }
        else {
            $("#max_values img").attr("src", "images/pencil.png");
            $("#max_values img").attr("id", "pencil");
            $("#max_aggregates").replaceWith("<select id='max_aggregates' name='max_aggregates'>");
            var o = new Option("2", 2);
            var o1 = new Option("5", 5);
            var o2 = new Option("10", 10);
            var o3 = new Option("15", 15);
            var o4 = new Option("20", 20);
            var o5 = new Option("25", 25);
            var o6 = new Option("50", 50);
            $("#max_aggregates").append(o);
            $("#max_aggregates").append(o1);
            $("#max_aggregates").append(o2);
            $("#max_aggregates").append(o3);
            $("#max_aggregates").append(o4);
            $("#max_aggregates").append(o5);
            $("#max_aggregates").append(o6);
        }
        
    }

    // Hide the normal filter and display the advanced filter
    function displayAdvancedFilter () {
        // Erase the normal filter
        $("#text-ip_dst").val('');
        $("#text-ip_src").val('');
        $("#text-dst_port").val('');
        $("#text-src_port").val('');
        
        // Hide the normal filter
        $(".filter_normal").hide();
        
        // Show the advanced filter
        $(".filter_advance").show();
    };
    
    // Hide the advanced filter and display the normal filter
    function displayNormalFilter () {
        // Erase the advanced filter
        $("#textarea_advanced_filter").val('');
        
        // Hide the advanced filter
        $(".filter_advance").hide();
        
        // Show the normal filter
        $(".filter_normal").show();
    };
    
    // Ask the user to define a name for the filter in order to save it
    function defineFilterName () {
        if ($("#text-name").val() == '') {
            $(".filter_save").show();
            
            return false;
        }
        
        return true;
    };

    // Display the appropriate filter
    var filter_type = <?php echo $filter_type; ?>;
    if (filter_type == 0) {
        displayNormalFilter ();
    }
    else {
        displayAdvancedFilter ();
    }
    
    $("#filter_id").change(function () {
        var filter_type;
        
        // Hide information and name/group row
        $(".filter_save").hide();
        
        // Clean fields
        if ($("#filter_id").val() == 0) {
            displayNormalFilter();
            
            // Check right filter type
            $("#radiobtn0001").attr("checked", "checked");
            
            $("#hidden-filter_selected").val(0);
            $("#text-ip_dst").val('');
            $("#text-ip_src").val('');
            $("#text-dst_port").val('');
            $("#text-src_port").val('');
            $("#text-router_ip").val('');
            $("#textarea_advanced_filter").val('');
            $("#aggregate").val('');
            
            // Hide update filter button
            $("#submit-update_button").hide();
            
        }
        else {
            // Load fields from DB
            $("#hidden-filter_selected").val(1);
            
            // Get filter type
            <?php
            if (! defined('METACONSOLE')) {
                echo 'jQuery.post ("ajax.php",';
            } else {
                echo 'jQuery.post ("'.$config['homeurl'].'../../ajax.php",';
            }
            ?>
                {"page" : "operation/netflow/nf_live_view",
                "get_filter_type" : 1,
                "id" : $("#filter_id").val()
                },
                function (data) {
                    filter_type = data;
                    // Display the appropriate filter
                    if (filter_type == 0) {
                        $(".filter_normal").show();
                        $(".filter_advance").hide();
                        
                        // Check right filter type
                        $("#radiobtn0001").attr("checked", "checked");
                    }
                    else {
                        $(".filter_normal").hide();
                        $(".filter_advance").show();
                        
                        // Check right filter type
                        $("#radiobtn0002").attr("checked", "checked");
                    }
                });
            
            // Get filter values from DB
            <?php
            if (! defined('METACONSOLE')) {
                echo 'jQuery.post ("ajax.php",';
            } else {
                echo 'jQuery.post ("'.$config['homeurl'].'../../ajax.php",';
            }
            ?>
                {"page" : "operation/netflow/nf_live_view",
                "get_filter_values" : 1,
                "id" : $("#filter_id").val()
                },
                function (data) {
                    jQuery.each (data, function (i, val) {
                        if (i == 'ip_dst')
                            $("#text-ip_dst").val(val);
                        if (i == 'ip_src')
                            $("#text-ip_src").val(val);
                        if (i == 'dst_port')
                            $("#text-dst_port").val(val);
                        if (i == 'src_port')
                            $("#text-src_port").val(val);
                        if (i == 'router_ip')
                            $("#text-router_ip").val(val);
                        if (i == 'advanced_filter')
                            $("#textarea_advanced_filter").val(val);
                        if (i == 'aggregate')
                            $("#aggregate").val(val);
                    });
                },
                "json");

            // Shows update filter button
            $("#submit-update_button").show();
            
        }
        
    });
    
    $(document).ready( function() {
        // Hide update filter button
        if ($("#filter_id").val() == 0) {
            $("#submit-update_button").hide();
        }
        else {
            $("#submit-update_button").show();
        }
        
        // Change color of name and group if save button has been pushed
        $("#submit-save_button").click(function () {
            if ($("#text-name").val() == "") {
                $('#filter_name_color').css('color', '#CC0000');
                $('#filter_group_color').css('color', '#CC0000');
            }
            else {
                $('#filter_name_color').css('color', '#000000');
                $('#filter_group_color').css('color', '#000000');
            }
        });
    });
    
    $("#text-time, #text-time_lower").timepicker({
        showSecond: true,
        timeFormat: '<?php echo TIME_FORMAT_JS; ?>',
        timeOnlyTitle: '<?php echo __('Choose time'); ?>',
        timeText: '<?php echo __('Time'); ?>',
        hourText: '<?php echo __('Hour'); ?>',
        minuteText: '<?php echo __('Minute'); ?>',
        secondText: '<?php echo __('Second'); ?>',
        currentText: '<?php echo __('Now'); ?>',
        closeText: '<?php echo __('Close'); ?>'});
        
    $("#text-date, #text-date_lower").datepicker({dateFormat: "<?php echo DATE_FORMAT_JS; ?>"});
    
    $.datepicker.regional["<?php echo get_user_language(); ?>"];

    function nf_view_click_period(event) {
        $(".nf_display").toggle();
        $(".nf_hidden").toggle(); 
    }
</script>
<style type="text/css">
.nf_hidden {
  display: none;
}
</style>