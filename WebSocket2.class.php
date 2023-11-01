<?php

/**
 * Template Class WebSocket Server
 * @package SocketServer
 * @author GymsGrind <gymsgrind@gmail.com>
 * @version 1.2
 * @abstract
 * @copyright Open Source
 */

define('BLACKLIST_FILE', 'blacklist.txt');

define('SYNC_STATUS_RUNNING', 1);
define('SYNC_STATUS_SUCCESS', 2);
define('SYNC_STATUS_ERROR', 3);
define('SYNC_STATUS_OK', 4);
define('SYNC_FILE_FORBIDEN', $_SERVER['SCRIPT_FILENAME']);
define('SYNC_DIR', 'tempSync');

class SocketServer
{

	private $address;
	private $port;
	private $socketResource;

	public $socket; // current socket in use
	private $clientSocketArray;
	private $newSocketArray;
	private $clientList; // client data array
	public Client $client; // client current data


	public $clientRecData;
	public $rawClientData;

	private $log_enable;

	private array $onCloseFunction;
	private array $onOpenFunction;
	private $actionFunctionArray;

	private $ServerActive;

	private $timeoutdata;
	private $sync;
	private $taskFile = null;

	private $classList = array();


	public function __construct($addr = 'localhost', $port = 8080, $log = true) {
		@session_start();
		$this->address = $addr;
		$this->port = $port;
		$this->log_enable = $log;
		$this->ServerActive = true;
		$this->clientList = array();
		$this->timeoutdata = array();
		$this->sync = new SyncCall();

		$this->onCloseFunction = [];
		$this->onOpenFunction = [];

		$this->socketResource = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		socket_set_option($this->socketResource, SOL_SOCKET, SO_REUSEADDR, 1);
		socket_bind($this->socketResource, $this->address, $this->port) or die("Could not bind to socket");
		socket_listen($this->socketResource) or die("Could not listen to socket");

		$this->clientSocketArray[] = $this->socketResource;
	}
	public function run() {
		$this->setInterval(5, function () { $this->_checkTasks(); });
		while ($this->ServerActive) {
			$this->newSocketArray = $this->clientSocketArray;
			socket_select($this->newSocketArray, $null, $null, 0, 10);
			$this->_addClient();
			$this->_handleReceivedData();
			$this->_handleTimeout();
		}
	}
	public function setClassesList(array $classList) {
		$this->classList = $classList;
	}
	public function getClass(string $name) {
		if (!isset($this->classList[$name])) die("Class $name not found");
		return $this->classList[$name];
	}
	/**
	 * Send a message to the client
	 * **$message** need to be encoded with seal() before
	 * @param string $message The message to send to the client **(already encoded)**
	 * @param resource|array $client The socket resource of the client (Default: current active socket)
	 * @return boolean **True** if the message is sent, **false** on error
	 */
	public function send($message, $socket = null) {
		if (gettype($message) == 'array') $message = json_encode($message);
		if ($socket === null) $socket = $this->socket;
		$sealedMsg = $this->_seal($message);
		return @socket_write($socket, $sealedMsg, strlen($sealedMsg));
	}
	public function sendTo($message, $clientArray) {
		foreach ($clientArray as $client) {
			$this->send($message, $client->socket());
		}
	}
	/**
	 * @param string $action the action name to execute when ['action' => $action] is received
	 * @param Closure $callback the callback to execute when the action is executed Function(SocketServer $server, Client $client)
	 */
	public function addAction($action, $callback) {
		$this->actionFunctionArray[$action] = ['callback' => $callback];
	}
	public function setInterval(int $timeout, $callback) {
		$this->timeoutdata[] = [
			'callback' => $callback,
			'timeout' => $timeout,
			'current_time' => time(),
			'last_time' => time(),
			'id' => uniqid(),
		];
	}
	public function getClients(array $needle) {
		$keys = array_keys($needle);
		$array = array();
		$bypass = false;
		for ($i = 0; $i < count($this->clientList); $i++) {
			foreach ($keys as $key) {
				if(!isset($this->clientList[$i]->session()[$key]) || $this->clientList[$i]->session()[$key] !== $needle[$key]) $bypass = true;
			} 
			if(!$bypass) $array[] = &$this->clientList[$i];
			$bypass = false;
		} return $array;
	}
	public function getClient(array $needle, bool $index = false) {
		$keys = array_keys($needle);
        $bypass = false;
        for ($i = 0; $i < count($this->clientList); $i++) {
            foreach ($keys as $key) {
                if(!isset($this->clientList[$i]->session()[$key]) || $this->clientList[$i]->session()[$key]!== $needle[$key]) $bypass = true;
            } if(!$bypass) {
				if(!$index) return $this->clientList[$i];
				else return $i;
			}
			$bypass = false;
        }
	}
	public function bind(array $sessionKeyValues = array()) : Client|null {
		foreach ($this->clientList as $client) {
			$found = true;
			foreach (array_keys($sessionKeyValues) as $key) {
                if (!isset($client->sessionArray[$key]) || $client->sessionArray[$key] !== $sessionKeyValues[$key]) $found = false;
            } if ($found) return $client;
		} return null;
	}
	/** @return array array of Client */
	public function bindAll(array $sessionKeyValues = array()) : array {
		$Foundclients = array();
		foreach ($this->clientList as $client) {
			$found = true;
			$session = $client->session();
			foreach (array_keys($sessionKeyValues) as $key) {
                if (!isset($session[$key]) || $session[$key] !== $sessionKeyValues[$key]) $found = false;
            } if ($found) $Foundclients[] = $client;
		} return $Foundclients;
	}
	public function onClose($callback) {
		$this->onCloseFunction['callback'] = $callback;
	}
	public function onConnect($callback) {
		$this->onOpenFunction[] = $callback;
	}
	public function expulse(Socket|null $socket = null) {
		$socket = $socket !== null ? $socket : $this->socket;
		socket_close($socket);
		$this->_socketRemove($socket);
	}
	private function _handleReceivedData() {
		foreach ($this->newSocketArray as $newSocketArrayResource) {
			while ($socketRecInfo = @socket_recv($newSocketArrayResource, $socketData, 1024, 0) >= 1) {
				if(!$this->_setActiveClient($newSocketArrayResource)) {
					if($this->log_enable) echo "Could not set the active Client\n";
					continue;
				}
				$socketMessage = $this->_unseal($socketData);
				$this->rawClientData = $socketMessage;
				try {
					$this->clientRecData = $socketMessage;
					if (!$this->_checkStatus()) {
						foreach ($this->onCloseFunction as $callback) {
							$callback($this, $this->client);
						}
						$this->_socketRemove($newSocketArrayResource);
						return;
					}
					$_REQUEST = @json_decode($socketMessage, true);
					$this->log();
					if(isset($this->actionFunctionArray[$_REQUEST['action']])) {
						$this->actionFunctionArray[$_REQUEST['action']]['callback']($this, $this->client);
						$this->client->sessionArray = $_SESSION;
					}
				} catch (Exception $e) {if($this->log_enable) echo 'error: ' . $e->getMessage() . PHP_EOL . PHP_EOL; }
				break 2;
			}
		}
	}
	private function _handleTimeout() {
		for ($i = 0; $i < count($this->timeoutdata); $i++) {
			if ($this->timeoutdata[$i]['current_time'] - $this->timeoutdata[$i]['last_time'] > $this->timeoutdata[$i]['timeout']) {
                $this->timeoutdata[$i]['callback']($this);
				$this->timeoutdata[$i]['last_time'] = time();
            } else {
                $this->timeoutdata[$i]['current_time'] = time();
            }
        }
	}
	private function _checkStatus() {
		$data = bin2hex($this->clientRecData);
		switch ($data) {
			case '03e8': // code 1000
				if($this->log_enable) echo 'Socket Closed with code 1000' . PHP_EOL;
				return false;
			case '03e9': // code closed unexpectedly
				if($this->log_enable) echo 'Socket Closed unexpectedly' . PHP_EOL;
				return false;
			default:
				return true;
		}
	}
	private function _setActiveClient($socket) {
		if (count($this->clientList) == 0) return false;
		for ($i = 0; $i < count($this->clientList); $i++) {
			if ($this->clientList[$i]->socketObject == $socket) {
				$this->client = $this->clientList[$i];
				$this->socket = $this->client->socketObject;
				$this->clientList[$i]->select();
				return true;
			}
		}
		return false;
	}
	static function _isBlackListed($socketOrIp) {
		if (!file_exists(BLACKLIST_FILE)) return false;
		$ip = '';
		if (gettype($socketOrIp) !== 'string') {
			socket_getpeername($socketOrIp, $ip);
		} else $ip = $socketOrIp;
		return in_array($ip, file(BLACKLIST_FILE));

	}
	private function _addClient() {
		if (in_array($this->socketResource, $this->newSocketArray)) {
			$newSocket = socket_accept($this->socketResource);
			if($this->_isBlackListed($newSocket)) return socket_close($newSocket);
			$this->clientSocketArray[] = $newSocket;
			$header = socket_read($newSocket, 1024);
			if ($this->_doHandshake($header, $newSocket, $this->address, $this->port) === false) return false;

			$this->client = new Client($newSocket, array(), $header);
			$this->clientList[] = $this->client;
			foreach ($this->onOpenFunction as $callback) {
				$callback($this, $this->client);
			}
			$newSocketIndex = array_search($this->socketResource, $this->newSocketArray);
			unset($this->newSocketArray[$newSocketIndex]);
			$this->newSocketArray = array_values($this->newSocketArray);
			return true;
		}
		return false;
	}
	private function _socketRemove(Socket $socket)
	{
		$socketIndex = array_search($socket, $this->clientSocketArray);
		if ($socketIndex === false) return false;
		unset($this->clientSocketArray[$socketIndex]);
		$this->clientSocketArray = array_values($this->clientSocketArray);

		for ($i = 0; $i < count($this->clientList); $i++) {
			if(!array_search($this->clientList[$i]->socketObject, $this->clientSocketArray)) {
				unset($this->clientList[$i]);
				$this->clientList = array_values($this->clientList);
			}
		}
		socket_close($socket);
		if($this->log_enable) echo json_encode($this->clientList, JSON_PRETTY_PRINT);
		return true;
	}
	/**
	 * Make the handshake with the client to establish the connection
	 * @param string $received_header The header of the client
	 * @param Socket $client_socket_resource The socket resource of the client
	 * @param string $address The address of the socket server
	 * @param int $port The port of the socket server
	 */
	private function _doHandshake($received_header, $client_socket_resource, $host_name, $port)
	{
		$headers = array();
		$lines = preg_split("/\r\n/", $received_header);
		foreach ($lines as $line) {
			$line = chop($line);
			if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
				$headers[$matches[1]] = $matches[2];
			}
		}

