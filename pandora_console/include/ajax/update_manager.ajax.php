<?php
/**
 * Extension to manage a list of gateways and the node address where they should
 * point to.
 *
 * @category   Update Manager Ajax
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

if (! check_acl($config['id_user'], 0, 'PM') && ! is_user_admin($config['id_user'])) {
    db_pandora_audit('ACL Violation', 'Trying to access update Management');
    include 'general/noaccess.php';
    return;
}

require_once $config['homedir'].'/include/functions_update_manager.php';
require_once $config['homedir'].'/include/functions_graph.php';
enterprise_include_once('include/functions_update_manager.php');

$upload_file = (boolean) get_parameter('upload_file');
$install_package = (boolean) get_parameter('install_package');
$check_install_package = (boolean) get_parameter('check_install_package');
$check_online_packages = (boolean) get_parameter('check_online_packages');
$check_online_enterprise_packages = (boolean) get_parameter('check_online_enterprise_packages');
$update_last_package = (boolean) get_parameter('update_last_package');
$update_last_enterprise_package = (boolean) get_parameter('update_last_enterprise_package');
$install_package_online = (boolean) get_parameter('install_package_online');
$check_progress_update = (boolean) get_parameter('check_progress_update');
$check_progress_enterprise_update = (boolean) get_parameter('check_progress_enterprise_update');
$install_package_step2 = (boolean) get_parameter('install_package_step2');
$enterprise_install_package = (boolean) get_parameter('enterprise_install_package');
$enterprise_install_package_step2 = (boolean) get_parameter('enterprise_install_package_step2');
$check_online_free_packages = (bool) get_parameter('check_online_free_packages');
$update_last_free_package = (bool) get_parameter('update_last_free_package');
$check_update_free_package = (bool) get_parameter('check_update_free_package');
$install_free_package = (bool) get_parameter('install_free_package');
$search_minor = (bool) get_parameter('search_minor');
$unzip_free_package = (bool) get_parameter('unzip_free_package');
$delete_desired_files = (bool) get_parameter('delete_desired_files');

if ($upload_file) {
    ob_clean();
    $return = [];

    if (isset($_FILES['upfile']) && $_FILES['upfile']['error'] == 0) {
        $extension = pathinfo($_FILES['upfile']['name'], PATHINFO_EXTENSION);

        // The package extension should be .oum
        if (strtolower($extension) === 'oum') {
            $path = $_FILES['upfile']['tmp_name'];
            // The package files will be saved in [user temp dir]/pandora_oum/package_name
            $destination = sys_get_temp_dir().'/pandora_oum/'.$_FILES['upfile']['name'];
            // files.txt will have the names of every file of the package
            if (file_exists($destination.'/files.txt')) {
                unlink($destination.'/files.txt');
            }

            $zip = new ZipArchive;
            // Zip open
            if ($zip->open($path) === true) {
                // The files will be extracted one by one
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filename = $zip->getNameIndex($i);

                    if ($zip->extractTo($destination, [$filename])) {
                        // Creates a file with the name of the files extracted
                        file_put_contents($destination.'/files.txt', $filename."\n", (FILE_APPEND | LOCK_EX));
                    } else {
                        // Deletes the entire extraction directory if a file can not be extracted
                        delete_directory($destination);
                        $return['status'] = 'error';
                        $return['message'] = __("There was an error extracting the file '".$filename."' from the package.");
                        echo json_encode($return);
                        return;
                    }
                }

                // Creates a file with the number of files extracted
                file_put_contents($destination.'/files.info.txt', $zip->numFiles);
                // Zip close
                $zip->close();

                $return['status'] = 'success';
                $return['package'] = $destination;
                echo json_encode($return);
                return;
            } else {
                $return['status'] = 'error';
                $return['message'] = __('The package was not extracted.');
                echo json_encode($return);
                return;
            }
        } else {
            $return['status'] = 'error';
            $return['message'] = __('Invalid extension. The package must have the extension .oum.');
            echo json_encode($return);
            return;
        }
    }

    $return['status'] = 'error';
    $return['message'] = __('The file was not uploaded succesfully.');
    echo json_encode($return);
    return;
}

if ($install_package) {
    global $config;
    ob_clean();

    $accept = (bool) get_parameter('accept', false);
    if ($accept) {
        $package = (string) get_parameter('package');
        $package = trim($package);

        $chunks = explode('_', basename($package));
        $chunks = explode('.', $chunks[1]);
        if (is_numeric($chunks[0])) {
            $version = $chunks[0];
        } else {
            $current_package = db_get_value(
                'value',
                'tconfig',
                'token',
                'current_package_enterprise'
            );
            if (!empty($current_package)) {
                $version = $current_package;
            }
        }

        // All files extracted
        $files_total = $package.'/files.txt';
        // Files copied
        $files_copied = $package.'/files.copied.txt';
        $return = [];

        if (file_exists($files_copied)) {
            unlink($files_copied);
        }


        if (file_exists($package)) {
            if ($files_h = fopen($files_total, 'r')) {
                while ($line = stream_get_line($files_h, 65535, "\n")) {
                    $line = trim($line);

                    // Tries to move the old file to the directory backup inside the extracted package
                    if (file_exists($config['homedir'].'/'.$line)) {
                        rename(
                            $config['homedir'].'/'.$line,
                            $package.'/backup/'.$line
                        );
                    }

                    // Tries to move the new file to the Pandora directory
                    $dirname = dirname($line);
                    if (!file_exists($config['homedir'].'/'.$dirname)) {
                        $dir_array = explode('/', $dirname);
                        $temp_dir = '';
                        foreach ($dir_array as $dir) {
                            $temp_dir .= '/'.$dir;
                            if (!file_exists($config['homedir'].$temp_dir)) {
                                mkdir($config['homedir'].$temp_dir);
                            }
                        }
                    }

                    if (is_dir($package.'/'.$line)) {
                        if (!file_exists($config['homedir'].'/'.$line)) {
                            mkdir($config['homedir'].'/'.$line);
                            file_put_contents($files_copied, $line."\n", (FILE_APPEND | LOCK_EX));
                        }
                    } else {
                        if (rename($package.'/'.$line, $config['homedir'].'/'.$line)) {
                            // Append the moved file to the copied files txt
                            if (!file_put_contents($files_copied, $line."\n", (FILE_APPEND | LOCK_EX))) {
                                // If the copy process fail, this code tries to restore the files backed up before
                                if ($files_copied_h = fopen($files_copied, 'r')) {
                                    while ($line_c = stream_get_line($files_copied_h, 65535, "\n")) {
                                        $line_c = trim($line_c);
                                        if (!rename($package.'/backup/'.$line, $config['homedir'].'/'.$line_c)) {
                                            $backup_status = __('Some of your files might not be recovered.');
                                        }
                                    }

                                    if (!rename($package.'/backup/'.$line, $config['homedir'].'/'.$line)) {
                                        $backup_status = __('Some of your files might not be recovered.');
                                    }

                                    fclose($files_copied_h);
                                } else {
                                    $backup_status = __('Some of your old files might not be recovered.');
                                }

                                fclose($files_h);
                                $return['status'] = 'error';
                                $return['message'] = __("Line '$line' not copied to the progress file.").'&nbsp;'.$backup_status;
                                echo json_encode($return);
                                return;
                            }
                        } else {
                            // If the copy process fail, this code tries to restore the files backed up before
                            if ($files_copied_h = fopen($files_copied, 'r')) {
                                while ($line_c = stream_get_line($files_copied_h, 65535, "\n")) {
                                    $line_c = trim($line_c);
                                    if (!rename($package.'/backup/'.$line, $config['homedir'].'/'.$line)) {
                                        $backup_status = __('Some of your old files might not be recovered.');
                                    }
                                }

                                fclose($files_copied_h);
                            } else {
                                $backup_status = __('Some of your files might not be recovered.');
                            }

                            fclose($files_h);
                            $return['status'] = 'error';
                            $return['message'] = __("File '$line' not copied.").'&nbsp;'.$backup_status;
                            echo json_encode($return);
                            return;
                        }
                    }
                }

                fclose($files_h);
            } else {
                $return['status'] = 'error';
                $return['message'] = __('An error ocurred while reading a file.');
                echo json_encode($return);
                return;
            }
        } else {
            $return['status'] = 'error';
            $return['message'] = __('The package does not exist');
            echo json_encode($return);
            return;
        }

        enterprise_hook(
            'update_manager_enterprise_set_version',
            [$version]
        );

        $product_name = io_safe_output(get_product_name());
        db_pandora_audit(
            'Update '.$product_name,
            "Update version: $version of ".$product_name.' by '.$config['id_user']
        );

        // An update have been applied, clean phantomjs cache.
        config_update_value(
            'clean_phantomjs_cache',
            1
        );

        $return['status'] = 'success';
        echo json_encode($return);
        return;
    } else {
        $return['status'] = 'error';
        $return['message'] = __('Package rejected.');
        echo json_encode($return);
        return;
    }
}

if ($check_install_package) {
    // 1 second
    // sleep(1);
    // Half second
    usleep(500000);

    ob_clean();

    $package = (string) get_parameter('package');
    // All files extracted
    $files_total = $package.'/files.txt';
    // Number of files extracted
    $files_num = $package.'/files.info.txt';
    // Files copied
    $files_copied = $package.'/files.copied.txt';

    $files = @file($files_copied);
    if (empty($files)) {
        $files = [];
    }

    $total = (int) @file_get_contents($files_num);

    $progress = 0;
    if ((count($files) > 0) && ($total > 0)) {
        $progress = format_numeric(((count($files) / $total) * 100), 2);
        if ($progress > 100) {
            $progress = 100;
        }
    }

    $return = [];
    $return['info'] = (string) implode('<br />', $files);
    $return['progress'] = $progress;

    if ($progress >= 100) {
        unlink($files_total);
        unlink($files_num);
        unlink($files_copied);
    }

    echo json_encode($return);
    return;
}

if ($check_online_enterprise_packages) {
    update_manager_check_online_enterprise_packages();

    return;
}

if ($check_online_packages) {
    return;
}

if ($update_last_enterprise_package) {
    update_manager_update_last_enterprise_package();

    return;
}

if ($update_last_package) {
    return;
}

if ($install_package_online) {
    return;
}

if ($install_package_step2) {
    update_manager_install_package_step2();

    return;
}

if ($check_progress_enterprise_update) {
    update_manager_check_progress_enterprise();

    return;
}

if ($check_progress_update) {
    return;
}

if ($enterprise_install_package) {
    $package = get_parameter('package', '');


    update_manager_enterprise_starting_update(
        $package,
        $config['attachment_store'].'/downloads/'.$package
    );

    return;
}

if ($enterprise_install_package_step2) {
    update_manager_install_enterprise_package_step2();

    return;
}

if ($check_online_free_packages) {
    update_manager_check_online_free_packages();

    return;
}

if ($search_minor) {
    $package = get_parameter('package', '');
    $ent = get_parameter('ent', false);
    $offline = get_parameter('offline', false);

    $have_minor_releases = db_check_minor_relase_available_to_um($package, $ent, $offline);

    $return['have_minor'] = false;
    if ($have_minor_releases) {
        $return['have_minor'] = true;
        $size_mr = get_number_of_mr($package, $ent, $offline);
        $return['mr'] = $size_mr;
    } else {
        $product_name = io_safe_output(get_product_name());
        $version = get_parameter('version', '');
        db_pandora_audit(
            'ERROR: Update '.$product_name,
            'Update version of '.$product_name.' by '.$config['id_user'].' has failed.'
        );
    }

    echo json_encode($return);

    return;
}

if ($update_last_free_package) {
    $package = get_parameter('package', '');
    $version = get_parameter('version', '');
    $package_url = base64_decode($package);

    $params = [
        'action'          => 'get_package',
        'license'         => $license,
        'limit_count'     => $users,
        'current_package' => $current_package,
        'package'         => $package,
        'version'         => $config['version'],
        'build'           => $config['build'],
    ];

    $curlObj = curl_init();
    curl_setopt($curlObj, CURLOPT_URL, $package_url);
    curl_setopt($curlObj, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curlObj, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curlObj, CURLOPT_SSL_VERIFYPEER, false);
    if (isset($config['update_manager_proxy_server'])) {
        curl_setopt($curlObj, CURLOPT_PROXY, $config['update_manager_proxy_server']);
    }

    if (isset($config['update_manager_proxy_port'])) {
        curl_setopt($curlObj, CURLOPT_PROXYPORT, $config['update_manager_proxy_port']);
    }

    if (isset($config['update_manager_proxy_user'])) {
        curl_setopt($curlObj, CURLOPT_PROXYUSERPWD, $config['update_manager_proxy_user'].':'.$config['update_manager_proxy_password']);
    }

    $result = curl_exec($curlObj);
    $http_status = curl_getinfo($curlObj, CURLINFO_HTTP_CODE);

    curl_close($curlObj);

    if (empty($result)) {
        echo json_encode(
            [
                'in_progress' => false,
                'message'     => __('Fail to update to the last package.'),
            ]
        );
    } else {
        file_put_contents(
            $config['attachment_store'].'/downloads/last_package.tgz',
            $result
        );

        echo json_encode(
            [
                'in_progress' => true,
                'message'     => __('Starting to update to the last package.'),
            ]
        );


        $progress_update_status = db_get_value(
            'value',
            'tconfig',
            'token',
            'progress_update_status'
        );



        if (empty($progress_update_status)) {
            db_process_sql_insert(
                'tconfig',
                [
                    'value' => 0,
                    'token' => 'progress_update',
                ]
            );

            db_process_sql_insert(
                'tconfig',
                [
                    'value' => json_encode(
                        [
                            'status'  => 'in_progress',
                            'message' => '',
                        ]
                    ),
                    'token' => 'progress_update_status',
                ]
            );
        } else {
            db_process_sql_update(
                'tconfig',
                ['value' => 0],
                ['token' => 'progress_update']
            );

            db_process_sql_update(
                'tconfig',
                [
                    'value' => json_encode(
                        [
                            'status'  => 'in_progress',
                            'message' => '',
                        ]
                    ),
                ],
                ['token' => 'progress_update_status']
            );
        }
    }

    return;
}

if ($check_update_free_package) {
    $progress_update = db_get_value(
        'value',
        'tconfig',
        'token',
        'progress_update'
    );

    $progress_update_status = db_get_value(
        'value',
        'tconfig',
        'token',
        'progress_update_status'
    );
    $progress_update_status = json_decode($progress_update_status, true);

    switch ($progress_update_status['status']) {
        case 'in_progress':
            $correct = true;
            $end = false;
        break;

        case 'fail':
            $correct = false;
            $end = false;
        break;

        case 'end':
            $correct = true;
            $end = true;
        break;
    }

    $progressbar_tag = progressbar(
        $progress_update,
        400,
        20,
        __('progress'),
        $config['fontpath']
    );
    preg_match("/src='(.*)'/", $progressbar_tag, $matches);
    $progressbar = $matches[1];

    echo json_encode(
        [
            'correct'     => $correct,
            'end'         => $end,
            'message'     => $progress_update_status['message'],
            'progressbar' => $progressbar,
        ]
    );

    return;
}

if ($unzip_free_package) {
    $version = get_parameter('version', '');

    $result = update_manager_extract_package();

    if ($result) {
        $return['correct'] = true;
        $return['message'] = __('The package is extracted.');
    } else {
        $return['correct'] = false;
        $return['message'] = __('Error in package extraction.');
    }

    echo json_encode($return);

    return;
}

if ($install_free_package) {
    $version = get_parameter('version', '');

    $install = update_manager_starting_update();

    sleep(3);

    if ($install) {
        update_manager_set_current_package($version);
        $return['status'] = 'success';
        $return['message'] = __('The package is installed.');
    } else {
        $return['status'] = 'error';
        $return['message'] = __('An error ocurred in the installation process.');
    }

    echo json_encode($return);

    return;
}


/*
 * Result info:
 * Types of status:
 * -1 -> Not exits file.
 * 0 -> File or directory deleted successfully.
 * 1 -> Problem delete file or directory.
 * 2 -> Not found file or directory.
 * 3 -> Don`t read file deleet_files.txt.
 * 4 -> "deleted" folder could not be created.
 * 5 -> "deleted" folder was created.
 * 6 -> The "delete files" could not be the "delete" folder.
 * 7 -> The "delete files" is moved to the "delete" folder.
 * Type:
 * f -> File
 * d -> Dir.
 * route: Path.
 */

