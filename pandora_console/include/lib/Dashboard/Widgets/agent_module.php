<?php
/**
 * Widget Agent module Pandora FMS Console
 *
 * @category   Console Class
 * @package    Pandora FMS
 * @subpackage Widget Agent module
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

namespace PandoraFMS\Dashboard;
use PandoraFMS\Agent;
use PandoraFMS\Module;

/**
 * Agent module Widgets.
 */
class AgentModuleWidget extends Widget
{

    /**
     * Name widget.
     *
     * @var string
     */
    protected $name;

    /**
     * Title widget.
     *
     * @var string
     */
    protected $title;

    /**
     * Page widget;
     *
     * @var string
     */
    protected $page;

    /**
     * Class name widget.
     *
     * @var [type]
     */
    protected $className;

    /**
     * Values options for each widget.
     *
     * @var [type]
     */
    protected $values;

    /**
     * Configuration required.
     *
     * @var boolean
     */
    protected $configurationRequired;

    /**
     * Error load widget.
     *
     * @var boolean
     */
    protected $loadError;

    /**
     * Width.
     *
     * @var integer
     */
    protected $width;

    /**
     * Heigth.
     *
     * @var integer
     */
    protected $height;

    /**
     * Grid Width.
     *
     * @var integer
     */
    protected $gridWidth;

    /**
     * Cell ID.
     *
     * @var integer
     */
    protected $cellId;


    /**
     * Construct.
     *
     * @param integer      $cellId      Cell ID.
     * @param integer      $dashboardId Dashboard ID.
     * @param integer      $widgetId    Widget ID.
     * @param integer|null $width       New width.
     * @param integer|null $height      New height.
     * @param integer|null $gridWidth   Grid width.
     */
    public function __construct(
        int $cellId,
        int $dashboardId=0,
        int $widgetId=0,
        ?int $width=0,
        ?int $height=0,
        ?int $gridWidth=0
    ) {
        global $config;

        include_once $config['homedir'].'/include/functions_agents.php';
        include_once $config['homedir'].'/include/functions_modules.php';

        // WARNING: Do not edit. This chunk must be in the constructor.
        parent::__construct(
            $cellId,
            $dashboardId,
            $widgetId
        );

        // Width.
        $this->width = $width;

        // Height.
        $this->height = $height;

        // Grid Width.
        $this->gridWidth = $gridWidth;

        // Cell Id.
        $this->cellId = $cellId;

        // Options.
        $this->values = $this->decoders($this->getOptionsWidget());

        // Positions.
        $this->position = $this->getPositionWidget();

        // Page.
        $this->page = basename(__FILE__);

        // ClassName.
        $class = new \ReflectionClass($this);
        $this->className = $class->getShortName();

        // Title.
        $this->title = __('Agent/Module View');

        // Name.
        if (empty($this->name) === true) {
            $this->name = 'agent_module';
        }

        // This forces at least a first configuration.
        $this->configurationRequired = false;
        if (isset($this->values['mModules']) === false) {
            $this->configurationRequired = true;
        }

        $this->overflow_scrollbars = false;
    }


    /**
     * Decoders hack for retrocompability.
     *
     * @param array $decoder Values.
     *
     * @return array Returns the values ​​with the correct key.
     */
    public function decoders(array $decoder): array
    {
        $values = [];
        // Retrieve global - common inputs.
        $values = parent::decoders($decoder);

        if (isset($decoder['mGroup']) === true) {
            $values['mGroup'] = $decoder['mGroup'];
        }

        if (isset($decoder['mRecursion']) === true) {
            $values['mRecursion'] = $decoder['mRecursion'];
        }

        if (isset($decoder['mModuleGroup']) === true) {
            $values['mModuleGroup'] = $decoder['mModuleGroup'];
        }

        if (isset($decoder['mAgents']) === true) {
            $values['mAgents'] = $decoder['mAgents'];
        }

        if (isset($decoder['mShowCommonModules']) === true) {
            $values['mShowCommonModules'] = $decoder['mShowCommonModules'];
        }

        if (isset($decoder['mModules']) === true) {
            $values['mModules'] = $decoder['mModules'];
        }

        return $values;
    }


