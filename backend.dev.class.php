<?php
session_start();
define('WC_USER_TABLE', 'Wc_users_custom');

define('WC_REGISTER_KEY', 'wc_register');
define('WC_LOGIN_KEY', 'wc_login');

define('WC_USERNAME', 'username');
define('WC_PASSWORD', 'password');

include_once __DIR__ . DIRECTORY_SEPARATOR .'WebSocket2.class.php';
include_once __DIR__ . DIRECTORY_SEPARATOR .'Utils.class.php';
/*
    $_SESSION['wc_session'] = array(
        'username' => '',
        'userAccess' => 1,
    );
*/

enum SiteUserFields: string {
    case name = 'name';
    case surname = 'surname';
    case age = 'age';
    case email = 'email';
    case phone = 'phone';
    case address = 'address';
    case username = 'username';
    case password = 'password';
}

enum SiteUserControl: string {
    case none = 'none';
    case simple ='simple';
    case multiple ='multiple';
}

$_ENV['wc_data'] = array(
    'live' => false,
    'init' => false,
    'verbose' => false,
    'siteName' => 'Wc_SiteName',

    'websocket_data' => [
        'host' => 'localhost',
        'port' => 4444,
        'run' => false,
        'wsObject' => null, // class SocketServer
    ],

    'php_data' => [
        'run' => false,
        'port' => 8080,
    ],

    'test_data' => [
        'run' => false,
        'request' => '',
        'data' => '',
    ],

    'database_data' => [
        'host' => 'localhost',
        'port' => 3306,
        'user' => 'root',
        'password' => '',
        'db_name' => 'Wc_project',
    ],
    'userSupport_data' => [
        'enabled' => false,
        'userControl' => SiteUserControl::none->value,
        'userKeys' => array(
            'username',
            'password',
        ),
        'userAccess' => array(), // [string => int, ...]
    ],
);

class WcBackendManager
{
    public WcFeaturesManager $featuresManager;
    public string $themeName = '';

    public Closure $initer;

    private ArgumentsManager $args;