		$secKey = $headers['Sec-WebSocket-Key'];
		$secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
		$buffer = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
			"Upgrade: websocket\r\n" .
			"Connection: Upgrade\r\n" .
			"WebSocket-Origin: $host_name\r\n" .
			"WebSocket-Location: ws://$host_name:$port/ws\r\n" .
			"Sec-WebSocket-Accept:$secAccept\r\n\r\n";
		return socket_write($client_socket_resource, $buffer, strlen($buffer));
	}
	/**
	 * Decode the message from the client
	 * @param string $socketData The Socket message from the client
	 * @return string The decoded message
	 */
	static function _unseal($socketData)
	{
		$length = ord($socketData[1]) & 127;
		if ($length == 126) {
			$masks = substr($socketData, 4, 4);
			$data = substr($socketData, 8);
		} elseif ($length == 127) {
			$masks = substr($socketData, 10, 4);
			$data = substr($socketData, 14);
		} else {
			$masks = substr($socketData, 2, 4);
			$data = substr($socketData, 6);
		}
		$socketData = "";
		for ($i = 0; $i < strlen($data); ++$i) {
			$socketData .= $data[$i] ^ $masks[$i % 4];
		}
		return $socketData;
	}
	/**
	 * Encode the message to send to the client
	 * @param string $socketData The message to send to the client
	 * @return string The encoded message
	 */
	static function _seal($socketData)
	{
		$b1 = 0x80 | (0x1 & 0x0f);
		$length = strlen($socketData);

		if ($length <= 125)
			$header = pack('CC', $b1, $length);
		elseif ($length > 125 && $length < 65536)
			$header = pack('CCn', $b1, 126, $length);
		elseif ($length >= 65536)
			$header = pack('CCNN', $b1, 127, $length);
		return $header . $socketData;
	}
	public function log($message = '')
	{
		if(!$this->log_enable) return;
		echo chr(27).chr(91).'H'.chr(27).chr(91).'J';
		echo PHP_EOL . '----------------------------------------------------------------';
		echo PHP_EOL . 'Clients connected: ' . (count($this->clientSocketArray) - 1) . PHP_EOL;
		echo 'Clients data: ' . PHP_EOL . '-------------' . PHP_EOL . json_encode($this->clientList, JSON_PRETTY_PRINT) . PHP_EOL . '-------------' . PHP_EOL;
		echo 'Last error: ' . socket_last_error($this->socketResource) . PHP_EOL;
		echo 'Last Data received: ' . $this->rawClientData . PHP_EOL;
		echo empty($message) ? '' : 'Custom message: '. $message . PHP_EOL;
	}
	public function setTaskFile($taskFile) {
		$this->taskFile = $taskFile;
		return $this;
	}
	public function createTask($data, $callback) {
		if(gettype($data) !== 'string') $data = json_encode($data);
		if(empty($this->taskFile)) die('Task file not set set with setTaskFile()');
		if(!file_exists($this->taskFile)) die('Task file not found verify task file path');
		$taskID = $this->sync->call($this->taskFile, $data);
		$this->sync->onDone($taskID, $callback);
	}
	private function _checkTasks() {
		foreach($this->sync->outfileList as $task) {
			$id = $task['outfile'];
			$this->sync->check($id);
		}
	}
}

