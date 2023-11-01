<?php

include_once 'request.class.php';
include_once 'prompt.class.php';

define('ACTIVE_SECURITY', true);
define('NO_SECURITY', false);
define('PROJECT_STATUS_SUCCESS', 'success');
define('PROJECT_STATUS_ERROR', 'error');

define('PROJECT_UPLOAD_MODE', 'upload');
define('PROJECT_DOWNLOAD_MODE', 'download');
define('PROJECT_ACTION_MODE', 'request');

define('ACTION_GET_CONFIG', 'getConfig');
define('ACTION_GET_KEYS','get_keys');
define('ACTION_GET_PROJECT', 'download_project');
define('ACTION_UPLOAD_PROJECT', 'upload_project');
define('ACTION_LOGIN', 'login');

define('PROJECT_KEY', 'key');
define('PROJECT_NAME', 'projectName');
define('PROJECT_PATH', 'path');
define('PROJECT_REQUEST_KEY_FILE', 'file');


// projects rights
define('PROJECT_RIGHTS_STATUS_NONE', false);
define('PROJECT_RIGHTS_STATUS_GET', 1);
define('PROJECT_RIGHTS_STATUS_SEND_WITH_UPGRADE', 2);
define('PROJECT_RIGHTS_STATUS_FULL', 3);


// Projects access
define('PROJECT_FULL_PROJECTS_ACCESS', 'full');

class projectsManager {
    private $projects = Array();
    private $username;
    private $password;
    private $updateList = Array();

    private $users = Array();

    private $keyFile = 'key';

    private $projectBasePath;

    private $zipPath = '';

    private $msg = Array();

    private $serverMode = false; // if the class is use on a server or on a client

    private $isLoged = false;

    private $logToFile = false;
    private $logFile = 'projects.log';

    private $request;
    private $ServerURL = '';

    private $prompt = null;
    private $returnData = Array();