    /**
     * @param string $themeName Theme name (Warning: must be unique among all themes so verify its disponibility in the dev section on the Web-creation website)
     * @param Closure $initer this is the initer function and parameters parsed are (WcFeaturesManager &$featuresManager)
     */
    public function __construct(string $themeName, Closure $initer) {
        if(self::isLive() && $_REQUEST['Wc_Theme_id'] != $themeName) return;
        $this->themeName = $themeName;
        $this->featuresManager = new WcFeaturesManager();
        $this->featuresManager->_setSuffix($this->themeName);
        $this->_initArgs();
        $this->_initBasicrequests();
        $featureref = &$this->featuresManager;
        $initer($featureref);
        $this->run();


    }
    private function _initBasicrequests() {
        $this->featuresManager->createRequest(WC_REGISTER_KEY, function (WcFeaturesManager $features, WcRequests $requests) {
            if(!$features->_featureIsEnabled(WcFeatures::userSupport)) Logger::log('User support is not enabled!');

            $user = & $features->Wcuser;
            $res = $user->get([
                'username' => $_REQUEST[WC_USERNAME],
            ], ['username']);
            if(!empty($res)) {
                $requests->addCallback(WebAppFunctions::WarningMsg, ['message' => 'Username already taken!']);
                $requests->returnData(WcRequestsStatus::ERROR);
            }
            $user->db->insert(WC_USER_TABLE, [
                'username' => $_REQUEST[WC_USERNAME],
                'password' => $_REQUEST[WC_PASSWORD]
            ]);
        });
    }
    private function run() {
        if($_ENV['wc_data']['init']) {
            $this->featuresManager->_initFeatures();
            echo "The initialization of {$this->themeName} is done\n";
        }
        else if($this->args->exists('request')) {
            $this->featuresManager->_setupFeatures();
            $requestName = $this->args->get('request');
            if($this->args->exists('data')) $requestData = $this->args->get('data');
            else $requestData = '';
            
            $this->testRequest($requestName, $requestData);
        }
        else if($_ENV['wc_data']['websocket_data']['run']) {
            if($this->featuresManager->_featureIsEnabled(WcFeatures::webSocket)) {
                $this->featuresManager->_setupFeatures();
                $this->featuresManager->webSocketStructure->socketServer->run();
            }
            else Logger::log('WebSocket feature is not enabled enable it with WcFeaturesManager->enableWebSocket()');
        }
        else if($_ENV['wc_data']['php_data']['run']) {
            echo "Serving on port ". $_ENV['wc_data']['php_data']['port']. "\n";
            exec('php -S localhost:'. $_ENV['wc_data']['php_data']['port'].' -t ' . getcwd() . '/');
            die();
        }
        else if(!self::isLive()) {
            if(!$_REQUEST['request']) die('You must specify params: [request, data]');
            $this->testRequest($_REQUEST['request'], $_REQUEST['data'] || '');
        } else if(self::isLive()){
            $this->makeRequest($_REQUEST['request'], json_decode($_REQUEST['Wc_Theme_data'], true));
        }

    }
    private function _initArgs() {
        $this->args = new ArgumentsManager();
        $res = @$this->args->SetArgs([
            '--init' => 'initialize the features to work in your environement',
            '--run' => 'Run the test server (param: "websocket", "php") default: "websocket"',
            '--port' => 'Port to run the test server default: 4444',
            '--request' => 'param: "request_name" and --data will be used to parse to the requested function',
            '--data' => 'param: data (will be parsed to the requested function as a string)',
            '--verbose' => 'Show detailed errors reports',
        ]);
        if(!$res)return false;

        if ($this->args->exists('verbose')) {
            $_ENV['wc_data']['verbose'] = true;
        }
        if ($this->args->exists('init')) {
            $_ENV['wc_data']['init'] = true;
            return;
        }
        if ($this->args->exists('run')) {
            switch ($this->args->get('run')) {
                case 'websocket':
                case false:
                    $_ENV['wc_data']['websocket_data']['run'] = true;
                    if ($this->args->exists('port')) $_ENV['wc_data']['websocket_data']['port'] = $this->_checkPort($this->args->get('port'));
                    break;
                case 'php':
                    $_ENV['wc_data']['php_data']['run'] = true;
                    if ($this->args->exists('port'))  $_ENV['wc_data']['php_data']['port'] = $this->_checkPort($this->args->get('port'));
                    break;
                default:
                    Logger::log('run parameter must be "php" or "websocket"');
                    break;
            }
        }
        if(count($this->args->Get()) == 0 && !self::isLive()) {
            die($this->args->ShowHelp());
        }
    }
    private function makeRequest(string $requestName, array $data = []) {
        $this->featuresManager->getRequest($requestName)($this->featuresManager, new WcRequests($data));
        die();
    }
    private function testRequest(string $requestName, string $data = '') {
        $this->featuresManager->getRequest($requestName)($this->featuresManager, new WcRequests($data));
        Logger::log("You must specify WcRequests::returnData()");
    }
    private function _checkPort(string|int $port): int {
        $port = intval($port);
        if (is_int($port) && $port > 0 && $port < 65536) return $port;
        else Logger::log('Invalid port number must be between 1 and 65535');
    }
    public static function isLive(): bool {
        return isset($_ENV['wc_data']['live']) && $_ENV['wc_data']['live'] == true;
    }
}
enum WcRequestsStatus: string {
    case ERROR = 'error';
    case SUCCESS ='success';
}
enum WebAppFunctions: string {
    /** display a message to the user on the top of the screen data: ['message' => string] */
    case WarningMsg = 'WarningMsg';
    /** Modale box with a message in it with button (Accept & Cancel) data: ['fr' => 'fr_version': string, 'en' => 'en_version': string, 'color' => 'hexColor|colorName': string] */
    case ConfirmMsg = 'Confirm';
    /** will execute the code set in data ['callback' => 'function': string]*/
    case Custom = 'Custom';

}
class WcRequests {
    private array|string $requestsData;
    private array $data = [];
    public function __construct(string|array $data) {
        $this->requestsData = $data;
    }
    /** 
     * this function concatenates the data
     ** set the data to be return 
     * */
    public function setData(array $data) {
        $this->data = [...$this->data, ...$data];
        return $this;
    }
    /** @return array the data set to be returned */
    public function getData() {
        return $this->data;
    }
    /** @return array|string the data from the request */
    public function getRequestsData() {
        return $this->requestsData;
    }
    /** end the request and return the data set with WcRequests->setData() */
    public function returnData(WcRequestsStatus $status) {
        die(json_encode([
            'status' => $status->value,
            'data' => $this->data
        ]));
    }
    /** add a callback to the response for the requests */
    public function addCallback(WebAppFunctions $type, array $data) {
        switch ($type) {
            case WebAppFunctions::WarningMsg:
                Common::checkFormat(['fr' => FormatType::STRING, 'en' => FormatType::STRING], $data, "in addCallback::WarningMsg\n");
                $this->data['callback'][] = "$.utils.warningMsg(Utils.translate('{$data['fr']}', '{$data['en']}');";
                break;
            case WebAppFunctions::ConfirmMsg:
                Common::checkFormat(['fr' => FormatType::STRING, 'en' => FormatType::STRING, 'color' => FormatType::STRING], $data, "in addCallback::ConfirmMsg\n");
                $this->data['callback'][] = "$.utils.confirm(Utils.translate('{$data['fr']}', '{$data['en']}'), '{$data['color']}')";
                break;
            case WebAppFunctions::Custom:
                Common::checkFormat(['callback' => FormatType::STRING], $data, "in addCallback::Custom\n");
                $this->data['callback'][] = $data['callback'];
                break;
        }
        return $this;
    }
}
enum FormatType: string {
    case STRING = 'string';
    case ARRAY = 'array';
    case OBJECT = 'object';
    case BOOLEAN = 'boolean';
    case INTEGER = 'integer';
    case FLOAT = 'float';
    case NULL = 'null';
    
};
class Common {
    /** 
     * @param array $arrayOfFormatedKeyValuesPairs ['key' => FormatType, 'key' => FormatType,...]
     */
    public static function checkFormat(array $arrayOfFormatedKeyValuesPairs, array $arrayToCheck, string $suffixError = '')
    {
        $formatedLength = count(array_keys($arrayOfFormatedKeyValuesPairs));
        $checkingLength = count(array_keys($arrayToCheck));
        if ($formatedLength != $checkingLength) {
            Logger::log("Needed keys: [" . implode(", ", array_keys($arrayOfFormatedKeyValuesPairs)) . "]\nGiven keys: [" . implode(", ", array_keys($arrayToCheck)) . "] {$suffixError}");
        }
        foreach ($arrayToCheck as $key => $value) {
            if (!array_key_exists($key, $arrayOfFormatedKeyValuesPairs)) {
                Logger::log("\nThere is no key {$key} {$suffixError}\n");
            }
        }
        foreach (array_keys($arrayOfFormatedKeyValuesPairs) as $key) {
            $value = $arrayOfFormatedKeyValuesPairs[$key];
            if($value instanceof FormatType === false) {
                Logger::log("you must provide a valid format type enum: FormatType");
            }
            switch ($value) {
                case FormatType::STRING:
                    if (!is_string($arrayToCheck[$key])) {
                        Logger::log("The value of the key {$key} must be a string {$suffixError}\n");
                    }
                    break;
                case FormatType::INTEGER:
                    if (!is_int($arrayToCheck[$key])) {
                        Logger::log("The value of the key {$key} must be an integer {$suffixError}\n");
                    }
                    break;
                case FormatType::FLOAT:
                    if (!is_float($arrayToCheck[$key])) {
                        Logger::log("The value of the key {$key} must be a float {$suffixError}\n");
                    }
                    break;
                case FormatType::BOOLEAN:
                    if (!is_bool($arrayToCheck[$key])) {
                        Logger::log("The value of the key {$key} must be a boolean {$suffixError}\n");
                    }
                    break;
                case FormatType::ARRAY:
                    if (!is_array($arrayToCheck[$key])) {
                        Logger::log("The value of the key {$key} must be an array {$suffixError}\n");
                    }
                    break;
                case FormatType::OBJECT:
                    if (!is_object($arrayToCheck[$key])) {
                        Logger::log("The value of the key {$key} must be an object {$suffixError}\n");
                    }
                    break;
                case FormatType::NULL:
                    if (!is_null($arrayToCheck[$key])) {
                        Logger::log("The value of the key {$key} must be null {$suffixError}\n");
                    }
                    break;
                default:
                    break;
            }
        }
        return true;
    }
}
enum WcFeatures {
    /** enable access to the database with WcFeaturesManager->enableDatabaseAccess()*/
    case database;
    /** enable support for user Login/Register with WcFeaturesManager->enableUserSupport() */
    case userSupport;
    /** enable the webSocket with WcFeaturesManager->enableWebSocket() */
    case webSocket;
}
class WcWebSocketStructure {
    public array $actions = array();
    public SocketServer $socketServer;
    public  Closure|null $onConnect = null;
    public  Closure|null $onDisconnect = null;
    private string|null $suffix = null;
    /** 
     * @param string $actionName this is the id to refer the request to
     * @param Closure $callback this is the callback function and parameters parsed are (WcFeaturesManager $features, WcWebSocketStructure $self)
     */
    public function createRequest(string $actionName, Closure $callback) {
        $this->actions[$actionName] = $callback;
    }

