<?php
/**
 * Extension to manage a list of gateways and the node address where they should
 * point to.
 *
 * @category   Planned Donwtimes
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



check_login();

$agent_d = check_acl($config['id_user'], 0, 'AD');
$agent_w = check_acl($config['id_user'], 0, 'AW');
$access = ($agent_d == true) ? 'AD' : (($agent_w == true) ? 'AW' : 'AD');
if (!$agent_d && !$agent_w) {
    db_pandora_audit(
        'ACL Violation',
        'Trying to access downtime scheduler'
    );
    include 'general/noaccess.php';
    return;
}

// Default.
set_unless_defined($config['past_planned_downtimes'], 1);

require_once 'include/functions_users.php';

// Buttons.
$buttons = [
    'text' => "<a href='index.php?sec=extensions&sec2=godmode/agentes/planned_downtime.list'>".html_print_image(
        'images/list.png',
        true,
        [
            'title' => __('List'),
            'class' => 'invert_filter',
        ]
    ).'</a>',
];

// Header.
ui_print_page_header(
    __('Planned Downtime'),
    'images/gm_monitoring.png',
    false,
    '',
    true,
    $buttons
);

// Recursion group filter.
$recursion = get_parameter('recursion', $_POST['recursion']);


// Initialize data.
$id_group = (int) get_parameter('id_group');
$name = (string) get_parameter('name');
$description = (string) get_parameter('description');

$type_downtime = (string) get_parameter('type_downtime', 'quiet');
$type_execution = (string) get_parameter('type_execution', 'once');
$type_periodicity = (string) get_parameter('type_periodicity', 'weekly');

$utimestamp = get_system_time();
// Fake utimestamp to retrieve the string date of the system.
$system_time = ($utimestamp - get_fixed_offset());

$once_date_from = (string) get_parameter(
    'once_date_from',
    date(DATE_FORMAT, $utimestamp)
);
$once_time_from = (string) get_parameter(
    'once_time_from',
    date(TIME_FORMAT, $utimestamp)
);
$once_date_to = (string) get_parameter(
    'once_date_to',
    date(DATE_FORMAT, $utimestamp)
);
$once_time_to = (string) get_parameter(
    'once_time_to',
    date(TIME_FORMAT, ($utimestamp + SECONDS_1HOUR))
);

$periodically_day_from = (int) get_parameter(
    'periodically_day_from',
    1
);
$periodically_day_to = (int) get_parameter(
    'periodically_day_to',
    31
);
$periodically_time_from = (string) get_parameter(
    'periodically_time_from',
    date(TIME_FORMAT, $system_time)
);
$periodically_time_to = (string) get_parameter(
    'periodically_time_to',
    date(TIME_FORMAT, ($system_time + SECONDS_1HOUR))
);

$monday = (bool) get_parameter('monday');
$tuesday = (bool) get_parameter('tuesday');
$wednesday = (bool) get_parameter('wednesday');
$thursday = (bool) get_parameter('thursday');
$friday = (bool) get_parameter('friday');
$saturday = (bool) get_parameter('saturday');
$sunday = (bool) get_parameter('sunday');

$first_create = (int) get_parameter('first_create');
$create_downtime = (int) get_parameter('create_downtime');
$update_downtime = (int) get_parameter('update_downtime');
$edit_downtime = (int) get_parameter('edit_downtime');
$id_downtime = (int) get_parameter('id_downtime');

$id_agent = (int) get_parameter('id_agent');
$insert_downtime_agent = (int) get_parameter('insert_downtime_agent');
$delete_downtime_agent = (int) get_parameter('delete_downtime_agent');

$modules_selection_mode = (string) get_parameter('modules_selection_mode');

// User groups with AD or AW permission for ACL checks.
$user_groups_ad = array_keys(
    users_get_groups($config['id_user'], $access)
);

// INSERT A NEW DOWNTIME_AGENT ASSOCIATION.
if ($insert_downtime_agent === 1) {
    insert_downtime_agent($id_downtime, $user_groups_ad);
}

// DELETE A DOWNTIME_AGENT ASSOCIATION.
if ($delete_downtime_agent === 1) {
    $id_da = (int) get_parameter('id_downtime_agent');

    // Check AD permission on downtime.
    $downtime_group = db_get_value(
        'id_group',
        'tplanned_downtime',
        'id',
        $id_downtime
    );

    if ($downtime_group === false
        || !in_array($downtime_group, $user_groups_ad)
    ) {
        db_pandora_audit(
            'ACL Violation',
            'Trying to access downtime scheduler'
        );
        include 'general/noaccess.php';
        return;
    }

    // Check AD permission on agent.
    $agent_group = db_get_value(
        'id_grupo',
        'tagente',
        'id_agente',
        $id_agent
    );

    if ($agent_group === false
        || !in_array($agent_group, $user_groups_ad)
    ) {
        db_pandora_audit(
            'ACL Violation',
            'Trying to access downtime scheduler'
        );
        include 'general/noaccess.php';
        return;
    }

    // 'Is running' check.
    $is_running = (bool) db_get_value(
        'executed',
        'tplanned_downtime',
        'id',
        $id_downtime
    );
    if ($is_running) {
        ui_print_error_message(
            __('This elements cannot be modified while the downtime is being executed')
        );
    } else {
        $row_to_delete = db_get_row('tplanned_downtime_agents', 'id', $id_da);

        $result = db_process_sql_delete(
            'tplanned_downtime_agents',
            ['id' => $id_da]
        );

        if ($result) {
            // Delete modules in downtime.
            db_process_sql_delete(
                'tplanned_downtime_modules',
                [
                    'id_downtime' => $row_to_delete['id_downtime'],
                    'id_agent'    => $id_agent,
                ]
            );
        }
    }
}

// UPDATE OR CREATE A DOWNTIME (MAIN DATA, NOT AGENT ASSOCIATION).
if ($create_downtime || $update_downtime) {
    $check = (bool) db_get_value('name', 'tplanned_downtime', 'name', $name);

    $datetime_from = strtotime($once_date_from.' '.$once_time_from);
    $datetime_to = strtotime($once_date_to.' '.$once_time_to);
    $now = time();

    if ($type_execution == 'once' && !$config['past_planned_downtimes'] && $datetime_from < $now) {
        ui_print_error_message(
            __('Not created. Error inserting data. Start time must be higher than the current time')
        );
    } else if ($type_execution == 'once' && $datetime_from >= $datetime_to) {
        ui_print_error_message(
            __('Not created. Error inserting data').'. '.__('The end date must be higher than the start date')
        );
    } else if ($type_execution == 'once' && $datetime_to <= $now && !$config['past_planned_downtimes']) {
        ui_print_error_message(
            __('Not created. Error inserting data').'. '.__('The end date must be higher than the current time')
        );
    } else if ($type_execution == 'periodically'
        && $type_periodicity == 'monthly'
        && $periodically_day_from == $periodically_day_to
    ) {
        ui_print_error_message(
            __('Not created. Error inserting data').'. '.__('The end time must be higher than the start time')
        );
    } else if ($type_execution == 'periodically' && $type_periodicity == 'monthly' && $periodically_day_from > $periodically_day_to) {
        ui_print_error_message(
            __('Not created. Error inserting data').'. '.__('The end day must be higher than the start day')
        );
    } else {
        $sql = '';
        if ($create_downtime) {
            // Check AD permission on new downtime.
            if (!in_array($id_group, $user_groups_ad)) {
                db_pandora_audit(
                    'ACL Violation',
                    'Trying to access downtime scheduler'
                );
                include 'general/noaccess.php';
                return;
            }

            if (trim(io_safe_output($name)) != '') {
                if (!$check) {
                    $values = [
                        'name'                   => $name,
                        'description'            => $description,
                        'date_from'              => $datetime_from,
                        'date_to'                => $datetime_to,
                        'executed'               => 0,
                        'id_group'               => $id_group,
                        'only_alerts'            => 0,
                        'monday'                 => $monday,
                        'tuesday'                => $tuesday,
                        'wednesday'              => $wednesday,
                        'thursday'               => $thursday,
                        'friday'                 => $friday,
                        'saturday'               => $saturday,
                        'sunday'                 => $sunday,
                        'periodically_time_from' => $periodically_time_from,
                        'periodically_time_to'   => $periodically_time_to,
                        'periodically_day_from'  => $periodically_day_from,
                        'periodically_day_to'    => $periodically_day_to,
                        'type_downtime'          => $type_downtime,
                        'type_execution'         => $type_execution,
                        'type_periodicity'       => $type_periodicity,
                        'id_user'                => $config['id_user'],
                    ];
                    if ($config['dbtype'] == 'oracle') {
                        $values['periodically_time_from'] = '1970/01/01 '.$values['periodically_time_from'];
                        $values['periodically_time_to'] = '1970/01/01 '.$values['periodically_time_to'];
                    }

                    $result = db_process_sql_insert(
                        'tplanned_downtime',
                        $values
                    );
                } else {
                    ui_print_error_message(
                        __('Each planned downtime must have a different name')
                    );
                }
            } else {
                ui_print_error_message(
                    __('Planned downtime must have a name')
                );
            }
        } else if ($update_downtime) {
            $old_downtime = db_get_row('tplanned_downtime', 'id', $id_downtime);

            // Check AD permission on OLD downtime.
            if (empty($old_downtime) || !in_array($old_downtime['id_group'], $user_groups_ad)) {
                db_pandora_audit(
                    'ACL Violation',
                    'Trying to access downtime scheduler'
                );
                include 'general/noaccess.php';
                return;
            }

            // Check AD permission on NEW downtime group.
            if (!in_array($id_group, $user_groups_ad)) {
                db_pandora_audit(
                    'ACL Violation',
                    'Trying to access downtime scheduler'
                );
                include 'general/noaccess.php';
                return;
            }

            // 'Is running' check.
            $is_running = (bool) $old_downtime['executed'];

            $values = [];
            if (trim(io_safe_output($name)) == '') {
                ui_print_error_message(
                    __('Planned downtime must have a name')
                );
            }

            // When running only certain items can be modified for the 'once' type.
            else if ($is_running && $type_execution == 'once') {
                $values = [
                    'description' => $description,
                    'date_to'     => $datetime_to,
                    'id_user'     => $config['id_user'],
                ];
            } else {
                $values = [
                    'name'                   => $name,
                    'description'            => $description,
                    'date_from'              => $datetime_from,
                    'date_to'                => $datetime_to,
                    'id_group'               => $id_group,
                    'only_alerts'            => 0,
                    'monday'                 => $monday,
                    'tuesday'                => $tuesday,
                    'wednesday'              => $wednesday,
                    'thursday'               => $thursday,
                    'friday'                 => $friday,
                    'saturday'               => $saturday,
                    'sunday'                 => $sunday,
                    'periodically_time_from' => $periodically_time_from,
                    'periodically_time_to'   => $periodically_time_to,
                    'periodically_day_from'  => $periodically_day_from,
                    'periodically_day_to'    => $periodically_day_to,
                    'type_downtime'          => $type_downtime,
                    'type_execution'         => $type_execution,
                    'type_periodicity'       => $type_periodicity,
                    'id_user'                => $config['id_user'],
                ];
                if ($config['dbtype'] == 'oracle') {
                    $values['periodically_time_from'] = '1970/01/01 '.$values['periodically_time_from'];
                    $values['periodically_time_to'] = '1970/01/01 '.$values['periodically_time_to'];
                }
            }

            if ($is_running) {
                $result = false;
            } else {
                if (!empty($values)) {
                    $result = db_process_sql_update(
                        'tplanned_downtime',
                        $values,
                        ['id' => $id_downtime]
                    );
                }
            }
        }

        if ($result === false) {
            if ($create_downtime) {
                ui_print_error_message(__('Could not be created'));
            } else {
                ui_print_error_message(__('Could not be updated'));
            }
        } else {
            if ($create_downtime && $name && !$check) {
                $id_downtime = $result;

                insert_downtime_agent($id_downtime, $user_groups_ad);

                ui_print_success_message(__('Successfully created'));
            } else if ($update_downtime && $name) {
                ui_print_success_message(__('Successfully updated'));
            }
        }
    }
}

// Have any data to show ?
if ($id_downtime > 0) {
    // Columns of the table tplanned_downtime.
    $columns = [
        'id',
        'name',
        'description',
        'date_from',
        'date_to',
        'executed',
        'id_group',
        'only_alerts',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
        'periodically_time_from',
        'periodically_time_to',
        'periodically_day_from',
        'periodically_day_to',
        'type_downtime',
        'type_execution',
        'type_periodicity',
        'id_user',
    ];

    switch ($config['dbtype']) {
        case 'mysql':
        case 'postgresql':
            $columns_str = implode(',', $columns);
            $sql = "SELECT $columns_str
                    FROM tplanned_downtime
                    WHERE id = $id_downtime";
        break;

        case 'oracle':
            // Oracle doesn't have TIME type,
            // so we should transform the DATE value.
            $new_time_from = "TO_CHAR(periodically_time_from, 'HH24:MI:SS') AS periodically_time_from";
            $new_time_to = "TO_CHAR(periodically_time_to, 'HH24:MI:SS') AS periodically_time_to";

            $time_from_key = array_search('periodically_time_from', $columns);
            $time_to_key = array_search('periodically_time_to', $columns);

            if ($time_from_key !== false) {
                $columns[$time_from_key] = $new_time_from;
            }

            if ($time_to_key !== false) {
                $columns[$time_to_key] = $new_time_to;
            }

            $columns_str = implode(',', $columns);
            $sql = "SELECT $columns_str
                    FROM tplanned_downtime
                    WHERE id = $id_downtime";
        break;
    }

    $result = db_get_row_sql($sql);

    // Permission check for the downtime with the AD user groups
    if (empty($result) || !in_array($result['id_group'], $user_groups_ad)) {
        db_pandora_audit(
            'ACL Violation',
            'Trying to access downtime scheduler'
        );
        include 'general/noaccess.php';
        return;
    }

    $name                     = (string) $result['name'];
    $id_group                 = (int) $result['id_group'];

    $description = (string) $result['description'];

    $type_downtime             = (string) $result['type_downtime'];
    $type_execution         = (string) $result['type_execution'];
    $type_periodicity         = (string) $result['type_periodicity'];

    $once_date_from         = date(DATE_FORMAT, $result['date_from']);
    $once_date_to             = date(DATE_FORMAT, $result['date_to']);
    $once_time_from         = date(TIME_FORMAT, $result['date_from']);
    $once_time_to             = date(TIME_FORMAT, $result['date_to']);

    $periodically_time_from = (string) $result['periodically_time_from'];
    $periodically_time_to     = (string) $result['periodically_time_to'];
    $periodically_day_from     = (int) $result['periodically_day_from'];
    $periodically_day_to     = (int) $result['periodically_day_to'];

    $monday                 = (bool) $result['monday'];
    $tuesday                 = (bool) $result['tuesday'];
    $wednesday                 = (bool) $result['wednesday'];
    $thursday                 = (bool) $result['thursday'];
    $friday                 = (bool) $result['friday'];
    $saturday                 = (bool) $result['saturday'];
    $sunday                 = (bool) $result['sunday'];

    $running = (bool) $result['executed'];
}

// When the planned downtime is in execution,
// only action to postpone on once type is enabled and the other are disabled.
$disabled_in_execution = (int) $running;

$table = new StdClass();
$table->class = 'databox filters';
$table->width = '100%';
$table->data = [];
$table->data[0][0] = __('Name');
$table->data[0][1] = html_print_input_text(
    'name',
    $name,
    '',
    25,
    40,
    true,
    $disabled_in_execution
);
$table->data[1][0] = __('Group');
$table->data[1][1] = '<div class="w250px">'.html_print_select_groups(
    false,
    $access,
    true,
    'id_group',
    $id_group,
    '',
    '',
    0,
    true,
    false,
    true,
    '',
    $disabled_in_execution
).'</div>';
$table->data[2][0] = __('Description');
$table->data[2][1] = html_print_textarea(
    'description',
    3,
    35,
    $description,
    '',
    true
);

$table->data[3][0] = __('Type').ui_print_help_tip(
    __('Quiet: Modules will not generate events or fire alerts.').'<br>'.__('Disable Agents: Disables the selected agents.').'<br>'.__('Disable Alerts: Disable alerts for the selected agents.'),
    true
);
$table->data[3][1] = html_print_select(
    [
        'quiet'                 => __('Quiet'),
        'disable_agents'        => __('Disabled Agents'),
        'disable_agents_alerts' => __('Disabled only Alerts'),
    ],
    'type_downtime',
    $type_downtime,
    'change_type_downtime()',
    '',
    0,
    true,
    false,
    true,
    '',
    $disabled_in_execution
);
$table->data[4][0] = __('Execution');
$table->data[4][1] = html_print_select(
    [
        'once'         => __('Once'),
        'periodically' => __('Periodically'),
    ],
    'type_execution',
    $type_execution,
    'change_type_execution();',
    '',
    0,
    true,
    false,
    true,
    '',
    $disabled_in_execution
);

$days = array_combine(range(1, 31), range(1, 31));
$table->data[5][0] = __('Configure the time').'&nbsp;';
;
$table->data[5][1] = "
    <div id='once_time' style='display: none;'>
        <table>
            <tr>
                <td>".__('From:').'</td>
                <td>'.html_print_input_text('once_date_from', $once_date_from, '', 10, 10, true, $disabled_in_execution).html_print_input_text('once_time_from', $once_time_from, '', 9, 9, true, $disabled_in_execution).'</td>
            </tr>
            <tr>
                <td>'.__('To:').'</td>
                <td>'.html_print_input_text('once_date_to', $once_date_to, '', 10, 10, true).html_print_input_text('once_time_to', $once_time_to, '', 9, 9, true)."</td>
            </tr>
        </table>
    </div>
    <div id='periodically_time' style='display: none;'>
        <table>
            <tr><td>".ui_get_using_system_timezone_warning().'</td></tr>
            <tr>
                <td>'.__('Type Periodicity:').'&nbsp;'.html_print_select(
    [
        'weekly'  => __('Weekly'),
        'monthly' => __('Monthly'),
    ],
    'type_periodicity',
    $type_periodicity,
    'change_type_periodicity();',
    '',
    0,
    true,
    false,
    true,
    '',
    $disabled_in_execution
)."</td>
            </tr>
            <tr>
                <td colspan='2'>
                    <table id='weekly_item' style='display: none;'>
                        <tr>
                            <td>".__('Mon').html_print_checkbox('monday', 1, $monday, true, $disabled_in_execution).'</td>
                            <td>'.__('Tue').html_print_checkbox('tuesday', 1, $tuesday, true, $disabled_in_execution).'</td>
                            <td>'.__('Wed').html_print_checkbox('wednesday', 1, $wednesday, true, $disabled_in_execution).'</td>
                            <td>'.__('Thu').html_print_checkbox('thursday', 1, $thursday, true, $disabled_in_execution).'</td>
                            <td>'.__('Fri').html_print_checkbox('friday', 1, $friday, true, $disabled_in_execution).'</td>
                            <td>'.__('Sat').html_print_checkbox('saturday', 1, $saturday, true, $disabled_in_execution).'</td>
                            <td>'.__('Sun').html_print_checkbox('sunday', 1, $sunday, true, $disabled_in_execution)."</td>
                        </tr>
                    </table>
                    <table id='monthly_item' style='display: none;'>
                        <tr>
                            <td>".__('From day:').'</td>
                            <td>'.html_print_select(
    $days,
    'periodically_day_from',
    $periodically_day_from,
    '',
    '',
    0,
    true,
    false,
    true,
    '',
    $disabled_in_execution
).'</td>
                            <td>'.__('To day:').'</td>
                            <td>'.html_print_select(
    $days,
    'periodically_day_to',
    $periodically_day_to,
    '',
    '',
    0,
    true,
    false,
    true,
    '',
    $disabled_in_execution
).'</td>
                            <td>'.ui_print_help_tip(__('The end day must be higher than the start day'), true).'</td>
                        </tr>
                    </table>
                    <table>
                        <tr>
                            <td>'.__('From hour:').'</td>
                            <td>'.html_print_input_text(
    'periodically_time_from',
    $periodically_time_from,
    '',
    7,
    7,
    true,
    $disabled_in_execution
).ui_print_help_tip(
    __('The end time must be higher than the start time'),
    true
).'</td>
                            <td>'.__('To hour:').'</td>
                            <td>'.html_print_input_text(
    'periodically_time_to',
    $periodically_time_to,
    '',
    7,
    7,
    true,
    $disabled_in_execution
).ui_print_help_tip(
    __('The end time must be higher than the start time'),
    true
).'</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>';

if ($id_downtime > 0) {
    echo "<form method=post action='index.php?sec=extensions&sec2=godmode/agentes/planned_downtime.editor&insert_downtime_agent=1&id_downtime=$id_downtime'>";
} else {
    echo '<form method="POST" action="index.php?sec=extensions&amp;sec2=godmode/agentes/planned_downtime.editor">';
}

// Editor form.
html_print_table($table);

echo "<td valign=top style='width:300px;padding-left:20px;'>";

$filter_group = (int) get_parameter('filter_group', 0);

// User AD groups to str for the filter.
$id_groups_str = implode(',', $user_groups_ad);

if (empty($id_groups_str)) {
    // Restrictive filter on error. This will filter all the downtimes.
    $id_groups_str = '-1';
}

$filter_cond = '';
if ($filter_group > 0) {
    if ($recursion) {
        $rg = groups_get_children_ids($filter_group, true);
        $filter_cond .= ' AND id_grupo IN (';

        $i = 0;
        $len = count($rg);

        foreach ($rg as $key) {
            if ($i == ($len - 1)) {
                $filter_cond .= $key.')';
            } else {
                $i++;
                $filter_cond .= $key.',';
            }
        }
    } else {
        $filter_cond = " AND id_grupo = $filter_group ";
    }
}

$agents = get_planned_downtime_agents_list($id_downtime, $filter_cond, $id_groups_str);

$disabled_add_button = false;
if (empty($agents) || $disabled_in_execution) {
    $disabled_add_button = true;
}

// Show available agents to include into downtime
$table = new StdClass();
$table->class = 'databox filters';
$table->width = '100%';
$table->data = [];

$table->data[0][0] = __('Group filter');
$table->data[0][1] = html_print_select_groups(false, $access, true, 'filter_group', $filter_group, '', '', '', true, false, true, '', false, 'min-width:180px;margin-right:15px;');
$table->data[0][2] = __('Recursion').'&nbsp&nbsp'.html_print_checkbox('recursion', 1, $recursion, true, false, '');

$table->data[1][0] = __('Available agents');
$table->data[1][1] = html_print_select($agents, 'id_agents[]', -1, '', _('Any'), -2, true, true, true, '', false, 'width: 180px;');


if ($type_downtime != 'quiet') {
    echo '<div id="available_modules_selection_mode" style="padding-top:20px;display: none;">';
} else {
    echo '<div id="available_modules_selection_mode" style="padding-top:20px">';
}

$table->data[2][1] = html_print_select(
    [
        'common' => __('Show common modules'),
        'all'    => __('Show all modules'),
    ],
    'modules_selection_mode',
    'common',
    false,
    '',
    '',
    true,
    false,
    true,
    '',
    false,
    'min-width:180px;'
);

echo '</div>';

$table->data[3][0] = __('Available modules:').ui_print_help_tip(
    __('Only for type Quiet for downtimes.'),
    true
);

if ($type_downtime != 'quiet') {
    echo '<div id="available_modules" style="display: none;">';
} else {
    echo '<div id="available_modules" style="">';
}

$table->data[3][1] = html_print_select(
    [],
    'module[]',
    '',
    '',
    '',
    0,
    true,
    true,
    true,
    '',
    false,
    'width: 180px;'
);
echo '</div>';

// Print agent table.
html_print_table($table);

echo '<br /><br /><br />';

html_print_input_hidden('id_agent', $id_agent);
echo '<div class="action-buttons w100p" >';
if ($id_downtime > 0) {
    html_print_input_hidden('update_downtime', 1);
    html_print_input_hidden('id_downtime', $id_downtime);
    html_print_submit_button(
        __('Update'),
        'updbutton',
        false,
        'class="sub upd"'
    );
} else {
    html_print_input_hidden('create_downtime', 1);
    html_print_submit_button(
        __('Add'),
        'crtbutton',
        false,
        'class="sub wand"'
    );
}

echo '</div>';
html_print_input_hidden('all_agents', implode(',', array_keys($agents)));
html_print_input_hidden('all_common_modules', '');
echo '</form>';

// Start Overview of existing planned downtime.
echo '<h4>'.__('Agents planned for this downtime').':</h4>';

// User the $id_groups_str built before.
$sql = sprintf(
    'SELECT ta.nombre, tpda.id,
                    ta.id_os, ta.id_agente, ta.id_grupo,
                    ta.ultimo_contacto, tpda.all_modules
                FROM tagente ta
                INNER JOIN tplanned_downtime_agents tpda
                    ON ta.id_agente = tpda.id_agent
                        AND tpda.id_downtime = %d
                WHERE ta.id_grupo IN (%s)',
    $id_downtime,
    $id_groups_str
);
$downtimes_agents = db_get_all_rows_sql($sql);

if (empty($downtimes_agents)) {
    echo '<div class="nf">'.__('There are no agents').'</div>';
} else {
    $table = new stdClass();
    $table->id = 'list';
    $table->class = 'databox data';
    $table->width = '100%';
    $table->data = [];
    $table->head = [];
    $table->head[0] = __('Name');
    $table->head[1] = __('Group');
    $table->head[2] = __('OS');
    $table->head[3] = __('Last contact');
    $table->head['count_modules'] = __('Modules');

    if (!$running) {
        $table->head[5] = __('Actions');
        $table->align[5] = 'center';
        $table->size[5] = '5%';
    }

    foreach ($downtimes_agents as $downtime_agent) {
        $data = [];

        $alias = db_get_value(
            'alias',
            'tagente',
            'id_agente',
            $downtime_agent['id_agente']
        );
        $data[0] = $alias;

        $data[1] = db_get_sql(
            'SELECT nombre
            FROM tgrupo
            WHERE id_grupo = '.$downtime_agent['id_grupo']
        );

        $data[2] = ui_print_os_icon($downtime_agent['id_os'], true, true);

        $data[3] = $downtime_agent['ultimo_contacto'];

        if ($type_downtime == 'disable_agents_alerts') {
            $data['count_modules'] = __('All alerts');
        } else if ($type_downtime == 'disable_agents') {
            $data['count_modules'] = __('Entire agent');
        } else {
            if ($downtime_agent['all_modules']) {
                $data['count_modules'] = __('All modules');
            } else {
                $data['count_modules'] = __('Some modules');
            }
        }

        if (!$running) {
            $data[5] = '';
            if ($type_downtime != 'disable_agents_alerts'
                && $type_downtime != 'disable_agents'
            ) {
                $data[5] = '<a href="javascript:show_editor_module('.$downtime_agent['id_agente'].');">'.html_print_image('images/config.png', true, ['border' => '0', 'alt' => __('Delete'), 'class' => 'invert_filter']).'</a>';
            }

            $data[5] .= '<a href="index.php?sec=extensions&amp;sec2=godmode/agentes/planned_downtime.editor&id_agent='.$downtime_agent['id_agente'].'&delete_downtime_agent=1&id_downtime_agent='.$downtime_agent['id'].'&id_downtime='.$id_downtime.'">'.html_print_image('images/cross.png', true, ['border' => '0', 'alt' => __('Delete'), 'class' => 'invert_filter']).'</a>';
        }

        $table->data['agent_'.$downtime_agent['id_agente']] = $data;
    }

    html_print_table($table);
}

$table = new stdClass();
$table->id = 'loading';
$table->width = '100%';
$table->colspan['loading'][0] = '6';
$table->style[0] = 'text-align: center;';
$table->data = [];
$table->data['loading'] = [];
$table->data['loading'][0] = html_print_image('images/spinner.gif', true);
echo "<div class='invisible'>";
html_print_table($table);
echo '</div>';

$table = new stdClass();
$table->id = 'editor';
$table->width = '100%';
$table->colspan['module'][1] = '5';
$table->data = [];
$table->data['module'] = [];
$table->data['module'][0] = '';
$table->data['module'][1] = '<h4>'.__('Modules').'</h4>';

// List of modules, empty, it is populated by javascript.
$table->data['module'][1] = "
    <table cellspacing='4' cellpadding='4' border='0' width='100%'
        id='modules_in_agent' class='databox_color'>
        <thead>
            <tr>
                <th scope='col' class='header c0'>".__('Module')."</th>
                <th scope='col' class='header c1'>".__('Action')."</th>
            </tr>
        </thead>
        <tbody>
            <tr class='datos' id='template' style='display: none;'>
                <td class='name_module' style=''></td>
                <td class='cell_delete_button' style='text-align: right; width:10%;' id=''>".'<a class="link_delete"
                        onclick="if(!confirm(\''.__('Are you sure?').'\')) return false;"
                        href="">'.html_print_image(
    'images/cross.png',
    true,
    [
        'border' => '0',
        'alt'    => __('Delete'),
        'class'  => 'invert_filter',
    ]
).'</a>'."</td>
            </tr>
            <tr class='datos2' id='add_modules_row'>
                <td class='datos2' style='' id=''>".__('Add Module:').'&nbsp;'.html_print_select(
    [],
    'modules',
    '',
    '',
    '',
    0,
    true
)."</td>
                <td class='datos2 button_cell' style='text-align: right; width:10%;' id=''>".'<div id="add_button_div">'.'<a class="add_button" href="">'.html_print_image(
    'images/add.png',
    true,
    [
        'border' => '0',
        'alt'    => __('Add'),
        'class'  => 'invert_filter',
    ]
).'</a></div>'."<div id='spinner_add' style='display: none;'>".html_print_image('images/spinner.gif', true).'</div></td>
            </tr>
        </tbody></table>';

echo "<div class='invisible'>";
html_print_table($table);
echo '</div>';

echo "<div class='invisible'>";
echo "<div id='spinner_template'>";
html_print_image('images/spinner.gif');
echo '</div>';
echo '</div>';

echo "<div id='some_modules_text' class='invisible'>";
echo __('Some modules');
echo '</div>';

echo "<div id='some_modules_text' class='invisible'>";
echo __('Some modules');
echo '</div>';

echo "<div id='all_modules_text' class='invisible'>";
echo __('All modules');
echo '</div>';

ui_include_time_picker();
ui_require_jquery_file('ui.datepicker-'.get_user_language(), 'include/javascript/i18n/');

// Auxiliary function for downtime agent creation.
function insert_downtime_agent($id_downtime, $user_groups_ad)
{
    // Check AD permission on downtime.
    $downtime_group = db_get_value(
        'id_group',
        'tplanned_downtime',
        'id',
        $id_downtime
    );

    if ($downtime_group === false
        || !in_array($downtime_group, $user_groups_ad)
    ) {
        db_pandora_audit(
            'ACL Violation',
            'Trying to access downtime scheduler'
        );
        include 'general/noaccess.php';
        return;
    }

    $agents = (array) get_parameter('id_agents');
    $module_names = (array) get_parameter('module');
    $modules_selection_mode = (string) get_parameter('modules_selection_mode');

    $all_modules = ($modules_selection_mode === 'all' && (empty($module_names) || (string) $module_names[0] === '0'));
    $all_common_modules = ($modules_selection_mode === 'common' && (empty($module_names) || (string) $module_names[0] === '0'));

    if ($all_common_modules === true) {
        $module_names = explode(',', get_parameter('all_common_modules'));
    }

    // 'Is running' check.
    $is_running = (bool) db_get_value(
        'executed',
        'tplanned_downtime',
        'id',
        $id_downtime
    );
    if ($is_running) {
        ui_print_error_message(
            __('This elements cannot be modified while the downtime is being executed')
        );
    } else {
        // If is selected 'Any', get all the agents.
        if (count($agents) === 1 && (int) $agents[0] === -2) {
            $all_agents = get_parameter('all_agents');
            $agents = explode(',', $all_agents);
        }

        foreach ($agents as $agent_id) {
            // Check module belongs to the agent.
            if ($modules_selection_mode == 'all' && $all_modules === false) {
                $check = false;
                foreach ($module_names as $module_name) {
                    $check_module = modules_get_agentmodule_id(
                        $module_name,
                        $agent_id
                    );
                    if (!empty($check_module)) {
                        $check = true;
                    }
                }

                if (!$check) {
                    continue;
                }
            }

            // Check AD permission on agent.
            $agent_group = db_get_value(
                'id_grupo',
                'tagente',
                'id_agente',
                $agent_id
            );

            if ($agent_group === false
                || !in_array($agent_group, $user_groups_ad)
            ) {
                continue;
            }

            $values = [
                'id_downtime' => $id_downtime,
                'id_agent'    => $agent_id,
                'all_modules' => $all_modules,
            ];
            $result = db_process_sql_insert(
                'tplanned_downtime_agents',
                $values
            );

            if ($result && !$all_modules) {
                foreach ($module_names as $module_name) {
                    $module = modules_get_agentmodule_id(
                        $module_name,
                        $agent_id
                    );

                    if (empty($module)) {
                        continue;
                    }

                    $values = [
                        'id_downtime'     => $id_downtime,
                        'id_agent'        => $agent_id,
                        'id_agent_module' => $module['id_agente_modulo'],
                    ];
                    $result = db_process_sql_insert(
                        'tplanned_downtime_modules',
                        $values
                    );

                    if ($result) {
                        $values = ['id_user' => $config['id_user']];
                        $result = db_process_sql_update(
                            'tplanned_downtime',
                            $values,
                            ['id' => $id_downtime]
                        );
                    }
                }
            }
        }
    }
}


?>
<script language="javascript" type="text/javascript">
    var id_downtime = <?php echo $id_downtime; ?>;
    var action_in_progress = false;
    var recursion = false;
    
    function change_type_downtime() {
        switch ($("#type_downtime").val()) {
            case 'disable_agents_alerts':
            case 'disable_agents':
                $("#available_modules").hide();
                $("#available_modules_selection_mode").hide();
                break;
            case 'quiet':
                $("#available_modules_selection_mode").show();
                $("#available_modules").show();
                break;
        }
    }
    
    function change_type_execution() {
        switch ($("#type_execution").val()) {
            case 'once':
                $("#periodically_time").hide();
                $("#once_time").show();
                break;
            case 'periodically':
                $("#once_time").hide();
                $("#periodically_time").show();
                break;
        }
    }
    
    function change_type_periodicity() {
        switch ($("#type_periodicity").val()) {
            case 'weekly':
                $("#monthly_item").hide();
                $("#weekly_item").show();
                break;
            case 'monthly':
                $("#weekly_item").hide();
                $("#monthly_item").show();
                break;
        }
    }
    
    function show_executing_alert () {
        alert("<?php echo __('This elements cannot be modified while the downtime is being executed'); ?>");
    }
    
    function show_editor_module(id_agent) {
        //Avoid freak states.
        if (action_in_progress)
            return;
        
        //Check if the row editor module exists 
        if ($('#loading_' + id_agent).length > 0) {
            //The row exists
            $('#loading_' + id_agent).remove();
        }
        else {
            if ($('#module_editor_' + id_agent).length == 0) {
                $("#list-agent_" + id_agent).after(
                    $("#loading-loading").clone().attr('id', 'loading_' + id_agent));
                
                jQuery.post ('ajax.php', 
                    {"page": "include/ajax/planned_downtime.ajax",
                    "get_modules_downtime": 1,
                    "id_agent": id_agent,
                    "id_downtime": id_downtime
                    },
                    function (data) {
                        if (data['correct']) {
                            //Check if the row editor module exists 
                            if ($('#loading_' + id_agent).length > 0) {
                                //The row exists
                                $('#loading_' + id_agent).remove();
                                
                                $("#list-agent_" + id_agent).after(
                                    $("#editor-module").clone()
                                        .attr('id', 'module_editor_' + id_agent)
                                        .hide());
                                
                                fill_row_editor(id_agent, data);
                            }
                        }
                    },
                    "json"
                );
            }
            else {
                if ($('#module_editor_' + id_agent).is(':visible')) {
                    $('#module_editor_' + id_agent).hide();
                }
                else {
                    $('#module_editor_' + id_agent).css('display', '');
                }
            }
        }
    }
    
    function fill_row_editor(id_agent, data) {
        //$("#modules", $('#module_editor_' + id_agent)).empty();
        
        //Fill the select for to add modules
        $.each(data['in_agent'], function(id_module, name) {
            $("#modules", $('#module_editor_' + id_agent))
                .append($("<option value='" + id_module + "'>" + name + "</option>"));
        });
        $(".add_button", $('#module_editor_' + id_agent)).
            attr('href', 'javascript:' +
                'add_module_in_downtime(' + id_agent + ')');
        
        
        //Fill the list of modules
        $.each(data['in_downtime'], function(id_module, name) {
            var template_row = $("#template").clone();
            
            $(template_row).css('display', '');
            $(template_row).attr('id', 'row_module_in_downtime_' + id_module);
            $(".name_module", template_row).html(name);
            $(".link_delete", template_row).attr('href',
                'javascript:' +
                'delete_module_from_downtime(' + id_downtime + ',' + id_module + ');');
            
            $("#add_modules_row", $('#module_editor_' + id_agent))
                .before(template_row);
        });
        
        //.show() is crap, because put a 'display: block;'.
        $('#module_editor_' + id_agent).css('display', '');
    }
    
    function add_row_module(id_downtime, id_agent, id_module, name) {
        var template_row = $("#template").clone();
        
        $(template_row).css('display', '');
        $(template_row).attr('id', 'row_module_in_downtime_' + id_module);
        $(".name_module", template_row).html(name);
        $(".link_delete", template_row).attr('href',
            'javascript:' +
            'delete_module_from_downtime(' + id_downtime + ',' + id_module + ');');
        
        $("#add_modules_row", $('#module_editor_' + id_agent))
            .before(template_row);
        
    }
    
    function fill_selectbox_modules(id_downtime, id_agent) {
        jQuery.post ('ajax.php', 
            {"page": "include/ajax/planned_downtime.ajax",
                "get_modules_downtime": 1,
                "id_agent": id_agent,
                "id_downtime": id_downtime,
                "none_value": 1
            },
            function (data) {
                if (data['correct']) {
                    $("#modules", $('#module_editor_' + id_agent)).empty();
                    
                    //Fill the select for to add modules
                    $.each(data['in_agent'], function(id_module, name) {
                        $("#modules", $('#module_editor_' + id_agent))
                            .append($("<option value='" + id_module + "'>" + name + "</option>"));
                    });
                    
                    $("#modules", $('#module_editor_' + id_agent)).val(0);
                }
            },
            "json"
        );
    }
    
    function add_module_in_downtime(id_agent) {
        var module_sel = $("#modules", $('#module_editor_' + id_agent)).val();
        
        if (module_sel == 0) {
            alert("<?php echo __('Please select a module.'); ?>");
        }
        else {
            action_in_progress = true;
            
            $("#add_button_div", $('#module_editor_' + id_agent)).toggle();
            $("#spinner_add", $('#module_editor_' + id_agent)).toggle();
            
            jQuery.post ('ajax.php', 
                {"page": "include/ajax/planned_downtime.ajax",
                    "add_module_into_downtime": 1,
                    "id_agent": id_agent,
                    "id_module": module_sel,
                    "id_downtime": id_downtime
                },
                function (data) {
                    if (data['correct']) {
                        $("#list-agent_"
                            + id_agent
                            + '-count_modules').html(
                                $("#some_modules_text").html());
                        
                        add_row_module(id_downtime, id_agent,
                            module_sel, data['name']);
                        fill_selectbox_modules(id_downtime, id_agent);
                        
                        
                        $("#add_button_div", $('#module_editor_' + id_agent))
                            .toggle();
                        $("#spinner_add", $('#module_editor_' + id_agent))
                            .toggle();
                    }
                    else if (data['executed']) {
                        show_executing_alert();
                    }
                    
                    action_in_progress = false;
                },
                "json"
            );
        }
    }
    
    function delete_module_from_downtime(id_downtime, id_module) {
        var spinner = $("#spinner_template").clone();
        var old_cell_content =
            $(".cell_delete_button", "#row_module_in_downtime_" + id_module)
            .clone(true);
        
        $(".cell_delete_button", "#row_module_in_downtime_" + id_module)
            .html(spinner);
        
        action_in_progress = true;
        
        jQuery.post ('ajax.php', 
            {"page": "include/ajax/planned_downtime.ajax",
            "delete_module_from_downtime": 1,
            "id_downtime": id_downtime,
            "id_module": id_module
            },
            function (data) {
                if (data['correct']) {
                    fill_selectbox_modules(id_downtime, data['id_agent']);
                    
                    $("#row_module_in_downtime_" + id_module).remove();
                    
                    if (data['all_modules']) {
                        $("#list-agent_"
                            + data['id_agent']
                            + '-count_modules').html(
                                $("#all_modules_text").html());
                    }
                }
                else if (data['executed']) {
                    show_executing_alert();
                }
                else {
                    $(".cell_delete_button", "#row_module_in_downtime_" + id_module)
                        .html($(old_cell_content));
                }
                
                action_in_progress = false;
            },
            "json"
        );
    }
    
    $(document).ready (function () {
        populate_agents_selector();

        $("#id_agents").change(agent_changed_by_multiple_agents);
        $("#modules_selection_mode").change(agent_changed_by_multiple_agents);
        
        change_type_downtime();
        change_type_execution();
        change_type_periodicity();
        
        $("#text-periodically_time_from, #text-periodically_time_to, #text-once_time_from, #text-once_time_to").timepicker({
            showSecond: true,
            timeFormat: '<?php echo TIME_FORMAT_JS; ?>',
            timeOnlyTitle: '<?php echo __('Choose time'); ?>',
            timeText: '<?php echo __('Time'); ?>',
            hourText: '<?php echo __('Hour'); ?>',
            minuteText: '<?php echo __('Minute'); ?>',
            secondText: '<?php echo __('Second'); ?>',
            currentText: '<?php echo __('Now'); ?>',
            closeText: '<?php echo __('Close'); ?>'});
        $("#text-once_date_from, #text-once_date_to").datepicker({dateFormat: "<?php echo DATE_FORMAT_JS; ?>", showButtonPanel: true});
        
        $.datepicker.setDefaults($.datepicker.regional[ "<?php echo get_user_language(); ?>"]);
        
        
        $("#filter_group").click (function () {
            $(this).css ("width", "auto");
        });
        
        $("#filter_group").blur (function () {
            $(this).css ("width", "180px");
        });
        
        $("#id_agent").click (function () {
            $(this).css ("width", "auto");
        });
        
        $("#id_agent").blur (function () {
            $(this).css ("width", "180px");
        });

        // Warning message about the problems caused updating a past planned downtime
        var type_execution = "<?php echo $type_execution; ?>";
        var datetime_from = <?php echo json_encode(strtotime($once_date_from.' '.$once_time_from)); ?>;
        var datetime_now = <?php echo json_encode($utimestamp); ?>;
        var create = <?php echo json_encode($create); ?>;
        if (!create && (type_execution == 'periodically' || (type_execution == 'once' && datetime_from < datetime_now))) {
            $("input#submit-updbutton, input#submit-add_item, table#list a").click(function (e) {
                if (!confirm("<?php echo __('WARNING: If you edit this planned downtime, the data of future SLA reports may be altered'); ?>")) {
                    e.preventDefault();
                }
            });
        }
        // Disable datepickers when it has readonly attribute
        $('input.hasDatepicker[readonly]').disable();

        $("#checkbox-recursion").click(function() {
            recursion = this.checked;
            $("#filter_group").trigger("change");
        });

        // Change agent selector based on group.
        $("#filter_group").change(function() {
            populate_agents_selector();
        });

        function populate_agents_selector() {
            jQuery.post ("ajax.php",
                {"page": "operation/agentes/ver_agente",
                    "get_agents_group_json": 1,
                    "id_group": $("#filter_group").val(),
                    "privilege": "AW",
                    "keys_prefix": "_",
                    "recursion": recursion,
                },
                function (data, status) {
                    $("#id_agents").empty();
                    $("#module").html('');

                    option_any = $("<option></option>")
                        .attr ("value", -2)
                        .html ("Any");
                    $("#id_agents").append (option_any);

                    jQuery.each (data, function (id, value) {
                        // Remove keys_prefix from the index
                        id = id.substring(1);

                        option = $("<option></option>")
                            .attr ("value", value["id_agente"])
                            .html (value["alias"]);
                        $("#id_agents").append (option);
                    });
                },
                "json"
            );
        }  
    });
</script>