    /**
     * Generates inputs for form (specific).
     *
     * @return array Of inputs.
     *
     * @throws Exception On error.
     */
    public function getFormInputs(): array
    {
        $values = $this->values;

        // Retrieve global - common inputs.
        $inputs = parent::getFormInputs();

        $inputs[] = [
            'label' => __('Filter modules'),
        ];

        $return_all_group = false;

        if (users_can_manage_group_all('RM') || $this->values['mGroup'] == 0) {
            $return_all_group = true;
        }

        $inputs[] = [
            'class'     => 'flex flex-row',
            'id'        => 'select_multiple_modules_filtered',
            'arguments' => [
                'type'                     => 'select_multiple_modules_filtered',
                'uniqId'                   => $this->cellId,
                'mGroup'                   => $this->values['mGroup'],
                'mRecursion'               => $this->values['mRecursion'],
                'mModuleGroup'             => $this->values['mModuleGroup'],
                'mAgents'                  => $this->values['mAgents'],
                'mShowCommonModules'       => $this->values['mShowCommonModules'],
                'mModules'                 => $this->values['mModules'],
                'mShowSelectedOtherGroups' => true,
                'mReturnAllGroup'          => $return_all_group,
                'mMetaFields'              => ((bool) is_metaconsole()),
            ],
        ];

        return $inputs;
    }


    /**
     * Get Post for widget.
     *
     * @return array
     */
    public function getPost():array
    {
        // Retrieve global - common inputs.
        $values = parent::getPost();

        $values['mGroup'] = \get_parameter(
            'filtered-module-group-'.$this->cellId
        );
        $values['mRecursion'] = \get_parameter_switch(
            'filtered-module-recursion-'.$this->cellId
        );
        $values['mModuleGroup'] = \get_parameter(
            'filtered-module-module-group-'.$this->cellId
        );
        $values['mAgents'] = \get_parameter(
            'filtered-module-agents-'.$this->cellId
        );
        $values['mShowCommonModules'] = \get_parameter(
            'filtered-module-show-common-modules-'.$this->cellId
        );
        $values['mModules'] = \get_parameter(
            'filtered-module-modules-'.$this->cellId
        );

        return $values;
    }


    /**
     * Data for draw table Agent/Modules.
     *
     * @param array $agents      Agents.
     * @param array $all_modules Modules.
     *
     * @return array
     */
    private function generateDataAgentModule(
        array $agents,
        array $all_modules
    ):array {
        $return = [];
        $cont = 0;
        $name = '';

        foreach ($all_modules as $key => $module) {
            if ($module == $name) {
                $modules_by_name[($cont - 1)]['id'][] = $key;
            } else {
                $name = $module;
                $modules_by_name[$cont]['name'] = $name;
                $modules_by_name[$cont]['id'][] = $key;
                $cont++;
            }
        }

        foreach ($agents as $agent) {
            $row = [];
            $row['agent_status'] = agents_get_status(
                $agent['id_agente'],
                true
            );
            $row['agent_name'] = $agent['nombre'];
            $row['agent_alias'] = $agent['alias'];

            $sql = sprintf(
                'SELECT id_agente_modulo, nombre
                FROM tagente_modulo
                WHERE id_agente = %d',
                $agent['id_agente']
            );

            $agent_modules = db_get_all_rows_sql($sql);

            $agent_modules = array_combine(
                array_column($agent_modules, 'id_agente_modulo'),
                array_column($agent_modules, 'nombre')
            );

            $row['modules'] = [];
            foreach ($modules_by_name as $module) {
                $row['modules'][$module['name']] = null;
                foreach ($module['id'] as $module_id) {
                    if (array_key_exists($module_id, $agent_modules) === true) {
                        $row['modules'][$module['name']] = modules_get_agentmodule_status(
                            $module_id
                        );
                        break;
                    }
                }
            }

            $return[] = $row;
        }

        return $return;
    }