    public function setSuffix(string $suffix) {
        if($this->suffix) return;
        $this->suffix = $suffix;
    }
    /** @param Closure $callback will be executed when a client connects. Parameters (WcFeaturesManager $features, WcWebSocketStructure $self) */
    public function setOnConnection(Closure|null $callback = null) {
        if (is_null($callback)) return $this->onConnect;
        $this->onConnect = $callback;
    }

    /** @param Closure $callback will be executed when a client disconnect. Parameters (WcFeaturesManager $features, WcWebSocketStructure $self) */
    public function setOnDisconnect(Closure $callback = null) {
        if (is_null($callback)) return $this->onDisconnect;
        $this->onDisconnect = $callback;
    }
    /**
     * select the Client filtered by data
     * @param array $data key/value pairs filtering the $_SESSION of other clients ['sessionKey' => 'value', ...]
     * @return WcWebSocketClient|null the first occurence of a client that matches the data
     */
    public function bind(array $data) : WcWebSocketClient|null {
        $client = $this->socketServer->bind($data);
        if(!$client) return null;
        return new WcWebSocketClient($client, $this->socketServer, $this->suffix);
    }
    /** 
     * Make a list of Client filtered by data
     * @param array $data key/value pairs filtering the $_SESSION of other clients ['sessionKey' => 'value', ...]
     * @return array array of WcWebSocketClient instances filtered by $data
     * */
    public function bindAll(array $data): array {
        $clients = $this->socketServer->bindAll($data);
        $returnList = array();
        foreach ($clients as $client) {
            $returnList[] = new WcWebSocketClient($client, $this->socketServer, $this->suffix);
        }
        return $returnList;
    }
    /** @return  WcWebSocketClient the current client */
    public function client(): WcWebSocketClient {
        return new WcWebSocketClient($this->socketServer->client, $this->socketServer, $this->suffix);
    }
}
class WcWebSocketClient {
    private Client $client;
    private SocketServer $socketServer;
    private string $suffix;

    public function __construct(Client &$client, SocketServer &$socketServer, $suffix) {
        $this->client = $client;
        $this->socketServer = $socketServer;
        $this->suffix = $suffix;
    }
    /** Send message to the client */
    public function send(array $data) {
        if(isset($data['wc_section_data'])) Logger::log('You cannot use wc_section_data as key! this is reserved for Web-Creation');
        $data['wc_section_data'] = $this->suffix;
        $this->client->send(json_encode($data));
    }
    /** Close the connection with the client */
    public function close() {
        $this->socketServer->expulse($this->client->socket());
    }
}
class WcFeaturesManager {
    public array $features;
    private array $requests = [];
    private string $suffix = '';
    public WcDatabase|null $Wcdb = null;
    public WcUsers|null $Wcuser = null;
    