if ($delete_desired_files === true) {
    global $config;

    // Initialize result.
    $result = [];
    $result['status_list'] = [];

    // Flag exist folder "deleted".
    $exist_deleted = true;

    // Route delete_files.txt.
    $route_delete_files = $config['homedir'];
    $route_delete_files .= '/extras/delete_files/delete_files.txt';

    // Route directory deleted.
    $route_dir_deleted = $config['homedir'];
    $route_dir_deleted .= '/extras/delete_files/deleted/';

    // Check isset directory deleted
    // if it does not exist, try to create it.
    if (is_dir($route_dir_deleted) === false) {
        $res_mkdir = mkdir($route_dir_deleted, 0777, true);
        $res = [];
        if ($res_mkdir !== true) {
            $exist_deleted = false;
            $res['status'] = 4;
        } else {
            $res['status'] = 5;
        }

        $res['type'] = 'd';
        $res['path'] = $url_to_delete;
        array_push($result['status_list'], $res);
    }

    // Check isset delete_files.txt.
    if (file_exists($route_delete_files) === true && $exist_deleted === true) {
        // Open file.
        $file_read = fopen($route_delete_files, 'r');
        // Check if read delete_files.txt.
        if ($file_read !== false) {
            while ($file_to_delete = stream_get_line($file_read, 65535, "\n")) {
                $file_to_delete = trim($file_to_delete);
                $url_to_delete = $config['homedir'].'/'.$file_to_delete;
                // Check is dir or file or not exists.
                if (is_dir($url_to_delete) === true) {
                    $rmdir_recursive = rmdir_recursive(
                        $url_to_delete,
                        $result['status_list']
                    );

                    array_push(
                        $result['status_list'],
                        $rmdir_recursive
                    );
                } else if (file_exists($url_to_delete) === true) {
                    $unlink = unlink($url_to_delete);
                    $res = [];
                    $res['status'] = ($unlink === true) ? 0 : 1;
                    $res['type'] = 'f';
                    $res['path'] = $url_to_delete;
                    array_push($result['status_list'], $res);
                } else {
                    $res = [];
                    $res['status'] = 2;
                    $res['path'] = $url_to_delete;
                    array_push($result['status_list'], $res);
                }
            }
        } else {
            $res = [];
            $res['status'] = 3;
            $res['path'] = $url_to_delete;
            array_push($result['status_list'], $res);
        }

        // Close file.
        fclose($route_delete_files);

        // Move delete_files.txt to dir extras/deleted/.
        $count_scandir = count(scandir($route_dir_deleted));
        $route_move = $route_dir_deleted.'/delete_files_'.$count_scandir.'.txt';
        $res_rename = rename(
            $route_delete_files,
            $route_move
        );

        $res = [];
        $res['status'] = ($res_rename === true) ? 7 : 6;
        $res['type'] = 'f';
        $res['path'] = $route_move;
        array_push($result['status_list'], $res);
    } else {
        if ($exist_deleted === true) {
            $res = [];
            $res['status'] = -1;
            array_push($result['status_list'], $res);
        }
    }

    // Translation diccionary neccesary.
    $result['translation'] = [
        'title'            => __('Delete files'),
        'not_file'         => __('The oum has no files to remove'),
        'not_found'        => __('Not found'),
        'not_deleted'      => __('Not deleted'),
        'not_read'         => __('The file delete_file.txt can not be read'),
        'folder_deleted_f' => __('\'deleted\' folder could not be created'),
        'folder_deleted_t' => __('\'deleted\' folder was created'),
        'move_file_f'      => __(
            'The "delete files" could not be the "delete" folder'
        ),
        'move_file_d'      => __(
            'The "delete files" is moved to the "delete" folder'
        ),
    ];

    echo json_encode($result);
    return;
}
