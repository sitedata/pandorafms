<?php
/**
 * Extension to manage a list of gateways and the node address where they should
 * point to.
 *
 * @category   Reporting
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


require_once $config['homedir'].'/include/functions_custom_graphs.php';
require_once $config['homedir'].'/include/db/oracle.php';

// Login check.
check_login();

if (! check_acl($config['id_user'], 0, 'RW')
    && ! check_acl($config['id_user'], 0, 'RM')
) {
    db_pandora_audit(
        'ACL Violation',
        'Trying to access report builder'
    );
    include 'general/noaccess.php';
    exit;
}

$meta = false;
if (($config['metaconsole'] == 1) && (defined('METACONSOLE'))) {
    $meta = true;
}

$show_graph_options = [];
$show_graph_options[0] = __('Only table');
$show_graph_options[1] = __('Table & Graph');
$show_graph_options[2] = __('Only graph');

// SLA sorting options.
$show_sort_options = [];
$show_sort_options[1] = __('Ascending');
$show_sort_options[2] = __('Descending');

enterprise_include('/godmode/reporting/reporting_builder.item_editor.php');
require_once $config['homedir'].'/include/functions_agents.php';
if (enterprise_include_once('include/functions_metaconsole.php')) {
    $servers = enterprise_hook('metaconsole_get_connection_names');
}

$idAgent = null;
$idAgentModule = null;
$idCustomGraph = null;
$text = null;
$label = null;
$header = null;
$idCustom = null;
$url = null;
$field = null;
$line = null;
$group = 0;
$group_by_agent = 0;
$order_uptodown = 0;
$show_resume = 0;
$show_address_agent = 0;
$top_n = 0;
$source = 0;
$top_n_value = 10;
$exception_condition = REPORT_EXCEPTION_CONDITION_EVERYTHING;
$exception_condition_value = 10;
$modulegroup = 0;
$period = SECONDS_1DAY;
$search = '';
$log_number = 1000;
// Added support for projection graphs.
$period_pg = SECONDS_5DAY;
$projection_period = SECONDS_5DAY;
$only_display_wrong = 0;
// Added support for prediction date report.
$min_interval = '0.00';
$max_interval = '0.00';
$monday = true;
$tuesday = true;
$wednesday = true;
$thursday = true;
$friday = true;
$saturday = true;
$sunday = true;
$time_from = '00:00:00';
$time_to = '00:00:00';
$compare_work_time = false;
$show_graph = 0;
$sla_sorted_by = 0;
$id_agents = '';
$inventory_modules = [];
$date = null;
$current_month = true;

// Only avg is selected by default for the simple graphs.
$fullscale = false;
$percentil = false;
$time_compare_overlapped = false;

// Added for events items.
$show_summary_group    = false;
$filter_event_severity = false;
$filter_event_type     = false;
$filter_event_status   = false;
$event_graph_by_agent = false;
$event_graph_by_user_validator = false;
$event_graph_by_criticity = false;
$event_graph_validated_vs_unvalidated = false;

$netflow_filter = 0;
$max_values = 0;
$resolution = NETFLOW_RES_MEDD;

$lapse_calc = 0;
$lapse = 300;
$visual_format = 0;

// Others.
$filter_search = '';
$filter_exclude = '';


// Added for select fields.
$total_time = true;
$time_failed = true;
$time_in_ok_status = true;
$time_in_unknown_status = true;
$time_of_not_initialized_module = true;
$time_of_downtime = true;
$total_checks = true;
$checks_failed = true;
$checks_in_ok_status = true;
$unknown_checks = true;
$agent_max_value = true;
$agent_min_value = true;
$uncompressed_module = true;

// Users.
$id_users = [];
$users_groups = [];
$select_by_group = false;

$nothing = __('Local metaconsole');
$nothing_value = 0;

$graph_render = (empty($config['type_mode_graph']) === true) ? 0 : $config['type_mode_graph'];

switch ($action) {
    case 'new':
        $actionParameter = 'save';
        $type = get_parameter('type', 'SLA');
        $name = '';
        $description = null;
        $sql = null;
        $show_in_same_row = 0;
        $hide_notinit_agents = 0;
        $priority_mode = REPORT_PRIORITY_MODE_OK;
        $failover_mode = 0;
        $failover_type = REPORT_FAILOVER_TYPE_NORMAL;
        $server_name = '';
        $server_id = 0;
        $dyn_height = 230;
        $landscape = false;
        $pagebreak = false;
        $summary = 0;
    break;

    case 'save':
    default:
        $actionParameter = 'update';

        // If we are creating a new report item
        // then clean interface and display creation view.
        $type = get_parameter('type', 'SLA');

        switch ($type) {
            case 'SLA_monthly':
            case 'SLA_weekly':
            case 'SLA_hourly':
            case 'SLA_services':
            case 'SLA':
            case 'top_n':
            case 'exception':
            case 'general':
            case 'availability':
            case 'availability_graph':
                $get_data_editor = true;
            break;

            default:
                $actionParameter = 'save';
                $action = 'new';

                $type = 'SLA';
                $name = '';
                $description = null;
                $sql = null;
                $show_in_same_row = 0;
                $hide_notinit_agents = 0;
                $server_name = '';
                $server_id = 0;
                $get_data_editor = false;
                $dyn_height = 230;
            break;
        }

        // Get data to fill editor if type is not SLA,
        // top_n, exception, general.
        if ($get_data_editor) {
            $item = db_get_row_filter('treport_content', ['id_rc' => $idItem]);
            $server_name = $item['server_name'];

            // Metaconsole db connection.
            if ($meta && empty($server_name) === false) {
                $connection = metaconsole_get_connection($server_name);
                $server_id = $connection['id'];
                if (metaconsole_load_external_db($connection) != NOERR) {
                    continue;
                }
            }

            $style = json_decode(io_safe_output($item['style']), true);

            $name_from_template = $style['name_label'];

            $show_in_same_row = $style['show_in_same_row'];
            $hide_notinit_agents = $style['hide_notinit_agents'];
            $dyn_height = $style['dyn_height'];
            $type = $item['type'];
            $name = $style['name_label'];

            if ($name === null || $name === '') {
                $name = $item['name'];
            }

            $landscape = $item['landscape'];
            $pagebreak = $item['pagebreak'];

            switch ($type) {
                case 'event_report_log':
                    $period = $item['period'];
                    $description = $item['description'];

                    $es = json_decode($item['external_source'], true);
                    $id_agents = $es['id_agents'];
                    $source = $es['source'];
                    $search = $es['search'];
                    $log_number = empty($es['log_number']) ? $log_number : $es['log_number'];
                break;

                case 'simple_graph':
                    $fullscale = isset($style['fullscale']) ? (bool) $style['fullscale'] : 0;
                    $percentil = isset($style['percentil']) ? (bool) $style['percentil'] : 0;
                    $graph_render = $item['graph_render'];
                    // The break hasn't be forgotten.
                case 'simple_baseline_graph':
                case 'projection_graph':
                    $description = $item['description'];
                    $idAgentModule = $item['id_agent_module'];
                    $idAgent = db_get_value_filter('id_agente', 'tagente_modulo', ['id_agente_modulo' => $idAgentModule]);
                    $period = $item['period'];
                    // 'top_n_value' field will be reused for projection report.
                    if ($type == 'projection_graph') {
                        $projection_period = $item['top_n_value'];
                        $period_pg = $item['period'];
                    }

                    // HACK it is saved in show_graph field.
                    $time_compare_overlapped = $item['show_graph'];
                break;

                case 'prediction_date':
                    $description = $item['description'];
                    $idAgentModule = $item['id_agent_module'];
                    $idAgent = db_get_value_filter('id_agente', 'tagente_modulo', ['id_agente_modulo' => $idAgentModule]);
                    // 'top_n' field will be reused for prediction_date report.
                    $max_interval = $item['top_n'];
                    $min_interval = $item['top_n_value'];
                    $intervals_text = $item['text'];
                    // Parse intervals text field.
                    $max_interval = substr($intervals_text, 0, strpos($intervals_text, ';'));
                    $min_interval = substr($intervals_text, (strpos($intervals_text, ';') + 1));
                    // 'top_n_value' field will be reused
                    // for prediction_date report.
                    $period_pg = $item['period'];
                break;

                case 'custom_graph':
                case 'automatic_custom_graph':
                    $description = $item['description'];
                    $period = $item['period'];
                    $idCustomGraph = $item['id_gs'];
                break;

                case 'availability_graph':
                    $summary = $item['summary'];
                case 'SLA':
                case 'SLA_weekly':
                case 'SLA_monthly':
                case 'SLA_hourly':
                    $description = $item['description'];
                    $only_display_wrong = $item['only_display_wrong'];
                    $monday = $item['monday'];
                    $tuesday = $item['tuesday'];
                    $wednesday = $item['wednesday'];
                    $thursday = $item['thursday'];
                    $friday = $item['friday'];
                    $saturday = $item['saturday'];
                    $sunday = $item['sunday'];
                    $time_from = $item['time_from'];
                    $time_to = $item['time_to'];
                    $compare_work_time = $item['compare_work_time'];
                    $show_graph = $item['show_graph'];
                    $priority_mode = isset($style['priority_mode']) ? $style['priority_mode'] : REPORT_PRIORITY_MODE_OK;
                    // 'top_n' filed will be reused for SLA sort option.
                    $sla_sorted_by = $item['top_n'];
                    $period = $item['period'];
                    $current_month = $item['current_month'];
                    $failover_mode = $item['failover_mode'];
                    $failover_type = $item['failover_type'];
                break;

                case 'histogram_data':
                case 'module_histogram_graph':
                    $description = $item['description'];
                    $period = $item['period'];
                    $idAgentModule = $item['id_agent_module'];
                    $idAgent = db_get_value_filter(
                        'id_agente',
                        'tagente_modulo',
                        ['id_agente_modulo' => $idAgentModule]
                    );
                break;

                case 'increment':
                    $description = $item['description'];
                    $idAgentModule = $item['id_agent_module'];
                    $idAgent = db_get_value_filter(
                        'id_agente',
                        'tagente_modulo',
                        ['id_agente_modulo' => $idAgentModule]
                    );
                    $period = $item['period'];
                break;

                case 'SLA_services':
                    $description = $item['description'];
                    $period = $item['period'];
                    $only_display_wrong = $item['only_display_wrong'];
                    $monday = $item['monday'];
                    $tuesday = $item['tuesday'];
                    $wednesday = $item['wednesday'];
                    $thursday = $item['thursday'];
                    $friday = $item['friday'];
                    $saturday = $item['saturday'];
                    $sunday = $item['sunday'];
                    $time_from = $item['time_from'];
                    $time_to = $item['time_to'];
                    $show_graph = $item['show_graph'];
                    // 'top_n' filed will be reused for SLA sort option.
                    $sla_sorted_by = $item['top_n'];
                break;

                case 'monitor_report':
                    $description = $item['description'];
                    $idAgentModule = $item['id_agent_module'];
                    $idAgent = db_get_value_filter(
                        'id_agente',
                        'tagente_modulo',
                        ['id_agente_modulo' => $idAgentModule]
                    );
                    $idAgentModule = $item['id_agent_module'];
                    $period = $item['period'];
                break;

                case 'avg_value':
                    $description = $item['description'];
                    $idAgentModule = $item['id_agent_module'];
                    $idAgent = db_get_value_filter(
                        'id_agente',
                        'tagente_modulo',
                        ['id_agente_modulo' => $idAgentModule]
                    );
                    $idAgentModule = $item['id_agent_module'];
                    $period = $item['period'];
                    $lapse = $item['lapse'];
                    $lapse_calc = $item['lapse_calc'];
                    $visual_format = $item['visual_format'];
                break;

                case 'max_value':
                    $description = $item['description'];
                    $idAgentModule = $item['id_agent_module'];
                    $idAgent = db_get_value_filter(
                        'id_agente',
                        'tagente_modulo',
                        ['id_agente_modulo' => $idAgentModule]
                    );
                    $idAgentModule = $item['id_agent_module'];
                    $period = $item['period'];
                    $lapse = $item['lapse'];
                    $lapse_calc = $item['lapse_calc'];
                    $visual_format = $item['visual_format'];
                break;

                case 'min_value':
                    $description = $item['description'];
                    $idAgentModule = $item['id_agent_module'];
                    $idAgent = db_get_value_filter(
                        'id_agente',
                        'tagente_modulo',
                        ['id_agente_modulo' => $idAgentModule]
                    );
                    $idAgentModule = $item['id_agent_module'];
                    $period = $item['period'];
                    $lapse = $item['lapse'];
                    $lapse_calc = $item['lapse_calc'];
                    $visual_format = $item['visual_format'];
                break;

                case 'sumatory':
                    $description = $item['description'];
                    $idAgentModule = $item['id_agent_module'];
                    $idAgent = db_get_value_filter(
                        'id_agente',
                        'tagente_modulo',
                        ['id_agente_modulo' => $idAgentModule]
                    );
                    $idAgentModule = $item['id_agent_module'];
                    $period = $item['period'];
                    $uncompressed_module = $item['uncompressed_module'];
                break;

                case 'historical_data':
                    $description = $item['description'];
                    $idAgentModule = $item['id_agent_module'];
                    $idAgent = db_get_value_filter(
                        'id_agente',
                        'tagente_modulo',
                        ['id_agente_modulo' => $idAgentModule]
                    );
                    $idAgentModule = $item['id_agent_module'];
                    $period = $item['period'];
                break;

                case 'text':
                    $description = $item['description'];
                    $text = $item['text'];
                break;

                case 'sql':
                    $header = $item['header_definition'];
                case 'sql_graph_pie':
                case 'sql_graph_vbar':
                case 'sql_graph_hbar':
                    $description = $item['description'];
                    $sql_query_report = $item['external_source'];
                    $idCustom = $item['treport_custom_sql_id'];
                    $historical_db = $item['historical_db'];
                    $period = 0;
                    $top_n_value = $item['top_n_value'];
                break;

                case 'url':
                    $description = $item['description'];
                    $url = $item['external_source'];
                break;

                case 'database_serialized':
                    $description = $item['description'];
                    $idAgentModule = $item['id_agent_module'];
                    $idAgent = db_get_value_filter(
                        'id_agente',
                        'tagente_modulo',
                        ['id_agente_modulo' => $idAgentModule]
                    );
                    $header = $item['header_definition'];
                    $field = $item['column_separator'];
                    $line = $item['line_separator'];
                    $period = $item['period'];
                break;

                case 'last_value':
                    $description = $item['description'];
                    $idAgentModule = $item['id_agent_module'];
                    $idAgent = db_get_value_filter(
                        'id_agente',
                        'tagente_modulo',
                        ['id_agente_modulo' => $idAgentModule]
                    );
                break;

                case 'alert_report_module':
                    $description = $item['description'];
                    $idAgentModule = $item['id_agent_module'];
                    $idAgent = db_get_value_filter(
                        'id_agente',
                        'tagente_modulo',
                        ['id_agente_modulo' => $idAgentModule]
                    );
                    $period = $item['period'];
                break;

                case 'alert_report_agent':
                    $description = $item['description'];
                    $idAgent = db_get_value_filter(
                        'id_agente',
                        'tagente_modulo',
                        ['id_agente' => $item['id_agent']]
                    );
                    $period = $item['period'];
                break;

                case 'alert_report_group':
                    $description = $item['description'];
                    $period = $item['period'];
                    $group = $item['id_group'];
                    $recursion = $item['recursion'];
                break;

                case 'event_report_agent':
                    $description = $item['description'];
                    $period = $item['period'];
                    $group = $item['id_group'];
                    $recursion = $item['recursion'];
                    $idAgent = $item['id_agent'];
                    $idAgentModule = $item['id_agent_module'];


                    $show_summary_group    = $style['show_summary_group'];
                    $filter_event_severity = json_decode(
                        $style['filter_event_severity'],
                        true
                    );
                    $filter_event_status   = json_decode(
                        $style['filter_event_status'],
                        true
                    );
                    $filter_event_type     = json_decode(
                        $style['filter_event_type'],
                        true
                    );

                    $event_graph_by_user_validator = $style['event_graph_by_user_validator'];
                    $event_graph_by_criticity = $style['event_graph_by_criticity'];
                    $event_graph_validated_vs_unvalidated = $style['event_graph_validated_vs_unvalidated'];
                    $include_extended_events = $item['show_extended_events'];

                    $filter_search = $style['event_filter_search'];
                    $filter_exclude = $style['event_filter_exclude'];
                break;

                case 'event_report_group':
                    $description = $item['description'];
                    $period = $item['period'];
                    $group = $item['id_group'];
                    $recursion = $item['recursion'];

                    $event_graph_by_agent = $style['event_graph_by_agent'];
                    $event_graph_by_user_validator = $style['event_graph_by_user_validator'];
                    $event_graph_by_criticity = $style['event_graph_by_criticity'];
                    $event_graph_validated_vs_unvalidated = $style['event_graph_validated_vs_unvalidated'];

                    $filter_search = $style['event_filter_search'];
                    $filter_exclude = $style['event_filter_exclude'];

                    $filter_event_severity = json_decode($style['filter_event_severity'], true);
                    $filter_event_status   = json_decode($style['filter_event_status'], true);
                    $filter_event_type     = json_decode($style['filter_event_type'], true);


                    $include_extended_events = $item['show_extended_events'];
                break;

                case 'event_report_module':
                    $description = $item['description'];
                    $period = $item['period'];
                    $group = $item['id_group'];
                    $idAgent = $item['id_agent'];
                    $idAgentModule = $item['id_agent_module'];

                    // Added for events items.
                    $show_summary_group    = $style['show_summary_group'];
                    $filter_event_severity = json_decode(
                        $style['filter_event_severity'],
                        true
                    );
                    $filter_event_status   = json_decode(
                        $style['filter_event_status'],
                        true
                    );
                    $filter_event_type     = json_decode(
                        $style['filter_event_type'],
                        true
                    );

                    $event_graph_by_agent = $style['event_graph_by_agent'];
                    $event_graph_by_user_validator = $style['event_graph_by_user_validator'];
                    $event_graph_by_criticity = $style['event_graph_by_criticity'];
                    $event_graph_validated_vs_unvalidated = $style['event_graph_validated_vs_unvalidated'];

                    $filter_search = $style['event_filter_search'];
                    $filter_exclude = $style['event_filter_exclude'];


                    $include_extended_events = $item['show_extended_events'];
                break;

                case 'general':
                    $description = $item['description'];
                    $group_by_agent = $item['group_by_agent'];
                    $period = $item['period'];
                    $order_uptodown = $item['order_uptodown'];
                    $show_resume = $item['show_resume'];
                break;

                case 'availability':
                    $description = $item['description'];
                    $period = $item['period'];
                    $order_uptodown = $item['order_uptodown'];
                    $show_resume = $item['show_resume'];
                    // HACK it is saved in show_graph field.
                    // Show interfaces instead the modules.
                    $show_address_agent = $item['show_graph'];
                    $monday = $item['monday'];
                    $tuesday = $item['tuesday'];
                    $wednesday = $item['wednesday'];
                    $thursday = $item['thursday'];
                    $friday = $item['friday'];
                    $saturday = $item['saturday'];
                    $sunday = $item['sunday'];
                    $time_from = $item['time_from'];
                    $time_to = $item['time_to'];
                    $compare_work_time = $item['compare_work_time'];
                    $total_time = $item['total_time'];
                    $time_failed = $item['time_failed'];
                    $time_in_ok_status = $item['time_in_ok_status'];
                    $time_in_unknown_status = $item['time_in_unknown_status'];
                    $time_of_not_initialized_module = $item['time_of_not_initialized_module'];
                    $time_of_downtime = $item['time_of_downtime'];
                    $total_checks = $item['total_checks'];
                    $checks_failed = $item['checks_failed'];
                    $checks_in_ok_status = $item['checks_in_ok_status'];
                    $unknown_checks = $item['unknown_checks'];
                    $agent_max_value = $item['agent_max_value'];
                    $agent_min_value = $item['agent_min_value'];
                    $failover_mode = $item['failover_mode'];
                    $failover_type = $item['failover_type'];
                break;

                case 'group_report':
                    $description = $item['description'];
                    $group = $item['id_group'];
                    $recursion = $item['recursion'];
                break;

                case 'network_interfaces_report':
                    $description = $item['description'];
                    $group = $item['id_group'];
                    $period = $item['period'];
                    $fullscale = isset($style['fullscale']) ? (bool) $style['fullscale'] : 0;
                    $recursion = $item['recursion'];
                    $graph_render = $item['graph_render'];
                break;

                case 'top_n':
                    $description = $item['description'];
                    $period = $item['period'];
                    $top_n = $item['top_n'];
                    $top_n_value = $item['top_n_value'];
                    $show_resume = $item['show_resume'];
                    $show_graph = $item['show_graph'];
                    $order_uptodown = $item['order_uptodown'];
                break;

                case 'exception':
                    $description = $item['description'];
                    $period = $item['period'];
                    $exception_condition = $item['exception_condition'];
                    $exception_condition_value = $item['exception_condition_value'];
                    $show_resume = $item['show_resume'];
                    $show_graph = $item['show_graph'];
                    $order_uptodown = $item['order_uptodown'];
                break;

                case 'agent_module':
                    $description = $item['description'];
                    $es = json_decode($item['external_source'], true);
                    $id_agents = $es['id_agents'];
                    $selection_a_m = get_parameter('selection');
                    $recursion = $item['recursion'];

                    if ((count($es['module']) == 1) && ($es['module'][0] == 0)) {
                        $module = '';
                    } else {
                        $module = $es['module'];
                    }

                    $group = $item['id_group'];
                    $modulegroup = $item['id_module_group'];
                    $idAgentModule = $module;
                break;

                case 'inventory':
                    $description = $item['description'];
                    $es = json_decode($item['external_source'], true);
                    $date = $es['date'];
                    $inventory_modules = $es['inventory_modules'];
                    $id_agents = $es['id_agents'];
                    $recursion = $item['recursion'];

                    $idAgent = $es['id_agents'];
                    $idAgentModule = $inventory_modules;
                break;

                case 'inventory_changes':
                    $period = $item['period'];
                    $description = $item['description'];
                    $es = json_decode($item['external_source'], true);
                    $inventory_modules = $es['inventory_modules'];
                    $id_agents = $es['id_agents'];
                    $recursion = $item['recursion'];
                break;

                case 'agent_configuration':
                    $idAgent = $item['id_agent'];
                break;

                case 'group_configuration':
                    $group = $item['id_group'];
                    $recursion = $item['recursion'];
                    $nothing = '';
                    $nothing_value = 0;
                break;

                case 'netflow_area':
                case 'netflow_data':
                case 'netflow_summary':
                    $netflow_filter = $item['text'];
                    // Filter.
                    $period = $item['period'];
                    $description = $item['description'];
                    $resolution = $item['top_n'];
                    // Interval resolution.
                    $max_values = $item['top_n_value'];
                    // Max values.
                break;

                case 'permissions_report':
                    $description = $item['description'];
                    $es = json_decode($item['external_source'], true);
                    $id_users = array_combine(
                        array_values($es['id_users']),
                        array_values($es['id_users'])
                    );

                    if (isset($id_users[0]) && $id_users[0] == 0) {
                        $id_users[0] = __('None');
                    }

                    $users_groups = $es['users_groups'];
                    $select_by_group = $es['select_by_group'];
                break;

                default:
                    // It's not possible.
                break;
            }

            switch ($type) {
                case 'event_report_agent':
                case 'simple_graph':
                case 'agent_configuration':
                case 'event_report_module':
                case 'alert_report_agent':
                case 'alert_report_module':
                case 'historical_data':
                case 'sumatory':
                case 'database_serialized':
                case 'last_value':
                case 'monitor_report':
                case 'histogram_data':
                case 'min_value':
                case 'max_value':
                case 'avg_value':
                case 'projection_graph':
                case 'prediction_date':
                case 'simple_baseline_graph':
                case 'event_report_log':
                case 'increment':
                    $label = (isset($style['label'])) ? $style['label'] : '';
                break;

                default:
                    $label = '';
                break;
            }

            // Restore db connection.
            if ($meta && $server_name != '') {
                metaconsole_restore_db();
            }
        }
    break;
}

$urlForm = $config['homeurl'].'index.php?sec=reporting&sec2=godmode/reporting/reporting_builder&tab=item_editor&action='.$actionParameter.'&id_report='.$idReport;

echo '<form action="'.$urlForm.'" method="post">';
html_print_input_hidden('id_item', $idItem);

$class = 'databox filters';

?>
<table   class="<?php echo $class; ?>" id="" border="0" cellpadding="4" cellspacing="4" width="100%">
    <?php
    if (defined('METACONSOLE')) {
        echo '<thead>
				<tr>
					<th align=center colspan=5>
						'.__('Item Editor').'
					</th>
				</tr>
			</thead>';
    }
    ?>
    <tbody>
        <tr id="row_type"   class="datos">
            <td class="bolder w220px">
                <?php echo __('Type'); ?>
            </td>
            <td  >
                <?php
                if ($action == 'new') {
                    html_print_select(reports_get_report_types(false, true), 'type', $type, 'chooseType();', '', '', '', '', '', '', '', '', '', '', true, 'reportingmodal');
                } else {
                    $report_type = reports_get_report_types();

                    if (!empty($report_type) && isset($report_type[$type]['name'])) {
                        echo $report_type[$type]['name'];
                    } else {
                        echo __('Not valid');
                    }

                    echo '<input type="hidden" id="type" name="type" value="'.$type.'" />';
                }
                ?>
                <?php
                if (!isset($text)) {
                    $text = __('This type of report brings a lot of data loading, it is recommended to use it for scheduled reports and not for real-time view.');
                }

                    echo '<a id="log_help_tip" style="visibility: hidden;" href="javascript:" class="tip" >'.html_print_image('images/tip.png', true, ['title' => $text]).'</a>';
                ?>
            </td>
        </tr>

        <tr id="row_name"   class="datos">
            <td class="bolder">
                <?php echo __('Name'); ?>
            </td>
            <td  >
                <?php
                if ($name_from_template != '') {
                    html_print_input_text(
                        'name',
                        $name_from_template,
                        '',
                        80,
                        100,
                        false,
                        false,
                        false,
                        '',
                        'fullwidth'
                    );
                } else {
                    html_print_input_text(
                        'name',
                        $name,
                        '',
                        80,
                        100,
                        false,
                        false,
                        false,
                        '',
                        'fullwidth'
                    );
                }
                ?>
            </td>
        </tr>
        <tr id="row_netflow_filter"   class="datos">
            <td class="bolder"><?php echo __('Filter'); ?></td>
            <td>
                <?php
                $own_info = get_user_info($config['id_user']);

                // Get group list that user has access.
                if (check_acl($config['id_user'], 0, 'RW')) {
                    $groups_user = users_get_groups(
                        $config['id_user'],
                        'RW',
                        $own_info['is_admin'],
                        true
                    );
                } else if (check_acl($config['id_user'], 0, 'RM')) {
                    $groups_user = users_get_groups(
                        $config['id_user'],
                        'RM',
                        $own_info['is_admin'],
                        true
                    );
                }

                $groups_id = [];
                foreach ($groups_user as $key => $groups) {
                    $groups_id[] = $groups['id_grupo'];
                }

                $sql_netflow = 'SELECT * FROM tnetflow_filter WHERE id_group IN ('.implode(',', $groups_id).')';
                html_print_select_from_sql(
                    $sql_netflow,
                    'netflow_filter',
                    $netflow_filter
                );
                ?>
            </td>
        </tr>
        <tr id="row_description"   class="datos">
            <td class="bolder"><?php echo __('Description'); ?></td>
            <td  >
                <?php
                echo html_print_textarea('description', 3, 25, $description);
                ?>
            </td>
        </tr>

        <?php
        if ($meta) {
            ?>
        <tr id="row_servers"   class="datos">
            <td class="bolder"><?php echo __('Server'); ?></td>
            <td  >
                <?php
                html_print_select(
                    $servers,
                    'combo_server',
                    $server_name,
                    '',
                    $nothing,
                    $nothing_value
                );
                ?>
            </td>
        </tr>
            <?php
        }
        ?>

        <tr id="row_label"   class="datos">
            <td class="bolder">
                <?php
                echo __('Label');
                ?>
            </td>
            <td  >
                <?php
                echo html_print_input_text(
                    'label',
                    $label,
                    '',
                    50,
                    255,
                    true,
                    false,
                    false,
                    '',
                    'fullwidth'
                );
                ?>
            </td>
        </tr>

        <tr id="row_search"   class="datos">
            <td class="bolder">
                <?php echo __('Search'); ?>
            </td>
            <td  >
                <?php
                html_print_input_text('search', $search, '', 40, 100);
                ?>
            </td>
        </tr>

        <tr id="row_log_number"   class="datos">
            <td class="bolder">
                <?php
                echo __('Log number');
                ui_print_help_tip(
                    __('Warning: this parameter limits the contents of the logs and affects the performance.')
                );
                ?>
            </td>
            <td  >
                <?php
                echo "<input name='log_number' max='10000' min='1' size='10' type='number' value='".$log_number."'>";
                ?>
            </td>
        </tr>

        <tr id="row_period"   class="datos">
            <td class="bolder">
                <?php
                echo __('Time lapse');
                ui_print_help_tip(
                    __('This is the range, or period of time over which the report renders the information for this report type. For example, a week means data from a week ago from now. ')
                );
                ?>
            </td>
            <td  >
                <?php
                html_print_extended_select_for_time(
                    'period',
                    $period,
                    '',
                    '',
                    '0',
                    10
                );
                ?>
            </td>
        </tr>

        <tr id="row_last_value"   class="datos">
            <td class="bolder" class="datos">
                <?php
                echo __('Last value');
                ui_print_help_tip(
                    __('Warning: period 0 reports cannot be used to show information back in time. Information contained in this kind of reports will be always reporting the most recent information')
                );
                ?>
            </td>
            <td  >
                <?php
                html_print_checkbox_switch(
                    'last_value',
                    '1',
                    ((int) $period === 0),
                    false,
                    false,
                    'set_last_value_period();'
                );
                ?>
            </td>
        </tr>

        <tr id="row_period1"   class="datos">
            <td class="bolder">
                <?php
                echo __('Period');
                ?>
            </td>
            <td  >
                <?php
                html_print_extended_select_for_time(
                    'period1',
                    $period_pg,
                    '',
                    '',
                    '0',
                    10
                );
                ?>
            </td>
        </tr>
        <tr id="row_estimate"   class="datos">
            <td class="bolder">
                <?php
                echo __('Projection period');
                ?>
            </td>
            <td  >
                <?php
                html_print_extended_select_for_time(
                    'period2',
                    $projection_period,
                    '',
                    '',
                    '0',
                    10
                );
                ?>
            </td>
        </tr>
        <tr id="row_interval"   class="datos">
            <td class="bolder">
            <?php
            echo __('Data range');
            ?>
            </td>
            <td>
                <?php
                echo __('Min').'&nbsp;';
                html_print_input_text('min_interval', $min_interval, '', 5, 10);
                echo '&nbsp;'.__('Max').'&nbsp;';
                html_print_input_text('max_interval', $max_interval, '', 5, 10);
                ?>
            </td>
        </tr>
        <tr id="row_only_display_wrong"   class="datos">
            <td class="bolder"><?php echo __('Only display wrong SLAs'); ?></td>
            <td>
                <?php
                html_print_checkbox_switch(
                    'checkbox_only_display_wrong',
                    1,
                    $only_display_wrong
                );
                ?>
            </td>
        </tr>

        <tr id="row_current_month">
            <td class="bolder">
                <?php echo __('Current month'); ?>
            </td>

            <td class="bolder">
                <?php
                html_print_checkbox_switch(
                    'current_month',
                    1,
                    $current_month
                );
                ?>
            </td>
        </tr>

        <tr id="row_working_time">
            <td class="bolder">
                <?php echo __('Working time'); ?>
            </td>
            <td>
                <?php echo ui_get_using_system_timezone_warning(); ?>
                <table border="0">
                    <tr>
                        <td>
                        <p class="mrgn_right_30px">
                            <?php
                            echo __('Monday').'<br>';
                                html_print_checkbox_switch('monday', 1, $monday);
                            ?>
                            </p>
                        </td>
                        <td>
                            <p class="mrgn_right_30px">
                                <?php
                                echo __('Tuesday').'<br>';
                                html_print_checkbox_switch('tuesday', 1, $tuesday);
                                ?>
                            </p>
                        </td>
                        <td>
                            <p class="mrgn_right_30px">
                                <?php
                                echo __('Wednesday').'<br>';
                                html_print_checkbox_switch('wednesday', 1, $wednesday);
                                ?>
                            </p>
                        </td>
                        <td>
                            <p class="mrgn_right_30px">
                                <?php
                                echo __('Thursday').'<br>';
                                html_print_checkbox_switch('thursday', 1, $thursday);
                                ?>
                            </p>
                        </td>
                        <td>
                            <p class="mrgn_right_30px">
                                <?php
                                echo __('Friday').'<br>';
                                html_print_checkbox_switch('friday', 1, $friday);
                                ?>
                            </p>
                        </td>
                        <td>
                            <p class="mrgn_right_30px">
                                <?php
                                echo __('Saturday').'<br>';
                                html_print_checkbox_switch('saturday', 1, $saturday);
                                ?>
                            </p>
                        </td>
                        <td>
                            <p class="mrgn_right_30px">
                                <?php
                                echo __('Sunday').'<br>';
                                html_print_checkbox_switch('sunday', 1, $sunday);
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <?php
                            echo __('Time from');
                            ?>
                        </td>
                        <td colspan="6">
                        <?php
                        html_print_input_text(
                            'time_from',
                            $time_from,
                            '',
                            7,
                            8
                        );
                        ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <?php
                            echo __('Time to');
                            ?>
                        </td>
                        <td colspan="6">
                        <?php
                        html_print_input_text(
                            'time_to',
                            $time_to,
                            '',
                            7,
                            8
                        );
                        ?>
                        </td>
                    </tr>
                    <tr id="row_working_time_compare">
                        <td>
                            <?php
                            echo __('Show 24x7 item');
                            ?>
                        </td>
                        <td colspan="6">
                        <?php
                        html_print_checkbox_switch(
                            'compare_work_time',
                            1,
                            $compare_work_time
                        );
                        ?>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr id="row_group"   class="datos">
            <td class="bolder"><?php echo __('Group'); ?></td>
            <td  >
                <?php
                echo '<div class="w250px inline padding-right-2-imp">';
                if (check_acl($config['id_user'], 0, 'RW')) {
                    html_print_select_groups(
                        $config['id_user'],
                        'RW',
                        true,
                        'combo_group',
                        $group,
                        ''
                    );
                } else if (check_acl($config['id_user'], 0, 'RM')) {
                    html_print_select_groups(
                        $config['id_user'],
                        'RM',
                        true,
                        'combo_group',
                        $group,
                        ''
                    );
                }

                echo '</div>';

                echo '&nbsp;&nbsp;&nbsp;'.__('Recursion').'&nbsp;&nbsp;&nbsp;'.html_print_checkbox_switch(
                    'recursion',
                    1,
                    $recursion,
                    true
                );
                ?>
            </td>
        </tr>
        <tr id="row_source"   class="datos">
            <td class="bolder"><?php echo __('Source'); ?></td>
            <td  >
                <?php
                $agents = agents_get_group_agents($group);
                if ((empty($agents)) || $agents == -1) {
                    $agents = [];
                }

                $sql_log = 'SELECT source AS k, source AS v
                        FROM tagente,tagent_module_log
                        WHERE tagente.id_agente = tagent_module_log.id_agent
                        AND tagente.disabled = 0';

                if (!empty($agents)) {
                    $index = 0;
                    foreach ($agents as $key => $a) {
                        if ($index == 0) {
                            $sql_log .= ' AND (id_agente = '.$key;
                        } else {
                            $sql_log .= ' OR id_agente = '.$key;
                        }

                        $index++;
                    }

                    $sql_log .= ')';
                }

                html_print_select_from_sql(
                    $sql_log,
                    'source',
                    $source,
                    'onselect=source_change_agents();',
                    __('All'),
                    '',
                    false,
                    false,
                    false
                );
                ?>
            </td>
        </tr>
        <tr id="row_module_group"   class="datos">
            <td class="bolder"><?php echo __('Module group'); ?></td>
            <td  >
                <?php
                html_print_select_from_sql(
                    'SELECT * FROM tmodule_group ORDER BY name',
                    'combo_modulegroup',
                    $modulegroup,
                    '',
                    __('All')
                );
                ?>
            </td>
        </tr>

        <tr id="row_agent"   class="datos">
            <td class="bolder"><?php echo __('Agent'); ?></td>
            <td  >
                <?php
                if ($meta) {
                    $connection = metaconsole_get_connection($server_name);
                    $agent_name = '';

                    if (metaconsole_load_external_db($connection) == NOERR) {
                        $agent_name = db_get_value_filter(
                            'alias',
                            'tagente',
                            ['id_agente' => $idAgent]
                        );
                    }

                    // Append server name.
                    if (!empty($agent_name)) {
                        $agent_name .= ' ('.$server_name.')';
                    }

                    // Restore db connection.
                    metaconsole_restore_db();
                } else {
                    $agent_name = agents_get_alias($idAgent);
                }

                html_print_input_hidden('id_agent', $idAgent);
                html_print_input_hidden('server_name', $server_name);
                html_print_input_hidden('server_id', $server_id);

                $params = [];
                $params['show_helptip'] = false;
                $params['input_name'] = 'agent';
                $params['value'] = $agent_name;

                $params['javascript_is_function_select'] = true;
                $params['selectbox_id'] = 'id_agent_module';
                $params['add_none_module'] = true;
                $params['use_hidden_input_idagent'] = true;
                $params['hidden_input_idagent_id'] = 'hidden-id_agent';
                if ($meta) {
                    $params['use_input_id_server'] = true;
                    $params['input_id_server_id'] = 'hidden-server_id';
                    $params['metaconsole_enabled'] = true;
                    $params['input_id'] = 'agent_autocomplete_events';
                    $params['javascript_page'] = 'include/ajax/agent';
                    $params['input_name'] = 'agent_text';
                }

                ui_print_agent_autocomplete_input($params);
                ?>
            </td>
        </tr>

        <tr id="row_module"   class="datos">
            <td class="bolder">
                <?php
                echo __('Module');
                ?>
            </td>
            <td class="mx180px">
                <?php
                if ($idAgent) {
                    $sql = 'SELECT id_agente_modulo, nombre
						FROM tagente_modulo
						WHERE id_agente =  '.$idAgent.' AND  delete_pending = 0';

                    if ($meta) {
                        $connection = metaconsole_get_connection($server_name);

                        if (metaconsole_load_external_db($connection) == NOERR) {
                            $agent_name_temp = db_get_all_rows_sql($sql);

                            if ($agent_name_temp === false) {
                                $agent_name_temp = [];
                            }

                            $result_select = [];
                            foreach ($agent_name_temp as $module_element) {
                                $result_select[$module_element['id_agente_modulo']] = $module_element['nombre'];
                            }

                            html_print_select(
                                $result_select,
                                'id_agent_module',
                                $idAgentModule,
                                '',
                                '',
                                '0'
                            );
                        }

                        // Restore db connection.
                        metaconsole_restore_db();
                    } else {
                        html_print_select_from_sql(
                            $sql,
                            'id_agent_module',
                            $idAgentModule,
                            '',
                            '',
                            '0'
                        );
                    }
                } else {
                    ?>
                    <select class="mx180px" id="id_agent_module" name="id_agent_module" disabled="disabled">
                        <option value="0">
                        <?php echo __('Select an Agent first'); ?>
                        </option>
                    </select>
                    <?php
                }
                ?>
            </td>
        </tr>

        <tr id="agents_row"   class="datos">
            <td class="bolder"><?php echo __('Agents'); ?></td>
            <td>
                <?php
                if ($source) {
                    $sql_log_report = 'SELECT id_agente, alias
							FROM tagente, tagent_module_log
							WHERE tagente.id_agente = tagent_module_log.id_agent
                            AND tagente.disabled = 0
                            AND tagent_module_log.source like "'.$source.'"';
                } else {
                    $sql_log_report = 'SELECT id_agente, alias
							FROM tagente, tagent_module_log
							WHERE tagente.id_agente = tagent_module_log.id_agent
                            AND tagente.disabled = 0';
                }

                $all_agent_log = db_get_all_rows_sql($sql_log_report);

                if (isset($all_agent_log) && is_array($all_agent_log)) {
                    foreach ($all_agent_log as $key => $value) {
                        $agents2[$value['id_agente']] = $value['alias'];
                    }
                }

                if ((empty($agents2)) || $agents2 == -1) {
                    $agents = [];
                }

                    $agents_select = [];
                if (is_array($id_agents) || is_object($id_agents)) {
                    foreach ($id_agents as $id) {
                        foreach ($agents2 as $key => $a) {
                            if ($key == (int) $id) {
                                $agents_select[$key] = $key;
                            }
                        }
                    }
                }

                html_print_select(
                    $agents2,
                    'id_agents3[]',
                    $agents_select,
                    $script = '',
                    '',
                    0,
                    false,
                    true,
                    true,
                    '',
                    false,
                    'min-width: 180px'
                );
                echo "<span id='spinner_hack' class='invisible'>".html_print_image(
                    'images/spinner.gif',
                    true
                ).'</span>';
                ?>
            </td>
        </tr>

        <tr id="agents_modules_row"   class="datos">
            <td class="bolder"><?php echo __('Agents'); ?></td>
            <td>
                <?php
                $all_agent_log = agents_get_agents(false, ['id_agente', 'alias']);
                foreach ($all_agent_log as $key => $value) {
                    $agents2[$value['id_agente']] = $value['alias'];
                }

                if ((empty($agents2)) || $agents2 == -1) {
                    $agents = [];
                }

                $agents_select = [];
                if (is_array($id_agents) || is_object($id_agents)) {
                    foreach ($id_agents as $id) {
                        foreach ($agents2 as $key => $a) {
                            if ($key == (int) $id) {
                                $agents_select[$key] = $key;
                            }
                        }
                    }
                }

                html_print_select(
                    $agents2,
                    'id_agents2[]',
                    $agents_select,
                    $script = '',
                    '',
                    0,
                    false,
                    true,
                    true,
                    '',
                    false,
                    'min-width: 180px'
                );
                ?>
            </td>
        </tr>

        <tr id="select_agent_modules"   class="datos">
            <td class="bolder"><?php echo __('Show modules'); ?></td>
            <td>
                <?php
                $selection = [
                    0 => __('Show common modules'),
                    1 => __('Show all modules'),
                ];

                html_print_select(
                    $selection,
                    'selection_agent_module',
                    $selection_a_m,
                    $script = '',
                    '',
                    0,
                    false,
                    false,
                    true,
                    '',
                    false,
                    'min-width: 180px'
                );
                ?>
            </td>
        </tr>

        <tr id="modules_row"   class="datos">
            <td class="bolder"><?php echo __('Modules'); ?></td>
            <td>
                <?php
                if (empty($id_agents) || $id_agents == null || $id_agents === 0) {
                    $all_modules = '';
                } else {
                    $all_modules = db_get_all_rows_sql(
                        'SELECT DISTINCT nombre FROM 
							tagente_modulo WHERE id_agente IN ('.implode(',', array_values($id_agents)).')'
                    );
                }

                if ((empty($all_modules)) || $all_modules == -1) {
                    $all_modules = [];
                }

                    $modules_select = [];
                    $all_modules_structured = [];
                if (is_array($idAgentModule) || is_object($idAgentModule)) {
                    foreach ($idAgentModule as $id) {
                        foreach ($all_modules as $key => $a) {
                            if ($a['id_agente_modulo'] == (int) $id) {
                                $modules_select[$a['id_agente_modulo']] = $a['id_agente_modulo'];
                            }
                        }
                    }
                }

                foreach ($all_modules as $a) {
                    $all_modules_structured[$a['id_agente_modulo']] = $a['nombre'];
                }

                html_print_select(
                    $all_modules_structured,
                    'module[]',
                    $modules_select,
                    $script = '',
                    __('None'),
                    0,
                    false,
                    true,
                    true,
                    '',
                    false,
                    'min-width: 180px'
                );
                ?>
            </td>
        </tr>

        <tr id="row_agent_multi"   class="datos">
            <td class="bolder"><?php echo __('Agents'); ?></td>
            <td>
                <?php
                $fields = [];
                $fields[0] = 'id_agente';
                $fields[1] = 'alias';
                $agents = enterprise_hook(
                    'inventory_get_agents',
                    [
                        false,
                        $fields,
                    ]
                );
                if ((empty($agents)) || $agents == -1) {
                    $agents = [];
                }

                $agents_select = [];
                foreach ($agents as $a) {
                    $agents_select[$a['id_agente']] = $a['alias'];
                }

                html_print_select(
                    $agents_select,
                    'id_agents[]',
                    $id_agents,
                    $script = '',
                    __('All'),
                    -1,
                    false,
                    true,
                    true,
                    '',
                    false,
                    'min-width: 180px'
                );
                ?>
            </td>
        </tr>

        <tr id="row_module_multi"   class="datos">
            <td class="bolder"><?php echo __('Modules'); ?></td>
            <td>
                <?php
                html_print_select(
                    [],
                    'inventory_modules[]',
                    '',
                    $script = '',
                    __('None'),
                    0,
                    false,
                    true,
                    true,
                    '',
                    false,
                    'min-width: 180px'
                );

                if (empty($inventory_modules)) {
                    $array_inventory_modules = [0 => 0];
                }

                $array_inventory_modules = implode(',', $inventory_modules);

                html_print_input_hidden(
                    'inventory_modules_selected',
                    $array_inventory_modules
                );
                ?>
            </td>
        </tr>

        <tr id="row_date"   class="datos">
            <td class="bolder"><?php echo __('Date'); ?></td>
            <td class="mx180px">
                <?php
                $dates = enterprise_hook(
                    'inventory_get_dates',
                    [
                        $idAgentModule,
                        $idAgent,
                        $group,
                    ]
                );

                if ($dates === ENTERPRISE_NOT_HOOK) {
                    $dates = [];
                }

                html_print_select(
                    $dates,
                    'date',
                    '',
                    '',
                    __('Last'),
                    0,
                    false,
                    false,
                    false,
                    '',
                    false,
                    'min-width: 180px'
                );
                html_print_input_hidden('date_selected', $date);
                ?>
            </td>
        </tr>

        <tr id="row_custom_graph"   class="datos">
            <td class="bolder"><?php echo __('Custom graph'); ?></td>
            <td  >
                <?php
                if ($meta) {
                    $graphs = [];
                    $graphs = metaconsole_get_custom_graphs();
                    $value_selected = $idCustomGraph.'|'.$server_name;
                    html_print_select(
                        $graphs,
                        'id_custom_graph',
                        $value_selected,
                        'change_custom_graph();',
                        __('None'),
                        0
                    );
                } else {
                    $list_custom_graphs = custom_graphs_get_user(
                        $config['id_user'],
                        false,
                        true,
                        'RR'
                    );

                    $graphs = [];
                    foreach ($list_custom_graphs as $custom_graph) {
                        $graphs[$custom_graph['id_graph']] = $custom_graph['name'];
                    }

                    html_print_select(
                        $graphs,
                        'id_custom_graph',
                        $idCustomGraph,
                        'change_custom_graph();',
                        __('None'),
                        0
                    );
                }

                $style_button_create_custom_graph = 'class="invisible"';
                $style_button_edit_custom_graph = '';
                if (empty($idCustomGraph)) {
                    $style_button_create_custom_graph = '';
                    $style_button_edit_custom_graph = 'class="invisible"';
                    // Select the target server.
                    if ($meta) {
                        $metaconsole_connections = enterprise_hook(
                            'metaconsole_get_connection_names'
                        );
                        if ($metaconsole_connections === false) {
                            $metaconsole_connections = [];
                        }

                        $result_servers = [];
                        foreach ($metaconsole_connections as $metaconsole_element) {
                            $connection_data = enterprise_hook(
                                'metaconsole_get_connection',
                                [$metaconsole_element]
                            );
                            $result_servers[$connection_data['server_name']] = $connection_data['server_name'];
                        }

                        // Print select combo with metaconsole servers.
                        if (!empty($result_servers)) {
                            echo '<div id="meta_target_servers" class="invisible">';
                            echo '&nbsp;&nbsp;&nbsp;&nbsp;'.__('Target server').'&nbsp;&nbsp;';
                            html_print_select($result_servers, 'meta_servers', '', '', __('None'), 0);
                            echo '</div>';
                        } else {
                            // If there are not metaconsole servers
                            // don't allow to create new custom graphs.
                            $style_button_create_custom_graph = 'class="invisible"';
                        }
                    }
                }

                echo '&nbsp;';
                html_print_button(
                    __('Create'),
                    'create_graph',
                    false,
                    'create_custom_graph();',
                    'class="sub add" '.$style_button_create_custom_graph
                );

                html_print_button(
                    __('Edit'),
                    'edit_graph',
                    false,
                    'edit_custom_graph();',
                    'class="sub config" '.$style_button_edit_custom_graph
                );
                ?>
            </td>
        </tr>

        <tr id="row_text"   class="datos">
            <td class="bolder"><?php echo __('Text'); ?></td>
            <td  >
            <?php
            html_print_textarea(
                'text',
                5,
                25,
                $text
            );
            ?>
                </td>
        </tr>

        <tr id="row_custom"   class="datos">
            <td class="bolder">
            <?php
            echo __('Custom SQL template');
            ?>
            </td>
            <td  >
            <?php
            html_print_select_from_sql(
                'SELECT id, name FROM treport_custom_sql',
                'id_custom',
                $idCustom,
                'chooseSQLquery()',
                '--',
                '0'
            );
            ?>
            </td>
        </tr>

        <tr id="row_query"   class="datos">
            <td class="bolder">
            <?php
            echo __('SQL query').ui_print_help_tip(
                __('The entities of the fields that contain them must be included.'),
                true
            );
            ?>
                </td>
            <td   id="sql_entry">
                <?php
                html_print_textarea('sql', 5, 25, $sql_query_report);
                ?>
            </td>
            <td   id="sql_example"></td>
        </tr>

        <tr id="row_max_items"   class="datos">
            <td class="bolder"><?php echo __('Max items'); ?></td>
            <td  >
                <?php
                html_print_input_text('max_items', $top_n_value, '', 7, 7);
                ?>
            </td>
            <td   id="max_items_example"></td>
        </tr>

        <tr id="row_header"   class="datos">
            <td class="bolder">
            <?php
            echo __('Serialized header').ui_print_help_tip(
                __('The separator character is |'),
                true
            );
            ?>
            </td>
            <td  >
            <?php
            html_print_input_text(
                'header',
                $header,
                '',
                90,
                250
            );
            ?>
            </td>
        </tr>

        <tr id="row_url"   class="datos">
            <td class="bolder"><?php echo __('URL'); ?></td>
            <td  >
            <?php
            html_print_input_text(
                'url',
                $url,
                '',
                90,
                250
            );
            ?>
                <span id="url_warning_text" class="error invisible bolder"><?php echo __('Protocol must be specified in URL (e.g.: "https://")'); ?></span>
                </td>
        </tr>
        <tr id="row_field_separator"   class="datos">
            <td class="bolder">
            <?php
            echo __('Field separator').ui_print_help_tip(
                __('Separator for different fields in the serialized text chain'),
                true
            );
            ?>
                </td>
            <td  >
            <?php
            html_print_input_text(
                'field',
                $field,
                '',
                2,
                4
            );
            ?>
                </td>
        </tr>
        <tr id="row_line_separator"   class="datos">
            <td class="bolder">
            <?php
            echo __('Line separator').ui_print_help_tip(
                __('Separator in different lines (composed by fields) of the serialized text chain'),
                true
            );
            ?>
                </td>
            <td  >
            <?php
            html_print_input_text(
                'line',
                $line,
                '',
                2,
                4
            );
            ?>
                </td>
        </tr>
        <tr id="row_group_by_agent"   class="datos">
            <td class="bolder">
            <?php
            echo __('Group by agent');
            ?>
            </td>
            <td>
            <?php
            html_print_checkbox_switch(
                'checkbox_row_group_by_agent',
                1,
                $group_by_agent
            );
            ?>
                </td>
        </tr>
        <tr id="row_order_uptodown"   class="datos">
            <td class="bolder"><?php echo __('Order'); ?></td>
            <td>
                <?php
                echo __('Ascending');
                html_print_radio_button(
                    'radiobutton_order_uptodown',
                    REPORT_ITEM_ORDER_BY_ASCENDING,
                    '',
                    $order_uptodown
                );
                echo __('Descending');
                html_print_radio_button(
                    'radiobutton_order_uptodown',
                    REPORT_ITEM_ORDER_BY_DESCENDING,
                    '',
                    $order_uptodown
                );
                echo __('By agent name');
                html_print_radio_button(
                    'radiobutton_order_uptodown',
                    REPORT_ITEM_ORDER_BY_AGENT_NAME,
                    '',
                    $order_uptodown
                );
                ?>
            </td>
        </tr>

        <tr id="row_quantity"   class="datos">
            <td class="bolder"><?php echo __('Quantity (n)'); ?></td>
            <td  >
            <?php
            html_print_input_text(
                'quantity',
                $top_n_value,
                '',
                5,
                5
            );
            ?>
                </td>
        </tr>

        <tr id="row_max_values"   class="datos">
            <td class="bolder"><?php echo __('Max. values'); ?></td>
            <td  >
            <?php
            html_print_input_text(
                'max_values',
                $max_values,
                '',
                5,
                5
            );
            ?>
                </td>
        </tr>

        <tr id="row_max_min_avg"   class="datos">
            <td class="bolder"><?php echo __('Display'); ?></td>
            <td>
                <?php
                echo __('Max');
                html_print_radio_button(
                    'radiobutton_max_min_avg',
                    1,
                    '',
                    $top_n
                );
                echo __('Min');
                html_print_radio_button(
                    'radiobutton_max_min_avg',
                    2,
                    '',
                    $top_n
                );
                echo __('Avg');
                html_print_radio_button(
                    'radiobutton_max_min_avg',
                    3,
                    '',
                    $top_n
                );
                ?>
            </td>
        </tr>

        <tr id="row_graph_render"   class="datos">
            <td class="bolder">
            <?php
            echo __('Graph render');
            ?>
            </td>
            <td>
                <?php
                $list_graph_render = [
                    1 => __('Avg, max & min'),
                    2 => __('Max only'),
                    3 => __('Min only'),
                    0 => __('Avg only'),
                ];
                html_print_select(
                    $list_graph_render,
                    'graph_render',
                    $graph_render
                );
                ?>
            </td>
        </tr>

        <tr id="row_fullscale"   class="datos">
            <td class="bolder">
            <?php
            echo __('Full resolution graph (TIP)').ui_print_help_tip(
                __('TIP mode charts do not support average - maximum - minimum series, you can only enable TIP or average, maximum or minimum series'),
                true
            );
            ?>
            </td>
            <td>
            <?php
            html_print_checkbox_switch(
                'fullscale',
                1,
                $fullscale
            );
            ?>
            </td>
        </tr>

        <tr id="row_time_compare_overlapped"   class="datos">
            <td class="bolder">
            <?php
            echo __('Time compare (Overlapped)');
            ?>
            </td>
            <td>
                <?php
                html_print_checkbox_switch(
                    'time_compare_overlapped',
                    1,
                    $time_compare_overlapped
                );
                ?>
            </td>
        </tr>

        <tr id="row_percentil"   class="datos">
            <td class="bolder"><?php echo __('Percentil'); ?></td>
            <td><?php html_print_checkbox_switch('percentil', 1, $percentil); ?></td>
        </tr>

        <tr id="row_exception_condition_value"   class="datos">
            <td class="bolder"><?php echo __('Value'); ?></td>
            <td  >
                <?php
                html_print_input_text(
                    'exception_condition_value',
                    $exception_condition_value,
                    '',
                    5,
                    5
                );
                ?>
            </td>
        </tr>

        <tr id="row_exception_condition"   class="datos">
            <td class="bolder"><?php echo __('Condition'); ?></td>
            <td>
                <?php
                $list_exception_condition = [
                    REPORT_EXCEPTION_CONDITION_EVERYTHING => __('Everything'),
                    REPORT_EXCEPTION_CONDITION_GE         => __('Greater or equal (>=)'),
                    REPORT_EXCEPTION_CONDITION_LE         => __('Less or equal (<=)'),
                    REPORT_EXCEPTION_CONDITION_L          => __('Less (<)'),
                    REPORT_EXCEPTION_CONDITION_G          => __('Greater (>)'),
                    REPORT_EXCEPTION_CONDITION_E          => __('Equal (=)'),
                    REPORT_EXCEPTION_CONDITION_NE         => __('Not equal (!=)'),
                    REPORT_EXCEPTION_CONDITION_OK         => __('OK'),
                    REPORT_EXCEPTION_CONDITION_NOT_OK     => __('Not OK'),
                ];
                html_print_select(
                    $list_exception_condition,
                    'exception_condition',
                    $exception_condition
                );
                ?>
            </td>
        </tr>

        <tr id="row_show_graph"   class="datos">
            <td class="bolder"><?php echo __('Show graph'); ?></td>
            <td>
            <?php
            html_print_select(
                $show_graph_options,
                'combo_graph_options',
                $show_graph
            );
            ?>
                </td>
        </tr>
        <tr id="row_select_fields"   class="datos">
        <td class="bolder mrgn_right_150px">
            <?php
            echo __('Select fields to show');
            ?>
            </td>
            <td>
            <table border="0">
            <td>
            <p class="mrgn_right_30px">
                <?php
                echo __('Total time').'<br>';
                html_print_checkbox_switch('total_time', 1, $total_time);
                ?>
             </p>
            </td>
            <td>
            <p class="mrgn_right_30px">
                <?php
                echo __('Time failed').'<br>';
                html_print_checkbox_switch('time_failed', 1, $time_failed);
                ?>
                </p>
            </td>
            <td>
            <p class="mrgn_right_30px">
                <?php
                echo __('Time in OK status').'<br>';
                html_print_checkbox_switch('time_in_ok_status', 1, $time_in_ok_status);
                ?>
                </p>
            </td>
            <td>
            <p class="mrgn_right_30px">
                <?php
                echo __('Time in unknown status').'<br>';
                html_print_checkbox_switch(
                    'time_in_unknown_status',
                    1,
                    $time_in_unknown_status
                );
                ?>
                </p>
            </td>
            <td>
            <p class="mrgn_right_30px">
                <?php
                echo __('Time of not initialized module').'<br>';
                html_print_checkbox_switch(
                    'time_of_not_initialized_module',
                    1,
                    $time_of_not_initialized_module
                );
                ?>
                </p>
            </td>
            <td>
            <p class="mrgn_right_30px">
                <?php
                echo __('Time of downtime').'<br>';
                html_print_checkbox_switch('time_of_downtime', 1, $time_of_downtime);
                ?>
                </p>
            </td>
            </table>
            </td>
        </tr>

        <tr id="row_show_address_agent"   class="datos">
            <td class="bolder">
                <?php
                echo __('Show address instead module name').ui_print_help_tip(
                    __('Show the main address of agent.'),
                    true
                );
                ?>
            </td>
            <td>
                <?php
                html_print_checkbox_switch(
                    'checkbox_show_address_agent',
                    1,
                    $show_address_agent
                );
                ?>
            </td>
        </tr>

        <tr id="row_show_resume"   class="datos">
            <td class="bolder">
            <?php
            echo __('Show resume').ui_print_help_tip(
                __('Show a summary chart with max, min and average number of total modules at the end of the report and Checks.'),
                true
            );
            ?>
            </td>
            <td>
                <?php
                html_print_checkbox_switch(
                    'checkbox_show_resume',
                    1,
                    $show_resume
                );
                ?>
            </td>
        </tr>

        <tr id="row_select_fields2"   class="datos">
        <td class="bolder mrgn_right_150px">
            <?php
            echo __('<p class= "mrgn_lft_15px">Select fields to show</p>');
            ?>
            </td>
            <td>
            <table border="0">
            <td>
            <p class="mrgn_right_30px">
                <?php
                echo __('Total checks');
                html_print_checkbox('total_checks', 1, $total_checks);
                ?>
                </p>
            </td>
            <td>
            <p class="mrgn_right_30px">
                <?php
                echo __('Checks failed');
                html_print_checkbox('checks_failed', 1, $checks_failed);
                ?>
             </p>
            </td>
            <td>
            <p class="mrgn_right_30px">
                <?php
                echo __('Checks in OK status');
                html_print_checkbox(
                    'checks_in_ok_status',
                    1,
                    $checks_in_ok_status
                );
                ?>
                </p>
            </td>
            <td>
            <p class="mrgn_right_30px">
                <?php
                echo __('Unknown checks');
                html_print_checkbox('unknown_checks', 1, $unknown_checks);
                ?>
                </p>
            </td>
            <td>
            </table>
            </td>
        </tr>
        <tr id="row_select_fields3"   class="datos">
        <td class="bolder mrgn_right_150px">
            <?php
            echo __('<p class="mrgn_lft_15px">Select fields to show</p>');
            ?>
            </td>
            <td>
            <table border="0">
            <td>
            <p class="mrgn_right_30px">
                <?php
                echo __('Agent max value');
                html_print_checkbox('agent_max_value', 1, $agent_max_value);
                ?>
             </p>
            </td>
            <td>
            <p class="mrgn_right_30px">
                <?php
                echo __('Agent min values');
                html_print_checkbox('agent_min_value', 1, $agent_min_value);
                ?>
                </p>
            </td>
            <td>
            </table>
            </td>
        </tr>

        <tr id="row_show_summary_group"   class="datos">
            <td class="bolder">
            <?php
            echo __('Show Summary group');
            ?>
            </td>
            <td>
                <?php
                html_print_checkbox_switch(
                    'show_summary_group',
                    true,
                    $show_summary_group
                );
                ?>
            </td>
        </tr>

        <tr id="row_event_severity"   class="datos">
            <td class="bolder"><?php echo __('Severity'); ?></td>
            <td>
                <?php
                $valuesSeverity = get_priorities();
                    html_print_select(
                        $valuesSeverity,
                        'filter_event_severity[]',
                        $filter_event_severity,
                        '',
                        __('All'),
                        '-1',
                        false,
                        true,
                        false,
                        '',
                        false,
                        false,
                        false,
                        false,
                        false,
                        ''
                    );
                    ?>
            </td>
        </tr>

        <tr id="row_event_type"   class="datos">
            <td class="bolder"><?php echo __('Event type'); ?></td>
            <td>
                <?php
                $event_types_select = get_event_types();
                    html_print_select(
                        $event_types_select,
                        'filter_event_type[]',
                        $filter_event_type,
                        '',
                        __('All'),
                        'all',
                        false,
                        true,
                        false,
                        '',
                        false,
                        false,
                        false,
                        false,
                        false,
                        ''
                    );
                    ?>
            </td>
        </tr>

        <tr id="row_event_status"   class="datos">
            <td class="bolder"><?php echo __('Event Status'); ?></td>
            <td>
                <?php
                $fields = events_get_all_status(true);
                    html_print_select(
                        $fields,
                        'filter_event_status[]',
                        $filter_event_status,
                        '',
                        '',
                        '',
                        false,
                        true,
                        false,
                        '',
                        false,
                        false,
                        false,
                        false,
                        false,
                        ''
                    );
                    ?>
            </td>
        </tr>

        <tr id="row_extended_events"   class="datos">
            <td class="bolder">
            <?php
            echo __('Include extended events');
            ?>
            </td>
            <td>
                <?php
                html_print_checkbox_switch(
                    'include_extended_events',
                    true,
                    $include_extended_events
                );
                ?>
            </td>
        </tr>

        <tr id="row_event_graphs"   class="datos">
            <td class="bolder"><?php echo __('Event graphs'); ?></td>
            <td>
                <span id="row_event_graph_by_agent">
                <?php
                echo __('By agent ');
                html_print_checkbox_switch(
                    'event_graph_by_agent',
                    true,
                    $event_graph_by_agent
                );
                ?>
                </span>
                <span id="row_event_graph_by_user">
                <?php
                echo __('By user validator ');
                html_print_checkbox_switch(
                    'event_graph_by_user_validator',
                    true,
                    $event_graph_by_user_validator
                );
                ?>
                </span>
                <span id="row_event_graph_by_criticity">
                <?php
                echo __('By criticity ');
                html_print_checkbox_switch(
                    'event_graph_by_criticity',
                    true,
                    $event_graph_by_criticity
                );
                ?>
                </span>
                <span id="row_event_graph_by_validated">
                <?php
                echo __('Validated vs unvalidated ');
                html_print_checkbox_switch(
                    'event_graph_validated_vs_unvalidated',
                    true,
                    $event_graph_validated_vs_unvalidated
                );
                ?>
                </span>
            </td>
        </tr>

        <tr id="row_historical_db_check"   class="datos">
            <td class="bolder">
                <?php
                echo __('Query History Database').ui_print_help_tip(
                    __('With the token enabled the query will affect the Historical Database, which may mean a small drop in performance.'),
                    true
                );
                ?>
            </td>
            <td  >
                <?php
                html_print_checkbox_switch('historical_db_check', 1, $historical_db);
                ?>
            </td>
        </tr>

        <tr id="row_dyn_height"   class="datos">
            <td class="bolder">
            <?php
            echo __('Height (dynamic graphs)');
            ?>
            </td>
            <td>
            <?php
            html_print_input_text(
                'dyn_height',
                $dyn_height,
                '',
                7,
                7
            );
            ?>
                </td>
        </tr>

        <tr id="row_show_in_same_row"   class="datos">
            <td class="bolder" class="datos">
                <?php
                echo __('Show in the same row');
                ui_print_help_tip(
                    __('Show one module per row with all its operations')
                );
                ?>
            </td>
            <td  >
                <?php
                html_print_checkbox_switch(
                    'show_in_same_row',
                    '1',
                    $show_in_same_row,
                    false,
                    false,
                    ''
                );
                ?>
            </td>
        </tr>

        <tr id="row_sort"   class="datos">
            <td class="bolder">
            <?php
            echo __('Order').ui_print_help_tip(
                __('SLA items sorted by fulfillment value'),
                true
            );
            ?>
            </td>
            <td>
            <?php
            html_print_select(
                $show_sort_options,
                'combo_sla_sort_options',
                $sla_sorted_by,
                '',
                __('None'),
                0
            );
            ?>
            </td>
        </tr>

        <tr id="row_priority_mode"   class="datos">
            <td class="bolder">
            <?php
            echo __('Priority mode');
            ?>
            </td>
            <td>
                <?php
                echo __('Priority ok mode');
                echo '<span class="mrgn_lft_5px"></span>';
                html_print_radio_button(
                    'priority_mode',
                    REPORT_PRIORITY_MODE_OK,
                    '',
                    $priority_mode == REPORT_PRIORITY_MODE_OK,
                    ''
                );

                echo '<span class="mrgn_30px"></span>';

                echo __('Priority unknown mode');
                echo '<span class="mrgn_lft_5px"></span>';
                html_print_radio_button(
                    'priority_mode',
                    REPORT_PRIORITY_MODE_UNKNOWN,
                    '',
                    $priority_mode == REPORT_PRIORITY_MODE_UNKNOWN,
                    ''
                );
                ?>
            </td>
        </tr>

        <tr id="row_failover_mode"   class="datos">
            <td class="bolder">
            <?php
            echo __('Failover mode').ui_print_help_tip(
                __('SLA calculation must be performed taking into account the failover modules assigned to the primary module'),
                true
            );
            ?>
            </td>
            <td>
                <?php
                html_print_checkbox_switch(
                    'failover_mode',
                    1,
                    $failover_mode
                );
                ?>
            </td>
        </tr>

        <tr id="row_failover_type"   class="datos">
            <td class="bolder">
            <?php
            echo __('Failover type');
            ?>
            </td>
            <td>
                <?php
                echo __('Failover normal');
                echo '<span class="mrgn_lft_5px"></span>';
                html_print_radio_button(
                    'failover_type',
                    REPORT_FAILOVER_TYPE_NORMAL,
                    '',
                    $failover_type == REPORT_FAILOVER_TYPE_NORMAL,
                    ''
                );

                echo '<span class="mrgn_30px"></span>';

                echo __('Failover simple');
                echo '<span class="mrgn_lft_5px"></span>';
                html_print_radio_button(
                    'failover_type',
                    REPORT_FAILOVER_TYPE_SIMPLE,
                    '',
                    $failover_type == REPORT_FAILOVER_TYPE_SIMPLE,
                    ''
                );
                ?>
            </td>
        </tr>

        <tr id="row_summary"class="datos">
            <td class="bolder">
            <?php
            echo __('Summary');
            ?>
            </td>
            <td>
            <?php
            html_print_checkbox_switch(
                'summary',
                1,
                $summary,
                false,
                false,
                '',
                false
            );
            ?>
            </td>
        </tr>

        <tr id="row_filter_search" class="datos">
            <td class="bolder"><?php echo __('Include filter'); ?></td>
            <td>
                <?php
                html_print_input_text('filter_search', $filter_search);
                ui_print_help_tip(__('Free text string search on event description'));
                ?>
            </td>
        </tr>
        <tr id="row_filter_exclude" style="" class="datos">
            <td style="font-weight:bold;"><?php echo __('Exclude filter'); ?></td>
            <td>
                <?php
                html_print_input_text('filter_exclude', $filter_exclude);
                ui_print_help_tip(__('Free text string search on event description'));
                ?>
            </td>
        </tr>

        <tr id="row_lapse_calc"   class="datos advanced_elements">
            <td class="bolder">
                <?php echo __('Calculate for custom intervals'); ?>
            </td>
            <td  >
                <?php
                html_print_checkbox_switch('lapse_calc', 1, $lapse_calc);
                ?>
            </td>
        </tr>

        <tr id="row_lapse"   class="datos advanced_elements">
            <td class="bolder">
                <?php
                echo __('Time lapse intervals');
                ui_print_help_tip(
                    __(
                        'Lapses of time in which the period is divided to make more precise calculations
'
                    )
                );
                ?>
            </td>
            <td  >
                <?php
                html_print_extended_select_for_time(
                    'lapse',
                    $lapse,
                    '',
                    '',
                    '0',
                    10,
                    '',
                    '',
                    '',
                    '',
                    !$lapse_calc
                );
                ?>
            </td>
        </tr>

        <tr id="row_visual_format"   class="datos advanced_elements">
            <td class="bolder" colspan="2">
                <?php
                if ($visual_format == 1) {
                    $visual_format_table = true;
                    $visual_format_graph = false;
                    $visual_format_both = false;
                } else if ($visual_format == 2) {
                    $visual_format_table = false;
                    $visual_format_graph = true;
                    $visual_format_both = false;
                } else if ($visual_format == 3) {
                    $visual_format_table = false;
                    $visual_format_graph = false;
                    $visual_format_both = true;
                }

                echo __('Table only');
                echo '<span class="mrgn_lft_10px"></span>';
                html_print_radio_button(
                    'visual_format',
                    1,
                    '',
                    $visual_format_table,
                    '',
                    !$lapse_calc
                );
                echo '<span class="mrgn_30px"></span>';
                echo __('Graph only');
                echo '<span class="mrgn_lft_10px"></span>';
                html_print_radio_button(
                    'visual_format',
                    2,
                    '',
                    $visual_format_graph,
                    '',
                    !$lapse_calc
                );
                echo '<span class="mrgn_30px;"></span>';
                echo __('Graph and table');
                echo '<span class="mrgn_lft_10px"></span>';
                html_print_radio_button(
                    'visual_format',
                    3,
                    '',
                    $visual_format_both,
                    '',
                    !$lapse_calc
                );
                ?>
            </td>
        </tr>

        <tr id="row_uncompressed_module"   class="datos">
            <td class="bolder">
            <?php
            echo __('Uncompress module').ui_print_help_tip(
                __('Use uncompressed module data.'),
                true
            );
            ?>
            </td>
            <td  >
            <?php
            html_print_checkbox_switch('uncompressed_module', 1, $item['uncompressed_module'], false, false, '', false);
            ?>
            </td>
        </tr>
        
        <tr id="row_profiles_group"   class="datos">
            <td class="bolder">
                <?php
                echo __('Group');
                ?>
            </td>
            <td>
            <?php
            $user_groups = users_get_groups();

            // Add a selector for users without assigned group.
            $user_groups[''] = __('Unassigned group');

            html_print_select(
                $user_groups,
                'users_groups[]',
                $users_groups,
                '',
                false,
                '',
                false,
                true,
                false,
                '',
                false,
                'min-width: 180px'
            );
            ?>
                </td>
        </tr>

        <tr id="row_users"   class="datos">
            <td class="bolder">
                <?php
                echo __('User');
                ?>
            </td>
            <td  >
                <?php
                $tmp_users = db_get_all_rows_filter('tusuario', [], 'id_user');
                foreach ($tmp_users as $key => $user) {
                    $select_users[$user['id_user']] = $user['id_user'];
                }

                $input_data = [
                    'type'         => 'select_multiple_filtered',
                    'class'        => 'w80p mw600px',
                    'name'         => 'id_users',
                    'return'       => 0,
                    'available'    => array_diff(
                        $select_users,
                        $id_users
                    ),
                    'selected'     => $id_users,
                    'group_filter' => [
                        'page'          => 'godmode/users/user_list',
                        'method'        => 'get_users_by_group',
                        'nothing'       => __('Unnasigned group'),
                        'nothing_value' => -1,
                        'id'            => $id_users,
                    ],
                    'texts'        => [
                        'title-left'  => 'Available users',
                        'title-right' => 'Selected users',
                        'filter-item' => 'Filter user name',
                    ],
                    'sections'     => [
                        'filters'               => 1,
                        'item-selected-filters' => 0,
                    ],
                ];

                html_print_input($input_data, 'div', true);
                ?>
            </td>
        </tr>

        <tr id="row_select_by_group"   class="datos">
            <td class="bolder">
                <?php
                echo __('Select by group');
                ?>
            </td>
            <td>
                <?php
                html_print_checkbox_switch(
                    'select_by_group',
                    1,
                    $select_by_group,
                    false
                );
                ?>
                </td>
        </tr>
        
        <tr id="row_landscape"   class="datos">
            <td class="bolder">
            <?php
            echo __('Show item in landscape format (only PDF)');
            ?>
            </td>
            <td><?php html_print_checkbox_switch('landscape', 1, $landscape); ?></td>
        </tr>

        <tr id="row_pagebreak"   class="datos">
            <td class="bolder">
            <?php
            echo __('Page break at the end of the item (only PDF)');
            ?>
            </td>
            <td><?php html_print_checkbox_switch('pagebreak', 1, $pagebreak); ?></td>
        </tr>

    </tbody>
</table>

<?php
print_SLA_list('100%', $action, $idItem);
print_General_list('100%', $action, $idItem, $type);
echo '<div class="action-buttons w100p" >';
if ($action == 'new') {
    html_print_submit_button(
        __('Create item'),
        'create_item',
        false,
        'class="sub wand"'
    );
} else {
    html_print_submit_button(
        __('Update item'),
        'edit_item',
        false,
        'class="sub upd"'
    );
}

echo '</div>';
echo '</form>';

ui_include_time_picker();
ui_require_javascript_file('pandora');


if ($enterpriseEnable) {
    reporting_enterprise_text_box();
}

// Restore db connection.
if ($meta) {
    metaconsole_restore_db();
}


/**
 * Function for return html.
 *
 * @param integer $width  Size.
 * @param string  $action Type.
 * @param integer $idItem Id Item.
 *
 * @return mixed Return html row tables for SLA.
 */
