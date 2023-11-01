<?php

class Requests {
    public $data;
    private $httpCode;
    public function Get($url, $headers = [], $ssl = true) {
        $ch = $this->MakeInit($url, $headers, $ssl);
        $output = curl_exec($ch);
        curl_close($ch);
        if(curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200) {
            $this->data = false;
            return false;
        }
        $this->data = $output;
        return $output;
    }
    public function Post($url, $data, $headers = [], $ssl = true) {
        $ch = $this->MakeInit($url, $headers, $ssl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $this->MakeRequest($ch);
    }
    public function FileDownload($url, $output_path, $data = false, $headers = [], $ssl = true) {
        set_time_limit(0);
        $ch = $this->MakeInit($url, $headers, $ssl);
        $fp = fopen ($output_path, 'w+');
        $ch = curl_init(str_replace(" ","%20",$url));
        curl_setopt($ch, CURLOPT_TIMEOUT, 1000);
        curl_setopt($ch, CURLOPT_FILE, $fp); 
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        if(curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200) return false;
        return true;
    }
    public function FileUpload($url, $file, $data = false, $headers = [], $ssl = true) {
        $ch = $this->MakeInit($url, $headers, $ssl);
        $cfile = new CURLFile($file);
        $data['file'] = $cfile;
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        return $this->MakeRequest($ch);
    }
    public function GetData() {
        return $this->data;
    }
    private function MakeInit($url, $headers = [], $ssl = true) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl);
        return $ch;
    }
    private function MakeRequest($ch) {
        $output = curl_exec($ch);
        $this->httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);
        if($this->httpCode != 200) {
            $this->data = false;
            return false;
        }
        $this->data = $output;
        return $output;
    }
    public function GetHttpCode() {
        return intval($this->httpCode);
    }
}