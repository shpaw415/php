<?php
include_once 'arguments.class.php';

define('SYNC_STATUS_RUNNING', 1);
define('SYNC_STATUS_SUCCESS', 2);
define('SYNC_STATUS_ERROR', 3);
define('SYNC_STATUS_OK', 4);
define('SYNC_FILE_FORBIDEN', $_SERVER['SCRIPT_FILENAME']);

define('SYNC_DIR', 'tempSync');

/**
 * Class SyncCall
 * @package Async
 * @version 1.0.0
 * Make sync call 
 */
class SyncCall {
    public $args;

    public $outfile = null;
    public $logfile = null;
    private $outfileList = [];
    
    public function __construct()
    {
        $this->args = new ArgumentsManager();
        $this->init();
    }
    private function init() {
        $this->logfile = $_SERVER['SCRIPT_FILENAME'] . '.log';
    }
    public function log($data, $status = SYNC_STATUS_OK) {
        $data = json_encode([
            'data' => $data,
            'status' => $status,
        ]);
        file_put_contents($this->logfile, $data . PHP_EOL, FILE_APPEND);
    }
    public function createTask() {
        $this->outfile = uniqid();
        $this->outfileList[] = [
            'outfile' => $this->outfile,
            'callback' => [
                'onError' => null,
                'onSuccess' => null,
                'onDone' => null,
            ]
        ];
        return $this->outfile;
    }
    /**
     * @param $file_path string The file to execute
     * @param $data array The data to pass to the file ['a' => 1, 'b' => 2]
     */
    public function call($file_path, $data, $task = null) {
        $argtext = '';
        if(is_null($task)) $task = $this->createTask();

        is_dir(SYNC_DIR) || mkdir(SYNC_DIR);
        file_put_contents(SYNC_DIR . DIRECTORY_SEPARATOR . $task, '');
        
        exec("php " . $file_path . " -data '". json_encode($data) . "' -outfile " . $task . " >/dev/null 2>/dev/null &");
    }
    public function onError($task, $callback) {
        $this->outfileList[$this->getKey($task)]['callback']['onError'] = $callback;
    }
    public function onSuccess($task, $callback) {
        $this->outfileList[$this->getKey($task)]['callback']['onSuccess'] = $callback;
    }
    public function onDone($task, $callback) {
        $this->outfileList[$this->getKey($task)]['callback']['onDone'] = $callback;
    }
    public function check($task) {
        $index = $this->getKey($task);
        if($index === false) return false;

        $data = file_get_contents(SYNC_DIR . DIRECTORY_SEPARATOR . $this->outfileList[$index]['outfile']);
        $data = json_decode($data, true);
        if(empty($data)) return null;
        
        if($data['status'] == SYNC_STATUS_ERROR) {
            if(!empty($this->outfileList[$index]['callback']['onError'])) $this->outfileList[$index]['callback']['onError']($data);
            if(!empty($this->outfileList[$index]['callback']['onDone'])) $this->outfileList[$index]['callback']['onDone']($data);
            unlink(SYNC_DIR . DIRECTORY_SEPARATOR . $this->outfileList[$index]['outfile']);
            unset($this->outfileList[$index]);
        }
        elseif($data['status'] == SYNC_STATUS_SUCCESS) {
            if(!empty($this->outfileList[$index]['callback']['onSuccess'])) $this->outfileList[$index]['callback']['onSuccess']($data);
            if(!empty($this->outfileList[$index]['callback']['onDone'])) $this->outfileList[$index]['callback']['onDone']($data);
            unlink(SYNC_DIR . DIRECTORY_SEPARATOR . $this->outfileList[$index]['outfile']);
            unset($this->outfileList[$index]);
        }
        elseif($data['status'] == SYNC_STATUS_RUNNING) return null;
        return $data;
    }
    private function getKey($task) {
        foreach($this->outfileList as $key => $data) {
            if($data['outfile'] == $task) return $key;
        } return false;
    }
    public function checkAll() {
        if(empty($this->outfileList)) return false;
        foreach($this->outfileList as $key => $data) {
            $this->check($data['outfile']);
        }
        return true;
    }
}

/**
 * Class SyncExecute
 * Execute the Synced Call
 */
class SyncExecute {

    public $outfile = null;
    public $logfile = '';
    public $status = SYNC_STATUS_RUNNING;
    public $args;
    public $data;

    public $outfile_data = null;
    public $logfile_data = null;
    public function __construct()
    {
        $this->args = new ArgumentsManager();
        $this->init();
    }
    private function init() {
        $this->logfile = $_SERVER['SCRIPT_FILENAME'] . '.log';

        $this->args->SetArgs([
            '-data' => 'data to pass to the file',
            '-outfile' => 'The file to execute',
        ]);
        if($this->args->Get('data')) {
            $this->data = json_decode($this->args->Get('data'), true);
            $_POST = $this->data;
            $_GET = $this->data;
            $_REQUEST = $this->data;
        }

        if(!file_exists(SYNC_DIR . DIRECTORY_SEPARATOR . $this->args->Get('outfile'))) die($this->log(['error' => 'Outfile does not exists'], SYNC_STATUS_ERROR));
        else $this->outfile = $this->args->Get('outfile');

        $this->done('', SYNC_STATUS_RUNNING);
    }
    public function updateOutFileData($data, $status = SYNC_STATUS_SUCCESS) {
        $this->outfile_data = json_encode([
            'data' => $data,
            'status' => $status
        ]);
        if(!file_put_contents(SYNC_DIR. DIRECTORY_SEPARATOR. $this->outfile, $this->outfile_data)) {
            $this->log('Cannot write to outfile', SYNC_STATUS_ERROR);
        }
    }
    public function getAllVars() {
        return [
            'outfile' => $this->outfile,
            'logfile' => $this->logfile,
            'status' => $this->status
        ];
    }
    public function log($data, $status = SYNC_STATUS_RUNNING) {
        $data = json_encode([
            'data' => $data,
            'status' => $status,
        ]);
        file_put_contents($this->logfile, $data . PHP_EOL, FILE_APPEND);

    }
    public function execute($callback) {
        $callback($this, $this->data);
    }
    public function done($data , $status = SYNC_STATUS_SUCCESS) {
        $data = json_encode([
            'data' => $data,
            'status' => $status
        ]);
        file_put_contents(SYNC_DIR . DIRECTORY_SEPARATOR . $this->outfile, $data);
    }
}