function print_SLA_list($width, $action, $idItem=null)
{
    global $config;
    global $meta;

    $report_item_type = db_get_value(
        db_escape_key_identifier('type'),
        'treport_content',
        'id_rc',
        $idItem
    );

    $failover_mode = db_get_value(
        'failover_mode',
        'treport_content',
        'id_rc',
        $idItem
    );
    ?>
    <table class="databox data" id="sla_list" border="0" cellpadding="4" cellspacing="4" width="100%">
        <thead>
            <tr>
                <th class="header sla_list_agent_col" scope="col">
                <?php
                echo __('Agent');
                ?>
                </th>
                <th class="header sla_list_module_col" scope="col">
                <?php
                echo __('Module');
                if ($report_item_type == 'availability_graph'
                    && $failover_mode
                ) {
                    ?>
                <th class="header sla_list_agent_failover" scope="col">
                    <?php
                    echo __('Agent Failover');
                    ?>
                </th>
                <th class="header sla_list_module_failover" scope="col">
                    <?php
                    echo __('Module Failover');
                    ?>
                </th>
                    <?php
                }
                ?>
                <th class="header sla_list_service_col" scope="col">
                <?php
                echo __('Service');
                ?>
                </th>
                <th class="header sla_list_sla_min_col" scope="col">
                <?php
                echo __('SLA Min. (value)');
                ?>
                </th>
                <th class="header sla_list_sla_max_col" scope="col">
                <?php
                echo __('SLA Max. (value)');
                ?>
                </th>
                <th class="header sla_list_sla_limit_col" scope="col">
                <?php
                echo __('SLA Limit (%)');
                ?>
                </th>
                <th class="header sla_list_action_col" scope="col">
                <?php
                echo __('Action');
                ?>
                </th>
            </tr>
        </thead>
            <?php
            switch ($action) {
                case 'new':
                    ?>
                    <tr id="sla_template"   class="datos">
                        <td colspan="6">
                        <?php
                        echo __('Please save the SLA for start to add items in this list.');
                        ?>
                        </td>
                    </tr>
                    <?php
                break;

                case 'save':
                case 'update':
                case 'edit':
                    echo '<tbody id="list_sla">';

                    $itemsSLA = db_get_all_rows_filter(
                        'treport_content_sla_combined',
                        ['id_report_content' => $idItem]
                    );

                    if ($itemsSLA === false) {
                        $itemsSLA = [];
                    }

                    foreach ($itemsSLA as $item) {
                        $server_name = $item['server_name'];
                        // Metaconsole db connection.
                        if ($meta && !empty($server_name)) {
                            $connection = metaconsole_get_connection(
                                $server_name
                            );
                            if (metaconsole_load_external_db($connection) != NOERR) {
                                continue;
                            }
                        }

                        $idAgent = db_get_value_filter(
                            'id_agente',
                            'tagente_modulo',
                            ['id_agente_modulo' => $item['id_agent_module']]
                        );
                        $nameAgent = agents_get_alias($idAgent);

                        $nameModule = db_get_value_filter(
                            'nombre',
                            'tagente_modulo',
                            ['id_agente_modulo' => $item['id_agent_module']]
                        );

                        if (isset($item['id_agent_module_failover']) === true
                            && $item['id_agent_module_failover'] !== 0
                        ) {
                            $idAgentFailover = db_get_value_filter(
                                'id_agente',
                                'tagente_modulo',
                                ['id_agente_modulo' => $item['id_agent_module_failover']]
                            );
                            $nameAgentFailover = agents_get_alias(
                                $idAgentFailover
                            );

                            $nameModuleFailover = db_get_value_filter(
                                'nombre',
                                'tagente_modulo',
                                ['id_agente_modulo' => $item['id_agent_module_failover']]
                            );
                        }

                        $server_name_element = '';
                        if ($meta && $server_name != '') {
                            $server_name_element .= ' ('.$server_name.')';
                        }

                        echo '<tr id="sla_'.$item['id'].'"   class="datos">';
                        echo '<td class="sla_list_agent_col">';
                        echo printSmallFont($nameAgent).$server_name_element;
                        echo '</td>';
                        echo '<td class="sla_list_module_col">';
                        echo printSmallFont($nameModule);
                        echo '</td>';

                        if ($report_item_type == 'availability_graph'
                            && $failover_mode
                        ) {
                            echo '<td class="sla_list_agent_failover">';
                            echo printSmallFont($nameAgentFailover).$server_name_element;
                            echo '</td>';
                            echo '<td class="sla_list_module_failover">';
                            echo printSmallFont($nameModuleFailover);
                            echo '</td>';
                        }

                        if (enterprise_installed()
                            && $report_item_type == 'SLA_services'
                        ) {
                            enterprise_include_once(
                                'include/functions_services.php'
                            );
                            $nameService = enterprise_hook(
                                'services_get_name',
                                [$item['id_agent_module']]
                            );
                            echo '<td class="sla_list_service_col">';
                            echo printSmallFont($nameService);
                            echo '</th>';
                        }

                        $item_sla_min = $item['sla_min'];
                        $item_sla_max = $item['sla_max'];
                        $item_sla_limit = $item['sla_limit'];

                        echo '<td class="sla_list_sla_min_col">';
                        echo $item_sla_min;
                        echo '</td>';
                        echo '<td class="sla_list_sla_max_col">';
                        echo $item_sla_max;
                        echo '</td>';
                        echo '<td class="sla_list_sla_limit_col">';
                        echo $item_sla_limit;
                        echo '</td>';
                        echo '<td class="sla_list_action_col center">';
                        echo '<a href="javascript: deleteSLARow('.$item['id'].');">';
                        echo html_print_image('images/cross.png', true, ['class' => 'invert_filter']);
                        echo '</a>';
                        echo '</td>';
                        echo '</tr>';

                        if ($meta) {
                            // Restore db connection.
                            metaconsole_restore_db();
                        }
                    }

                    echo '</tbody>';
                    ?>
                    <tbody id="sla_template">
                        <tr id="row" class="datos" style="display: none">
                            <td class="sla_list_agent_col agent_name"></td>
                            <td class="sla_list_module_col module_name"></td>
                            <?php
                            if ($report_item_type == 'availability_graph'
                                && $failover_mode
                            ) {
                                ?>
                            <td class="sla_list_agent_failover agent_name_failover"></td>
                            <td class="sla_list_module_failover module_name_failover"></td>
                                <?php
                            }

                            if (enterprise_installed()
                                && $report_item_type == 'SLA_services'
                            ) {
                                echo '<td class="sla_list_service_col service_name"></td>';
                            }
                            ?>
                            <td class="sla_list_sla_min_col sla_min"></td>
                            <td class="sla_list_sla_max_col sla_max"></td>
                            <td class="sla_list_sla_limit_col sla_limit"></td>

                            <td class="sla_list_action_col center">
                                <a class="delete_button" href="javascript: deleteSLARow(0);">
                                    <?php
                                    html_print_image(
                                        'images/cross.png',
                                        false,
                                        ['class' => 'invert_filter']
                                    );
                                    ?>
                                </a>
                            </td>
                        </tr>
                    </tbody>

                    <tbody>
                        <tr id="sla_form"   class="datos">
                            <td class="sla_list_agent_col">
                                <input id="hidden-id_agent_sla" name="id_agent_sla" value="" type="hidden">
                                <input id="hidden-id_server" name="id_server" value="" type="hidden">
                                <?php
                                $params = [];
                                $params['show_helptip'] = true;
                                $params['input_name'] = 'agent_sla';
                                $params['value'] = '';
                                $params['use_hidden_input_idagent'] = true;
                                $params['hidden_input_idagent_id'] = 'hidden-id_agent_sla';
                                $params['javascript_is_function_select'] = true;
                                $params['selectbox_id'] = 'id_agent_module_sla';
                                $params['add_none_module'] = false;
                                if ($meta) {
                                    $params['use_input_id_server'] = true;
                                    $params['input_id_server_id'] = 'hidden-id_server';
                                    $params['disabled_javascript_on_blur_function'] = true;
                                }

                                ui_print_agent_autocomplete_input($params);
                                ?>
                            <td class="sla_list_module_col">
                                <select id="id_agent_module_sla" name="id_agente_modulo_sla" disabled="disabled" class="mx180px">
                                    <option value="0">
                                        <?php
                                        echo __('Select an Agent first');
                                        ?>
                                    </option>
                                </select>
                            </td>
                            <?php
                            if ($report_item_type == 'availability_graph'
                                && $failover_mode
                            ) {
                                ?>
                                <td class="sla_list_agent_failover_col">
                                    <input id="hidden-id_agent_failover" name="id_agent_failover" value="" type="hidden">
                                    <input id="hidden-server_name_failover" name="server_name_failover" value="" type="hidden">
                                    <?php
                                    $params = [];
                                    $params['show_helptip'] = true;
                                    $params['input_name'] = 'agent_failover';
                                    $params['value'] = '';
                                    $params['use_hidden_input_idagent'] = true;
                                    $params['hidden_input_idagent_id'] = 'hidden-id_agent_failover';
                                    $params['javascript_is_function_select'] = true;
                                    $params['selectbox_id'] = 'id_agent_module_failover';
                                    $params['add_none_module'] = false;
                                    if ($meta) {
                                        $params['use_input_id_server'] = true;
                                        $params['input_id_server_id'] = 'hidden-id_server';
                                        $params['disabled_javascript_on_blur_function'] = true;
                                    }

                                    ui_print_agent_autocomplete_input($params);
                                    ?>
                                </td>
                                <td class="sla_list_module_failover_col">
                                    <select id="id_agent_module_failover" name="id_agent_module_failover" disabled="disabled" class="mx180px">
                                        <option value="0">
                                            <?php
                                            echo __('Select an Agent first');
                                            ?>
                                        </option>
                                    </select>
                                </td>
                                <?php
                            }

                            if (enterprise_installed()
                                && $report_item_type == 'SLA_services'
                            ) {
                                enterprise_include_once(
                                    'include/functions_services.php'
                                );
                                // Services list.
                                $services = [];
                                $services_tmp = enterprise_hook(
                                    'services_get_services',
                                    [
                                        false,
                                        [
                                            'id',
                                            'name',
                                            'sla_id_module',
                                            'sla_value_id_module',
                                        ],
                                    ]
                                );
                        if (!empty($services_tmp)
                            && $services_tmp != ENTERPRISE_NOT_HOOK
                        ) {
                            foreach ($services_tmp as $service) {
                                $check_module_sla = modules_check_agentmodule_exists(
                                    $service['sla_id_module']
                                );
                                $check_module_sla_value = modules_check_agentmodule_exists(
                                    $service['sla_value_id_module']
                                );
                                if ($check_module_sla
                                    && $check_module_sla_value
                                ) {
                                    $services[$service['id']] = $service['name'];
                                }
                            }
                        }

                                echo '<td class="sla_list_service_col">';
                                echo html_print_select(
                                    $services,
                                    'id_service',
                                    false,
                                    '',
                                    '',
                                    '',
                                    true,
                                    false,
                                    false
                                );
                                echo '</td>';
                            }
                            ?>
                            <td class="sla_list_sla_min_col">
                                <input name="sla_min" id="text-sla_min" size="10" maxlength="10" type="text">
                            </td>
                            <td class="sla_list_sla_max_col">
                                <input name="sla_max" id="text-sla_max" size="10" maxlength="10" type="text">
                            </td>
                            <td class="sla_list_sla_limit_col">
                                <input name="sla_limit" id="text-sla_limit" size="10" maxlength="10" type="text">
                            </td>
                            <td class="sla_list_action_col center">
                                <a href="javascript: addSLARow();">
                                    <?php
                                    html_print_image(
                                        'images/disk.png',
                                        false,
                                        ['class' => 'invert_filter']
                                    );
                                    ?>
                                </a>
                            </td>
                        </tr>
                    </tbody>
                    <?php
                break;

                default:
                    // It's not possible.
                break;
            }
            ?>
    </table>
    <span class="invisible" id="module_sla_text">
        <?php echo __('Select an Agent first'); ?>
    </span>
    <?php
}


