<?php

class ReturnData
{
    private $status;
    private $data;
    private $msg;

    public function __construct()
    {
        $this->status = '';
        $this->data = [];
        $this->msg = '';
    }
    public function SendReturnData(bool $die, string|bool $status, string $msg = '', Array $data = [])
    {
        $this->status = $status;
        $this->data = $data;
        $this->msg = $msg;

        echo json_encode([
            'status' => $this->status,
            'data' => $this->data,
            'message' => $this->msg,
        ]);
        if ($die) die();
    }
    public function SetMessage(string $msg)
    {
        $this->msg = $msg;
        return $this;
    }
    public function SetData(Array $data)
    {
        $this->data = $data;
        return $this;
    }
    public function SetStatus(mixed $status)
    {
        $this->status = $status;
        return $this;
    }
    public function Send() {
        $this->SendReturnData(true, $this->status, $this->msg, $this->data);
    }
    static function Go(mixed $status, String|int $msg, String|int|array $data = []) {
        die(json_encode([
           'status' => $status,
            'data' => $data,
            'message' => $msg,
        ]));
    }
}