    /**
     * Draw table Agent/Module.
     *
     * @param array $visualData Data for draw.
     * @param array $allModules Data for th draw.
     *
     * @return string Html output.
     */
    private function generateViewAgentModule(
        array $visualData,
        array $allModules
    ):string {
        $style = 'display:flex; width:100%; height:100%; margin: 10px;';
        $table_data = '<div style="'.$style.'">';
        $table_data .= '<table class="widget_agent_module transparent mrgn_0px" cellpadding="1" cellspacing="0" border="0">';

        if (empty($visualData) === false) {
            $table_data .= '<th>'.__('Agents').' / '.__('Modules').'</th>';

            $array_names = [];

            foreach ($allModules as $module_name) {
                $file_name = ui_print_truncate_text(
                    $module_name,
                    'module_small',
                    false,
                    true,
                    false,
                    '...'
                );
                $table_data .= '<th class="pdd_10px">'.$file_name.'</th>';
            }

            foreach ($visualData as $row) {
                $table_data .= "<tr class='height_35px'>";
                switch ($row['agent_status']) {
                    case AGENT_STATUS_ALERT_FIRED:
                        $rowcolor = COL_ALERTFIRED;
                        $textcolor = '#000';
                    break;

                    case AGENT_STATUS_CRITICAL:
                        $rowcolor = COL_CRITICAL;
                        $textcolor = '#FFF';
                    break;

                    case AGENT_STATUS_WARNING:
                        $rowcolor = COL_WARNING;
                        $textcolor = '#000';
                    break;

                    case AGENT_STATUS_NORMAL:
                        $rowcolor = COL_NORMAL;
                        $textcolor = '#FFF';
                    break;

                    case AGENT_STATUS_UNKNOWN:
                    case AGENT_STATUS_ALL:
                    default:
                        $rowcolor = COL_UNKNOWN;
                        $textcolor = '#FFF';
                    break;
                }

                $file_name = \ui_print_truncate_text(
                    $row['agent_alias'],
                    'agent_small',
                    false,
                    true,
                    false,
                    '...'
                );
                $table_data .= "<td style='background-color: ".$rowcolor.";'>";
                $table_data .= $file_name;
                $table_data .= '</td>';

                if ($row['modules'] === null) {
                    $row['modules'] = [];
                }

                foreach ($row['modules'] as $module_name => $module) {
                    if ($module === null) {
                        if (in_array($module_name, $allModules) === true) {
                            $style = 'background-color: transparent;';
                            $table_data .= "<td style='".$style."'>";
                            $table_data .= '</td>';
                        } else {
                            continue;
                        }
                    } else {
                        $style = 'text-align: center;';
                        $style .= ' background-color: transparent;';
                        $table_data .= "<td style='".$style."'>";
                        switch ($module) {
                            case AGENT_STATUS_NORMAL:
                                $table_data .= \ui_print_status_image(
                                    'module_ok.png',
                                    __(
                                        '%s in %s : NORMAL',
                                        $module_name,
                                        $row['agent_alias']
                                    ),
                                    true,
                                    [
                                        'width'  => '20px',
                                        'height' => '20px',
                                    ]
                                );
                            break;

                            case AGENT_STATUS_CRITICAL:
                                $table_data .= \ui_print_status_image(
                                    'module_critical.png',
                                    __(
                                        '%s in %s : CRITICAL',
                                        $module_name,
                                        $row['agent_alias']
                                    ),
                                    true,
                                    [
                                        'width'  => '20px',
                                        'height' => '20px',
                                    ]
                                );
                            break;

                            case AGENT_STATUS_WARNING:
                                $table_data .= \ui_print_status_image(
                                    'module_warning.png',
                                    __(
                                        '%s in %s : WARNING',
                                        $module_name,
                                        $row['agent_alias']
                                    ),
                                    true,
                                    [
                                        'width'  => '20px',
                                        'height' => '20px',
                                    ]
                                );
                            break;

                            case AGENT_STATUS_UNKNOWN:
                                $table_data .= \ui_print_status_image(
                                    'module_unknown.png',
                                    __(
                                        '%s in %s : UNKNOWN',
                                        $module_name,
                                        $row['agent_alias']
                                    ),
                                    true,
                                    [
                                        'width'  => '20px',
                                        'height' => '20px',
                                    ]
                                );
                            break;

                            case 4:
                                $table_data .= \ui_print_status_image(
                                    'module_no_data.png',
                                    __(
                                        '%s in %s : Not initialize',
                                        $module_name,
                                        $row['agent_alias']
                                    ),
                                    true,
                                    [
                                        'width'  => '20px',
                                        'height' => '20px',
                                    ]
                                );
                            break;

                            case AGENT_STATUS_ALERT_FIRED:
                            default:
                                $table_data .= \ui_print_status_image(
                                    'module_alertsfired.png',
                                    __(
                                        '%s in %s : ALERTS FIRED',
                                        $module_name,
                                        $row['agent_alias']
                                    ),
                                    true,
                                    [
                                        'width'  => '20px',
                                        'height' => '20px',
                                    ]
                                );
                            break;
                        }

                        $table_data .= '</td>';
                    }
                }

                $table_data .= '</tr>';
            }
        } else {
            $table_data .= '<tr><td>';
            $table_data .= __(
                'Please configure this widget before usage'
            );
            $table_data .= '</td></tr>';
        }

        $table_data .= '</table>';
        $table_data .= '</div>';

        return $table_data;
    }