/**
 * Functions return rows html.
 *
 * @param integer $width  Size.
 * @param string  $action Type action.
 * @param integer $idItem Id type.
 * @param string  $type   Type.
 *
 * @return mixed Return html rows.
 */
function print_General_list($width, $action, $idItem=null, $type='general')
{
    global $config;
    global $meta;

    if (!isset($meta)) {
        $meta = false;
    }

    $failover_mode = db_get_value(
        'failover_mode',
        'treport_content',
        'id_rc',
        $idItem
    );

    $operation = [
        'avg' => __('rate'),
        'max' => __('max'),
        'min' => __('min'),
        'sum' => __('sum'),
    ];

    include_once $config['homedir'].'/include/functions_html.php';
    ?>
    <table class="databox data" id="general_list" border="0" cellpadding="4" cellspacing="4" width="100%">
        <thead>
            <tr>
                <?php
                if ($type == 'availability') {
                    ?>
                    <th class="header" scope="col">
                        <?php echo __('Agent'); ?>
                    </th>
                    <th class="header" scope="col">
                        <?php echo __('Module'); ?>
                    </th>
                    <?php
                    if ($failover_mode) {
                        ?>
                        <th class="header" scope="col">
                            <?php echo __('Agent Failover'); ?>
                        </th>
                        <th class="header" scope="col">
                            <?php echo __('Module Failover'); ?>
                        </th>
                        <?php
                    }
                    ?>
                    <th class="header" scope="col">
                        <?php echo __('Action'); ?>
                    </th>
                    <?php
                } else {
                    ?>
                    <th class="header" scope="col">
                        <?php echo __('Agent'); ?>
                    </th>
                    <th class="header" scope="col">
                        <?php echo __('Module'); ?>
                    </th>
                    <th class="header" scope="col">
                        <?php
                        echo __('Operation');
                        echo ui_print_help_tip(
                            __('Please be careful, when the module have diferent intervals in their life, the summatory maybe get bad result.'),
                            true
                        );
                        ?>
                    </th>
                    <th class="header" scope="col">
                        <?php echo __('Action'); ?>
                    </th>
                    <?php
                }
                ?>
            </tr>
        </thead>
            <?php
            switch ($action) {
                case 'new':
                    ?>
                    <tr id="general_template"   class="datos">
                        <td colspan="4">
                            <?php
                            echo __('Please save the report to start adding items into the list.');
                            ?>
                        </td>
                    </tr>
                    <?php
                break;

                case 'save':
                case 'update':
                case 'edit':
                    echo '<tbody id="list_general">';
                    $itemsGeneral = db_get_all_rows_filter(
                        'treport_content_item',
                        ['id_report_content' => $idItem]
                    );

                    if ($itemsGeneral === false) {
                        $itemsGeneral = [];
                    }

                    foreach ($itemsGeneral as $item) {
                        $server_name = $item['server_name'];
                        // Metaconsole db connection.
                        if ($meta && !empty($server_name)) {
                            $connection = metaconsole_get_connection(
                                $server_name
                            );
                            if (metaconsole_load_external_db($connection) != NOERR) {
                                continue;
                            }
                        }

                        $idAgent = db_get_value_filter(
                            'id_agente',
                            'tagente_modulo',
                            ['id_agente_modulo' => $item['id_agent_module']]
                        );

                        $nameAgent = agents_get_alias($idAgent);
                        $nameModule = db_get_value_filter(
                            'nombre',
                            'tagente_modulo',
                            ['id_agente_modulo' => $item['id_agent_module']]
                        );

                        if (isset($item['id_agent_module_failover']) === true
                            && $item['id_agent_module_failover'] !== 0
                        ) {
                            $idAgentFailover = db_get_value_filter(
                                'id_agente',
                                'tagente_modulo',
                                ['id_agente_modulo' => $item['id_agent_module_failover']]
                            );
                            $nameAgentFailover = agents_get_alias(
                                $idAgentFailover
                            );

                            $nameModuleFailover = db_get_value_filter(
                                'nombre',
                                'tagente_modulo',
                                ['id_agente_modulo' => $item['id_agent_module_failover']]
                            );
                        }

                        $server_name_element = '';
                        if ($meta && $server_name != '') {
                            $server_name_element .= ' ('.$server_name.')';
                        }

                        if ($type == 'availability') {
                            if ($failover_mode) {
                                echo '<tr id="general_'.$item['id'].'"   class="datos">
                                    <td>'.printSmallFont($nameAgent).$server_name_element.'</td>
                                    <td>'.printSmallFont($nameModule).'</td>
                                    <td>'.printSmallFont($nameAgentFailover).$server_name_element.'</td>
                                    <td>'.printSmallFont($nameModuleFailover).'</td>
                                    <td class="center">
                                        <a href="javascript: deleteGeneralRow('.$item['id'].');">'.html_print_image('images/cross.png', true, ['class' => 'invert_filter']).'</a>
                                    </td>
                                </tr>';
                            } else {
                                echo '<tr id="general_'.$item['id'].'"   class="datos">
                                    <td>'.printSmallFont($nameAgent).$server_name_element.'</td>
                                    <td>'.printSmallFont($nameModule).'</td>
                                    <td class="center">
                                        <a href="javascript: deleteGeneralRow('.$item['id'].');">'.html_print_image('images/cross.png', true, ['class' => 'invert_filter']).'</a>
                                    </td>
                                </tr>';
                            }
                        } else {
                            echo '<tr id="general_'.$item['id'].'"   class="datos">
								<td>'.printSmallFont($nameAgent).$server_name_element.'</td>
								<td>'.printSmallFont($nameModule).'</td>
								<td>'.printSmallFont($operation[$item['operation']]).'</td>
								<td class="center">
									<a href="javascript: deleteGeneralRow('.$item['id'].');">'.html_print_image('images/cross.png', true, ['class' => 'invert_filter']).'</a>
								</td>
							</tr>';
                        }

                        if ($meta) {
                            // Restore db connection.
                            metaconsole_restore_db();
                        }
                    }

                    echo '</tbody>';
                    ?>

                    <tbody id="general_template">
                        <tr id="row" class="datos" style="display: none">
                            <td class="agent_name"></td>
                            <td class="module_name"></td>
                            <?php
                            if ($type == 'availability'
                                && $failover_mode
                            ) {
                                ?>
                                <td class="agent_name_failover"></td>
                                <td class="module_name_failover"></td>
                                <?php
                            }

                            if ($type != 'availability') {
                                ?>
                                <td class="operation_name"></td>
                                <?php
                            }
                            ?>
                            <td class="center">
                                <a class="delete_button" href="javascript: deleteGeneralRow(0);">
                                    <?php
                                    html_print_image(
                                        'images/cross.png',
                                        false,
                                        ['class' => 'invert_filter']
                                    );
                                    ?>
                                </a>
                            </td>
                        </tr>
                    </tbody>

                    <tbody>
                        <tr id="general_form"   class="datos">
                            <td>
                                <input id="hidden-id_agent_general" name="id_agent_general" value="" type="hidden">
                                <input id="hidden-server_name_general" name="server_name_general" value="" type="hidden">
                                <?php
                                $params = [];
                                $params['show_helptip'] = true;
                                $params['input_name'] = 'agent_general';
                                $params['value'] = '';
                                $params['use_hidden_input_idagent'] = true;
                                $params['hidden_input_idagent_id'] = 'hidden-id_agent_general';
                                $params['javascript_is_function_select'] = true;
                                $params['selectbox_id'] = 'id_agent_module_general';
                                $params['add_none_module'] = false;
                                if ($meta) {
                                    $params['use_input_id_server'] = true;
                                    $params['input_id_server_id'] = 'hidden-id_server';
                                    $params['disabled_javascript_on_blur_function'] = true;
                                    $params['javascript_is_function_select'] = true;
                                }

                                ui_print_agent_autocomplete_input($params);
                                ?>
                            </td>
                            <td>
                                <select id="id_agent_module_general" name="id_agente_modulo_general" disabled="disabled" class="mx180px">
                                    <option value="0">
                                        <?php
                                        echo __('Select an Agent first');
                                        ?>
                                    </option>
                                </select>
                            </td>
                            <?php
                            if ($type == 'availability' && $failover_mode) {
                                ?>
                                <td class="sla_list_agent_failover_col">
                                    <input id="hidden-id_agent_failover" name="id_agent_failover" value="" type="hidden">
                                    <input id="hidden-server_name_failover" name="server_name_failover" value="" type="hidden">
                                    <?php
                                    $params = [];
                                    $params['show_helptip'] = true;
                                    $params['input_name'] = 'agent_failover';
                                    $params['value'] = '';
                                    $params['use_hidden_input_idagent'] = true;
                                    $params['hidden_input_idagent_id'] = 'hidden-id_agent_failover';
                                    $params['javascript_is_function_select'] = true;
                                    $params['selectbox_id'] = 'id_agent_module_failover';
                                    $params['add_none_module'] = false;
                                    if ($meta) {
                                        $params['use_input_id_server'] = true;
                                        $params['input_id_server_id'] = 'hidden-id_server';
                                        $params['disabled_javascript_on_blur_function'] = true;
                                    }

                                    ui_print_agent_autocomplete_input($params);
                                    ?>
                                </td>
                                <td class="sla_list_module_failover_col">
                                    <select id="id_agent_module_failover" name="id_agent_module_failover" disabled="disabled" class="mx180px">
                                        <option value="0">
                                            <?php
                                            echo __('Select an Agent first');
                                            ?>
                                        </option>
                                    </select>
                                </td>
                                <?php
                            }
                            ?>
                            <?php
                            if ($type !== 'availability') {
                                ?>
                                <td>
                                    <?php
                                    html_print_select(
                                        $operation,
                                        'id_operation_module_general',
                                        0,
                                        false,
                                        '',
                                        '',
                                        false,
                                        false,
                                        true,
                                        'width: 200px',
                                        false
                                    );
                                    ?>
                                </td>
                                <?php
                            }
                            ?>
                            <td class="center">
                                <a href="javascript: addGeneralRow();">
                                    <?php
                                    html_print_image(
                                        'images/disk.png',
                                        false,
                                        ['class' => 'invert_filter']
                                    );
                                    ?>
                                </a>
                            </td>
                        </tr>
                    </tbody>
                    <?php
                break;

                default:
                    // It's not possible.
                break;
            }
            ?>
    </table>
    <span class="invisible" id="module_general_text">
        <?php echo __('Select an Agent first'); ?>
    </span>
    <?php
}


