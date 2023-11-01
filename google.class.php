<?php

include_once 'config.php';
include_once 'request.class.php';
include_once 'utils.class.php';

class GoogleApi {

    private $api_key;
    private $req;
    private $profile;
    private $console;
    public $returnMsg;

    private $distancematrix_url = 'https://maps.googleapis.com/maps/api/distancematrix/json';
    private $geocode_url = 'https://maps.googleapis.com/maps/api/geocode/json';
    private $mapScript_url = 'https://maps.googleapis.com/maps/api/js';
    private $autoComplete_url = "https://maps.googleapis.com/maps/api/js";

    function __construct($api_key) {

        $this->req = new Requests();
        $this->console = new Utils();
        $this->api_key = $api_key;
        $this->returnMsg = '';
    }

    public function ApiDistBtw($pointA, $pointB) {

        $url = "{$this->distancematrix_url}?units=metric&origins={$pointA['lat']},{$pointA['lng']}&destinations={$pointB['lat']},{$pointB['lng']}&key={$this->api_key}";
        $response = $this->req->get($url);

        if ($response['status'] == 'OK') {
            $this->returnMsg = 'OK';
            return $response['rows'][0]['elements'][0]['distance']['value'];
        } else {
            $this->returnMsg = $response;
            return false;
        }
    }
    public function GetAddrInfo($location_str) {
        $location_str = urlencode($location_str);
        $url = "{$this->geocode_url}?address={$location_str}&key={$this->api_key}";
        return $this->req->get($url);
    }
    public function GetMap() {
        $url =  "{$this->mapScript_url}?key={$this->api_key}&callback=init_Map";
        return $this->req->get($url);
    }
    public function GetAutoCompleteScript() {
        $url = "{$this->autoComplete_url}?key={$this->api_key}&libraries=places&callback=init_Map";
        return $this->req->get($url);
    }
    public function GetScript() {
        $url = "{$this->autoComplete_url}?key={$this->api_key}&libraries=places,maps&callback=init_Map&v=weekly";
        return $this->req->get($url);
    }
    
}