    /**
     * Draw widget.
     *
     * @return string;
     */
    public function load()
    {
        global $config;

        $output = '';
        if (check_acl($config['id_user'], 0, 'AR') === 0) {
            $output .= '<div class="container-center">';
            $output .= ui_print_error_message(
                __('You don\'t have access'),
                '',
                true
            );
            $output .= '</div>';
            return $output;
        }

        // Extract info all modules selected.
        $target_modules = explode(',', $this->values['mModules']);
        $all_modules = Module::search(
            ['id_agente_modulo' => $target_modules]
        );
        if ($all_modules !== null) {
            $reduceAllModules = array_reduce(
                $all_modules,
                function ($carry, $item) {
                    $carry[$item->name()] = null;
                    return $carry;
                }
            );
        } else {
            $reduceAllModules = [];
        }

        $visualData = [];
        // Extract info agents selected.
        $target_agents = explode(',', $this->values['mAgents']);
        foreach ($target_agents as $agent_id) {
            try {
                $id_agente = $agent_id;
                if ((bool) is_metaconsole() === true) {
                    $tmeta_agent = db_get_row_filter(
                        'tmetaconsole_agent',
                        [ 'id_agente' => $id_agente ]
                    );

                    $id_agente = $tmeta_agent['id_tagente'];
                    $tserver = $tmeta_agent['id_tmetaconsole_setup'];

                    if (metaconsole_connect(null, $tserver) !== NOERR) {
                        continue;
                    }
                }

                $agent = new Agent((int) $id_agente);
                $visualData[$agent_id]['agent_status'] = $agent->lastStatus();
                $visualData[$agent_id]['agent_name'] = $agent->name();
                $visualData[$agent_id]['agent_alias'] = $agent->alias();

                $modules = $agent->searchModules(
                    ['id_agente_modulo' => $target_modules]
                );

                $visualData[$agent_id]['modules'] = $reduceAllModules;
                foreach ($modules as $module) {
                    if ((bool) is_metaconsole() === true) {
                        $reduceAllModules[$module->name()] = null;
                    }

                    $visualData[$agent_id]['modules'][$module->name()] = $module->getStatus()->estado();
                }

                if ((bool) is_metaconsole() === true) {
                    metaconsole_restore_db();
                }
            } catch (\Exception $e) {
                echo 'Error: ['.$agent_id.']'.$e->getMessage();
            }
        }

        ksort($reduceAllModules);
        $allModules = array_keys($reduceAllModules);
        if ($allModules === null) {
            $allModules = [];
        }

        $output = $this->generateViewAgentModule(
            $visualData,
            $allModules
        );

        return $output;
    }


    /**
     * Get description.
     *
     * @return string.
     */
    public static function getDescription()
    {
        return __('Agent/Module View');
    }


    /**
     * Get Name.
     *
     * @return string.
     */
    public static function getName()
    {
        return 'agent_module';
    }


}