echo "<div id='message_no_name'  title='".__('Item Editor Information')."' class='invisible'>";
echo "<p class='center bolder'>".__('Please select a name.').'</p>';
echo '</div>';

echo "<div id='message_no_agent'  title='".__('Item Editor Information')."' class='invisible'>";
echo "<p class='center bolder'>".__('Please select an agent.').'</p>';
echo '</div>';

echo "<div id='message_no_module'  title='".__('Item Editor Information')."' class='invisible'>";
echo "<p class='center bolder'>".__('Please select a module.').'</p>';
echo '</div>';

echo "<div id='message_no_sql_query'  title='".__('Item Editor Information')."' class='invisible'>";
echo "<p class='center bolder'>".__('Please insert a SQL query.').'</p>';
echo '</div>';

echo "<div id='message_no_url'  title='".__('Item Editor Information')."' class='invisible'>";
echo "<p class='center bolder'>".__('Please insert a URL.').'</p>';
echo '</div>';

echo "<div id='message_no_interval_option'  title='".__('Item Editor Information')."' class='invisible'>";
echo "<p class='center bolder''>".__('Please checked a custom interval option.').'</p>';
echo '</div>';

echo "<div id='message_no_user'  title='".__('Item Editor Information')."' class='invisible'>";
echo "<p class='center bolder'>".__('Please select a user.').'</p>';
echo '</div>';

