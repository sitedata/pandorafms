<?php
/**
 * SNMP Console.
 *
 * @category   SNMP
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
enterprise_include('operation/snmpconsole/snmp_view.php');
enterprise_include('include/functions_snmp.php');
require_once 'include/functions_agents.php';
require_once 'include/functions_snmp.php';

ui_require_css_file('snmp_view');

check_login();
$agent_a = check_acl($config['id_user'], 0, 'AR');
$agent_w = check_acl($config['id_user'], 0, 'AW');
$access = ($agent_a == true) ? 'AR' : (($agent_w == true) ? 'AW' : 'AR');
if (!$agent_a && !$agent_w) {
    db_pandora_audit(
        'ACL Violation',
        'Trying to access SNMP Console'
    );
    include 'general/noaccess.php';
    exit;
}

// Read parameters.
$filter_severity = (int) get_parameter('filter_severity', -1);
$filter_fired = (int) get_parameter('filter_fired', -1);
$filter_status = (int) get_parameter('filter_status', 0);
$free_search_string = (string) get_parameter('free_search_string', '');
$pagination = (int) get_parameter('pagination', $config['block_size']);
$offset = (int) get_parameter('offset', 0);
$pure = (int) get_parameter('pure', 0);
$trap_type = (int) get_parameter('trap_type', -1);
$group_by = (int) get_parameter('group_by', 0);
$refr = (int) get_parameter('refresh');
$default_refr = !empty($refr) ? $refr : $config['vc_refr'];
$hours_ago = get_parameter('hours_ago', 8);

// Build ranges.
$now = new DateTime();
$ago = new DateTime();
$interval = new DateInterval(sprintf('PT%dH', $hours_ago));
$ago->sub($interval);

$date_from_trap = $ago->format('Y/m/d');
$date_to_trap = $now->format('Y/m/d');
$time_from_trap = $ago->format('H:i:s');
$time_to_trap = $now->format('H:i:s');

$user_groups = users_get_groups($config['id_user'], $access, false);

$str_user_groups = '';
$i = 0;
foreach ($user_groups as $id => $name) {
    if ($i == 0) {
        $str_user_groups .= $id;
    } else {
        $str_user_groups .= ','.$id;
    }

    $i++;
}

$url = 'index.php?sec=estado&sec2=operation/snmpconsole/snmp_view';
$url .= '&filter_severity='.$filter_severity.'&filter_fired='.$filter_fired;
$url .= '&free_search_string='.$free_search_string.'&pagination='.$pagination;
$url .= '&offset='.$offset.'&trap_type='.$trap_type.'&group_by='.$group_by;
$url .= '&hours_ago='.$hours_ago.'&pure='.$pure;

$statistics['text'] = '<a href="index.php?sec=estado&sec2=operation/snmpconsole/snmp_statistics&pure='.$config['pure'].'&refr='.$refr.'">'.html_print_image(
    'images/op_reporting.png',
    true,
    [
        'title' => __('Statistics'),
        'class' => 'invert_filter',
    ]
).'</a>';
$list['text'] = '<a href="'.$url.'&pure='.$config['pure'].'&refresh='.$refr.'">'.html_print_image(
    'images/op_snmp.png',
    true,
    [
        'title' => __('List'),
        'class' => 'invert_filter',
    ]
).'</a>';
$list['active'] = true;

if ($config['pure']) {
    $fullscreen['text'] = '<a target="_top" href="'.$url.'&pure=0&refresh='.$refr.'">'.html_print_image(
        'images/normal_screen.png',
        true,
        [
            'title' => __('Normal screen'),
            'class' => 'invert_filter',
        ]
    ).'</a>';
} else {
    // Fullscreen.
    $fullscreen['text'] = '<a target="_top" href="'.$url.'&pure=1&refresh='.$refr.'">'.html_print_image(
        'images/full_screen.png',
        true,
        [
            'title' => __('Full screen'),
            'class' => 'invert_filter',
        ]
    ).'</a>';
}


// OPERATIONS
// Delete SNMP Trap entry Event (only incident management access).
if (isset($_GET['delete'])) {
    $id_trap = (int) get_parameter_get('delete', 0);
    if ($id_trap > 0 && check_acl($config['id_user'], 0, 'IM')) {
        if ($group_by) {
            $sql_ids_traps = 'SELECT id_trap, source FROM ttrap WHERE oid IN (SELECT oid FROM ttrap WHERE id_trap = '.$id_trap.')
			AND source IN (SELECT source FROM ttrap WHERE id_trap = '.$id_trap.')';
            $ids_traps = db_get_all_rows_sql($sql_ids_traps);

            foreach ($ids_traps as $key => $value) {
                $result = db_process_sql_delete('ttrap', ['id_trap' => $value['id_trap']]);
                enterprise_hook('snmp_update_forwarded_modules', [$value]);
            }
        } else {
            $forward_info = db_get_row('ttrap', 'id_trap', $id_trap);
            $result = db_process_sql_delete('ttrap', ['id_trap' => $id_trap]);
            enterprise_hook('snmp_update_forwarded_modules', [$forward_info]);
            ui_print_result_message(
                $result,
                __('Successfully deleted'),
                __('Could not be deleted')
            );
        }
    } else {
        db_pandora_audit(
            'ACL Violation',
            'Trying to delete SNMP event ID #'.$id_trap
        );
    }
}

// Check Event (only incident write access).
if (isset($_GET['check'])) {
    $id_trap = (int) get_parameter_get('check', 0);
    if (check_acl($config['id_user'], 0, 'IW')) {
        $values = [
            'status'     => 1,
            'id_usuario' => $config['id_user'],
        ];
        $result = db_process_sql_update('ttrap', $values, ['id_trap' => $id_trap]);
        enterprise_hook('snmp_update_forwarded_modules', [$id_trap]);

        ui_print_result_message(
            $result,
            __('Successfully updated'),
            __('Could not be updated')
        );
    } else {
        db_pandora_audit(
            'ACL Violation',
            'Trying to checkout SNMP Trap ID'.$id_trap
        );
    }
}

// Mass-process DELETE.
if (isset($_POST['deletebt'])) {
    $trap_ids = get_parameter_post('snmptrapid', []);
    if (is_array($trap_ids) && check_acl($config['id_user'], 0, 'IW')) {
        if ($group_by) {
            foreach ($trap_ids as $key => $value) {
                $sql_ids_traps = 'SELECT id_trap, source FROM ttrap WHERE oid IN (SELECT oid FROM ttrap WHERE id_trap = '.$value.')
				AND source IN (SELECT source FROM ttrap WHERE id_trap = '.$value.')';
                $ids_traps = db_get_all_rows_sql($sql_ids_traps);

                foreach ($ids_traps as $key2 => $value2) {
                    $result = db_process_sql_delete('ttrap', ['id_trap' => $value2['id_trap']]);
                    enterprise_hook('snmp_update_forwarded_modules', [$value2]);
                }
            }
        } else {
            foreach ($trap_ids as $id_trap) {
                $forward_info = db_get_row('ttrap', 'id_trap', $id_trap);
                db_process_sql_delete('ttrap', ['id_trap' => $id_trap]);
                enterprise_hook('snmp_update_forwarded_modules', [$forward_info]);
            }
        }
    } else {
        db_pandora_audit(
            'ACL Violation',
            'Trying to mass-delete SNMP Trap ID'
        );
    }
}

// Mass-process UPDATE.
if (isset($_POST['updatebt'])) {
    $trap_ids = get_parameter_post('snmptrapid', []);
    if (is_array($trap_ids) && check_acl($config['id_user'], 0, 'IW')) {
        foreach ($trap_ids as $id_trap) {
            $sql = sprintf("UPDATE ttrap SET status = 1, id_usuario = '%s' WHERE id_trap = %d", $config['id_user'], $id_trap);
            db_process_sql($sql);
            enterprise_hook('snmp_update_forwarded_modules', [$id_trap]);
        }
    } else {
        db_pandora_audit(
            'ACL Violation',
            'Trying to mass-delete SNMP Trap ID'
        );
    }
}

// All traps.
$all_traps = db_get_all_rows_sql('SELECT DISTINCT source FROM ttrap');

if (empty($all_traps)) {
    $all_traps = [];
}

// Set filters.
$agents = [];
$oids = [];
$severities = get_priorities();
$alerted = [
    __('Not fired'),
    __('Fired'),
];
foreach ($all_traps as $trap) {
    $agent = agents_get_agent_with_ip($trap['source']);
    $agents[$trap['source']] = $agent !== false ? ($agent['alias'] ? $agent['alias'] : $agent['nombre']) : $trap['source'];
    $oid = enterprise_hook('get_oid', [$trap]);
    if ($oid === ENTERPRISE_NOT_HOOK) {
        $oid = $trap['oid'];
    }

    $oids[$oid] = $oid;
}

$prea = array_keys($user_groups);
$ids = join(',', $prea);
// Cuantos usuarios hay operadores con un grupo que exista y no lo tenga ningun usuario.
$user_in_group_wo_agents = db_get_value_sql('select count(DISTINCT(id_usuario)) from tusuario_perfil where id_usuario ="'.$config['id_user'].'" and id_perfil = 1 and id_grupo in (select id_grupo from tgrupo where id_grupo in ('.$ids.') and id_grupo not in (select id_grupo from tagente))');

switch ($config['dbtype']) {
    case 'mysql':
    case 'postgresql':
        if ($user_in_group_wo_agents == 0) {
            $rows = db_get_all_rows_filter(
                'tagente',
                ['id_grupo' => array_keys($user_groups)],
                ['id_agente']
            );
            $id_agents = [];
            foreach ($rows as $row) {
                $id_agents[] = $row['id_agente'];
            }

            if (!empty($id_agents)) {
                $address_by_user_groups = agents_get_addresses($id_agents);
                foreach ($address_by_user_groups as $i => $a) {
                    $address_by_user_groups[$i] = '"'.$a.'"';
                }
            }
        } else {
            $rows = db_get_all_rows_filter(
                'tagente',
                [],
                ['id_agente']
            );
            $id_agents = [];
            foreach ($rows as $row) {
                $id_agents[] = $row['id_agente'];
            }

            $all_address_agents = agents_get_addresses($id_agents);
            foreach ($all_address_agents as $i => $a) {
                $all_address_agents[$i] = '"'.$a.'"';
            }
        }
    break;

    default:
        // Default.
    break;
}

if (empty($address_by_user_groups)) {
    $address_by_user_groups = [];
    array_unshift($address_by_user_groups, '""');
}

if (empty($all_address_agents)) {
    $all_address_agents = [];
    array_unshift($all_address_agents, '""');
}


// Make query to extract traps of DB.
switch ($config['dbtype']) {
    case 'mysql':
        $sql = 'SELECT *
			FROM ttrap
			WHERE (
				`source` IN ('.implode(',', $address_by_user_groups).") OR
				`source`='' OR
				`source` NOT IN (".implode(',', $all_address_agents).')
				)
				%s
			ORDER BY timestamp DESC
			LIMIT %d,%d';
    break;

    case 'postgresql':
        $sql = 'SELECT *
			FROM ttrap
			WHERE (
				source IN ('.implode(',', $address_by_user_groups).") OR
				source='' OR
				source NOT IN (".implode(',', $all_address_agents).')
				)
				%s
			ORDER BY timestamp DESC
			LIMIT %d OFFSET %d';
    break;

    case 'oracle':
        $sql = "SELECT *
			FROM ttrap
			WHERE (source IN (
					SELECT direccion FROM tagente
					WHERE id_grupo IN ($str_user_groups)
					) OR source='' OR source NOT IN (SELECT direccion FROM tagente WHERE direccion IS NOT NULL)) %s
			ORDER BY timestamp DESC";
    break;

    default:
         // Default.
    break;
}

switch ($config['dbtype']) {
    case 'mysql':
    case 'postgresql':
        $sql_all = 'SELECT *
			FROM ttrap
			WHERE (
				source IN ('.implode(',', $address_by_user_groups).") OR
				source='' OR
				source NOT IN (".implode(',', $all_address_agents).')
				)
				%s
			ORDER BY timestamp DESC';
        $sql_count = 'SELECT COUNT(id_trap)
			FROM ttrap
			WHERE (
				source IN ('.implode(',', $address_by_user_groups).") OR
				source='' OR
				source NOT IN (".implode(',', $all_address_agents).')
				)
				%s';
    break;

    case 'oracle':
        $sql_all = "SELECT *
			FROM ttrap
			WHERE (source IN (
					SELECT direccion FROM tagente
					WHERE id_grupo IN ($str_user_groups)
					) OR source='' OR source NOT IN (SELECT direccion FROM tagente WHERE direccion IS NOT NULL))
				%s
			ORDER BY timestamp DESC";
        $sql_count = "SELECT COUNT(id_trap)
			FROM ttrap
			WHERE (
				source IN (
					SELECT direccion FROM tagente
					WHERE id_grupo IN ($str_user_groups)
					) OR source='' OR source NOT IN (SELECT direccion FROM tagente WHERE direccion IS NOT NULL))
				%s";
    break;

    default:
         // Default.
    break;
}

// $whereSubquery = 'WHERE 1=1';
$whereSubquery = '';

if ($filter_fired != -1) {
    $whereSubquery .= ' AND alerted = '.$filter_fired;
}

if ($free_search_string != '') {
    switch ($config['dbtype']) {
        case 'mysql':
            $whereSubquery .= '
				AND (source LIKE "%'.$free_search_string.'%" OR
				oid LIKE "%'.$free_search_string.'%" OR
				oid_custom LIKE "%'.$free_search_string.'%" OR
				type_custom LIKE "%'.$free_search_string.'%" OR
				value LIKE "%'.$free_search_string.'%" OR
				value_custom LIKE "%'.$free_search_string.'%" OR
				id_usuario LIKE "%'.$free_search_string.'%" OR
				text LIKE "%'.$free_search_string.'%" OR
				description LIKE "%'.$free_search_string.'%")';
        break;

        case 'postgresql':
        case 'oracle':
            $whereSubquery .= '
				AND (source LIKE \'%'.$free_search_string.'%\' OR
				oid LIKE \'%'.$free_search_string.'%\' OR
				oid_custom LIKE \'%'.$free_search_string.'%\' OR
				type_custom LIKE \'%'.$free_search_string.'%\' OR
				value LIKE \'%'.$free_search_string.'%\' OR
				value_custom LIKE \'%'.$free_search_string.'%\' OR
				id_usuario LIKE \'%'.$free_search_string.'%\' OR
				text LIKE \'%'.$free_search_string.'%\' OR
				description LIKE \'%'.$free_search_string.'%\')';
        break;

        default:
             // Default.
        break;
    }
}

if ($date_from_trap != '') {
    if ($time_from_trap != '') {
        $whereSubquery .= '
			AND (UNIX_TIMESTAMP(timestamp) > UNIX_TIMESTAMP("'.$date_from_trap.' '.$time_from_trap.'"))
		';
    } else {
        $whereSubquery .= '
			AND (UNIX_TIMESTAMP(timestamp) > UNIX_TIMESTAMP("'.$date_from_trap.' 23:59:59"))
		';
    }
}

if ($date_to_trap != '') {
    if ($time_to_trap) {
        $whereSubquery .= '
			AND (UNIX_TIMESTAMP(timestamp) < UNIX_TIMESTAMP("'.$date_to_trap.' '.$time_to_trap.'"))
		';
    } else {
        $whereSubquery .= '
			AND (UNIX_TIMESTAMP(timestamp) < UNIX_TIMESTAMP("'.$date_to_trap.' 23:59:59"))
		';
    }
}

if ($filter_severity != -1) {
    // There are two special severity values aimed to match two different trap standard severities in database: warning/critical and critical/normal.
    if ($filter_severity != EVENT_CRIT_OR_NORMAL && $filter_severity != EVENT_CRIT_WARNING_OR_CRITICAL) {
        // Test if enterprise is installed to search oid in text or oid field in ttrap.
        if ($config['enterprise_installed']) {
            $whereSubquery .= ' AND (
    			(alerted = 0 AND severity = '.$filter_severity.') OR
    			(alerted = 1 AND priority = '.$filter_severity.'))';
        } else {
            $whereSubquery .= ' AND (
    			(alerted = 0 AND 1 = '.$filter_severity.') OR
    			(alerted = 1 AND priority = '.$filter_severity.'))';
        }
    } else if ($filter_severity === EVENT_CRIT_WARNING_OR_CRITICAL) {
        // Test if enterprise is installed to search oid in text or oid field in ttrap.
        if ($config['enterprise_installed']) {
            $whereSubquery .= ' AND (
                (alerted = 0 AND (severity = '.EVENT_CRIT_WARNING.' OR severity = '.EVENT_CRIT_CRITICAL.')) OR
                (alerted = 1 AND (priority = '.EVENT_CRIT_WARNING.' OR priority = '.EVENT_CRIT_CRITICAL.')))';
        } else {
            $whereSubquery .= ' AND (
                (alerted = 1 AND (priority = '.EVENT_CRIT_WARNING.' OR priority = '.EVENT_CRIT_CRITICAL.')))';
        }
    } else if ($filter_severity === EVENT_CRIT_OR_NORMAL) {
        // Test if enterprise is installed to search oid in text or oid field in ttrap.
        if ($config['enterprise_installed']) {
            $whereSubquery .= ' AND (
                (alerted = 0 AND (severity = '.EVENT_CRIT_NORMAL.' OR severity = '.EVENT_CRIT_CRITICAL.')) OR
                (alerted = 1 AND (priority = '.EVENT_CRIT_NORMAL.' OR priority = '.EVENT_CRIT_CRITICAL.')))';
        } else {
            $whereSubquery .= ' AND (
                (alerted = 1 AND (priority = '.EVENT_CRIT_NORMAL.' OR priority = '.EVENT_CRIT_CRITICAL.')))';
        }
    }
}

if ($filter_status != -1) {
    $whereSubquery .= ' AND status = '.$filter_status;
}

if ($trap_type == 5) {
    $whereSubquery .= ' AND type NOT IN (0, 1, 2, 3, 4)';
} else if ($trap_type != -1) {
    $whereSubquery .= ' AND type = '.$trap_type;
}

// Disable this feature (time will decide if temporarily) in Oracle cause the group by is very confictive.
if ($group_by && $config['dbtype'] != 'oracle') {
    $where_without_group = $whereSubquery;
    $whereSubquery .= ' GROUP BY source,oid';
}

switch ($config['dbtype']) {
    case 'mysql':
        $sql = sprintf($sql, $whereSubquery, $offset, $pagination);
    break;

    case 'postgresql':
        $sql = sprintf($sql, $whereSubquery, $pagination, $offset);
    break;

    case 'oracle':
        $set = [];
        $set['limit'] = $pagination;
        $set['offset'] = $offset;
        $sql = sprintf($sql, $whereSubquery);
        $sql = oracle_recode_query($sql, $set);
    break;

    default:
        // Default.
    break;
}

$sql_all = sprintf($sql_all, $whereSubquery);
$sql_count = sprintf($sql_count, $whereSubquery);

$table = new stdClass();
$table->width = '100%';
$table->cellpadding = 0;
$table->cellspacing = 0;
$table->class = 'databox filters';
$table->size = [];
$table->size[0] = '120px';
$table->data = [];

// Alert status select.
$table->data[1][0] = '<strong>'.__('Alert').'</strong>';
$table->data[1][1] = html_print_select(
    $alerted,
    'filter_fired',
    $filter_fired,
    '',
    __('All'),
    '-1',
    true
);

// Block size for pagination select.
$table->data[2][0] = '<strong>'.__('Block size for pagination').'</strong>';
$paginations[25] = 25;
$paginations[50] = 50;
$paginations[100] = 100;
$paginations[200] = 200;
$paginations[500] = 500;
$table->data[2][1] = html_print_select(
    $paginations,
    'pagination',
    $pagination,
    '',
    __('Default'),
    $config['block_size'],
    true
);

// Severity select.
$table->data[1][2] = '<strong>'.__('Severity').'</strong>';
$table->data[1][3] = html_print_select(
    $severities,
    'filter_severity',
    $filter_severity,
    '',
    __('All'),
    -1,
    true
);

// Status.
$table->data[3][0] = '<strong>'.__('Status').'</strong>';

$status_array[-1] = __('All');
$status_array[0] = __('Not validated');
$status_array[1] = __('Validated');
$table->data[3][1] = html_print_select(
    $status_array,
    'filter_status',
    $filter_status,
    '',
    '',
    '',
    true
);

// Free search (search by all alphanumeric fields).
$table->data[2][3] = '<strong>'.__('Free search').'</strong>'.ui_print_help_tip(
    __(
        'Search by any alphanumeric field in the trap.
		REMEMBER trap sources need to be searched by IP Address'
    ),
    true
);
$table->data[2][4] = html_print_input_text(
    'free_search_string',
    $free_search_string,
    '',
    40,
    0,
    true
);

$table->data[4][0] = '<strong>'.__('Max. hours old').'</strong>';
$table->data[4][1] = html_print_input(
    [
        'type'   => 'number',
        'name'   => 'hours_ago',
        'value'  => $hours_ago,
        'step'   => 1,
        'return' => true,
    ]
);

// Type filter (ColdStart, WarmStart, LinkDown, LinkUp, authenticationFailure, Other).
$table->data[4][3] = '<strong>'.__('Trap type').'</strong>'.ui_print_help_tip(__('Search by trap type'), true);
$trap_types = [
    -1 => __('None'),
    0  => __('Cold start (0)'),
    1  => __('Warm start (1)'),
    2  => __('Link down (2)'),
    3  => __('Link up (3)'),
    4  => __('Authentication failure (4)'),
    5  => __('Other'),
];
$table->data[4][4] = html_print_select(
    $trap_types,
    'trap_type',
    $trap_type,
    '',
    '',
    '',
    true,
    false,
    false
);

// Disable this feature (time will decide if temporarily) in Oracle cause the group by is very confictive.
if ($config['dbtype'] != 'oracle') {
    $table->data[3][3] = '<strong>'.__('Group by Enterprise String/IP').'</strong>';
    $table->data[3][4] = __('Yes').'&nbsp;'.html_print_radio_button('group_by', 1, '', $group_by, true).'&nbsp;&nbsp;';
    $table->data[3][4] .= __('No').'&nbsp;'.html_print_radio_button('group_by', 0, '', $group_by, true);
}

$filter = '<form id="filter_form" method="POST" action="index.php?sec=snmpconsole&sec2=operation/snmpconsole/snmp_view&refresh='.((int) get_parameter('refresh', 0)).'&pure='.$config['pure'].'">';
$filter .= html_print_table($table, true);
$filter .= '<div style="width: '.$table->width.'; text-align: right;">';
$filter .= html_print_submit_button(__('Update'), 'search', false, 'class="sub upd"', true);
$filter .= '</div>';
$filter .= '</form>';

$filter_resume = [];
$filter_resume['filter_fired'] = $alerted[$filter_fired];
$filter_resume['filter_severity'] = $severities[$filter_severity];
$filter_resume['pagination'] = $paginations[$pagination];
$filter_resume['free_search_string'] = $free_search_string;
$filter_resume['filter_status'] = $status_array[$filter_status];
$filter_resume['group_by'] = $group_by;
$filter_resume['hours_ago'] = $hours_ago;
$filter_resume['trap_type'] = $trap_types[$trap_type];

$traps = db_get_all_rows_sql($sql);
$trapcount = (int) db_get_value_sql($sql_count);

// No traps.
if (empty($traps)) {
    // Header.
    ui_print_page_header(
        __('SNMP Console'),
        'images/op_snmp.png',
        false,
        'snmp_console',
        false,
        [
            $list,
            $statistics,
        ]
    );

        $sql2 = 'SELECT *
			FROM ttrap
			WHERE (
				`source` IN ('.implode(',', $address_by_user_groups).") OR
				`source`='' OR
				`source` NOT IN (".implode(',', $all_address_agents).')
				)
				AND status = 0
			ORDER BY timestamp DESC';
        $traps2 = db_get_all_rows_sql($sql2);

    if (!empty($traps2)) {
        ui_toggle($filter, __('Toggle filter(s)'));

        print_snmp_tags_active_filters($filter_resume);

        ui_print_info_message(['no_close' => true, 'message' => __('There are no SNMP traps in database that contains this filter') ]);
    } else {
        ui_print_info_message(['no_close' => true, 'message' => __('There are no SNMP traps in database') ]);
    }

    return;
} else {
    if ($config['pure']) {
        echo '<div id="dashboard-controls">';

        echo '<div id="menu_tab">';
        echo '<ul class="mn">';
        // Normal view button.
        echo '<li class="nomn">';
        $normal_url = 'index.php?sec=snmpconsole&sec2=operation/snmpconsole/snmp_view&filter_severity='.$filter_severity.'&filter_fired='.$filter_fired.'&filter_status='.$filter_status.'&refresh='.((int) get_parameter('refresh', 0)).'&pure=0&trap_type='.$trap_type.'&group_by='.$group_by.'&free_search_string='.$free_search_string.'&date_from_trap='.$date_from_trap.'&date_to_trap='.$date_to_trap.'&time_from_trap='.$time_from_trap.'&time_to_trap='.$time_to_trap;

        $urlPagination = $normal_url.'&pagination='.$pagination.'&offset='.$offset;

        echo '<a href="'.$urlPagination.'">';
        echo html_print_image(
            'images/normal_screen.png',
            true,
            [
                'title' => __('Exit fullscreen'),
                'class' => 'invert_filter',
            ]
        );
        echo '</a>';
        echo '</li>';

        // Auto refresh control.
        echo '<li class="nomn">';
        echo '<div class="dashboard-refr mrgn_top_6px">';
        echo '<div class="dashboard-countdown display_in"></div>';
        $normal_url = 'index.php?sec=snmpconsole&sec2=operation/snmpconsole/snmp_view&filter_severity='.$filter_severity.'&filter_fired='.$filter_fired.'&filter_status='.$filter_status.'&refresh='.((int) get_parameter('refresh', 0)).'&pure=1&trap_type='.$trap_type.'&group_by='.$group_by.'&free_search_string='.$free_search_string.'&date_from_trap='.$date_from_trap.'&date_to_trap='.$date_to_trap.'&time_from_trap='.$time_from_trap.'&time_to_trap='.$time_to_trap;

        $urlPagination = $normal_url.'&pagination='.$pagination.'&offset='.$offset;


        echo '<form id="refr-form" method="get" action="'.$urlPagination.'"  >';
        echo __('Refresh every').':';
        echo html_print_select(get_refresh_time_array(), 'refresh', $refr, '', '', 0, true, false, false);
        echo '</form>';
        echo '</li>';

        html_print_input_hidden('sec', 'snmpconsole');
        html_print_input_hidden('sec2', 'operation/snmpconsole/snmp_view');
        html_print_input_hidden('pure', 1);
        html_print_input_hidden('refresh', ($refr > 0 ? $refr : $default_refr));

        // Dashboard name.
        echo '<li class="nomn">';
        echo '<div class="dashboard-title">'.__('SNMP Traps').'</div>';
        echo '</li>';

        echo '</ul>';
        echo '</div>';

        echo '</div>';

        ui_require_css_file('pandora_enterprise', ENTERPRISE_DIR.'/include/styles/');
        ui_require_css_file('pandora_dashboard', ENTERPRISE_DIR.'/include/styles/');
        ui_require_css_file('cluetip', 'include/styles/js/');

        ui_require_jquery_file('countdown');
        ui_require_javascript_file('pandora_dashboard', ENTERPRISE_DIR.'/include/javascript/');
        ui_require_javascript_file('wz_jsgraphics');
        ui_require_javascript_file('pandora_visual_console');
    } else {
        // Header.
        ui_print_page_header(
            __('SNMP Console'),
            'images/op_snmp.png',
            false,
            '',
            false,
            [
                $fullscreen,
                $list,
                $statistics,
            ]
        );
    }
}

ui_toggle($filter, __('Toggle filter(s)'));
unset($table);

print_snmp_tags_active_filters($filter_resume);

if (($config['dbtype'] == 'oracle') && ($traps !== false)) {
    $traps_size = count($traps);
    for ($i = 0; $i < $traps_size; $i++) {
        unset($traps[$i]['rnum']);
    }
}

$url_snmp = 'index.php?sec=snmpconsole&sec2=operation/snmpconsole/snmp_view';
$url_snmp .= '&filter_severity='.$filter_severity.'&filter_fired='.$filter_fired;
$url_snmp .= '&filter_status='.$filter_status.'&refresh='.((int) get_parameter('refresh', 0));
$url_snmp .= '&pure='.$config['pure'].'&trap_type='.$trap_type;
$url_snmp .= '&group_by='.$group_by.'&free_search_string='.$free_search_string;
$url_snmp .= '&hours_ago='.$hours_ago;

$urlPagination = $url_snmp.'&pagination='.$pagination.'&offset='.$offset;

ui_pagination($trapcount, $urlPagination, $offset, $pagination);

echo '<form name="eventtable" method="POST" action="'.$urlPagination.'">';

$table = new StdClass();
$table->cellpadding = 0;
$table->cellspacing = 0;
$table->width = '100%';
$table->class = 'databox data';
$table->head = [];
$table->size = [];
$table->data = [];
$table->align = [];
$table->headstyle = [];

$table->head[0] = __('Status');
$table->align[0] = 'center';
$table->size[0] = '5%';
$table->headstyle[0] = 'text-align: center';

$table->head[1] = __('SNMP Agent');
$table->align[1] = 'center';
$table->size[1] = '15%';
$table->headstyle[1] = 'text-align: center';

$table->head[2] = __('Enterprise String');
$table->align[2] = 'center';
$table->size[2] = '18%';
$table->headstyle[2] = 'text-align: center';

if ($group_by) {
    $table->head[3] = __('Count');
    $table->align[3] = 'center';
    $table->size[3] = '5%';
    $table->headstyle[3] = 'text-align: center';
}

$table->head[4] = __('Trap subtype');
$table->align[4] = 'center';
$table->size[4] = '10%';
$table->headstyle[4] = 'text-align: center';

$table->head[5] = __('User ID');
$table->align[5] = 'center';
$table->size[5] = '10%';
$table->headstyle[5] = 'text-align: center';

$table->head[6] = __('Timestamp');
$table->align[6] = 'center';
$table->size[6] = '10%';
$table->headstyle[6] = 'text-align: center';

$table->head[7] = __('Alert');
$table->align[7] = 'center';
$table->size[7] = '5%';
$table->headstyle[7] = 'text-align: center';

$table->head[8] = __('Action');
$table->align[8] = 'center';
$table->size[8] = '10%';
$table->headstyle[8] = 'min-width: 125px;text-align: center';

$table->head[9] = html_print_checkbox_extended(
    'allbox',
    1,
    false,
    false,
    'javascript:CheckAll();',
    'class="chk" title="'.__('All').'"',
    true
);
$table->align[9] = 'center';
$table->size[9] = '5%';
$table->headstyle[9] = 'text-align: center';

$table->style[8] = 'background: #F3F3F3; color: #111 !important;';

// Skip offset records.
$idx = 0;
if ($traps !== false) {
    foreach ($traps as $trap) {
        $data = [];
        if (empty($trap['description'])) {
            $trap['description'] = '';
        }

        $severity = enterprise_hook('get_severity', [$trap]);
        if ($severity === ENTERPRISE_NOT_HOOK) {
            $severity = $trap['alerted'] == 1 ? $trap['priority'] : 1;
        }

        // Status.
        if ($trap['status'] == 0) {
            $data[0] = html_print_image(
                'images/pixel_red.png',
                true,
                [
                    'title'  => __('Not validated'),
                    'width'  => '20',
                    'height' => '20',
                ]
            );
        } else {
            $data[0] = html_print_image(
                'images/pixel_green.png',
                true,
                [
                    'title'  => __('Validated'),
                    'width'  => '20',
                    'height' => '20',
                ]
            );
        }

        // Agent matching source address.
        $table->cellclass[$idx][1] = get_priority_class($severity);
        $agent = agents_get_agent_with_ip($trap['source']);
        if ($agent === false) {
            if (! check_acl($config['id_user'], 0, 'AR')) {
                continue;
            }

            $data[1] = '<a href="index.php?sec=estado&sec2=godmode/agentes/configurar_agente&new_agent=1&direccion='.$trap['source'].'" title="'.__('Create agent').'">'.$trap['source'].'</a>';
        } else {
            if (! check_acl($config['id_user'], $agent['id_grupo'], 'AR')) {
                continue;
            }

            $data[1] = '<a href="index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente='.$agent['id_agente'].'" title="'.__('View agent details').'">';
            $data[1] .= '<strong>'.$agent['alias'].ui_print_help_tip($trap['source'], true, 'images/tip-blanco.png');
            '</strong></a>';
        }

        // OID.
        $table->cellclass[$idx][2] = get_priority_class($severity);
        if (! empty($trap['text'])) {
            $enterprise_string = $trap['text'];
        } else if (! empty($trap['oid'])) {
            $enterprise_string = $trap['oid'];
        } else {
            $enterprise_string = __('N/A');
        }

        $data[2] = '<a href="javascript: toggleVisibleExtendedInfo('.$trap['id_trap'].');">'.$enterprise_string.'</a>';

        // Count.
        if ($group_by) {
            $sql = "SELECT * FROM ttrap WHERE 1=1 
					$where_without_group
					AND oid='".$trap['oid']."' 
					AND source='".$trap['source']."'";
            $group_traps = db_get_all_rows_sql($sql);
            $count_group_traps = count($group_traps);
            $table->cellclass[$idx][3] = get_priority_class($severity);
            $data[3] = '<strong>'.$count_group_traps.'</strong></a>';
        }

        // Value.
        $table->cellclass[$idx][4] = get_priority_class($severity);
        if (empty($trap['value'])) {
            $data[4] = __('N/A');
        } else {
            $data[4] = ui_print_truncate_text($trap['value'], GENERIC_SIZE_TEXT, false);
        }

        // User.
        $table->cellclass[$idx][5] = get_priority_class($severity);
        if (!empty($trap['status'])) {
            $data[5] = '<a href="index.php?sec=workspace&sec2=operation/users/user_edit&ver='.$trap['id_usuario'].'">'.substr($trap['id_usuario'], 0, 8).'</a>';
            if (!empty($trap['id_usuario'])) {
                $data[5] .= ui_print_help_tip(get_user_fullname($trap['id_usuario']), true);
            }
        } else {
            $data[5] = '--';
        }

        // Timestamp.
        $table->cellclass[$idx][6] = get_priority_class($severity);
        $data[6] = '<span title="'.$trap['timestamp'].'">';
        $data[6] .= ui_print_timestamp($trap['timestamp'], true);
        $data[6] .= '</span>';

        // Use alert severity if fired.
        if (!empty($trap['alerted'])) {
            $data[7] = html_print_image('images/pixel_yellow.png', true, ['width' => '20', 'height' => '20', 'border' => '0', 'title' => __('Alert fired')]);
        } else {
            $data[7] = html_print_image('images/pixel_gray.png', true, ['width' => '20', 'height' => '20', 'border' => '0', 'title' => __('Alert not fired')]);
        }

        // Actions.
        $data[8] = '';

        if (empty($trap['status']) && check_acl($config['id_user'], 0, 'IW')) {
            $data[8] .= '<a href="'.$urlPagination.'&check='.$trap['id_trap'].'">'.html_print_image('images/ok.png', true, ['border' => '0', 'title' => __('Validate')]).'</a> ';
        }

        if ($trap['source'] == '') {
            $is_admin = db_get_value('is_admin', 'tusuario', 'id_user', $config['id_user']);
            if ($is_admin) {
                $data[8] .= '<a href="'.$urlPagination.'&delete='.$trap['id_trap'].'&offset='.$offset.'" onClick="javascript:return confirm(\''.__('Are you sure?').'\')">'.html_print_image(
                    'images/cross.png',
                    true,
                    [
                        'border' => '0',
                        'title'  => __('Delete'),
                        'class'  => 'invert_filter',
                    ]
                ).'</a> ';
            }
        } else {
            $agent_trap_group = db_get_value('id_grupo', 'tagente', 'nombre', $trap['source']);

            if ((check_acl($config['id_user'], $agent_trap_group, 'IM'))) {
                $data[8] .= '<a href="'.$urlPagination.'&delete='.$trap['id_trap'].'&offset='.$offset.'" onClick="javascript:return confirm(\''.__('Are you sure?').'\')">'.html_print_image(
                    'images/cross.png',
                    true,
                    [
                        'border' => '0',
                        'title'  => __('Delete'),
                        'class'  => 'invert_filter',
                    ]
                ).'</a> ';
            }
        }

        $data[8] .= '<a href="javascript: toggleVisibleExtendedInfo('.$trap['id_trap'].');">'.html_print_image(
            'images/eye.png',
            true,
            [
                'alt'   => __('Show more'),
                'title' => __('Show more'),
                'class' => 'invert_filter',
            ]
        ).'</a>';
        $data[8] .= enterprise_hook('editor_link', [$trap]);


        $data[9] = html_print_checkbox_extended('snmptrapid[]', $trap['id_trap'], false, false, '', 'class="chk"', true);

        array_push($table->data, $data);

        // Hiden file for description.
        $string = '<table width="90%" class="toggle border_1px_d3">
			<tr>
				<td align="left" valign="top" width="15%">'.'<b>'.__('Variable bindings:').'</b></td>
				<td align="left" >';

        if ($group_by) {
            $new_url = 'index.php?sec=snmpconsole&sec2=operation/snmpconsole/snmp_view';
            $new_url .= '&filter_severity='.$filter_severity;
            $new_url .= '&filter_fired='.$filter_fired;
            $new_url .= '&filter_status='.$filter_status;
            $new_url .= '&refresh='.((int) get_parameter('refresh', 0));
            $new_url .= '&pure='.$config['pure'];
            $new_url .= '&group_by=0&free_search_string='.$free_search_string;
            $new_url .= '&hours_ago='.$hours_ago;

            $string .= '<a href='.$new_url.'>'.__('See more details').'</a>';
        } else {
            // Print binding vars separately.
            $binding_vars = explode("\t", $trap['oid_custom']);
            foreach ($binding_vars as $var) {
                $string .= $var.'<br/>';
            }
        }

        $string .= '</td>
			</tr>
			<tr>
				<td align="left" valign="top">'.'<b>'.__('Enterprise String:').'</td>
				<td align="left"> '.$trap['oid'].'</td>
			</tr>';

        if ($trap['description'] != '') {
            $string .= '<tr>
					<td align="left" valign="top">'.'<b>'.__('Description:').'</td>
					<td align="left">'.$trap['description'].'</td>
				</tr>';
        }

        if ($trap['type'] != '') {
            $trap_types = [
                -1 => __('None'),
                0  => __('Cold start (0)'),
                1  => __('Warm start (1)'),
                2  => __('Link down (2)'),
                3  => __('Link up (3)'),
                4  => __('Authentication failure (4)'),
                5  => __('Other'),
            ];

            switch ($trap['type']) {
                case -1:
                    $desc_trap_type = __('None');
                break;

                case 0:
                    $desc_trap_type = __('Cold start (0)');
                break;

                case 1:
                    $desc_trap_type = __('Warm start (1)');
                break;

                case 2:
                    $desc_trap_type = __('Link down (2)');
                break;

                case 3:
                    $desc_trap_type = __('Link up (3)');
                break;

                case 4:
                    $desc_trap_type = __('Authentication failure (4)');
                break;

                default:
                    $desc_trap_type = __('Other');
                break;
            }

            $string .= '<tr><td align="left" valign="top"><b>'.__('Trap type:').'</b></td><td align="left">'.$desc_trap_type.'</td></tr>';
        }

        if ($group_by) {
            $sql = "SELECT * FROM ttrap WHERE 1=1 
					$where_without_group
					AND oid='".$trap['oid']."' 
					AND source='".$trap['source']."'";
            $group_traps = db_get_all_rows_sql($sql);
            $count_group_traps = count($group_traps);

            $sql = "SELECT timestamp FROM ttrap WHERE 1=1 
					$where_without_group
					AND oid='".$trap['oid']."' 
					AND source='".$trap['source']."'
					ORDER BY `timestamp` DESC";
            $last_trap = db_get_value_sql($sql);

            $sql = "SELECT timestamp FROM ttrap WHERE 1=1
					$where_without_group
					AND oid='".$trap['oid']."' 
					AND source='".$trap['source']."'
					ORDER BY `timestamp` ASC";
            $first_trap = db_get_value_sql($sql);

            $string .= '<tr>
					<td align="left" valign="top">'.'<b>'.__('Count:').'</td>
					<td align="left">'.$count_group_traps.'</td>
				</tr>';
            $string .= '<tr>
					<td align="left" valign="top">'.'<b>'.__('First trap:').'</td>
					<td align="left">'.$first_trap.'</td>
				</tr>';
            $string .= '<tr>
					<td align="left" valign="top">'.'<b>'.__('Last trap:').'</td>
					<td align="left">'.$last_trap.'</td>
				</tr>';
        }

        $string .= '</table>';

        $data = [$string];
        // $data = array($trap['description']);
        $idx++;
        $table->rowclass[$idx] = 'trap_info_'.$trap['id_trap'];
        $table->colspan[$idx][0] = 10;
        $table->rowstyle[$idx] = 'display: none;';
        array_push($table->data, $data);

        $idx++;
    }
}

// No matching traps.
if ($idx == 0) {
    echo '<div class="nf">'.__('No matching traps found').'</div>';
} else {
    html_print_table($table);
}

unset($table);

echo '<div class="w98p right">';
if (check_acl($config['id_user'], 0, 'IW')) {
    html_print_submit_button(__('Validate'), 'updatebt', false, 'class="sub ok"');
}

if (check_acl($config['id_user'], 0, 'IM')) {
    echo '&nbsp;';
    html_print_submit_button(__('Delete'), 'deletebt', false, 'class="sub delete" onClick="javascript:return confirm(\''.__('Are you sure?').'\')"');
}

echo '</div></form>';


echo '<div class="snmp_view_div">';
echo '<h3>'.__('Status').'</h3>';
echo html_print_image(
    'images/pixel_green.png',
    true,
    [
        'width'  => '20',
        'height' => '20',
    ]
).' - '.__('Validated');
echo '<br />';
echo html_print_image(
    'images/pixel_red.png',
    true,
    [
        'width'  => '20',
        'height' => '20',
    ]
).' - '.__('Not validated');
echo '</div>';
echo '<div class="snmp_view_div">';
echo '<h3>'.__('Alert').'</h3>';
echo html_print_image(
    'images/pixel_yellow.png',
    true,
    [
        'width'  => '20',
        'height' => '20',
    ]
).' - '.__('Fired');
echo '<br />';
echo html_print_image(
    'images/pixel_gray.png',
    true,
    [
        'width'  => '20',
        'height' => '20',
    ]
).' - '.__('Not fired');
echo '</div>';
echo '<div class="snmp_view_div">';
echo '<h3>'.__('Action').'</h3>';
echo html_print_image('images/ok.png', true).' - '.__('Validate');
echo '<br />';
echo html_print_image('images/cross.png', true, ['class' => 'invert_filter']).' - '.__('Delete');
echo '</div>';
echo '<div class="snmp_view_div">';
echo '<h3>'.__('Legend').'</h3>';
foreach (get_priorities() as $num => $name) {
    echo '<span class="'.get_priority_class($num).'">'.$name.'</span>';
    echo '<br />';
}

echo '</div>';
echo '<div class="both">&nbsp;</div>';

ui_include_time_picker();
?>

<script language="JavaScript" type="text/javascript">

    $(document).ready( function() {
        var controls = document.getElementById('dashboard-controls');
        autoHideElement(controls, 1000);
        
        var startCountDown = function (duration, cb) {
            $('div.dashboard-countdown').countdown('destroy');
            if (!duration) return;
            var t = new Date();
            t.setTime(t.getTime() + duration * 1000);
            $('div.dashboard-countdown').countdown({
                until: t,
                format: 'MS',
                layout: '(%M%nn%M:%S%nn%S <?php echo __('Until next'); ?>) ',
                alwaysExpire: true,
                onExpiry: function () {
                    $('div.dashboard-countdown').countdown('destroy');
                    cb();
                }
            });
        }
        
        // Auto refresh select
        $('form#refr-form').submit(function (event) {
            event.preventDefault();
        });
        
        var handleRefrChange = function (event) {
            event.preventDefault();
            var url = $('form#refr-form').prop('action');
            var refr = Number.parseInt(event.target.value, 10);
            
            startCountDown(refr, function () {
                window.location = url + '&refresh=' + refr;
            });
        }
        
        $('form#refr-form select').change(handleRefrChange).change();
        
        
    });
    
    function CheckAll() {
        for (var i = 0; i < document.eventtable.elements.length; i++) {
            var e = document.eventtable.elements[i];
            if (e.type == 'checkbox' && e.name != 'allbox')
                e.checked = !e.checked;
        }
    }
    
    function toggleDiv (divid) {
        if (document.getElementById(divid).style.display == 'none') {
            document.getElementById(divid).style.display = 'block';
        }
        else {
            document.getElementById(divid).style.display = 'none';
        }
    }
    
    function toggleVisibleExtendedInfo(id_trap) {
        display = $('.trap_info_' + id_trap).css('display');
        
        if (display != 'none') {
            $('.trap_info_' + id_trap).css('display', 'none');
        }
        else {
            $('.trap_info_' + id_trap).css('display', '');
        }
    }

</script>