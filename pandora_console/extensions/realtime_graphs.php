<?php
/**
 * Funtions real time.
 *
 * @category   Realtime
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

require_once $config['homedir'].'/include/graphs/fgraph.php';
require_once $config['homedir'].'/include/functions_snmp_browser.php';


/**
 * Real time charts.
 *
 * @return void
 */
function pandora_realtime_graphs()
{
    global $config;
    check_login();

    $id_network = get_parameter('id_network', 0);
    $action = get_parameter('action', 'list');

    $onheader = [];

    $hide_header = get_parameter('hide_header', 0);
    if ($hide_header === 0) {
        ui_print_page_header(
            __('Realtime graphs'),
            'images/extensions.png',
            false,
            'real_time_view',
            false,
            $onheader
        );
    }

    $chart[time()]['graph'] = '0';
    $interactive_graph = true;
    $color = [];
    $legend = '';
    $long_index = [];
    $no_data_image = '';

    $canvas = '<div id="graph_container">';
    $canvas .= '<div id="chartLegend"></div>';

    $width = 800;
    $height = 300;

    $data_array['realtime']['data'][0][0] = (time() - 10);
    $data_array['realtime']['data'][0][1] = 0;
    $data_array['realtime']['data'][1][0] = time();
    $data_array['realtime']['data'][1][1] = 0;
    $data_array['realtime']['color'] = 'green';

    $params = [
        'agent_module_id'   => false,
        'period'            => 300,
        'width'             => $width,
        'height'            => $height,
        'unit'              => $unit,
        'only_image'        => $only_image,
        'homeurl'           => $homeurl,
        'type_graph'        => 'area',
        'font'              => $config['fontpath'],
        'font-size'         => $config['font_size'],
        'array_data_create' => $data_array,
        'show_legend'       => false,
        'show_menu'         => false,
    ];

    $canvas .= grafico_modulo_sparse($params);

    $canvas .= '</div>';
    echo $canvas;

    $table->width = '100%';
    $table->id = 'table-form';
    $table->class = 'databox filters';
    $table->style = [];
    $table->cellpadding = '0';
    $table->cellspacing = '0';
    $table->style['graph'] = 'font-weight: bold;';
    $table->style['refresh'] = 'font-weight: bold;';
    $table->style['incremental'] = 'font-weight: bold;';
    $table->style['reset'] = 'font-weight: bold;';
    $table->style['snmp_address'] = 'font-weight: bold;';
    $table->style['snmp_community'] = 'font-weight: bold;';
    $table->style['snmp_oid'] = 'font-weight: bold;';
    $table->style['snmp_oid'] = 'font-weight: bold;';
    $table->data = [];

    $graph_fields['cpu_load'] = __('%s Server CPU', get_product_name());
    $graph_fields['pending_packets'] = __(
        'Pending packages from %s Server',
        get_product_name()
    );
    $graph_fields['disk_io_wait'] = __(
        '%s Server Disk IO Wait',
        get_product_name()
    );
    $graph_fields['apache_load'] = __(
        '%s Server Apache load',
        get_product_name()
    );
    $graph_fields['mysql_load'] = __(
        '%s Server MySQL load',
        get_product_name()
    );
    $graph_fields['server_load'] = __(
        '%s Server load',
        get_product_name()
    );
    $graph_fields['snmp_interface'] = __('SNMP Interface throughput');

    $graph = get_parameter('graph', 'cpu_load');
    $refresh = get_parameter('refresh', '1000');

    if ($graph != 'snmp_module') {
        $data['graph'] = __('Graph').'&nbsp;&nbsp;';
        $data['graph'] .= html_print_select(
            $graph_fields,
            'graph',
            $graph,
            '',
            '',
            0,
            true
        );
    }

    $refresh_fields[1000]  = human_time_description_raw(1, true, 'large');
    $refresh_fields[5000]  = human_time_description_raw(5, true, 'large');
    $refresh_fields[10000] = human_time_description_raw(10, true, 'large');
    $refresh_fields[30000] = human_time_description_raw(30, true, 'large');

    if ($graph == 'snmp_module') {
        $agent_alias = io_safe_output(get_parameter('agent_alias', ''));
        $module_name = io_safe_output(get_parameter('module_name', ''));
        $module_incremental = get_parameter('incremental', 0);
        $data['module_info'] = $agent_alias.': <b>'.$module_name.'</b>';

        // Append all the hidden in this cell.
        $data['module_info'] .= html_print_input_hidden(
            'incremental',
            $module_incremental,
            true
        );
        $data['module_info'] .= html_print_select(
            ['snmp_module' => '-'],
            'graph',
            'snmp_module',
            '',
            '',
            0,
            true,
            false,
            true,
            '',
            false,
            'display: none;'
        );
    }

    $data['refresh'] = __('Refresh interval').'&nbsp;&nbsp;';
    $data['refresh'] .= html_print_select(
        $refresh_fields,
        'refresh',
        $refresh,
        '',
        '',
        0,
        true
    );
    if ($graph != 'snmp_module') {
        $data['incremental'] = __('Incremental').'&nbsp;&nbsp;';
        $data['incremental'] .= html_print_checkbox('incremental', 1, 0, true);
    }

    $data['reset'] = html_print_button(
        __('Clear graph'),
        'reset',
        false,
        'javascript:realtimeGraphs.clearGraph();',
        'class="sub delete mgn_tp_0" ',
        true
    );
    $table->data[] = $data;

    if ($graph == 'snmp_interface' || $graph == 'snmp_module') {
        echo snmp_browser_print_container(true, '100%', '60%', 'none');
    }

    // Print the relative path to AJAX calls.
    html_print_input_hidden('rel_path', get_parameter('rel_path', ''));

    // Print the form.
    echo '<form id="realgraph" method="post">';
    html_print_table($table);
    echo '</form>';

    // Define a custom action to save
    // the OID selected in the SNMP browser to the form.
    html_print_input_hidden(
        'custom_action',
        urlencode(
            base64_encode(
                '&nbsp;<a href="javascript:realtimeGraphs.setOID();"><img src="'.ui_get_full_url('images').'/input_filter.disabled.png" title="'.__('Use this OID').'" class="vertical_middle"></img></a>'
            )
        ),
        false
    );
    html_print_input_hidden('incremental_base', '0');

    echo '<script type="text/javascript" src="'.ui_get_full_url('include/javascript/pandora_snmp_browser.js').'"></script>';
    echo '<script type="text/javascript" src="'.ui_get_full_url('extensions/realtime_graphs/realtime_graphs.js').'"></script>';
    echo '<link rel="stylesheet" type="text/css" href="'.ui_get_full_url('extensions/realtime_graphs/realtime_graphs.css').'"></style>';

    // Store servers timezone offset to be retrieved from js.
    set_js_value('timezone_offset', date('Z', time()));
}


extensions_add_operation_menu_option(
    __('Realtime graphs'),
    'estado',
    null,
    'v1r1',
    'view'
);
extensions_add_main_function('pandora_realtime_graphs');

$db = null;
