<?php
/**
 * Combined graph
 *
 * @category   Combined graph
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

require_once 'include/functions_custom_graphs.php';

if (is_ajax() === true) {
    $search_agents = (bool) get_parameter('search_agents', false);

    if ($search_agents === true) {
        include_once 'include/functions_agents.php';

        $id_agent = (int) get_parameter('id_agent');
        $string = (string) get_parameter('q');
        // Q is what autocomplete plugin gives.
        $id_group = (int) get_parameter('id_group');

        $filter = [];
        $filter[] = '(nombre COLLATE utf8_general_ci LIKE "%'.$string.'%" OR direccion LIKE "%'.$string.'%" OR comentarios LIKE "%'.$string.'%")';
        $filter['id_grupo'] = $id_group;

        $agents = agents_get_agents($filter, ['nombre', 'direccion']);
        if ($agents === false) {
            return;
        }

        foreach ($agents as $agent) {
            echo $agent['nombre'].'|'.$agent['direccion']."\n";
        }

        return;
    }

    return;
}

check_login();

if (! check_acl($config['id_user'], 0, 'RW')
    && ! check_acl($config['id_user'], 0, 'RM')
) {
    db_pandora_audit(
        'ACL Violation',
        'Trying to access graph builder'
    );
    include 'general/noaccess.php';
    exit;
}

if ($edit_graph) {
    $graphInTgraph = db_get_row_sql(
        'SELECT * FROM tgraph WHERE id_graph = '.$id_graph
    );
    $stacked = $graphInTgraph['stacked'];
    $period = $graphInTgraph['period'];
    $id_group = $graphInTgraph['id_group'];
    $check = false;
    $percentil = $graphInTgraph['percentil'];
    $summatory_series = $graphInTgraph['summatory_series'];
    $average_series = $graphInTgraph['average_series'];
    $modules_series = $graphInTgraph['modules_series'];
    $fullscale = $graphInTgraph['fullscale'];

    if ($stacked == CUSTOM_GRAPH_BULLET_CHART_THRESHOLD) {
        $stacked = CUSTOM_GRAPH_BULLET_CHART;
        $check = true;
    }
} else {
    $id_agent = 0;
    $id_module = 0;
    $id_group = null;
    $period = SECONDS_1DAY;
    $factor = 1;
    $stacked = 4;
    $check = false;
    $percentil = 0;
    $summatory_series = 0;
    $average_series = 0;
    $modules_series = 0;
    if ($config['full_scale_option'] == 1) {
        $fullscale = 1;
    } else {
        $fullscale = 0;
    }
}

// -----------------------
// CREATE/EDIT GRAPH FORM
// -----------------------
$url = 'index.php?sec=reporting&sec2=godmode/reporting/graph_builder';
if ($edit_graph) {
    $output = "<form method='post' action='".$url.'&edit_graph=1&update_graph=1&id='.$id_graph."'>";
} else {
    $output = "<form method='post' action='".$url."&edit_graph=1&add_graph=1'>";
}

$output .= "<table width='100%' cellpadding=4 cellspacing=4 class='databox filters'>";
$output .= '<tr>';
$output .= "<td class='datos'><b>".__('Name').'</b></td>';
$output .= "<td class='datos'><input type='text' name='name' size='25' ";
if ($edit_graph) {
    $output .= "value='".$graphInTgraph['name']."' ";
}

$output .= '>';

$own_info = get_user_info($config['id_user']);

$return_all_group = true;

if (users_can_manage_group_all('RW') === false
    && users_can_manage_group_all('RM') === false
) {
    $return_all_group = false;
}

$output .= '<td><b>'.__('Group').'</b></td><td>';
if (check_acl($config['id_user'], 0, 'RW')) {
    $output .= html_print_input(
        [
            'type'           => 'select_groups',
            'id_user'        => $config['id_user'],
            'privilege'      => 'RW',
            'returnAllGroup' => $return_all_group,
            'name'           => 'graph_id_group',
            'selected'       => $id_group,
            'script'         => '',
            'nothing'        => '',
            'nothing_value'  => '',
            'return'         => true,
            'required'       => true,
        ]
    );
} else if (check_acl($config['id_user'], 0, 'RM')) {
    $output .= html_print_input(
        [
            'type'           => 'select_groups',
            'id_user'        => $config['id_user'],
            'privilege'      => 'RM',
            'returnAllGroup' => $return_all_group,
            'name'           => 'graph_id_group',
            'selected'       => $id_group,
            'script'         => '',
            'nothing'        => '',
            'nothing_value'  => '',
            'return'         => true,
            'required'       => true,
        ]
    );
}

$output .= '</td></tr>';
$output .= '<tr>';
$output .= "<td class='datos2'><b>".__('Description').'</b></td>';
$output .= "<td class='datos2' colspan=3><textarea name='description' class='height_45px' cols=55 rows=2>";
if ($edit_graph) {
    $output .= $graphInTgraph['description'];
}

$output .= '</textarea>';
$output .= '</td></tr>';
if ($stacked == CUSTOM_GRAPH_GAUGE) {
    $hidden = ' class="invisible" ';
} else {
    $hidden = '';
}

$output .= '<tr>';
$output .= "<td class='datos'>";
$output .= '<b>'.__('Period').'</b></td>';
$output .= "<td class='datos'>";
$output .= html_print_extended_select_for_time(
    'period',
    $period,
    '',
    '',
    '0',
    10,
    true
);
$output .= "</td><td class='datos2'>";
$output .= '<b>'.__('Type of graph').'</b></td>';
$output .= "<td class='datos2'> <div class='left inline'>";

require_once $config['homedir'].'/include/functions_graph.php';

$stackeds = [
    CUSTOM_GRAPH_AREA         => __('Area'),
    CUSTOM_GRAPH_STACKED_AREA => __('Stacked area'),
    CUSTOM_GRAPH_LINE         => __('Line'),
    CUSTOM_GRAPH_STACKED_LINE => __('Stacked line'),
    CUSTOM_GRAPH_BULLET_CHART => __('Bullet chart'),
    CUSTOM_GRAPH_GAUGE        => __('Gauge'),
    CUSTOM_GRAPH_HBARS        => __('Horizontal bars'),
    CUSTOM_GRAPH_VBARS        => __('Vertical bars'),
    CUSTOM_GRAPH_PIE          => __('Pie'),
];
$output .= html_print_select($stackeds, 'stacked', $stacked, '', '', 0, true);

$output .= '</div></td></tr>';

$output .= '<tr>';
$output .= "<td class='datos2 thresholdDiv'><b>";
$output .= __('Equalize maximum thresholds');
$output .= '</b></td>';
$output .= "<td class='datos2 thresholdDiv'>";
$output .= html_print_checkbox(
    'threshold',
    CUSTOM_GRAPH_BULLET_CHART_THRESHOLD,
    $check,
    true,
    false,
    '',
    false
);
$output .= '</td></tr>';

$output .= "<tr><td class='datos2 sparse_graph '><b>";
$output .= __('Percentil');
$output .= '</b></td>';
$output .= "<td class='datos2 sparse_graph'>";
$output .= html_print_checkbox(
    'percentil',
    1,
    $percentil,
    true
);
$output .= '</td>';
$output .= '</tr>';

$output .= "<tr><td class='datos2 sparse_graph'><b>";
$output .= __('Add summatory series');
$output .= '</b></td>';
$output .= "<td class='datos2 sparse_graph'>";
$output .= html_print_checkbox(
    'summatory_series',
    1,
    $summatory_series,
    true
);
$output .= "</td><td class='datos2 sparse_graph'><b>";
$output .= __('Add average series');
$output .= '</b></td>';
$output .= "<td class='datos2 sparse_graph'>";
$output .= html_print_checkbox(
    'average_series',
    1,
    $average_series,
    true
);
$output .= '</td></tr>';
$output .= "<tr><td class='datos2 sparse_graph'><b>";
$output .= __('Modules and series');
$output .= '</b></td>';
$output .= "<td class='datos2 sparse_graph'>";
$output .= html_print_checkbox('modules_series', 1, $modules_series, true);
$output .= '</td>';
$output .= "<td class='datos2 sparse_graph'><b>";
$output .= __('Show full scale graph (TIP)');
$output .= '</td>';
$output .= "<td class='datos2 sparse_graph'>";
$output .= html_print_checkbox('fullscale', 1, $fullscale, true);
$output .= '</td>';
$output .= '</tr>';

$output .= '</table>';

if ($edit_graph) {
    $output .= "<div class='w100p'>";
    $output .= "<input type=submit name='store' class='sub upd right' value='".__('Update')."'>";
    $output .= '</div>';
} else {
    $output .= "<div class='w100p'>";
    $output .= "<input type=submit name='store' class='sub next right' value='".__('Create')."'>";
    $output .= '</div>';
}

$output .= '</form>';

echo $output;
?>
<script type="text/javascript">
    $(document).ready(function() {
        if ($("#stacked").val() == '<?php echo CUSTOM_GRAPH_BULLET_CHART; ?>') {
            $(".thresholdDiv").show();
            $(".sparse_graph").hide();
        } else if (
            $("#stacked").val() == '<?php echo CUSTOM_GRAPH_AREA; ?>' ||
            $("#stacked").val() == '<?php echo CUSTOM_GRAPH_LINE; ?>'
        ) {
            $(".thresholdDiv").hide();
            $(".sparse_graph").show();
        } else {
            $(".thresholdDiv").hide();
            $(".sparse_graph").hide();
        }

        if( !$("#checkbox-summatory_series").is(":checked") &&
            !$("#checkbox-average_series").is(":checked")
        ){
            $("#checkbox-modules_series").attr("disabled", true);
            $("#checkbox-modules_series").attr("checked", false);
        }

        $("#stacked").change(function(){
            if ( $(this).val() == '<?php echo CUSTOM_GRAPH_BULLET_CHART; ?>') {
                $(".thresholdDiv").show();
                $(".sparse_graph").hide();
            } else if (
                $(this).val() == '<?php echo CUSTOM_GRAPH_AREA; ?>' ||
                $(this).val() == '<?php echo CUSTOM_GRAPH_LINE; ?>'
            ) {
                $(".thresholdDiv").hide();
                $(".sparse_graph").show();
            } else {
                $(".thresholdDiv").hide();
                $(".sparse_graph").hide();
            }
        });

        $("#checkbox-summatory_series").change(function() {
            if( $("#checkbox-summatory_series").is(":checked") &&
                $("#checkbox-modules_series").is(":disabled")
            ) {
                $("#checkbox-modules_series").removeAttr("disabled");
            } else if(!$("#checkbox-average_series").is(":checked")) {
                $("#checkbox-modules_series").attr("disabled", true);
                $("#checkbox-modules_series").attr("checked", false);
            }
        });

        $("#checkbox-average_series").change(function() {
            if( $("#checkbox-average_series").is(":checked") &&
                $("#checkbox-modules_series").is(":disabled")
            ) {
                $("#checkbox-modules_series").removeAttr("disabled");
            } else if(!$("#checkbox-summatory_series").is(":checked")) {
                $("#checkbox-modules_series").attr("disabled", true);
                $("#checkbox-modules_series").attr("checked", false);
            }
        });
    });
</script>
