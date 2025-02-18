<?php
/**
 * Dashboards View header dashboard Pandora FMS Console
 *
 * @category   Console Class
 * @package    Pandora FMS
 * @subpackage Dashboards
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

// Button for display full screen mode.
global $config;



$queryFull = [
    'dashboardId' => $dashboardId,
    'refr'        => $refr,
    'pure'        => 1,
];
$urlFull = $url.'&'.http_build_query($queryFull);
$fullscreen['text'] = '<a id="full_screen_link" href="'.$urlFull.'">';
$fullscreen['text'] .= html_print_image(
    'images/full_screen.png',
    true,
    [
        'title' => __('Full screen mode'),
        'class' => 'invert_filter',
    ]
);
$fullscreen['text'] .= '</a>';

// Button for display normal screen mode.
$queryNormal = ['dashboardId' => $dashboardId];
$urlNormal = $url.'&'.http_build_query($queryNormal);
$normalscreen['text'] = '<a href="'.$urlNormal.'">';
$normalscreen['text'] .= html_print_image(
    'images/normal_screen.png',
    true,
    [
        'title' => __('Back to normal mode'),
        'class' => 'invert_filter',
    ]
);
$normalscreen['text'] .= '</a>';

// Button for display modal options dashboard.
$options['text'] = '<a href="#" onclick=\'';
$options['text'] .= 'show_option_dialog('.json_encode(
    [
        'title'       => __('Update Dashboard'),
        'btn_text'    => __('Ok'),
        'btn_cancel'  => __('Cancel'),
        'url'         => $ajaxController,
        'url_ajax'    => ui_get_full_url('ajax.php'),
        'dashboardId' => $dashboardId,
    ]
);
$options['text'] .= ')\'>';
$options['text'] .= html_print_image(
    'images/setup.png',
    true,
    [
        'title' => __('Options'),
        'class' => 'invert_filter',
    ]
);
$options['text'] .= '</a>';

// Button for back to list dashboards.
$back_to_dashboard_list['text'] = '<a href="'.$url.'">';
$back_to_dashboard_list['text'] .= html_print_image(
    'images/list.png',
    true,
    [
        'title' => __('Back to dashboards list'),
        'class' => 'invert_filter',
    ]
);
$back_to_dashboard_list['text'] .= '</a>';

$slides['text'] = '<a href="#" onclick=\'';
$slides['text'] .= 'formSlides('.json_encode(
    [
        'title'       => __('Slides'),
        'btn_text'    => __('Ok'),
        'btn_cancel'  => __('Cancel'),
        'url'         => $ajaxController,
        'url_ajax'    => ui_get_full_url('ajax.php'),
        'dashboardId' => $dashboardId,
    ]
);
$slides['text'] .= ')\'>';

$slides['text'] .= html_print_image(
    'images/images.png',
    true,
    [
        'title' => __('Slides mode'),
        'class' => 'invert_filter',
    ]
);
$slides['text'] .= '</a>';

// Public Url.
$queryPublic = [
    'dashboardId' => $dashboardId,
    'hash'        => $hash,
    'id_user'     => $config['id_user'],
    'pure'        => 1,
];
$publicUrl = ui_get_full_url(
    'operation/dashboard/public_dashboard.php?'.http_build_query($queryPublic)
);
$publiclink['text'] = '<a id="public_link" href="'.$publicUrl.'" target="_blank">';
$publiclink['text'] .= html_print_image(
    'images/camera_mc.png',
    true,
    [
        'title' => __('Show link to public dashboard'),
        'class' => 'invert_filter',
    ]
);
$publiclink['text'] .= '</a>';

// Refresh selector time dashboards.
if (isset($config['public_dashboard']) === true
    && (bool) $config['public_dashboard'] === true
) {
    $urlRefresh = $publicUrl;
} else {
    $queryRefresh = [
        'dashboardId' => $dashboardId,
        'pure'        => 1,
    ];
    $urlRefresh = $url.'&'.http_build_query($queryRefresh);
}

$comboRefreshCountdown['text'] = '<div class="dashboard-countdown display_in"></div>';
$comboRefresh['text'] = '<form id="refr-form" method="post" class="mrgn_top_13px" action="'.$urlRefresh.'">';
$comboRefresh['text'] .= __('Refresh').':';
$comboRefresh['text'] .= html_print_select(
    \get_refresh_time_array(),
    'refr',
    $refr,
    '',
    '',
    0,
    true,
    false,
    false,
    '',
    false,
    'margin-top: 3px;'
);
$comboRefresh['text'] .= '</form>';

// Select all dashboard view user.
$queryCombo = [
    'pure' => $config['pure'],
];
$urlCombo = $url.'&'.http_build_query($queryCombo);
$combo_dashboard['text'] = '<form id="form-select-dashboard" name="query_sel" method="post" action="'.$urlCombo.'">';
$combo_dashboard['text'] .= html_print_select(
    $dashboards,
    'dashboardId',
    $dashboardId,
    'this.form.submit();',
    '',
    0,
    true,
    false,
    true,
    '',
    false,
    ''
);
$combo_dashboard['text'] .= '</form>';

// Edit mode.
$enable_disable['text'] = html_print_checkbox_switch(
    'edit-mode',
    1,
    false,
    true
);

// New Widget.
$newWidget['text'] = '<a href="#" id="add-widget" class="invisible">';
$newWidget['text'] .= html_print_image(
    'images/add.png',
    true,
    [
        'title' => __('Add Cell'),
        'class' => 'invert_filter',
    ]
);
$newWidget['text'] .= '</a>';

if (isset($config['public_dashboard']) === true
    && (bool) $config['public_dashboard'] === true
) {
    $buttons = [
        'combo_refresh_one_dashboard' => $comboRefresh,
        'combo_refresh_countdown'     => $comboRefreshCountdown,
    ];
} else if ($config['pure']) {
    if (check_acl_restricted_all($config['id_user'], $dashboardGroup, 'RW') === 0) {
        $buttons = [
            'back_to_dashboard_list'      => $back_to_dashboard_list,
            'normalscreen'                => $normalscreen,
            'combo_refresh_one_dashboard' => $comboRefresh,
            'slides'                      => $slides,
            'combo_refresh_countdown'     => $comboRefreshCountdown,
        ];
    } else {
        if ($publicLink === true) {
            $buttons = [
                'combo_refresh_one_dashboard' => $comboRefresh,
                'combo_refresh_countdown'     => $comboRefreshCountdown,
            ];
        } else {
            $buttons = [
                'back_to_dashboard_list'      => $back_to_dashboard_list,
                'save_layout'                 => $save_layout_dashboard,
                'normalscreen'                => $normalscreen,
                'combo_refresh_one_dashboard' => $comboRefresh,
                'slides'                      => $slides,
                'options'                     => $options,
                'combo_refresh_countdown'     => $comboRefreshCountdown,
            ];
        }
    }
} else {
    if (check_acl_restricted_all($config['id_user'], $dashboardGroup, 'RW') === 0) {
        $buttons = [
            'back_to_dashboard_list' => $back_to_dashboard_list,
            'fullscreen'             => $fullscreen,
            'slides'                 => $slides,
            'public_link'            => $publiclink,
            'combo_dashboard'        => $combo_dashboard,
            'newWidget'              => $newWidget,
        ];
    } else {
        $buttons = [
            'enable_disable'         => $enable_disable,
            'back_to_dashboard_list' => $back_to_dashboard_list,
            'fullscreen'             => $fullscreen,
            'slides'                 => $slides,
            'public_link'            => $publiclink,
            'combo_dashboard'        => $combo_dashboard,
            'options'                => $options,
            'newWidget'              => $newWidget,
        ];
    }
}

if ($publicLink === false) {
    if ((bool) is_metaconsole() === true) {
        ui_meta_print_header(
            __('Dashboards').' » '.__('List'),
            false,
            $buttons
        );
    } else {
        ui_print_page_header(
            $dashboardName,
            '',
            false,
            '',
            false,
            $buttons
        );
    }
} else {
    $output = '<div id="dashboard-controls">';
    foreach ($buttons as $key => $value) {
        $output .= '<div>';
        $output .= $value['text'];
        $output .= '</div>';
    }

    $output .= '</div>';
    echo $output;
}