echo "<div id='message_no_group'  title='".__('Item Editor Information')."' class='invisible'>";
echo "<p class='center bolder'>".__('Please select a group.').'</p>';
echo '</div>';

ui_require_javascript_file(
    'pandora_inventory',
    ENTERPRISE_DIR.'/include/javascript/'
);
?>

<script type="text/javascript">
$(document).ready (function () {
    chooseType();
    chooseSQLquery();

    $("#id_agents").change(agent_changed_by_multiple_agents);

    // Load selected modules by default
    $("#id_agents2").trigger('click');

    $('#combo_server').change (function (){
        $("#id_agents").html('');
            $("#id_agents2").html('');
            $("#module").html('');
            $("#inventory_modules").html('');
    })

    $("#text-url").keyup (
        function () {
            const user_typed_url = $(this).val();

            if (user_typed_url.match('^.+:\/\/')) {
                $("#url_warning_text").hide();
            } else {
                $("#url_warning_text").show();
            }
        }
    );

    $("#combo_group").change (
        function () {

            // Alert report group must show all matches when selecting All group
            // ignoring 'recursion' option. #6497.
            if ($("#combo_group").val() == 0) {
                $('#checkbox-recursion').attr('disabled',true)
                $('#checkbox-recursion').attr('checked','checked')
            } else {
                $('#checkbox-recursion').removeAttr('disabled')
            }

            $("#id_agents2").html('');
            $("#module").html('');
            $("#inventory_modules").html('');
            jQuery.post ("ajax.php",
                {"page" : "operation/agentes/ver_agente",
                    "get_agents_group_json" : 1,
                    "id_group" : this.value,
                    "privilege" : "AW",
                    "keys_prefix" : "_",
                    "recursion" : $('#checkbox-recursion').is(':checked')
                },
                function (data, status) {
                    jQuery.each (data, function (id, value) {
                        // Remove keys_prefix from the index
                        id = id.substring(1);

                        option = $("<option></option>")
                            .attr ("value", value["id_agente"])
                            .html (value["alias"]);
                        $("#id_agents").append (option);
                        $("#id_agents2").append (option);
                    });
                },
                "json"
            );
        }
    );
    $("#combo_group").change();

    $("#checkbox-recursion").change (
        function () {
            jQuery.post ("ajax.php",
                {"page" : "operation/agentes/ver_agente",
                    "get_agents_group_json" : 1,
                    "id_group" : $("#combo_group").val(),
                    "privilege" : "AW",
                    "keys_prefix" : "_",
                    "recursion" : $('#checkbox-recursion').is(':checked')
                },
                function (data, status) {
                    $("#id_agents2").html('');
                    jQuery.each (data, function (id, value) {
                        // Remove keys_prefix from the index
                        id = id.substring(1);

                        option = $("<option></option>")
                            .attr ("value", value["id_agente"])
                            .html (value["alias"]);
                        $("#id_agents").append (option);
                        $("#id_agents2").append (option);
                    });
                },
                "json"
            );
        }
    );

    $("#combo_modulegroup").change (
        function () {
            jQuery.post ("ajax.php",
                {"page" : "operation/agentes/ver_agente",
                    "get_modules_group_json" : 1,
                    "id_module_group" : this.value,
                    "id_agents" : $("#id_agents2").val(),
                    "selection" : $("#selection_agent_module").val()
                },
                function (data, status) {
                    $("#module").html('');
                    jQuery.each (data, function (id, value) {
                        option = $("<option></option>")
                            .attr ("value", value["id_agente_modulo"])
                            .html (value["nombre"]);
                        $("#module").append (option);
                    });
                },
                "json"
            );
        }
    );

    $("#id_agents2").change (
        function () {
            jQuery.post ("ajax.php",
                {"page" : "operation/agentes/ver_agente",
                    "get_modules_group_json" : 1,
                    "id_module_group" : $("#combo_modulegroup").val(),
                    "id_agents" : $("#id_agents2").val(),
                    "selection" : $("#selection_agent_module").val()
                },
                function (data, status) {
                    $("#module").html('');
                    if(data){
                        jQuery.each (data, function (id, value) {
                            option = $("<option></option>")
                                .attr ("value", value["id_agente_modulo"])
                                .html (value["nombre"]);
                            $("#module").append (option);
                        });
                    }
                },
                "json"
            );
        }
    );

    $("#selection_agent_module").change(
        function() {
            jQuery.post ("ajax.php",
                {"page" : "operation/agentes/ver_agente",
                    "get_modules_group_json" : 1,
                    "id_module_group" : $("#combo_modulegroup").val(),
                    "id_agents" : $("#id_agents2").val(),
                    "selection" : $("#selection_agent_module").val()
                },
                function (data, status) {
                    $("#module").html('');
                    if(data){
                        jQuery.each (data, function (id, value) {
                            option = $("<option></option>")
                                .attr ("value", value["id_agente_modulo"])
                                .html (value["nombre"]);
                            $("#module").append (option);
                        });
                    }
                },
                "json"
            );
        }
    );

    $("#text-time_to, #text-time_from").timepicker({
        showSecond: true,
        timeFormat: '<?php echo TIME_FORMAT_JS; ?>',
        timeOnlyTitle: '<?php echo __('Choose time'); ?>',
        timeText: '<?php echo __('Time'); ?>',
        hourText: '<?php echo __('Hour'); ?>',
        minuteText: '<?php echo __('Minute'); ?>',
        secondText: '<?php echo __('Second'); ?>',
        currentText: '<?php echo __('Now'); ?>',
        closeText: '<?php echo __('Close'); ?>'
    });

    $('#id_agent_module').change(function(){
        var idModule = $(this).val();
        var params = [];
        params.push("get_type=1");
        params.push("id_module=" + idModule);
        params.push("page=include/ajax/module");
        jQuery.ajax ({
            data: params.join ("&"),
            type: 'POST',
            url: action=
            <?php
            echo '"'.ui_get_full_url(
                false,
                false,
                false,
                false
            ).'"';
            ?>
            + "/ajax.php",
            async: false,
            timeout: 10000,
            success: function (data) {
                switch (data) {
                    case 'boolean':
                    case 'sparse':
                        //$("#row_percentil").show();
                        break;
                    default:
                        $("#row_percentil").hide();
                        break;
                }
            }
        });
    });

    $("#checkbox-select_by_group").change(function () {
        var select_by_group  = $('#checkbox-select_by_group').prop('checked');
    
    if(select_by_group == true) {
        $("#row_users").hide(); 
        $("#row_profiles_group").show(); 

    } else {
        $("#row_users").show(); 
        $("#row_profiles_group").hide(); 

    }
    });

    $("#checkbox-fullscale").change(function(e){
        if(e.target.checked === true) {
            $("#graph_render").prop('disabled', 'disabled');
        } else {
            $("#graph_render").prop('disabled', false);
        }
    });

    $('#checkbox-fullscale').trigger('change');

    $("#submit-create_item").click(function () {
        var type = $('#type').val();
        var name = $('#text-name').val();

        if($('#text-name').val() == ''){
            dialog_message('#message_no_name');
                return false;
        }

        switch (type){
            case 'alert_report_module':
            case 'alert_report_agent':
            case 'event_report_agent':
            case 'event_report_module':
            case 'simple_graph':
            case 'simple_baseline_graph':
            case 'prediction_date':
            case 'projection_graph':
            case 'avg_value':
            case 'max_value':
            case 'min_value':
            case 'monitor_report':
            case 'histogram_data':
            case 'database_serialized':
            case 'last_value':
            case 'sumatory':
            case 'historical_data':
            case 'agent_configuration':
            case 'module_histogram_graph':
            case 'increment':
                if ($("#hidden-id_agent").val() == 0) {
                    dialog_message('#message_no_agent');
                    return false;
                }
                break;
            case 'agent_module':
                if ($("select#id_agents2>option:selected").val() == undefined) {
                    dialog_message('#message_no_agent');
                      return false;
                      }
                      break;
            case 'inventory':
            case 'inventory_changes':
                 if ($("select#id_agents>option:selected").val() == undefined) {
                    dialog_message('#message_no_agent');
                    return false;
                    }
                    break;
            case 'event_report_log':
                if ($("#id_agents3").val() == '') {
                    dialog_message('#message_no_agent');
                    return false;
                }
                break;
                case 'permissions_report':
                if ($("#checkbox-select_by_group").prop("checked") && $("select#users_groups>option:selected").val() == undefined) {
                    dialog_message('#message_no_group');
                    return false;
                    }
                if ($("#checkbox-select_by_group").prop("checked") == false && $("select#selected-select-id_users>option:selected").val() == 0) {
                    dialog_message('#message_no_user');
                    return false;
                    }
            break;
            default:
                break;
        }

        switch (type){
            case 'alert_report_module':
            case 'event_report_module':
            case 'simple_graph':
            case 'simple_baseline_graph':
            case 'prediction_date':
            case 'projection_graph':
            case 'monitor_report':
            case 'histogram_data':
            case 'module_histogram_graph':
            case 'avg_value':
            case 'max_value':
            case 'min_value':
            case 'database_serialized':
            case 'last_value':
            case 'sumatory':
            case 'historical_data':
            case 'increment':

                if ($("#id_agent_module").val() == 0) {
                    dialog_message('#message_no_module');
                    return false;
                }
                break;
            case 'agent_module':
                if ($("select#module>option:selected").val() == undefined) {
                    dialog_message('#message_no_module');
                    return false;
                    }
                    break;
            case 'inventory':
            case 'inventory_changes':
                if ($("select#inventory_modules>option:selected").val() == 0) {
                    dialog_message('#message_no_module');
                    return false;
                }
                    break;
            case 'sql':
                if ($("#textarea_sql").val() == ''
                && $("select#id_custom>option:selected").val() == 0) {
                    dialog_message('#message_no_sql_query');
                    return false;
                }
                    break;
            case 'sql_graph_pie':
            case 'sql_graph_hbar':
            case 'sql_graph_vbar':
                if ($("#textarea_sql").val() == '') {
                    dialog_message('#message_no_sql_query');
                    return false;
                }
                    break;
            case 'url':
                if ($("#text-url").val() == '') {
                    dialog_message('#message_no_url');
                     return false;
                }
                    break;
            default:
                break;
        }

        if (type == 'avg_value' || type == 'max_value' || type == 'min_value') {
            if (($('input:radio[name=visual_format]:checked').val() != 1
            && $('input:radio[name=visual_format]:checked').val() != 2
            && $('input:radio[name=visual_format]:checked').val() != 3)
            && $("#checkbox-lapse_calc").is(":checked")) {
                dialog_message('#message_no_interval_option');
                     return false;
            }
        }

    });

    $("#submit-edit_item").click(function () {
        var type = $('#type').val();

        if($('#text-name').val() == ''){
            dialog_message('#message_no_name');
                return false;
        }
        switch (type){
            case 'alert_report_module':
            case 'alert_report_agent':
            case 'event_report_agent':
            case 'event_report_module':
            case 'simple_graph':
            case 'simple_baseline_graph':
            case 'prediction_date':
            case 'projection_graph':
            case 'avg_value':
            case 'max_value':
            case 'min_value':
            case 'monitor_report':
            case 'histogram_data':
            case 'database_serialized':
            case 'last_value':
            case 'sumatory':
            case 'historical_data':
            case 'agent_configuration':
            case 'module_histogram_graph':
            case 'increment':
                if ($("#hidden-id_agent").val() == 0) {
                    dialog_message('#message_no_agent');
                    return false;
                }
                break;
            case 'agent_module':
                if ($("select#id_agents2>option:selected").val() == undefined) {
                    dialog_message('#message_no_agent');
                    return false;
                    }
                    break;
            case 'inventory':
                if ($("select#id_agents>option:selected").val() == undefined) {
                    dialog_message('#message_no_agent');
                    return false;
                    }
                    break;
            
            case 'permissions_report':
                if ($("#checkbox-select_by_group").prop("checked") && $("select#users_groups>option:selected").val() == undefined) {
                    dialog_message('#message_no_group');
                    return false;
                    }
                if ($("#checkbox-select_by_group").prop("checked") == false && $("select#selected-select-id_users>option:selected").val() == 0) {
                    dialog_message('#message_no_user');
                    return false;
                    }
            break;

            default:
                break;
        }

        switch (type){
            case 'alert_report_module':
            case 'event_report_module':
            case 'simple_graph':
            case 'simple_baseline_graph':
            case 'prediction_date':
            case 'projection_graph':
            case 'monitor_report':
            case 'histogram_data':
            case 'module_histogram_graph':
            case 'avg_value':
            case 'max_value':
            case 'min_value':
            case 'database_serialized':
            case 'last_value':
            case 'sumatory':
            case 'historical_data':
            case 'increment':

                if ($("#id_agent_module").val() == 0) {
                    dialog_message('#message_no_module');
                    return false;
                }
                break;
            case 'agent_module':
                if ($("select#module>option:selected").val() == undefined) {
                    dialog_message('#message_no_module');
                    return false;
                }
                    break;
            case 'inventory':
                if ($("select#inventory_modules>option:selected").val() == 0) {
                    dialog_message('#message_no_module');
                    return false;
                }
                    break;
            case 'sql':
                if ($("#textarea_sql").val() == ''
                && $("select#id_custom>option:selected").val() == 0) {
                    dialog_message('#message_no_sql_query');
                    return false;
                }
                    break;
            case 'sql_graph_pie':
            case 'sql_graph_hbar':
            case 'sql_graph_vbar':
                if ($("#textarea_sql").val() == '') {
                    dialog_message('#message_no_sql_query');
                     return false;
                }
                    break;
            case 'url':
                if ($("#text-url").val() == '') {
                    dialog_message('#message_no_url');
                     return false;
                }
                    break;
            default:
                break;
        }

        if (type == 'avg_value' || type == 'max_value' || type == 'min_value') {
            if (($('input:radio[name=visual_format]:checked').val() != 1
            && $('input:radio[name=visual_format]:checked').val() != 2
            && $('input:radio[name=visual_format]:checked').val() != 3)
            && $("#checkbox-lapse_calc").is(":checked")) {
                dialog_message('#message_no_interval_option');
                     return false;
            }
        }

    });

    $("#checkbox-lapse_calc").change(function () {
        if($(this).is(":checked")){
            $( "#lapse_select" ).prop( "disabled", false );
            $("[name=visual_format]").prop( "disabled", false );
        }
        else{
            $( "#lapse_select" ).prop( "disabled", true );
            $("[name=visual_format]").prop( "disabled", true );
        }
    });

    $("#checkbox-checkbox_show_resume").change(function(){
        type = $("#type").val();
        if($(this).is(":checked") && type !== 'general'){
            $("#row_select_fields2").show();
            $("#row_select_fields3").show();
        }
        else{
            $("#row_select_fields2").hide();
            $("#row_select_fields3").hide();
        }
    });

    $("#checkbox-failover_mode").change(function(){
        if($(this).is(":checked")){
            $("#row_failover_type").show();
        }
        else{
            $("#row_failover_type").hide();
        }
    });
});

