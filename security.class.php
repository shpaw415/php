<?php

class Security {

    public $isloged = false;
    private $login;
    private $unauthorized;

    public function __construct()
    {
        include_once 'config.php';
        $this->unauthorized = ['<', '>', '(', ')', '{', '}', '[', ']', '=', '+', '-', '*', '/', '\\', ';', ':', '"', "'", ',', '.', '?', '!', '@', '#', '$', '%', '^', '&', '*', '|', '`', '~', ' '];
    }
    public function Encrypt($string, $password = null) {
        if ($password == null) {
            $enc_key = ENCRYPTION_KEY;
        } else {
            $enc_key = $password;
        }
        $output = openssl_encrypt($string, "AES-256-CBC", $enc_key, 0, ENCRYPTION_IV);
        $output = base64_encode($output);
        return $output;
    }
    public function Decrypt($string, $password = null) {
        if ($password == null) {
            $enc_key = ENCRYPTION_KEY;
        } else {
            $enc_key = $password;
        }
        $output = openssl_decrypt(base64_decode($string), "AES-256-CBC", $enc_key, 0, ENCRYPTION_IV);
        return $output;
    }
    public function Sanitize_xss($string) {
            return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
    public function Verify_sanitized($dic) {
        foreach ($dic as $key => $value) {
            if ($key != $value) {
                return false;
            }
        }
        return true;
    }
    public function Verify_email($email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return true;
        } else {
            return false;
        }
    }
    public function Sanitize_path($string) {
        $unothorized = ['/', '\\', '..'];
        foreach ($unothorized as $char) {
            if (strpos($string, $char) != false) {
                return false;
            }
        } return true;
    }
    public function unauthorizedChar() {
        $rtn = '';
        foreach ($this->unauthorized as $char) {
            $rtn = $rtn . $char . ', ';
        } return $rtn;
    }
    public function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ*~!@#$%^&()_+-=,./<>?;:[]{}|';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
    static function password_hash($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}