class Client {
	public array $sessionArray = array();
	public Socket $socketObject;
	public string $address = '';
	public array $cookies = array();
	private string $headers;

	public function __construct($socket, $session = array(), $headers = false) {
		$this->sessionArray = $session;
        $this->socketObject = $socket;
		$this->headers = $headers;
		$this->_setCookies();
		if($socket !== null)  socket_getpeername($socket, $this->address);
	}
	private function _setCookies() {
		if ($this->headers === false) return;
		$headers = array();
		$lines = preg_split("/\r\n/", $this->headers);
		foreach ($lines as $line) {
			$line = chop($line);
			if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
				$headers[$matches[1]] = $matches[2];
			}
		}
		if(!isset($headers['Cookie'])) return;
		$Rawcookies = $headers['Cookie'];
		$cookies = explode(';', $Rawcookies);
		foreach ($cookies as $cookie) {
            $parts = explode('=', $cookie);
            $this->cookies[$parts[0]] = $parts[1];
        }
	}
	public function session(null|array $session = null) {
		if ($session === null) {
            return $this->sessionArray;
        }
		$this->sessionArray = $session;
		return $this;
	}
	/** 
	 * @param array $keyValues array of key value pairs to set session values
	 */
	public function setSession(array $keyValues) {
		foreach ($keyValues as $key => $value) {
            $this->sessionArray[$key] = $value;
        }
        return $this;
	}
	public function socket(Socket|null &$socket = null) {
		if ($socket === null) {
            return $this->socketObject;
        }
        $this->socketObject = $socket;
        return $this;
    }
	public function select() {
		$_SESSION = $this->sessionArray;
		$_COOKIE = $this->cookies;
		return $this;
	}
	public function send(array|string $message) {
		$message = gettype($message) == 'array'? json_encode($message) : $message;
		$sealedMsg = SocketServer::_seal($message);
		socket_write($this->socketObject, $sealedMsg, strlen($sealedMsg));
		return $this;
	}
	public function toBlackList() {
		if (empty($this->address)) return $this;
		$file = fopen(BLACKLIST_FILE, 'a');
		fwrite($file, $this->address. PHP_EOL);
		fclose($file);
		return $this;
	}
	public function addr() {
		if (empty($this->address)) socket_getpeername($this->socketObject, $this->address);
		return $this->address;
	}

}

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
    public $outfileList = [];

    
    public function __construct()
    {
        $this->args = new WebSocketArgumentsManager();
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
		if (gettype($data) !== 'string') $data = json_encode($data);
        
        exec("php " . $file_path . " -data '". $data . "' -outfile " . $task . " >/dev/null 2>/dev/null &");
		return $task;
    }
    public function onError($task, $callback, &$dataToParse) {
        $this->outfileList[$this->getKey($task)]['callback']['onError'] = $callback;
		$this->outfileList[$this->getKey($task)]['$dataToParse'] = &$dataToParse;
	}
    public function onSuccess($task, $callback, &$dataToParse) {
        $this->outfileList[$this->getKey($task)]['callback']['onSuccess'] = $callback;
		$this->outfileList[$this->getKey($task)]['$dataToParse'] = &$dataToParse;
	}
    public function onDone($task, $callback, &$dataToParse = array()) {
        $this->outfileList[$this->getKey($task)]['callback']['onDone'] = $callback;
		$this->outfileList[$this->getKey($task)]['$dataToParse'] = &$dataToParse;
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
        } elseif($data['status'] == SYNC_STATUS_SUCCESS) {
            if(!empty($this->outfileList[$index]['callback']['onSuccess'])) $this->outfileList[$index]['callback']['onSuccess']($data);
            if(!empty($this->outfileList[$index]['callback']['onDone'])) $this->outfileList[$index]['callback']['onDone']($data);
        }
        elseif($data['status'] == SYNC_STATUS_RUNNING) return null;

		unlink(SYNC_DIR . DIRECTORY_SEPARATOR . $this->outfileList[$index]['outfile']);
		unset($this->outfileList[$index]);
		$this->outfileList = array_values($this->outfileList);

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
        $this->args = new WebSocketArgumentsManager();
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