function create_custom_graph() {
    <?php
    global $config;

    // Metaconsole activated.
    if ($meta) {
        ?>
        var target_server = $("#meta_servers").val();
        // If target server is not selected.
        if (target_server == 0) {
            $("#meta_target_servers").fadeOut ('normal');
            $("#meta_target_servers").fadeIn ('normal');
            $("#meta_target_servers").css('display', 'inline');
        }
        else {
            var hash_data;
            var params1 = [];
            params1.push("get_metaconsole_hash_data=1");
            params1.push("server_name=" + target_server);
            params1.push("page=include/ajax/reporting.ajax");
            jQuery.ajax ({
                data: params1.join ("&"),
                type: 'POST',
                url: action=
                <?php
                echo '"'.ui_get_full_url(false, false, false, false).'"';
                ?>
                + "/ajax.php",
                async: false,
                timeout: 10000,
                success: function (data) {
                    hash_data = data;
                }
            });

            var server_url;
            var params1 = [];
            params1.push("get_metaconsole_server_url=1");
            params1.push("server_name=" + target_server);
            params1.push("page=include/ajax/reporting.ajax");
            jQuery.ajax ({
                data: params1.join ("&"),
                type: 'POST',
                url: action=
                <?php
                echo '"'.ui_get_full_url(
                    false,
                    false,
                    false,
                    false
                ).'"';
                ?>
                + "/ajax.php",
                async: false,
                timeout: 10000,
                success: function (data) {
                    server_url = data;
                }
            });

            window.location.href = server_url + "/index.php?sec=reporting&sec2=godmode/reporting/graph_builder&create=Create graph" + hash_data;
        }
        <?php
    } else {
        ?>
        window.location.href = "index.php?sec=reporting&sec2=godmode/reporting/graph_builder&create=Create graph";
        <?php
    }
    ?>
}