    public WcWebSocketStructure $webSocketStructure;
    public function __construct() {

    }
    /** For testing and live version */
    public function _setupFeatures() {
        foreach ($this->features as $feature) {
            switch ($feature) {
                case WcFeatures::database:
                    $this->Wcdb->init();
                    break;
                case WcFeatures::userSupport:
                    break;
                case WcFeatures::webSocket:
                    break;
            }
        }
    }
    /** used for table suffix setted in WcBackendManager::_construct() */
    public function _setSuffix(string $suffix): void {
        $this->suffix = $suffix;
    }
    /**
     * @param string $name the name is the id of the request to redirect the request to this callback
     * @param Closure $callback this is the callback function and parameters parsed are (WcFeaturesManager $features, WcRequests $request)
     */
    public function createRequest(string $name, Closure $callback): void {
        if(array_key_exists($name, $this->requests)) Logger::log("The request {$name} already exists");
        $this->requests[$name] = $callback;
    }
    public function getRequest(string $name): Closure {
        if(!array_key_exists($name, $this->requests)) Logger::log("The request {$name} does not exists");
        return $this->requests[$name];
    }
    public function getsession(): array {
        if(!isset($_SESSION[$this->suffix])) $_SESSION[$this->suffix] = [];
        return $_SESSION[$this->suffix];
    }
    public function setsession(array $session): void {
        if(!isset($_SESSION[$this->suffix])) $_SESSION[$this->suffix] = [];
        $_SESSION[$this->suffix] = [$_SESSION[$this->suffix], ...$session];
    }
    public function database(): WcDatabase {
        if(!$this->_featureIsEnabled(WcFeatures::database)) Logger::log("you must enable database before you can use it with WcFeaturesManager::enableDatabaseAccess");
        if($this->Wcdb === null) Logger::log("you must initialize the database before you can use it with WcFeaturesManager::enableDatabaseAccess");
        return $this->Wcdb;
    }
    public function _featureIsEnabled(WcFeatures $feature): bool {
        foreach($this->features as $f) {
            if($f == $feature) return true;
        } return false;
    }
    /** 
     * enable access to the database and use for initialize your database for testing 
     **/
    public function enableDatabaseAccess(WcDatabaseStructure $structure, string $host = 'localhost', string $user = 'root', string $password = '', string $db_name = 'Wc_Project'): WcDatabase {
        $this->features[] = WcFeatures::database;
        $this->Wcdb = new WcDatabase($structure, $host, $user, $password, $db_name);
        $this->Wcdb->_setSuffix($this->suffix);
        return $this->Wcdb;
    }
    /** 
     * enable support for user Login/Register 
     * @param array $WcColumnsStructure array of WcColumnsStructure (this will be used to add custom columns for users in the database)
     */
    public function enableUserSupport(array $WcColumnsStructure = []): WcUsers {
        if(!$this->_featureIsEnabled(WcFeatures::database)) Logger::log("you must enable WcFeatures::database before you can use WcFeatures::userSupport");
        $this->features[] = WcFeatures::userSupport;

        if($_ENV['wc_data']['userSupport_data']['enabled'] === false) {
            if($this->Wcdb->structure->tableExists(WC_USER_TABLE)) Logger::log("Wc_users_custom table is used by WcFeatures::userSupport rename your table for something else");
            $_ENV['wc_data']['userSupport_data']['enabled'] = true;
        }
        $this->Wcuser = new WcUsers($this->suffix, $WcColumnsStructure, $this->Wcdb->_getCreds());
        return $this->Wcuser;
    }
    /** enable the webSocket */
    public function enableWebSocket(WcWebSocketStructure $webSocket) {
        $this->features[] = WcFeatures::webSocket;
        $webSocket->setSuffix($this->suffix);
        if($_ENV['wc_data']['websocket_data']['run'] == false) return;
        $wsdata = & $_ENV['wc_data']['websocket_data'];
        if($wsdata['wsObject'] == null) $_ENV['wc_data']['websocket_data']['wsObject'] = new SocketServer($wsdata['host'], $wsdata['port'], $_ENV['wc_data']['verbose']);
        $webSocket->socketServer = & $_ENV['wc_data']['websocket_data']['wsObject'];
        $this->webSocketStructure = & $webSocket;
        foreach ($webSocket->actions as $actionName => $callback) {
            $callback = function () use ($callback) {
                $_REQUEST = $_REQUEST['data'];
                $callback($this, $this->webSocketStructure); 
            };
            $webSocket->socketServer->addAction("{$this->suffix}_$actionName", $callback);
        }
        if($webSocket->onConnect) $webSocket->socketServer->onConnect(function () use ($webSocket) { $webSocket->setOnConnection()($this, $webSocket); });
        if($webSocket->onDisconnect) $webSocket->socketServer->onClose(function () use ($webSocket) { $webSocket->setOnDisconnect()($this, $webSocket); });

    }
    /**
     * For --init option
     */
    public function _initFeatures() {
        $pdo = null;
        if($this->_featureIsEnabled(WcFeatures::database)) {
            $creds = $this->Wcdb->_getCreds();
            $pdo = new PDO("mysql:host={$creds['host']}", $creds['username'], $creds['password']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sql = "CREATE DATABASE IF NOT EXISTS {$creds['dbname']}";
            $pdo->exec($sql);

            $pdo = new PDO("mysql:host={$creds['host']};dbname={$creds['dbname']};charset=utf8", $creds['username'], $creds['password']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        foreach ($this->features as $feature) {
            switch ($feature) {
                case WcFeatures::database:
                    $this->_initDatabase($pdo);
                    echo "\nDatabase and tables created\n";
                    break;
                case WcFeatures::userSupport:
                    $this->_initUserSupport($pdo);
                    break;
                case WcFeatures::webSocket:
                    break;
                default:
                    break;
            }
        }
    }
    private function _initWebSocket() {

    }
    private function _initDatabase(PDO $pdo) {
        foreach ($this->Wcdb->structure->tables as $table => $columnStructure) {
            if ($columnStructure instanceof WcTableStructure) {
                $sql = "CREATE TABLE IF NOT EXISTS {$this->suffix}_{$table}";
                $columnString = '(Wc_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,';
                foreach($columnStructure->columns as $column) {
                    if ($column instanceof WcColumnStructure) {
                        $columnString.= "{$column->name} {$column->type->value},";
                        if ($column->length != null) {
                            $columnString = substr($columnString, 0, -1);
                            $columnString .= "({$column->length}),";
                        }
                    }
                }
                $columnString = substr($columnString, 0, -1);
                $sql.= $columnString;
                $sql.= ")";
                $pdo->exec($sql);
            }
        }
    }
    private function _initUserSupport(PDO $pdo) {
        $table = $this->Wcuser->db->structure->getTable(WC_USER_TABLE);
        $sql = "CREATE TABLE IF NOT EXISTS ".WC_USER_TABLE. " (Wc_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(null);
        $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach($table->columns as $column) {
            $sql = "SELECT {$column->name} FROM {$table->tableName}";
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute(null);
                $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $sql = "ALTER TABLE {$table->tableName} ADD {$column->name} {$column->type->value}";
                if($column->length!= null) $sql.= "({$column->length})";
                $pdo->exec($sql);
            }
        }
        echo "\nUserSupport inited for {$this->suffix}\n";
    }
}
class WcUsers {
    private string $suffix = '';
    public WcDatabase $db;
    /** 
     * Query database for userdata 
     * @param string $username the username to query
     * @param array $returnData ['column_name', ...]
     */
    public function __construct(string $suffix, array $customColumns = [], array $creds = []) {
        $this->suffix = $suffix;
        $_SESSION['wc_session'][$suffix] = array();
        $this->_initDatabase($customColumns, $creds);
    }
    private function _initDatabase(array $customColumns = [], array $creds = []) {
        for ($i = 0; $i < count($customColumns); $i++) {
            $struct = & $customColumns[$i];
            if($struct instanceof WcColumnStructure) {
                $struct->name = "{$this->suffix}_{$struct->name}";
            } else Logger::log('$WcColumnsStructure must contain only WcColumnStructure instances in enableUserSupport');
        }
        $structure = new WcDatabaseStructure();
        $structure->addTable(WC_USER_TABLE, [
            new WcColumnStructure('username', ColumnTypeString::char, 100),
            new WcColumnStructure('password', ColumnTypeString::char, 100),
            new WcColumnStructure('AuthToken', ColumnTypeString::char, 100),
            new WcColumnStructure('userAccess', ColumnTypeNumbers::int, 3),
            new WcColumnStructure('lastLogin', ColumnTypeDate::date, null),
            ...array_map(function(string $col) {
                return new WcColumnStructure($col, ColumnTypeString::char,100);
            }, $_ENV['wc_data']['userSupport_data']['userKeys']),
            ...$customColumns
        ]);
        $this->db = new WcDatabase($structure, $creds['host'], $creds['username'], $creds['password'], $creds['dbname']);
    }
    public function getByUsername(string $username, array $returnData): array {
        for ($i = 0; $i < count($returnData); $i++) {
            $returnData[$i] = "{$this->suffix}_{$returnData[$i]}";
        }
        return $this->db->get(WC_USER_TABLE, [
            'username' => $username,
        ], $returnData);
    }
    private function formatColName(array $colArray): array {
        for ($i = 0; $i < count($colArray); $i++) {
            $colArray[$i] = "{$this->suffix}_{$colArray[$i]}";
        }
        return $colArray;
    }
    public function get(array $testCols, array $returnCols): array {
        return $this->db->get(WC_USER_TABLE, $this->formatColName($testCols), $this->formatColName($returnCols));
    }   
    public function update(array $testCols, array $newColValues): void {
        $this->db->update(WC_USER_TABLE, $this->formatColName($testCols), $this->formatColName($newColValues));
    }
    /**
     * @return string|null username if logged in, null otherwise
     */
    public function getUsername($randomUser = false): string|null {
        if ($randomUser) return 'User_' . Utils::GenerateRandomString(5); 
        if(!isset($_SESSION['wc_session']['username'])) return null;
        return $_SESSION['wc_session']['username'];
    }
    public function setSession(array $data): void {
        $_SESSION['wc_session'][$this->suffix] = [...$_SESSION[$this->suffix], ...$data];
    }
    public function getSession(): array {
        return $_SESSION['wc_session'][$this->suffix];
    }
    public function login(string $username, string $password): bool {
        $user = $this->db->get(WC_USER_TABLE, ['username' => strtolower($username)], ['username', 'password', 'userAccess']);
        if(empty($user)) return false;
        if(!password_verify($password, $user[0]['password'])) return false;

        $_SESSION['wc_session']['username'] = $username;
        $_SESSION['wc_session']['userAccess'] = $user[0]['userAccess'];
        return true;
    }
    /**
     * @param array $register ['username' => string, 'password' => string, (custom keys from SiteUserFields)] 
     * @return array true if registration is successful and array of errored fields [string, ...]
     ** you can get registration fields with WcUsers::getRegistrationFields()
     */
    public function register(array $register): array|bool {
        $returnArray = array();
        $cols = $this->getRegisterFields();
        if(isset($register[SiteUserFields::username->value])) $register[SiteUserFields::username->value] = strtolower($register[SiteUserFields::username->value]);
        foreach($cols as $key) {
            if(!array_key_exists($key, $register)) Logger::log('Register: ' . implode(', ', $cols) . ' must be provided');
        }
        if(isset($this->db->get(WC_USER_TABLE, ['username' => $register['username']], ['username'])[0])) {
            $returnArray[] = SiteUserFields::username->value;
        } 
        if(in_array(SiteUserFields::email->value, $cols) && !empty($this->db->get(WC_USER_TABLE, ['email' => $register['email']], ['email']))) {
            $returnArray[] = SiteUserFields::email->value;
        }
        if(empty($returnArray)) {
            $register[SiteUserFields::password->value] = password_hash($register[SiteUserFields::password->value], PASSWORD_DEFAULT);
            $this->db->insert(WC_USER_TABLE, $register);
            return true;
        }
        else return $returnArray;
    }
    public function modifyPassword(string $newPassword): bool {
        if(!$this->getUsername()) return false;
        $this->db->update(WC_USER_TABLE, ['username' => $this->getUsername()], ['password' => password_hash($newPassword, PASSWORD_DEFAULT)]);
        return true;
    }
    /**
     * @return array array of strings representing the registration fields the client wants to register (field names are listed in enum SiteUserFields)
     */
    public function getRegisterFields() {
        return $_ENV['wc_data']['userSupport_data']['userKeys'];
    }
    /**
     * 
     */
    public function modifyUserAccess(string $username, int $access) {
        $this->db->update(WC_USER_TABLE, ['username' => $username], ['userAccess' => $access]);
    }
    /**
     * @return bool true if user is logged in, false otherwise
     */
    public function isLogged(): bool {
        if(!isset($_SESSION['wc_session']['username'])) return false;
        return true;
    }
}
class WcDatabase
{
    /** array of WcDatabaseStructure */
    public WcDatabaseStructure|null $structure = null;
    private PDO $db;
    private PDOStatement $stmt;
    private array $result = array();
    private string $db_name = ''; // will be set when the compiler will run
    private string $host = 'localhost';
    private string $user = 'root';
    private string $pass = '';
    private string $suffix = '';
    /**
     * @param string $host the host for testing purposes
     * @param string $user the user for testing purposes
     * @param string $pass the password for testing purposes
     * @param string $db_name the database name for testing purposes
     */
    public function __construct(WcDatabaseStructure $structure, string $host = 'localhost', $user = 'root', $pass = '', $db_name = 'Wc_project')
    {
        $this->structure = $structure;
        if(!WcBackendManager::isLive()) {
            $_ENV['wc_data']['database_data'] = [
                ...$_ENV['wc_data']['database_data'],
                'host' => $host,
                'user' => $user,
                'password' => $pass,
                'db_name' => $db_name,
            ];
        }
        $data = $_ENV['wc_data']['database_data'];
        $this->host = $data['host'];
        $this->user = $data['user'];
        $this->pass = $data['password'];
        $this->db_name = $data['db_name'];
    }

    public function _getCreds(): array {
        return ['username' => $this->user, 'password' => $this->pass, 'host' => $this->host, 'dbname' => $this->db_name];
    }
    /** used for table suffix random naming... set in WcFeaturesManager::enableDatabaseAccess() */
    public function _setSuffix(string $suffix): void {
        $this->suffix = $suffix;
    }
    public function init()
    {
        try {
            $this->db = new PDO("mysql:host={$this->host};dbname={$this->db_name};charset=utf8", $this->user, $this->pass);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) { echo 'Error while connecting to Database: ' . $e->getMessage(); }
    }
    /** 
     * @param int $id the id of the row to query
     * @param string $table the name of the table to query
     * @param array $returnCols the columns to return
     */
    public function getRowById(int $id, string $table, array $returnCols): array {
        $tab = $this->structure->getTable($table);
        $retColStr = '';
        foreach($returnCols as $col) {
            if(!$tab->columnExists($col)) Logger::log("getRowById({$id}, {$table}, array()) - column {$col} does not exist in your structure");
            $retColStr.= "{$col},";
        }
        $retColStr = substr($retColStr, 0, -1);
        $this->makeQuery("SELECT {$retColStr} FROM {$this->suffix}_{$table} WHERE Wc_id = ?", [$id]);
        return $this->result;
    }
    /**
     * For delete and get operations
     * @param string $table name of the table
     * @param array $columnsToTest table parameters must be in the following format: ['column_name' => 'value_is', ...]
     * @param array|string $columnsToreturn array of columns to return ['column_name', 'column_name',...]
     **/
    public function get(string $table, array $columnsToTest, array|string $columnsToreturn): array
    {
        $query = "";
        $for = [];

        if(is_array($columnsToreturn)) {
            $columns = array_keys($columnsToTest);
            $this->tableColumnExists($table, $columns, 'in $params');
            $this->tableColumnExists($table, $columnsToreturn, 'in $columnsToreturn');

            $columnToSelectString = '';
            foreach ($columnsToreturn as $column) {
                $columnToSelectString.= "{$column},";
            } $columnToSelectString = substr($columnToSelectString, 0, -1);
        } else if($columnsToreturn == '*') {
            $columnToSelectString = '*';
        } else Logger::log('The value of the key $columnsToreturn must be an array("column_name", ...) or \'*\' ');
        $columnsToVerifyString = '';
        foreach ($columnsToTest as $key => $value) {
            $columnsToVerifyString.= "{$key} = ? AND ";
            $for[] = $value;
        } $columnsToVerifyString = substr($columnsToVerifyString, 0, -5);

        $query = "SELECT {$columnToSelectString} FROM {$this->suffix}_{$table} WHERE {$columnsToVerifyString}";
        $this->makeQuery($query, $for);
        return $this->result;
    }
    public function deleteRowById(int $id, string $table): void {
        $this->structure->getTable($table);
        $this->stmt = $this->db->prepare("DELETE FROM {$this->suffix}_{$table} WHERE Wc_id =?");
        $this->stmt->execute([$id]);
    }
    /**
     * Delete a row from a table
     * @param string $table name of the table to delete a row
     * @param array $columnsKeys table parameters must be in the following format: ['column_name' => 'value_is', ...]
     */
    public function delete(string $table, array $columnsKeys = array()) {
        if(count($columnsKeys) == 0) Logger::log('You must specify at least one $columnsKeys');
        $columns = array_keys($columnsKeys);
        $this->tableColumnExists($table, $columns, 'in $columnsKeys');
        $columnsToTestString = '';
        foreach ($columnsKeys as $param) {
            $columnsToTestString.= "{$param['column']} =? AND ";
        } $columnsToTestString = substr($columnsToTestString, 0, -5);

        $query = "DELETE FROM {$this->suffix}_{$table} WHERE {$columnsToTestString}";
        $this->makeQuery($query, $columnsKeys);
    }
    /**
     * Insert a row into a table ( all columns must be specified )
     * @param string $table name of the table to insert a row
     * @param array $columnsKeys table parameters must be in the following format: ['column_name' => 'value', 'column_name' => 'value',...]
     * @return bool|string return the id of the inserted row or false if an error occurs
     */
    public function insert(string $table, array $columnsKeys = array()) {
        $tableObj = $this->structure->getTable($table);
        $this->tableColumnExists($table, array_keys($columnsKeys), 'in insert($table, $columnsKeys)');
        $missingKeys = array();
        foreach ($tableObj->columns as $column) {
            if ($column instanceof WcColumnStructure) {
                if(!array_key_exists($column->name, $columnsKeys)) $missingKeys[] = $column->name;
                else {
                    $column->checkFormat($columnsKeys[$column->name]);
                    $column->checkLength($columnsKeys[$column->name]);
                }
            }
        }
        if (count($missingKeys) > 0) Logger::log("\n\nYou must specify all the columns values for the table {$table}.\nMissing columns: [". implode(", ", $missingKeys)."]\n\n");
        $query = "INSERT INTO {$this->suffix}_{$tableObj->tableName} SET ";
        $for = [];
        foreach ($columnsKeys as $column => $value) {
            $query.= "{$column} =?, ";
            $for[] = $value;
        } $query = substr($query, 0, -2);
        $this->makeQuery($query, $for);
        return $this->db->lastInsertId();
    }
    /**
     * Update a row in a table ( all columns must be specified )
     * @param string $table name of the table to update a row
     * @param array $columnsKeys table parameters must be in the following format: ['column_name' => 'value', 'column_name' => 'value',...]
    */
    public function updateRowById(int $id, string $table, array $columnsKeys = array()) {
        $this->tableColumnExists($table, array_keys($columnsKeys), 'in updateRowById($id, $table, $columnsKeys)');
        $sql = "UPDATE {$this->suffix}_{$table} SET ";
        foreach ($columnsKeys as $column => $value) {
            $sql.= "{$column} =?, ";
        }
        $sql = substr($sql, 0, -2);
        $sql.= " WHERE Wc_id =?";
        $this->makeQuery($sql, [...array_values($columnsKeys), $id]);
    }
    /**
     * Update a row in a table
     * @param string $table name of the table to update a row
     * @param array $columnsToTest table parameters must be in the following format: ['column_name' => 'value', ...]
     * @param array $columnsToChange table parameters must be in the following format: ['column_name' => 'newValue', ...]
     */
    public function update(string $table, array $columnsToTest ,array $columnsToChange = array()) {
        $tableObj = $this->structure->getTable($table);
        $whereColumnsString = '';
        $keycheck = array_keys($columnsToTest);
        $this->tableColumnExists($table, $keycheck, 'in $columnsToTest');
        $keycheck = array_keys($columnsToChange);
        $this->tableColumnExists($table, $keycheck, 'in $columnsToChange');

        $query = '';
        $for = [];

        $setColumnsString = '';
        foreach ($columnsToChange as $column => $value) {
            $setColumnsString.= "{$column} =?, ";
            $for[] = $value;
        } $setColumnsString = substr($setColumnsString, 0, -2);

        foreach ($columnsToTest as $column => $value) {
            $whereColumnsString.= "{$column} =? AND ";
            $for[] = $value;
        } $whereColumnsString = substr($whereColumnsString, 0, -5);
        $query = "UPDATE {$this->suffix}_{$tableObj->tableName} SET {$setColumnsString} WHERE {$whereColumnsString}";
        $this->makeQuery($query, $for);
    }
    /** 
     * @param string $table the name of the table to count
     * @return int number of row in table
     */
    public function rowCount(string $table): int {
        $this->structure->getTable($table);
        $this->makeQuery("SELECT COUNT(*) FROM {$this->suffix}_{$table}", []);
        return intval($this->result[0]['COUNT(*)']);
    }
    private function makeQuery(string $query, array $for) {
        $this->stmt = $this->db->prepare($query);
        $this->stmt->execute($for);
        $this->result = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function tableColumnExists(string $table, string|array $columns, string $errorSuffix = '')
    {
        if (!is_array($columns)) $columns = array($columns);
        $table = $this->structure->getTable($table);
        if (!$table) Logger::log("\nTable '{$table->tableName}' not found in your structure\n\n");
        foreach ($columns as $column) {
            if (!$table->columnExists($column)) Logger::log("\nColumn '{$column}' not found in table '{$table->tableName}' you must define the structure! {$errorSuffix}\n\n");
        }
        return true;
    }
    private function tableExists(string $table): bool {
        try { $this->structure->getTable($table); } catch (Exception $e) { return false; }
        return true;
    }
}

// compiler & backend.dev common data and functions
class WcDatabaseStructure
{
    public array $tables = [];

    /**
     * @param string $tableName Table name
     * @param array $columns array of WcColumnStructure
     */
    public function addTable(string $tableName, array $columns = []): WcDatabaseStructure
    {
        if (isset($this->tables[$tableName])) {
            Logger::log("Table {$tableName} already exists");
        }
        foreach ($columns as $column) {
            if ($column instanceof WcColumnStructure) continue;
            else Logger::log('Items in columns must be instances of WcColumnStructure');
        }
        $this->tables[$tableName] = new WcTableStructure($tableName, $columns);
        return $this;
    }
    public function getTable(string $tableName): WcTableStructure
    {
        if (!array_key_exists($tableName, $this->tables)) Logger::log("\nTable {$tableName} does not exist in WcDatabaseStructure\n\n");
        return $this->tables[$tableName];
    }
    public function tableExists(string $tableName): bool {
        return array_key_exists($tableName, $this->tables);
    }
}
class WcTableStructure
{
    public string $tableName;
    /** array of WcColumnStructure */
    public array $columns;

    public function __construct(string $tableName, array $columns)
    {
        $this->tableName = $tableName;
        $this->columns = $columns;
    }
    public function columnExists(string $columnName)
    {
        foreach ($this->columns as $column) {
            if ($column->name == $columnName) return true;
        }
        return false;
    }
    public function addColumn(WcColumnStructure $column) {
        if($this->columnExists($column->name)) Logger::log("Column {$column->name} already exists in table {$this->tableName}");
        $this->columns[] = $column;
        return $this;
    }
    public function addColumns(array $columns) {
        foreach ($columns as $column) {
            $this->addColumn($column);
        }
        return $this;
    }
}
class WcColumnStructure
{
    public string $name;
    public ColumnTypeString|ColumnTypeNumbers|ColumnTypeDate $type;
    public int|null $length = null;

    /** 
     * @param string $name may create an error if the word is reserved by the database https://dev.mysql.com/doc/refman/8.0/en/keywords.html
     */
    public function __construct(string $name, ColumnTypeString|ColumnTypeNumbers|ColumnTypeDate $type, int|null $length)
    {

        $this->name = $name;
        $this->type = $type;
        $this->length = $length;
    }
    public function checkFormat($valueToTest) {
        $type = gettype($valueToTest);
        switch ($type) {
            case'string':
                if ($this->type instanceof ColumnTypeString) break;
                elseif($this->type instanceof ColumnTypeDate) break;
                else Logger::log("Wrong format for column {$this->name} must be string ({$this->type->value})");
            case 'integer':
            case 'double':
            case 'boolean':
                if ($this->type instanceof ColumnTypeNumbers === false) Logger::log("Wrong format for column {$this->name} must be number ({$this->type->value})");
                break;
            case 'array':
                if ($this->type != ColumnTypeString::json) Logger::log("Wrong format for column {$this->name} must be string (JSON)");
                break;
            case 'NULL': break;
            default:
                Logger::log("Wrong format for column {$this->name} must be  ({$this->type->value})");            
        }
    }
    public function checkLength($valueToTest) {
        if($this->length === null) return;
        switch(gettype($valueToTest)) {
            case 'integer':
            case 'double':
                $len = strlen(strval($valueToTest));
                if($len > $this->length) Logger::log("Wrong length for column {$this->name} must be of max: {$this->length}\nCurrent length: {$len}");
                break;
            case'string':
                $len = strlen($valueToTest);
                if($len > $this->length) Logger::log("Wrong length for column {$this->name} must be of max: {$this->length}\nCurrent length: {$len}");
                break;
            default:
                break;
        }
    }
}
enum ColumnTypeString: string {
    /** 0 to 65.535 */
    case varchar ='VARCHAR';
    /** 0 to 255 */
    case char = 'CHAR';
    /** 0 to 65.535 */
    case text = 'TEXT';
    /** static max: 16.777.215 */
    case mediumtext = 'MEDIUMTEXT';
    /** static max:  4.294.967.295 */
    case longtext = 'LONGTEXT';
    case json = 'JSON';
};
enum ColumnTypeNumbers: string {
    /** 1 to 64 (Default: 1) */
    case bit = 'BIT';
    /** 1 or 0 */
    case boolean = 'BOOLEAN';
    /** digit after ( , ) length is default to 100 */
    case double = 'DOUBLE';
    /** min: -9223372036854775808, max: 9223372036854775807 */
    case int = 'INT';
};
enum ColumnTypeDate: string {
    /** YYYY-MM-DD */
    case date = 'DATE';
    /** YYYY-MM-DD hh:mm:ss */
    case datetime = 'DATETIME';
    /** min: '-838:59:59', max: '838:59:59' */
    case time = 'TIME';
}
class Logger {
    public static function log(string $message) {
        $message = PHP_EOL . $message . PHP_EOL;
        if ($_ENV['wc_data']['verbose'] === true) {
            throw new Exception($message);
        } else die($message);
    }
} 

class ArgumentsManager
{
    private $argsList = [];
    private $stringIndexer = ['\'', '"', '`'];

    private $RawArgs = [];

    /**
     * @param $args array [ '-a' => 'Description' ]
     * @return bool true if all args are valid and false otherwise
     */
    public function SetArgs(array $args)
    {
        $this->RawArgs = $args;
        $string_to_parse = '';
        if(!is_array($_SERVER['argv'])) return false;
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
    public function exists(string $getArg) {
        foreach ($this->argsList as $key => $arg) {
            if(array_key_exists($getArg, $arg)) return true;
        } return false;
    }
}