class WebSocketArgumentsManager
{
    private $argsList = [];
    private $stringIndexer = ['\'', '"', '`'];

    private $RawArgs = [];

    /**
     * @param $args array [ '-a' => 'Description' ]
     * @return bool true if all args are valid and false otherwise
     */
    public function SetArgs($args)
    {
        $this->RawArgs = $args;
        $string_to_parse = '';

        if (in_array('-h', $_SERVER['argv']) || in_array('--help', $_SERVER['argv'])) {
            return false;
        }

        for ($i = 0; $i < count($_SERVER['argv']); $i++) {
            if ($_SERVER['argv'][$i][0] != '-' && $_SERVER['argv'][$i][0]!= '>') {
                if (!$this->Get('default') && $i != 0) {
                    $this->argsList[] = [
                        'default' => $_SERVER['argv'][$i]
                    ];
                }
                continue;
            } elseif ($_SERVER['argv'][$i][0] == '>') break;
            if (!array_key_exists($_SERVER['argv'][$i], $args)) return false;
            $string_to_parse = $_SERVER['argv'][$i + 1];
            if (is_int($string_to_parse)) {
            } elseif (!array_search($_SERVER['argv'][$i + 1][0], $this->stringIndexer)) {
            } elseif (array_search($_SERVER['argv'][$i + 1][strlen($_SERVER['argv'][$i + 1]) - 1], $this->stringIndexer)) {
                $string_to_parse = substr($_SERVER['argv'][$i + 1], 1);
                $string_to_parse = substr($string_to_parse, strlen($string_to_parse));
            } else {
                for ($j = $i; $j < count($_SERVER['argv']); $j++) {
                    $string_to_parse .= $_SERVER['argv'][$j] . ' ';
                    if (array_search($_SERVER['argv'][$j][strlen($_SERVER['argv'][$j]) - 1], $this->stringIndexer)) {
                        break;
                    }
                    $i++;
                }
            }
            array_push($this->argsList, [
                str_replace('-', '', $_SERVER['argv'][$i]) => $string_to_parse
            ]);
            $i++;
        } return true;
    }

    public function ShowHelp()
    {
        $text = "\n Usage: php " . __FILE__ . " [options]\n\n
        Options:\n";
        foreach ($this->RawArgs as $key => $value) {
            $text .= " " . $key . "\t\t\t" . $value . PHP_EOL;
        }
        $text .= " -h, --help\t\tShow this help\n\n";
        return $text;
    }

    /**
     * @param $arg string 'a'
     */
    public function Get($arg = '')
    {
        if (empty($arg)) return $this->argsList;
        elseif ($arg === true) $arg = 'default';
        foreach ($this->argsList as $argList) {
            $arg = str_replace('-', '', $arg);
            if (array_key_exists($arg, $argList)) {
                return $argList[$arg];
            }
        }
        return false;
    }
}