    public function __construct(string $projectBasePath = __DIR__, $zipPath = __DIR__ . '/zip', bool $logToFile = false) {
        $this->projectBasePath = $projectBasePath;
        $this->logToFile = $logToFile;
        $this->zipPath = $zipPath === false? __DIR__ . '/zip' : $zipPath;
        $this->request = new Requests();
        $this->Init();
    }
    private function Init() {
        $dirlist = [
            $this->zipPath,
            $this->projectBasePath,
            __DIR__
        ];

        $is_ok = true;

        foreach ($dirlist as $dir) {
            if (!is_dir($dir)) {
                if(!mkdir($dir, 0777, true)) {
                    $this->set_msg("Error while creating {$dir}");
                    $is_ok = false;
                    echo 1;
                }
            } elseif (!is_writable($dir)) {
                    $this->set_msg("Error : Please change {$dir} Permission");
                    $is_ok = false;
                    echo 2;
            } 
        }
        if (!$is_ok) {
            echo $this->get_msg();
            exit();
        }
        if (!is_file($this->keyFile)) {
            file_put_contents($this->keyFile, '{}');
        }
        foreach ($this->projects as $projectName => $project) {
            if (!is_dir($this->projectBasePath . DIRECTORY_SEPARATOR . $project[PROJECT_PATH])) {
                mkdir($this->projectBasePath . DIRECTORY_SEPARATOR . $project[PROJECT_PATH], 0777, true);
            }
        }

        $this->projects = $this->getKeys();
    }
    /**
     * @param string $filepath the path of the zip file
     * - SERVER SIDE USE
     */
    private function send_project($projectName) {
        $filepath = $this->zipPath . DIRECTORY_SEPARATOR . $projectName . '.zip';
        if (file_exists($filepath)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filepath));
            flush(); // Flush system output buffer
            readfile($filepath);
            exit;
        } else http_response_code(404);
    }
    /**
     * - SERVER SIDE USE
     */
    public function AddProject($projectName, $projectPath) {
        if (strstr($projectName, ' ')) {
            $this->set_msg("Project name can't contain spaces");
            return;
        }
        $local_keys = $this->getKeys();
        if($this->isproject($projectName) || isset($local_keys[$projectName])) return;
        $this->projects[$projectName] = [
            'path' => $projectPath,
            'key' => $this->randomString(10)
        ];
        file_put_contents($this->keyFile, json_encode($this->projects, JSON_PRETTY_PRINT));

        if(!is_dir($this->projectBasePath . DIRECTORY_SEPARATOR . $projectPath)) {
            mkdir($this->projectBasePath . DIRECTORY_SEPARATOR . $projectPath, 0777, true);
        }
    }
    private function zip_project($projectName) {
        if (!$this->isproject($projectName)) return;
        $result = shell_exec("cd {$this->projectBasePath} && zip -r {$this->zipPath}/{$projectName}.zip {$this->projects[$projectName]['path']}");
        if ($this->serverMode) {
            $result === null? $this->set_msg("{$projectName} : error while zipping") : $this->set_msg("{$projectName} : {$this->username} as made a zip");
        }
    }
    /**
     * @param string projectName the name of the project you selected
     * - SERVER SIDE USE
     */
    private function receive_projects(string $projectName, string $file_req_name = 'file') {

        if (!$this->isLoged || !$this->isproject($projectName)) return;
        $target_file = $this->zipPath . DIRECTORY_SEPARATOR . basename($_FILES[$file_req_name]["name"]);
        file_exists($target_file)? unlink($target_file) : null;
        if(!isset($_FILES[$file_req_name]["tmp_name"]) || $_FILES[$file_req_name]["tmp_name"] === '') {
            $this->set_return_data('Error : No file selected or file too big you may need to increase the upload_max_filesize in php.ini', PROJECT_STATUS_ERROR);
        }
        if(!move_uploaded_file($_FILES[$file_req_name]["tmp_name"], $target_file))
        $this->update_key($projectName);
    }
    private function extract_project($projectName) {
        $projectName = str_replace('/', '', $projectName);
        shell_exec("rm -rf {$this->projectBasePath}/{$this->projects[$projectName][PROJECT_PATH]}");
        mkdir("{$this->projectBasePath}/{$this->projects[$projectName][PROJECT_PATH]}", 0777, true);
        shell_exec("unzip {$this->zipPath}/{$projectName}.zip -d {$this->projectBasePath}");
    }
    /**
     * @param string $username authaurized username
     * @param string $password password
     * @param array $options options [project_name => RIGHTS_STATUS, ...]
     * - SERVER SIDE USE
     */
    public function AddUser($username, $password, $options = []) {
        $this->users[$username] = [
            'projects' => [],
            'password' => $password,
        ];
        foreach ($options as $projectName => $right) {
            $this->users[$username]['projects'][$projectName] = $right;
        }
    }
    private function RightsVerify($projectName, $needs = PROJECT_RIGHTS_STATUS_GET) {

        $data = $_POST['data'];
        $fullAccess = isset($this->users[$this->username]['projects'][PROJECT_FULL_PROJECTS_ACCESS]);

        if (!$this->isproject($projectName)) {
            $this->set_return_data(
                "Project {$projectName} doesn't exist", 
                PROJECT_STATUS_ERROR
            );
        }
        elseif (!$fullAccess && !isset($this->users[$this->username]['projects'][$projectName])) 
        { 
            // check if project exist
            $this->set_return_data(
                "You don't have the rights to do this on {$projectName} (1)", 
                PROJECT_STATUS_ERROR
            ); 
        }
        elseif ($fullAccess && $this->users[$this->username]['projects'][PROJECT_FULL_PROJECTS_ACCESS] < $needs ||
            isset($this->users[$this->username]['projects'][$projectName]) && $this->users[$this->username]['projects'][$projectName] < $needs
        ) {
            $this->set_return_data(
                "You don't have the rights to do this on {$projectName}", 
                PROJECT_STATUS_ERROR
            );
        }
        elseif($fullAccess && $this->users[$this->username]['projects'][PROJECT_FULL_PROJECTS_ACCESS] == PROJECT_RIGHTS_STATUS_SEND_WITH_UPGRADE ||
            $this->users[$this->username]['projects'][$projectName] == PROJECT_RIGHTS_STATUS_SEND_WITH_UPGRADE && 
            $needs == PROJECT_RIGHTS_STATUS_SEND_WITH_UPGRADE
        ) {
            if(!$this->Get_User_Status_to_Project($projectName, $this->username)) {
                $this->set_return_data("{$projectName} {$this->username} need to be updated first", PROJECT_STATUS_ERROR);
            }
        }
    }
    private function randomString($nbr = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $nbr; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        } return $randomString;
    }
    private function isproject($projectName) {
        if (isset($this->projects[$projectName])) return true;
        $this->set_msg("Project {$projectName} does not exist");
        return false;
    }
    /**
     * - CHECK FOR USER LOGIN
     * - SERVER SIDE USE
     */
    private function login_verify($username, $password) {
        foreach ($this->users as $uname => $uinfo) {
            if ($username == $uname && $uinfo['password'] == $password) {
                $this->username = $username;
                $this->isLoged = true;
                return true;
            }
        } $this->set_msg("error on login \nusername:'{$username}'\n password: '{$password}'" . PHP_EOL);
        return false;
    }
    /**
     * - CLIENT SIDE USE
     */
    private function set_login($username, $password) {
        $this->username = $username;
        $this->password = $password;

        $this->make_request(ACTION_LOGIN);
        if(!$this->get_return_data()) {
            return false;
        }
        $this->isLoged = true;
        $this->set_msg('Login Success');
        return true;

    }
    private function upload_project_to_server($projectName) {
        if (!$this->isproject($projectName)) return;
        $this->make_request(
            ACTION_UPLOAD_PROJECT, 
            [
                PROJECT_NAME => $projectName,
                PROJECT_KEY => $this->getKeys($projectName),
            ], 
            PROJECT_UPLOAD_MODE
        );
        if(!$this->get_return_data()) return false;
        $this->set_msg('Upload Success');
        $this->update_key($projectName, $this->get_return_data()[PROJECT_KEY]);
    }
    /**
     * @param string $action action to perform on the server
     * - CLIENT SIDE USE
     */
    private function make_request($action, $data = [], $mode = PROJECT_ACTION_MODE) {

        $defaultData = [
            'action' => $action,
            'username' => $this->username,
            'password' => $this->password,
            'data' => $data
        ];

        $defaultData = ['data' => json_encode($defaultData)];

        if($mode == PROJECT_ACTION_MODE) 
            $this->request->Post($this->ServerURL, $defaultData);

        elseif($mode == PROJECT_DOWNLOAD_MODE)
            $this->request->FileDownload(
                $this->ServerURL, 
                $this->zipPath . DIRECTORY_SEPARATOR . $data['projectName'] . '.zip',
                $defaultData
            );

        elseif($mode == PROJECT_UPLOAD_MODE)
            $this->request->FileUpload($this->ServerURL, 
                $this->zipPath . DIRECTORY_SEPARATOR . $data['projectName'] . '.zip',
                $defaultData
            );
        if($this->request->GetHttpCode() != 200){
            $this->set_msg("{$mode} Failed with code {$this->request->GetHttpCode()}");
        }
        return $this->request->GetData();
    }
    /**
     * - SERVER SIDE USE
     */
    private function set_return_data($data, $status = PROJECT_STATUS_SUCCESS) {
        $this->returnData = [
            'status' => $status,
            'data' => $data,
        ];
        die(json_encode($this->returnData));
    }
    /**
     * - CLIENT SIDE USE
     */
    private function get_return_data() {
        $data = json_decode($this->request->GetData(), true);
        if($data['status'] == PROJECT_STATUS_SUCCESS) {
            return $data['data'];
        } $this->set_msg($data['data']);
        return false;
    }
    private function check_update($keys = false) {
        $this->updateList = Array();
        $this->make_request(ACTION_GET_CONFIG);
        if(!$this->get_return_data()) return false;

        $Server_keys = $this->get_return_data();
        $local_keys = $this->getKeys();
        foreach ($Server_keys as $projectName => $info) {
            if (!isset($local_keys[$projectName])) {
                $this->updateList[] = [
                    'projectName' => $projectName,
                    'key' => $info['key'],
                    'msg' => 'New Project as been added to the server.',
                ];
            } elseif ($local_keys[$projectName]['key'] != $info['key']) {
                $this->updateList[] = [
                    'projectName' => $projectName,
                    'key' => $info['key'],
                    'msg' => 'Project has been updated on the server.',
                ];
            }
        }
    }
    /**
     * - CLIENT SIDE USE
     */
    private function make_update($updateList = false) {
        $this->set_msg('Updating Projects...');
        $this->get_msg();
        if (!$updateList) $updateList = $this->updateList;
        foreach ($updateList as $name => $info) {
            if(!$this->isproject($name)) {
                $this->set_msg("{$name} : Project Does not exist");
                continue;
            }
            if(!$this->make_request(
                ACTION_GET_PROJECT, 
                ['projectName' => $name],
                PROJECT_DOWNLOAD_MODE
            )) $this->set_msg("{$name} : Update Failed");
            else {
                if (json_decode($data = file_get_contents($this->zipPath . DIRECTORY_SEPARATOR . $name . '.zip'), true) != null) {
                    $data = json_decode($data, true);
                    $this->set_msg("{$data['data']}");
                    unlink($this->zipPath. DIRECTORY_SEPARATOR . $name . '.zip');
                } else {
                    $this->extract_project($name);
                    $this->update_key($name, $this->projects[$name][PROJECT_KEY]);
                    $this->set_msg("{$name} : Update Done");
                }
            }
            echo $this->get_msg();
        }
    }
    private function show_update() {
        if (count($this->updateList) == 0) {
            $this->set_msg('Everything is up to date.');
        } else {
            $this->set_msg('Update Available');
            foreach ($this->updateList as $update) {
                $this->set_msg("{$update['projectName']} : {$update['msg']}");
            }
        } echo $this->get_msg();
    }
    private function show_projects() {
        $text = 'Projects: [ ';
        foreach($this->projects as $name => $key) {
            $text .= "{$name}, ";
        } echo "{$text}]";
    }
    private function get_projects() {
        $projects = Array();
        foreach($this->projects as $name => $key) {
            $projects[] = $name;
        } return $projects;
    }
    private function update_key($projectName, $key = false) {
        if(!$key) $key = $this->randomString(10);
        $local_keys = $this->getKeys();
        $local_keys[$projectName][PROJECT_KEY] = $key;
        file_put_contents($this->keyFile, json_encode($local_keys, JSON_PRETTY_PRINT));
        $this->get_config();
    }
    private function getKeys($projectName = false) {
        $data = json_decode(file_get_contents($this->keyFile), true);
        if(!$projectName) return $data;
        
        return empty($data) === true? false : $data[$projectName][PROJECT_KEY];
    }
    private function set_msg($text) {
        $this->msg[] = $text;
        if ($this->serverMode) file_put_contents($this->logFile, $this->get_msg(), FILE_APPEND | LOCK_EX);
    }
    /**
     * - CLIENT SIDE USE
     */
    private function get_config() {
        if ($this->make_request(ACTION_GET_CONFIG)) {
            $config = $this->get_return_data();
            if($config === false) return false;
            $this->projects = $config;
            $keyformat = Array();
            foreach($this->projects as $name => $info) {
                $keyformat[$name] = $info['key'];
            }
        } else $this->set_msg('Config Failed to load');
    }
    private function get_msg() {
        if (count($this->msg) == 0) return '';
        $text = PHP_EOL;
        foreach ($this->msg as $message) {
            $text .= "{$message}" . PHP_EOL;
        } 
        $this->msg = Array();
        return $text;
    }
    private function Set_User_Status_to_Project($projectName, $username = false) {
        $local_key = $this->getKeys();
        if(!$username) $local_key[$projectName]['user_current_version'] = [];
        elseif(in_array($username, $local_key[$projectName]['user_current_version'])) return;
        else $local_key[$projectName]['user_current_version'][] = $username;
        file_put_contents($this->keyFile, json_encode($local_key, JSON_PRETTY_PRINT));
    }
    private function Get_User_Status_to_Project($projectName, $username) {
        $local_key = $this->getKeys();
        if(!in_array($username, $local_key[$projectName]['user_current_version'])) return false;
        else return true;
    }
    public function run_client($serverURL) {
        $this->ServerURL = $serverURL;
        $this->prompt = new promptManager(true);

        while(true) {
            if($this->set_login(
                $this->prompt->get_input('Username > '), 
                $this->prompt->get_input('Password > ')
            )) break;
            $this->prompt->ClearScreen();
            echo $this->get_msg();
        }
        $this->prompt->SetDefaultPrompt("{$this->username} >");
        $this->prompt->ClearScreen();
        $this->get_config();
        $this->check_update();
        $this->show_update();

        $this->prompt->Add('send', 'Send newer version of a project', [
            'projectName' => 'Project Name separated by space',
        ],function($self) {
            if ($this->prompt->GetArgs() == null) {
                $this->show_projects();
                $this->set_msg('Specify a project to upload to the server');
                echo $this->get_msg();
                return;
            }
            $projectList = Array();
            if ($this->prompt->GetArgs()[0] == 'all') $projectList = $this->get_projects();
            else foreach($this->prompt->GetArgs() as $arg) {
                if(!$this->isproject($arg)) echo $this->get_msg();
                else $projectList[] = $arg;
            }
            foreach($projectList as $project) {
                $this->zip_project($project);
                $this->upload_project_to_server($project);
                echo $this->get_msg();
            }
        });

        $this->prompt->Add('get', 'get the newest version of a project from the server ', [
            'all' => 'get all projects from the server',
            'The Project Name' => 'get a specific project from the server'
        ],function($self) {
            if($self->GetArgs() == null) {
                $this->show_projects();
                echo PHP_EOL.'Please specify a project';
                return;
            }
            $updateList = Array();
            foreach($self->GetArgs() as $arg) {
                if ($arg == 'all') {
                    $updateList = $this->projects;
                    break;
                }
                if($this->isproject($arg)) $updateList[$arg] = $this->projects[$arg];
            } if (!empty($updateList[$arg])) $this->make_update($updateList);
            echo $this->get_msg();
        });

        $this->prompt->Add('update', 'Check if updates are avalable', [], function($self) {
            $this->get_config();
            $this->check_update();
            $this->show_update();
        });

        $this->prompt->Add('project', 'Show Projects List', [], function() {
            foreach($this->projects as $name => $info) {
                $this->set_msg("Name: {$name} \npath: {$this->projectBasePath}/{$info['path']}" . PHP_EOL);
            } echo $this->get_msg();
        });

        $this->prompt->Add('exit', 'Exit program', [], function($self) {
            $self->ExitPrompt();
        });

        $this->prompt->Run();
    }
    public function run_server() {
        $this->serverMode = true;

        if(!isset($_POST['data'])) die(PROJECT_STATUS_ERROR);
        $_POST = json_decode($_POST['data'], true);
        $data = $_POST['data'];

        if(!$this->login_verify($_POST['username'], $_POST['password'])) $this->set_return_data('Login Failed', PROJECT_STATUS_ERROR);
        switch($_POST['action']) {
            case ACTION_GET_CONFIG:
                $this->set_return_data($this->projects);
                break;
            case ACTION_GET_PROJECT:
                $this->RightsVerify($data[PROJECT_NAME], PROJECT_RIGHTS_STATUS_GET);
                if(!isset($data[PROJECT_NAME])) $this->set_return_data('Project Name not specified', PROJECT_STATUS_ERROR);
                $this->zip_project($data[PROJECT_NAME]);
                $this->Set_User_Status_to_Project($data[PROJECT_NAME], $this->username);
                $this->send_project($data[PROJECT_NAME]);
                break;
            case ACTION_UPLOAD_PROJECT:
                $this->RightsVerify($data[PROJECT_NAME], PROJECT_RIGHTS_STATUS_SEND_WITH_UPGRADE);
                if(!isset($_FILES[PROJECT_REQUEST_KEY_FILE])) $this->set_return_data('No file uploaded', PROJECT_STATUS_ERROR);
                $this->set_msg("{$data[PROJECT_NAME]} uploaded");
                $this->receive_projects($data[PROJECT_NAME], PROJECT_REQUEST_KEY_FILE);
                $this->extract_project($data[PROJECT_NAME]);
                $this->update_key($data[PROJECT_NAME]);
                $this->Set_User_Status_to_Project($data[PROJECT_NAME]);
                $this->set_return_data([PROJECT_KEY => $this->getKeys($data[PROJECT_NAME])]);
                break;
            case ACTION_LOGIN:
                $this->set_return_data('Login Success');
                break;
        }
    }
}