function edit_custom_graph() {
    var id_graph = $("#id_custom_graph").val();
    <?php
    global $config;

    // Metaconsole activated.
    if ($meta) {
        ?>
        var agent_server_temp;
        var id_element_graph;
        var id_server;

        if (id_graph.indexOf("|") != -1) {
            agent_server_temp = id_graph.split('|');
            id_element_graph = agent_server_temp[0];
            id_server = agent_server_temp[1];
        }

        var hash_data;
        var params1 = [];
        params1.push("get_metaconsole_hash_data=1");
        params1.push("server_name=" + id_server);
        params1.push("page=include/ajax/reporting.ajax");
        jQuery.ajax ({
            data: params1.join ("&"),
            type: 'POST',
            url: action=
            <?php
            echo '"'.ui_get_full_url(
                false,
                false,
                false,
                false
            ).'"';
            ?>
            + "/ajax.php",
            async: false,
            timeout: 10000,
            success: function (data) {
                hash_data = data;
            }
        });

        var server_url;
        var params1 = [];
        params1.push("get_metaconsole_server_url=1");
        params1.push("server_name=" + id_server);
        params1.push("page=include/ajax/reporting.ajax");
        jQuery.ajax ({
            data: params1.join ("&"),
            type: 'POST',
            url: action=
            <?php
            echo '"'.ui_get_full_url(
                false,
                false,
                false,
                false
            ).'"';
            ?>
            + "/ajax.php",
            async: false,
            timeout: 10000,
            success: function (data) {
                server_url = data;
            }
        });

        window.location.href = server_url + "index.php?sec=reporting&sec2=godmode/reporting/graph_builder&edit_graph=1&id=" + id_element_graph + hash_data;        
        <?php
    } else {
        ?>
        window.location.href = "index.php?sec=reporting&sec2=godmode/reporting/graph_builder&edit_graph=1&id=" + id_graph;
        <?php
    }
    ?>
}

function change_custom_graph() {
    //Hidden the button create or edit custom graph
    if ($("#id_custom_graph").val() != "0") {
        $("#meta_servers").val(0);
        $("#meta_target_servers").css('display', 'none');
        $("#button-create_graph").css("display", "none");
        $("#button-edit_graph").css("display", "");
    }
    else {
        $("#meta_servers").val(0);
        $("#meta_target_servers").css('display', 'none');
        $("#button-create_graph").css("display", "");
        $("#button-edit_graph").css("display", "none");
    }
}

function chooseSQLquery() {
    var idCustom = $("#id_custom").val();

    if (idCustom == 0) {
        $("#sql_example").css('display', 'none');
        $("#sql_entry").css('display', '');
        $("#sql_example").html('');
    }
    else {
        $("#sql_example").css('display', '');
        $("#sql_entry").css('display', 'none');

        var params1 = [];
        params1.push("get_image_path=1");
        params1.push("img_src=" + "images/spinner.gif");
        params1.push("page=include/ajax/skins.ajax");
        jQuery.ajax ({
            data: params1.join ("&"),
            type: 'POST',
            url: action=
            <?php
            echo '"'.ui_get_full_url(
                false,
                false,
                false,
                false
            ).'"';
            ?>
            + "/ajax.php",
            async: false,
            timeout: 10000,
            success: function (data) {
                $("#sql_example").html(data);
            }
        });

        var params = [];
        params.push("get_custom_sql=1");
        params.push("id=" + idCustom);
        params.push("page=include/ajax/reporting.ajax");
        jQuery.ajax ({
            data: params.join ("&"),
            type: 'POST',
            url: action=
            <?php
            echo '"'.ui_get_full_url(
                false,
                false,
                false,
                false
            ).'"';
            ?>
            + "/ajax.php",
            timeout: 10000,
            dataType: 'json',
            success: function (data) {
                if (data['correct']) {
                    $("#sql_example").html(data['sql']);
                }
            }
        });
    }
}

function deleteSLARow(id_row) {
    //ajax to delete
    var params = [];
    params.push("delete_sla_item=1");
    params.push("id=" + id_row);
    params.push("page=include/ajax/reporting.ajax");
    jQuery.ajax ({
        data: params.join ("&"),
        type: 'POST',
        url: action=
        <?php
        echo '"'.ui_get_full_url(
            false,
            false,
            false,
            false
        ).'"';
        ?>
        + "/ajax.php",
        timeout: 10000,
        dataType: 'json',
        success: function (data) {
            if (data['correct']) {
                $("#sla_" + id_row).remove();
            }
        }
    });
}

function deleteGeneralRow(id_row) {
    //ajax to delete
    var params = [];
    params.push("delete_general_item=1");
    params.push("id=" + id_row);
    params.push("page=include/ajax/reporting.ajax");
    jQuery.ajax ({
        data: params.join ("&"),
        type: 'POST',
        url: action=
        <?php
        echo '"'.ui_get_full_url(
            false,
            false,
            false,
            false
        ).'"';
        ?>
        + "/ajax.php",
        timeout: 10000,
        dataType: 'json',
        success: function (data) {
            if (data['correct']) {
                $("#general_" + id_row).remove();
            }
        }
    });
}

function addSLARow() {
    var nameAgent = $("input[name=agent_sla]").val();
    var nameAgentFailover = $("input[name=agent_failover]").val();
    var idAgent = $("input[name=id_agent_sla]").val();
    var serverId = $("input[name=id_server]").val();
    var idModule = $("#id_agent_module_sla").val();
    var idModuleFailover = $("#id_agent_module_failover").val();
    var nameModule = $("#id_agent_module_sla :selected").text();
    var nameModuleFailover = $("#id_agent_module_failover :selected").text();
    var slaMin = $("input[name=sla_min]").val();
    var slaMax = $("input[name=sla_max]").val();
    var slaLimit = $("input[name=sla_limit]").val();
    var serviceId = $("select#id_service>option:selected").val();
    var serviceName = $("select#id_service>option:selected").text();

    if (((idAgent != '') && (slaMin != '') && (slaMax != '')
        && (slaLimit != '')) || serviceId != '') {
            if (nameAgent != '') {
                //Truncate nameAgent
                var params = [];
                params.push("truncate_text=1");
                params.push("text=" + nameAgent);
                params.push("page=include/ajax/reporting.ajax");
                jQuery.ajax ({
                    data: params.join ("&"),
                    type: 'POST',
                    url: action=
                    <?php
                    echo '"'.ui_get_full_url(
                        false,
                        false,
                        false,
                        false
                    ).'"';
                    ?>
                    + "/ajax.php",
                    async: false,
                    timeout: 10000,
                    success: function (data) {
                        nameAgent = data;
                    }
                });

                //Truncate nameModule
                var params = [];
                params.push("truncate_text=1");
                params.push("text=" + nameModule);
                params.push("page=include/ajax/reporting.ajax");
                jQuery.ajax ({
                    data: params.join ("&"),
                    type: 'POST',
                    url: action=
                    <?php
                    echo '"'.ui_get_full_url(
                        false,
                        false,
                        false,
                        false
                    ).'"';
                    ?>
                    + "/ajax.php",
                    async: false,
                    timeout: 10000,
                    success: function (data) {
                        nameModule = data;
                    }
                });
            }

            if (nameAgentFailover != '') {
                //Truncate nameAgentFailover
                var params = [];
                params.push("truncate_text=1");
                params.push("text=" + nameAgentFailover);
                params.push("page=include/ajax/reporting.ajax");
                jQuery.ajax ({
                    data: params.join ("&"),
                    type: 'POST',
                    url: action=
                    <?php
                    echo '"'.ui_get_full_url(
                        false,
                        false,
                        false,
                        false
                    ).'"';
                    ?>
                    + "/ajax.php",
                    async: false,
                    timeout: 10000,
                    success: function (data) {
                        nameAgentFailover = data;
                    }
                });

                //Truncate nameModuleFailover
                var params = [];
                params.push("truncate_text=1");
                params.push("text=" + nameModuleFailover);
                params.push("page=include/ajax/reporting.ajax");
                jQuery.ajax ({
                    data: params.join ("&"),
                    type: 'POST',
                    url: action=
                    <?php
                    echo '"'.ui_get_full_url(
                        false,
                        false,
                        false,
                        false
                    ).'"';
                    ?>
                    + "/ajax.php",
                    async: false,
                    timeout: 10000,
                    success: function (data) {
                        nameModuleFailover = data;
                    }
                });
            }

            var params = [];
            params.push("add_sla=1");
            params.push("id=" + $("input[name=id_item]").val());
            params.push("id_module=" + idModule);
            params.push("id_module_failover=" + idModuleFailover);
            params.push("sla_min=" + slaMin);
            params.push("sla_max=" + slaMax);
            params.push("sla_limit=" + slaLimit);
            params.push("server_id=" + serverId);

            if (serviceId != '') {
                params.push("id_service=" + serviceId);
            }

            params.push("page=include/ajax/reporting.ajax");
            jQuery.ajax ({
                data: params.join ("&"),
                type: 'POST',
                url: action=
                <?php
                echo '"'.ui_get_full_url(
                    false,
                    false,
                    false,
                    false
                ).'"';
                ?>
                + "/ajax.php",
                timeout: 10000,
                dataType: 'json',
                success: function (data) {
                    if (data['correct']) {
                        row = $("#sla_template").clone();
                        $("#row", row).show();
                        $("#row", row).attr('id', 'sla_' + data['id']);
                        $(".agent_name", row).html(nameAgent);
                        $(".module_name", row).html(nameModule);
                        $(".agent_name_failover", row).html(nameAgentFailover);
                        $(".module_name_failover", row).html(nameModuleFailover);
                        $(".service_name", row).html(serviceName);
                        $(".sla_min", row).html(slaMin);
                        $(".sla_max", row).html(slaMax);
                        $(".sla_limit", row).html(slaLimit);
                        $(".delete_button", row).attr(
                            'href',
                            'javascript: deleteSLARow(' + data['id'] + ');'
                        );
                        $("#list_sla").append($(row).html());
                        $("input[name=id_agent_sla]").val('');
                        $("input[name=id_agent_failover]").val('');
                        $("input[name=id_server]").val('');
                        $("input[name=agent_sla]").val('');
                        $("input[name=agent_failover]").val('');
                        $("#id_agent_module_sla").empty();
                        $("#id_agent_module_sla").attr('disabled', 'true');
                        $("#id_agent_module_sla").append(
                            $("<option></option>")
                            .attr ("value", 0)
                            .html ($("#module_sla_text").html()));
                        $("#id_agent_module_failover").empty();
                        $("#id_agent_module_failover").attr('disabled', 'true');
                        $("#id_agent_module_failover").append(
                            $("<option></option>")
                            .attr ("value", 0)
                            .html ($("#module_sla_text").html()));
                        $("input[name=sla_min]").val('');
                        $("input[name=sla_max]").val('');
                        $("input[name=sla_limit]").val('');
                    }
                }
            });
    }
    else {
        alert("<?php echo __('Could not be created'); ?>");
    }
}

function addGeneralRow() {
    var nameAgent = $("input[name=agent_general]").val();
    var idAgent = $("input[name=id_agent_general]").val();
    var serverId = $("input[name=id_server]").val();
    var idModule = $("#id_agent_module_general").val();
    var nameAgentFailover = $("input[name=agent_failover]").val();
    var idModuleFailover = $("#id_agent_module_failover").val();
    var nameModuleFailover = $("#id_agent_module_failover :selected").text();

    var operation;
    if ($("#id_operation_module_general").length) {
        operation = $("#id_operation_module_general").val();
    }
    else {
        operation = "";
    }
    var nameModule = $("#id_agent_module_general :selected").text();
    var nameOperation = $("#id_operation_module_general :selected").text();

    if (idAgent != '') {
        //Truncate nameAgent
        var params = [];
        params.push("truncate_text=1");
        params.push("text=" + nameAgent);
        params.push("page=include/ajax/reporting.ajax");
        jQuery.ajax ({
            data: params.join ("&"),
            type: 'POST',
            url: action=
            <?php
            echo '"'.ui_get_full_url(
                false,
                false,
                false,
                false
            ).'"';
            ?>
            + "/ajax.php",
            async: false,
            timeout: 10000,
            success: function (data) {
                nameAgent = data;
            }
        });
        //Truncate nameModule
        var params = [];
        params.push("truncate_text=1");
        params.push("text=" + nameModule);
        params.push("page=include/ajax/reporting.ajax");
        jQuery.ajax ({
            data: params.join ("&"),
            type: 'POST',
            url: action=
            <?php
            echo '"'.ui_get_full_url(
                false,
                false,
                false,
                false
            ).'"';
            ?>
            + "/ajax.php",
            async: false,
            timeout: 10000,
            success: function (data) {
                nameModule = data;
            }
        });

        //Truncate nameOperation
        var params = [];
        params.push("truncate_text=1");
        params.push("text=" + nameOperation);
        params.push("page=include/ajax/reporting.ajax");
        jQuery.ajax ({
            data: params.join ("&"),
            type: 'POST',
            url: action=
            <?php
            echo '"'.ui_get_full_url(
                false,
                false,
                false,
                false
            ).'"';
            ?>
            + "/ajax.php",
            async: false,
            timeout: 10000,
            success: function (data) {
                nameOperation = data;
            }
        });

        if (nameAgentFailover != '') {
            //Truncate nameAgentFailover
            var params = [];
            params.push("truncate_text=1");
            params.push("text=" + nameAgentFailover);
            params.push("page=include/ajax/reporting.ajax");
            jQuery.ajax ({
                data: params.join ("&"),
                type: 'POST',
                url: action=
                <?php
                echo '"'.ui_get_full_url(
                    false,
                    false,
                    false,
                    false
                ).'"';
                ?>
                + "/ajax.php",
                async: false,
                timeout: 10000,
                success: function (data) {
                    nameAgentFailover = data;
                }
            });

            //Truncate nameModuleFailover
            var params = [];
            params.push("truncate_text=1");
            params.push("text=" + nameModuleFailover);
            params.push("page=include/ajax/reporting.ajax");
            jQuery.ajax ({
                data: params.join ("&"),
                type: 'POST',
                url: action=
                <?php
                echo '"'.ui_get_full_url(
                    false,
                    false,
                    false,
                    false
                ).'"';
                ?>
                + "/ajax.php",
                async: false,
                timeout: 10000,
                success: function (data) {
                    nameModuleFailover = data;
                }
            });
        }

        var params = [];
        params.push("add_general=1");
        params.push("id=" + $("input[name=id_item]").val());
        params.push("id_module=" + idModule);
        params.push("id_module_failover=" + idModuleFailover);
        params.push("id_server=" + serverId);
        params.push("operation=" + operation);
        params.push("id_agent=" + idAgent);
        params.push("page=include/ajax/reporting.ajax");
        jQuery.ajax ({
            data: params.join ("&"),
            type: 'POST',
            url: action=
            <?php
            echo '"'.ui_get_full_url(
                false,
                false,
                false,
                false
            ).'"';
            ?>
            + "/ajax.php",
            timeout: 10000,
            dataType: 'json',
            success: function (data) {
                if (data['correct']) {
                    row = $("#general_template").clone();
                    $("#row", row).show();
                    $("#row", row).attr('id', 'general_' + data['id']);
                    $(".agent_name", row).html(nameAgent);
                    $(".module_name", row).html(nameModule);
                    $(".agent_name_failover", row).html(nameAgentFailover);
                    $(".module_name_failover", row).html(nameModuleFailover);
                    $(".operation_name", row).html(nameOperation);
                    $(".delete_button", row).attr(
                        'href',
                        'javascript: deleteGeneralRow(' + data['id'] + ');'
                    );

                    $("#list_general").append($(row).html());

                    $("input[name=id_agent_general]").val('');
                    $("input[name=id_server]").val('');
                    $("input[name=agent_general]").val('');
                    $("#id_operation_module_general").val('avg');
                    $("#id_agent_module_general").empty();
                    $("#id_agent_module_general").attr('disabled', 'true');

                    $("input[name=id_agent_failover]").val('');
                    $("input[name=agent_failover]").val('');
                    $("#id_agent_module_failover").empty();
                    $("#id_agent_module_failover").attr('disabled', 'true');
                    $("#id_agent_module_failover").append(
                        $("<option></option>")
                        .attr ("value", 0)
                        .html ($("#module_sla_text").html()));
                }
            }
        });
    }
}

