<?php

define('LOGIN_AUTH_TOKEN', 'loginToken');

class Login {
    public $db;
    private $security;

    public $returnMsg;

    private $cookie_name;
    private $cookie_value;
    public $isloged;
    public $username;
    private $cookie_name_AutoLogin;
    private $remember;
    private $cookie_name_token;

    private $table;
    private $user_column;
    private $pass_column;
    private $email_column;
    private $token_column;
    public $userdata;

    private $errorData = [];

    /**
     * @param string $table the table name for the login table (user)
     * @param string $user_column the column name for the username
     * @param string $pass_column the column name for the password
     * @param string $email_column the column name for the e-mail
     * @param string $token_column the column name for the token (WebSocket token)
     */
    public function __construct(
        $table = 'user', 
        $user_column = 'username', 
        $pass_column = 'password',
        $email_column = 'email',
        $token = 'AuthToken',
    ) {
        include_once 'security.class.php';
        include_once 'database.class.php';

        $this->db = new Database();
        $this->security = new Security();

        $this->isloged = false;
        $this->remember = false;
        $this->returnMsg = '';

        $this->cookie_name = 'loginToken';
        $this->cookie_name_AutoLogin = 'loginTokenAuto';
        $this->cookie_name_token = 'loginTokenWS';

        $this->cookie_value = false;

        $this->table = $table;
        $this->user_column = $user_column;
        $this->pass_column = $pass_column;
        $this->email_column = $email_column;
        $this->token_column = $token;


    }

    public function login($username = false, $password = false , $email = false) {
        $username = strtolower($username);
        $email = $email ? strtolower($email) : '';
        if (!$username || empty($username)) return $this->CookieLogin();
        $query = "SELECT * FROM ".$this->table." WHERE ".$this->user_column." = ? OR ".$this->email_column." = ?";
        $for = [$username, $email];
        $result = $this->db->query_me($query, $for);
        if (!$this->db->query_me($query, $for)) {
            $this->returnMsg = 'username or password incorrect';
            return false;
        }
        $this->userdata = $this->db->result[0];
        if (!password_verify($password, $this->userdata['password'])) {
            $this->returnMsg = 'username or password incorrect';
            return false;
        }
        $this->username = $this->userdata['username'];
        $this->isloged = true;
        $this->SetCookie();
        $this->SetCookieTokenWS();
        $this->returnMsg = 'you are now logged in';
        return true;
    }
    public function signUp($username, $password, $email = false) {
        include_once 'utils.class.php';
        $username = strtolower($username);
        $password = password_hash($password, PASSWORD_DEFAULT);
        $email = strtolower($email);


        $for = [$username];
        $emailStr = '';

        if($email && !Utils::isEmailAddress($email)) {
            $this->returnMsg = 'invalid email address';
            return false;
        } else if ($email) {
            $emailStr =  "or {$this->email_column} =?";
            $for[] = $email;
        }
        $query = "select * from {$this->table} where {$this->user_column} =? {$emailStr}";
        $res = $this->db->query_me($query, $for);
        if ($res) {
            foreach($res as $row) {
                if($row['username'] == $username) {
                    $this->returnMsg = 'username already exists';
                    $this->errorData[] = 'username';
                }
                if ($row['email'] == $email) {
                    $this->returnMsg = 'email already exists';
                    $this->errorData[] = 'email';
                }
            } return false;
        }
        
        $qmark = '';
        $for = [$username, $password];
        if ($email) {
            $emailStr = ", {$this->email_column}";
            $qmark = ',?';
            $for[] = $email;
        }
        $query = "INSERT INTO {$this->table} ({$this->user_column}, {$this->pass_column}{$emailStr}) VALUES (?,?{$qmark})";
        $this->db->query_me($query, $for);
        $this->returnMsg = 'User created';
        return true;
    }
    public function getError() {
        return $this->errorData;
    }
    public function logout(){
        setcookie($this->cookie_name_AutoLogin, "0", time() - 3600, "/");
        setcookie($this->cookie_name, "", time() - 3600, "/");
        return true;
    }
    public function WSLogin($username, $token) {
        if (!$this->CheckToken($username, $token)) return false;
        $this->username = $username;
        $this->isloged = true;
        return true;
    }
    public function SetRemember($value) {
        if (!$value) return;
        $this->remember = true;
    }
    public function CheckLoginStatus() {
        return $this->isloged;
    }
    private function CookieLogin() {
        if (!$this->GetCookie()) return false;
        if (!$this->db->query_me("SELECT * FROM ".$this->table." WHERE BINARY ".$this->user_column." = ?", [$this->cookie_value['username']])) {
            $this->returnMsg = 'Invalid cookie';
            return false;
        }
        $this->userdata = $this->db->result[0];
        $this->username = $this->cookie_value['username'];
        $this->isloged = true;
        $this->SetCookieTokenWS();
        return true;
    }
    public function MakeToken() {
        $token = $this->security->generateRandomString(32);
        $query = "UPDATE ".$this->table." SET ".$this->token_column." = ? WHERE ".$this->user_column." = ?";
        $for = [$token, $this->username];
        $this->db->query_me($query, $for);
        return $token;
    }
    private function CheckToken($username, $token) {
        $query = "SELECT ".$this->token_column." FROM ".$this->user_column." WHERE ".$this->user_column." = ? AND ".$this->token_column." = ?";
        $for = [$username, $token];
        $token = $this->db->query_me($query, $for)[0][$this->token_column] ?? false;
        $query = "UPDATE ".$this->user_column." SET ".$this->token_column." = ? WHERE ".$this->user_column." = ?";
        $for = [null, $username];
        $this->db->query_me($query, $for);

        if (!$token) {
            $this->returnMsg = 'Invalid token';
            return false;
        } return true;
    }
    private function SetCookie() {
        $data = [
            'username' => $this->username,
        ];
        $data = $this->security->Encrypt(json_encode($data));
        setcookie($this->cookie_name, $data, time() + (86400 * 7), "/", "", false, true);
        setcookie($this->cookie_name_AutoLogin, $this->remember, time() + (86400 * 7), "/");
    }
    private function SetCookieTokenWS() {
        $data = [
            'username' => $this->username,
            'token' => $this->MakeToken(),
        ];
        $data = $this->security->Encrypt(json_encode($data));
        setcookie($this->cookie_name_token, $data, time() + (86400 * 7), "/");
    }
    
    private function GetCookie() {
        if (!isset($_COOKIE[$this->cookie_name])) {
            $this->returnMsg = 'Cookie not found';
            return false;
        }
        $decrypted_cookie = $this->security->Decrypt($_COOKIE[$this->cookie_name]);
        if (!$decrypted_cookie) {
            $this->returnMsg = 'Invalid cookie';
            return false;
        }
        $this->cookie_value = json_decode($decrypted_cookie, true);
        return true;
    }
    static function isCookied() {
        return isset($_COOKIE[LOGIN_AUTH_TOKEN]);
    }
    public function SetSession() {
        $_SESSION['username'] = $this->username;
        $_SESSION['login'] = true;
    }
}
?>