function chooseType() {
    type = $("#type").val();
    $("#row_description").hide();
    $("#row_label").hide();
    $("#row_period").hide();
    $("#row_agent").hide();
    $("#row_module").hide();
    $("#row_period").hide();
    $("#row_search").hide();
    $("#row_log_number").hide();
    $("#row_period1").hide();
    $("#row_estimate").hide();
    $("#row_interval").hide();
    $("#row_custom_graph").hide();
    $("#row_text").hide();
    $("#row_query").hide();
    $("#row_max_items").hide();
    $("#row_header").hide();
    $("#row_custom").hide();
    $("#row_url").hide();
    $("#row_field_separator").hide();
    $("#row_line_separator").hide();
    $("#row_custom_example").hide();
    $("#row_group").hide();
    $("#row_current_month").hide();
    $("#row_failover_mode").hide();
    $("#row_failover_type").hide();
    $("#row_summary").hide();
    $("#row_working_time").hide();
    $("#row_working_time_compare").hide();
    $("#row_only_display_wrong").hide();
    $("#row_combo_module").hide();
    $("#row_group_by_agent").hide();
    $("#general_list").hide();
    $("#row_order_uptodown").hide();
    $("#row_show_resume").hide();
    $("#row_show_address_agent").hide();
    $("#row_show_graph").hide();
    $("#row_max_min_avg").hide();
    $("#row_fullscale").hide();
    $("#row_graph_render").hide();
    $("#row_time_compare_overlapped").hide();
    $("#row_quantity").hide();
    $("#row_exception_condition_value").hide();
    $("#row_exception_condition").hide();
    $("#row_dyn_height").hide();
    $("#row_show_in_same_row").hide();
    $("#row_historical_db_check").hide();
    $("#row_lapse_calc").hide();
    $("#row_lapse").hide();
    $("#row_visual_format").hide();
    $('#row_hide_notinit_agents').hide();
    $('#row_priority_mode').hide();
    $("#row_module_group").hide();
    $("#row_servers").hide();
    $("#row_sort").hide();
    $("#row_date").hide();
    $("#row_agent_multi").hide();
    $("#row_module_multi").hide();
    $("#row_event_graphs").hide();
    $("#row_event_graph_by_agent").hide();
    $("#row_event_graph_by_user").hide();
    $("#row_event_graph_by_criticity").hide();
    $("#row_event_graph_by_validated").hide();
    $("#row_extended_events").hide();
    $("#row_netflow_filter").hide();
    $("#row_max_values").hide();
    $("#row_resolution").hide();
    $("#row_last_value").hide();
    $("#row_filter_search").hide();
    $("#row_filter_exclude").hide();
    $("#row_percentil").hide();
    $("#log_help_tip").css("visibility", "hidden");
    $("#agents_row").hide();
    $("#agents_modules_row").hide();
    $("#select_agent_modules").hide();
    $("#modules_row").hide();
    $("#row_show_summary_group").hide();
    $("#row_event_severity").hide();
    $("#row_event_type").hide();
    $("#row_event_status").hide();
    $("#row_source").hide();
    $('#row_select_fields').hide();
    $("#row_select_fields2").hide();
    $("#row_select_fields3").hide();
    $("#row_uncompressed_module").hide();
    $("#row_users").hide();
    $("#row_profiles_group").hide();
    $("#row_select_by_group").hide();


    // SLA list default state.
    $("#sla_list").hide();
    $(".sla_list_agent_col").show();
    $(".sla_list_module_col").show();
    $(".sla_list_service_col").hide();
    $(".sla_list_sla_min_col").show();
    $(".sla_list_sla_max_col").show();
    $(".sla_list_sla_limit_col").show();
    $(".sla_list_action_col").show();

    $('#agent_autocomplete_events').show();

    switch (type) {
        case 'event_report_group':
            $("#row_description").show();
            $("#row_period").show();
            $("#row_servers").show();
            $("#row_group").show();
            $("#row_event_filter").show();
            $("#row_event_graphs").show();



            $("#row_event_graph_by_agent").show();
            $("#row_event_graph_by_user").show();
            $("#row_event_graph_by_criticity").show();
            $("#row_event_graph_by_validated").show();
            $("#row_extended_events").show();

            $("#row_filter_search").show();
            $("#row_filter_exclude").show();


            $("#row_event_severity").show();
            $("#row_event_status").show();
            $("#row_event_type").show();
            
            $("#row_historical_db_check").hide();
            break;

        case 'event_report_log':
            $("#log_help_tip").css("visibility", "visible");
            $("#row_description").show();
            $("#row_period").show();
            $("#row_search").show();
            $("#row_log_number").show();
            $("#agents_row").show();
            $("#row_source").show();
            $("#row_historical_db_check").hide();
            break;

        case 'increment':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_module").show();
            $("#row_period").show();
            break;

        case 'simple_graph':
            $("#row_time_compare_overlapped").show();
            $("#row_fullscale").show();
            $("#row_graph_render").show();
            $("#row_percentil").show();

            // Force type.
            if('<?php echo $action; ?>' === 'new'){
                $("#graph_render").val(<?php echo $graph_render; ?>);
            }

            // The break hasn't be forgotten, this element
            // only should be shown on the simple graphs.
        case 'simple_baseline_graph':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_module").show();
            $("#row_period").show();
            $("#row_historical_db_check").hide();
            break;

        case 'projection_graph':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_module").show();
            $("#row_period1").show();
            $("#row_estimate").show();
            $("#row_historical_db_check").hide();
            break;

        case 'prediction_date':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_period1").show();
            $("#row_module").show();
            $("#row_interval").show();
            $("#row_historical_db_check").hide();
            break;

        case 'custom_graph':
        case 'automatic_custom_graph':
            $("#row_description").show();
            $("#row_period").show();
            $("#row_custom_graph").show();
            $("#row_historical_db_check").hide();
            break;

        case 'SLA':
            $("#row_description").show();
            $("#row_period").show();
            $("#sla_list").show();
            $("#row_working_time").show();
            $("#row_working_time_compare").show();
            $("#row_only_display_wrong").show();
            $("#row_show_graph").show();
            $("#row_sort").show();
            $('#row_hide_notinit_agents').show();
            $("#row_historical_db_check").hide();
            break;

        case 'availability_graph':
            $("#row_description").show();
            $("#row_period").show();
            $("#sla_list").show();
            $("#row_working_time").show();
            $("#row_working_time_compare").show();
            $("#row_historical_db_check").hide();
            $("#row_priority_mode").show();
            $("#row_failover_mode").show();
            var failover_checked = $("input[name='failover_mode']").prop("checked");
            if(failover_checked){
                $("#row_failover_type").show();
            }
            $("#row_summary").show();
            break;

        case 'histogram_data':
        case 'module_histogram_graph':
            $("#row_description").show();
            $("#row_period").show();
            $("#row_agent").show();
            $("#row_module").show();
            $("#row_historical_db_check").hide();
            break;

        case 'SLA_monthly':
        case 'SLA_weekly':
        case 'SLA_hourly':
            $("#row_description").show();
            $("#sla_list").show();
            $("#row_current_month").show();
            $("#row_working_time").show();
            $("#row_sort").show();
            $("#row_priority_mode").show();
            $("#row_historical_db_check").hide();
            break;

        case 'SLA_services':
            $("#row_description").show();
            $("#row_period").show();
            $("#row_only_display_wrong").show();
            $("#row_working_time").show();
            $("#row_sort").show();

            $(".sla_list_agent_col").hide();
            $(".sla_list_module_col").hide();
            $(".sla_list_service_col").show();
            $(".sla_list_sla_min_col").hide();
            $(".sla_list_sla_max_col").hide();
            $(".sla_list_sla_limit_col").hide();
            $("#sla_list").show();
            $("#row_historical_db_check").hide();
            break;

        case 'monitor_report':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_module").show();
            $("#row_period").show();
            $("#row_historical_db_check").hide();
            break;

        case 'avg_value':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_module").show();
            $("#row_period").show();
            $("#row_lapse_calc").show();
            $("#row_lapse").show();
            $("#row_visual_format").show();
            $("#row_historical_db_check").hide();
            break;

        case 'max_value':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_module").show();
            $("#row_period").show();
            $("#row_lapse_calc").show();
            $("#row_lapse").show();
            $("#row_visual_format").show();
            $("#row_historical_db_check").hide();
            break;

        case 'min_value':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_module").show();
            $("#row_period").show();
            $("#row_lapse_calc").show();
            $("#row_lapse").show();
            $("#row_visual_format").show();
            $("#row_historical_db_check").hide();
            break;

        case 'sumatory':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_module").show();
            $("#row_period").show();
            $("#row_historical_db_check").hide();
            $("#row_uncompressed_module").show();
            break;

        case 'historical_data':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_module").show();
            $("#row_period").show();
            $("#row_historical_db_check").hide();
            break;

        case 'agent_detailed':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_period").show();
            $("#row_historical_db_check").hide();
            break;

        case 'text':
            $("#row_description").show();
            $("#row_text").show();
            $("#row_historical_db_check").hide();
            break;

        case 'sql':
            $("#row_description").show();
            $("#row_query").show();
            $("#row_header").show();
            $("#row_custom").show();
            $("#row_custom_example").show();
            $("#row_servers").show();
            $("#row_historical_db_check").show();
            break;

        case 'sql_graph_pie':
        case 'sql_graph_hbar':
        case 'sql_graph_vbar':
            $("#row_description").show();
            $("#row_query").show();
            $("#row_max_items").show();
            $("#row_dyn_height").show();
            $("#row_servers").show();
            $("#row_historical_db_check").show();
            break;

        case 'url':
            $("#row_description").show();
            $("#row_url").show();
            $("#row_historical_db_check").hide();
            break;

        case 'database_serialized':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_module").show();
            $("#row_header").show();
            $("#row_field_separator").show();
            $("#row_line_separator").show();
            $("#row_period").show();
            $("#row_historical_db_check").hide();
            break;

        case 'last_value':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_module").show();
            break;

        case 'alert_report_module':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_module").show();
            $("#row_period").show();
            $("#row_historical_db_check").hide();
            break;

        case 'alert_report_group':
            $("#row_description").show();
            $("#row_period").show();
            $("#row_group").show();
            $("#row_servers").show();
            $("#row_historical_db_check").hide();
            break;

        case 'alert_report_agent':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_period").show();
            $("#row_historical_db_check").hide();
            break;

        case 'event_report_group':
            $("#row_description").show();
            $("#row_period").show();
            $("#row_servers").show();
            $("#row_group").show();
            $("#row_event_severity").show();
            $("#row_event_status").show();
            $("#row_show_summary_group").show();

            $("#row_event_graph_by_agent").show();
            $("#row_event_graph_by_user").show();
            $("#row_event_graph_by_criticity").show();
            $("#row_event_graph_by_validated").show();
            $("#row_event_type").show();
            $("#row_extended_events").show();

            $("#row_filter_search").show();
            $("#row_filter_exclude").show();

            $("#row_historical_db_check").hide();
            break;


        case 'event_report_agent':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_period").show();
            $("#row_event_severity").show();
            $("#row_event_status").show();
            $("#row_show_summary_group").show();
            $("#row_event_graphs").show();
            $("#row_event_type").show();
            $("#row_extended_events").show();
            $("#row_extended_events").show();

            $("#row_event_graph_by_user").show();
            $("#row_event_graph_by_criticity").show();
            $("#row_event_graph_by_validated").show();

            $('#agent_autocomplete').hide();
            $('#agent_autocomplete_events').show();
            $("#row_filter_search").show();
            $("#row_filter_exclude").show();

            $("#row_historical_db_check").hide();
            break;

        case 'event_report_module':
            $("#row_description").show();
            $("#row_agent").show();
            $("#row_module").show();
            $("#row_period").show();
            $("#row_event_severity").show();
            $("#row_event_status").show();
            $("#row_show_summary_group").show();
            $("#row_event_graphs").show();
            $("#row_event_type").show();
            $("#row_extended_events").show();

            $("#row_event_graph_by_user").show();
            $("#row_event_graph_by_criticity").show();
            $("#row_event_graph_by_validated").show();

            $('#agent_autocomplete').hide();
            $('#agent_autocomplete_events').show();
            $("#row_filter_search").show();
            $("#row_filter_exclude").show();

            $("#row_historical_db_check").hide();
            break;

        case 'general':
            $("#row_description").show();
            $("#row_group_by_agent").show();
            $("#row_period").show();
            $("#general_list").show();
            $("#row_order_uptodown").show();
            $("#row_show_resume").show();
            $("#row_show_in_same_row").show();

            var checked = $("input[name='last_value']").prop("checked");

            $("#row_last_value").show();
            if (checked) {
                $("#row_period").hide();
                $("input[name='last_value']").prop("checked", true);
            }
            $("#row_historical_db_check").hide();
            break;

        case 'availability':
            $("#row_description").show();
            $("#row_period").show();
            $("#general_list").show();
            $("#row_order_uptodown").show();
            $("#row_show_address_agent").show();
            $("#row_show_resume").show();
            $("#row_working_time").show();
            $("#row_working_time_compare").show();
            $('#row_hide_notinit_agents').show();
            $('#row_select_fields').show();
             if($("#checkbox-checkbox_show_resume").is(":checked")){
                $("#row_select_fields2").show();
                 $("#row_select_fields3").show();
             }
             else{
                $("#row_select_fields2").hide();
                 $("#row_select_fields3").hide();
             }
            $("#row_historical_db_check").hide();

            $("#row_failover_mode").show();
            var failover_checked = $("input[name='failover_mode']").prop("checked");
            if(failover_checked){
                $("#row_failover_type").show();
            }
            break;

        case 'group_report':
            $("#row_group").show();
            $("#row_servers").show();
            $("#row_description").show();
            $("#row_historical_db_check").hide();
            break;

        case 'network_interfaces_report':
            $("#row_group").show();
            $("#row_description").show();
            $("#row_period").show();
            $("#row_historical_db_check").hide();
            $("#row_graph_render").show();
            $("#row_fullscale").show();
            // Force MAX type.
            if('<?php echo $action; ?>' === 'new'){
                $("#graph_render").val(2);
            }
            break;

        case 'top_n':
            $("#row_description").show();
            $("#row_period").show();
            $("#row_max_min_avg").show();
            $("#row_quantity").show();
            $("#general_list").show();
            $("#row_order_uptodown").show();
            $("#row_show_resume").show();
            $("#row_show_graph").show();
            $("#row_historical_db_check").hide();
            break;

        case 'exception':
            $("#row_description").show();
            $("#row_period").show();
            $("#general_list").show();
            $("#row_exception_condition_value").show();
            $("#row_exception_condition").show();
            $("#row_order_uptodown").show();
            $("#row_show_resume").show();
            $("#row_show_graph").show();

            var checked = $("input[name='last_value']").prop("checked");

            $("#row_last_value").show();
            if (checked) {
                $("#row_period").hide();
                $("input[name='last_value']").prop("checked", true);
            }
            $("#row_historical_db_check").hide();
            break;

        case 'agent_module':
            $("#row_description").show();
            $("#row_group").show();
            $("#row_module_group").show();
            $("#select_agent_modules").show();
            $("#agents_modules_row").show();
            $("#modules_row").show();
            $("#row_historical_db_check").hide();
            break;

        case 'inventory_changes':
            $("#row_description").show();
            $("#row_period").show();
            $("#row_group").show();
            $("#row_agent_multi").show();
            $("#row_module_multi").show();
            $("#row_servers").show();
            $("#id_agents").change(event_change_id_agent_inventory);
            $("#id_agents").trigger('change');

            $("#combo_server").change(function() {
                $('#hidden-date_selected').val('');
                updateInventoryDates(
                <?php
                echo '"'.ui_get_full_url(
                    false,
                    false,
                    false,
                    false
                ).'"';
                ?>
                );
                updateAgents($(this).val(),
                <?php
                echo '"'.ui_get_full_url(
                    false,
                    false,
                    false,
                    false
                ).'"';
                ?>
                );
            });
            $("#combo_group").change(function() {
                updateAgents($(this).val(),
                <?php
                echo '"'.ui_get_full_url(
                    false,
                    false,
                    false,
                    false
                ).'"';
                ?>
                );
            });
            $("#row_historical_db_check").hide();

            break;

        case 'inventory':
            $("#row_description").show();
            $("#row_group").show();
            $("#row_agent_multi").show();
            $("#row_module_multi").show();
            $("#row_date").show();

            $("#id_agents")
                .change(event_change_id_agent_inventory);
            $("#id_agents").trigger('change');

            $("#row_servers").show();

            $("#combo_server").change(function() {
                $('#hidden-date_selected').val('');
                updateInventoryDates(
                <?php
                echo '"'.ui_get_full_url(
                    false,
                    false,
                    false,
                    false
                ).'"';
                ?>
                );
                updateAgents($(this).val(),
                <?php
                echo '"'.ui_get_full_url(
                    false,
                    false,
                    false,
                    false
                ).'"';
                ?>
                );
            });

            $("#combo_group").change(function() {
                $('#hidden-date_selected').val('');
                updateInventoryDates(
                <?php
                echo '"'.ui_get_full_url(
                    false,
                    false,
                    false,
                    false
                ).'"';
                ?>
                );
                updateAgents($(this).val(),
                <?php
                echo '"'.ui_get_full_url(
                    false,
                    false,
                    false,
                    false
                ).'"';
                ?>
                );
            });
            $("#id_agents").change(function() {
                $('#hidden-date_selected').val('');
                updateInventoryDates(
                <?php
                echo '"'.ui_get_full_url(
                    false,
                    false,
                    false,
                    false
                ).'"';
                ?>
                );
            });
            $("#inventory_modules").change(function() {
                $('#hidden-date_selected').val('');
                updateInventoryDates(
                <?php
                echo '"'.ui_get_full_url(
                    false,
                    false,
                    false,
                    false
                ).'"';
                ?>
                );
            });

            if (!$("#hidden-date_selected").val())
                updateInventoryDates(
                <?php
                echo '"'.ui_get_full_url(
                    false,
                    false,
                    false,
                    false
                ).'"';
                ?>
                );
                $("#row_historical_db_check").hide();
            break;

        case 'inventory_changes':
        $("#row_historical_db_check").hide();
            break;

        case 'agent_configuration':
            $("#row_agent").show();
            $("#row_historical_db_check").hide();
            break;

        case 'group_configuration':
            $("#row_group").show();
            $("#row_servers").show();
            $("#row_historical_db_check").hide();
            $("#combo_server option[value='0']").remove();
            break;

        case 'netflow_area':
            $("#row_netflow_filter").show();
            $("#row_description").show();
            $("#row_period").show();
            $("#row_max_values").show();
            $("#row_resolution").show();
            $("#row_servers").show();
            $("#row_historical_db_check").hide();
            break;

        case 'netflow_data':
            $("#row_netflow_filter").show();
            $("#row_description").show();
            $("#row_period").show();
            $("#row_max_values").show();
            $("#row_resolution").show();
            $("#row_servers").show();
            $("#row_historical_db_check").hide();
            break;

        case 'netflow_summary':
            $("#row_netflow_filter").show();
            $("#row_description").show();
            $("#row_period").show();
            $("#row_max_values").show();
            $("#row_resolution").show();
            $("#row_servers").show();
            $("#row_historical_db_check").hide();
            break;

        case 'permissions_report':
            $("#row_description").show();
            $("#row_users").show();
            $("#row_profiles_group").show();
            $("#row_select_by_group").show();

            if($("#checkbox-select_by_group").prop("checked")) {
                $("#row_users").hide();
            } else {
                $("#row_profiles_group").hide(); 
            }
            
    }

    switch (type) {
        case 'event_report_agent':
        case 'simple_graph':
        case 'event_report_module':
        case 'alert_report_agent':
        case 'alert_report_module':
        case 'historical_data':
        case 'sumatory':
        case 'database_serialized':
        case 'monitor_report':
        case 'min_value':
        case 'max_value':
        case 'avg_value':
        case 'simple_baseline_graph':
            $("#row_label").show();
            break;
        default:
            break;
    }
}

function event_change_id_agent_inventory() {
    agent_changed_by_multiple_agents_inventory(
        {"data" : {
            "homedir" :
            <?php
            echo '"'.ui_get_full_url(
                false,
                false,
                false,
                false
            ).'"';
            ?>
            }
        },
        null,
        null,
        $("#combo_server").val());
}

function set_last_value_period() {
    var checked = $("input[name='last_value']").prop("checked");

    if (checked) {
        $("#row_period").hide();
        period_set_value($("#hidden-period").attr('class'), 0);
        alert("<?php echo __('Warning: period 0 reports cannot be used to show information back in time. Information contained in this kind of reports will be always reporting the most recent information'); ?>");
    }
    else {
        $("#row_period").show();
    }
}

function source_change_agents() {
    $("#id_agents3").empty();
    $("#spinner_hack").show();
    jQuery.post ("ajax.php",
        {"page" : "operation/agentes/ver_agente",
            "get_agents_source_json" : 1,
            "source" : $("#source").val()
        },
        function (data, status) {
            for (var clave in data) {
                $("#id_agents3").append(
                    '<option value="'+clave+'">'+data[clave]+'</option>'
                );
            }
            $("#spinner_hack").hide();
        },
        "json"
    );
}

function dialog_message(message_id) {
  $(message_id)
    .css("display", "inline")
    .dialog({
      modal: true,
      show: "blind",
      hide: "blind",
      width: "400px",
      buttons: {
        Close: function() {
          $(this).dialog("close");
        }
      }
    });
}

</script>
