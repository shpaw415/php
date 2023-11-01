<?php

class promptManager {
    private $functionList = Array();
    private $prompt_aguments = Array();
    private $prompt = '$> ';
    private $clearAtCommand;
    private $active_prompt = true;

    public function __construct($clearAtCommand = false) {
        $this->Init();
        $this->clearAtCommand = $clearAtCommand;
    }
    private function Init() {
        $this->functionList["help"] = [
            "description" => "show this message",
            "callback" => function() { 
                $this->ShowHelp($this->GetArgs() != null? $this->GetArgs()[0] : false);
            },
            "options" => []
        ];
    }
    public function get_input($prompt = false) {
        $line = self::formatArgs(readline($prompt === false ? $this->prompt : $prompt));
        $this->prompt_aguments = $line;
        array_shift($this->prompt_aguments);
        return trim($line[0]);
    }
    public function Add($input, $description, $options = [],$callback = false) {
        $this->functionList[$input] = array(
            "description" => $description,
            "callback" => $callback,
            "options" => $options === false ? [] : $options
        );
    }
    public function SetDefaultPrompt($prompt) {
        $this->prompt = $prompt;
    }
    public function ClearScreen() {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') system('cls');
        else system('clear');
    }

    public function Run() {
        $a = true;
        while ($a) {
            $input = $this->get_input();
            if ($this->clearAtCommand) $this->ClearScreen();
            echo PHP_EOL;
            if (array_key_exists($input, $this->functionList)) {
                $this->functionList[$input]["callback"]($this);
            } else echo $this->ShowHelp();
            echo PHP_EOL;
            if (!$this->active_prompt) break;
        }
    }
    public function ExitPrompt() {
        $this->active_prompt = false;
    }
    public function ShowHelp($command = false) {
        if (!$command || $command === null) {
            foreach ($this->functionList as $key => $value) {
                echo PHP_EOL.$key . " - " . $value["description"] . PHP_EOL;
                foreach ($value["options"] as $option => $description) {
                    echo "\t * {$option} - {$description}" . PHP_EOL;
                }
            } return;
        }
        if (!array_key_exists($command, $this->functionList)) {
            echo 'this command does not exist';
            return;
        }
        foreach ($this->functionList[$command] as $option => $description) {
            echo PHP_EOL."{$option}\t {$description}";
        }
        
    }
    public function GetArgs() {
        return $this->prompt_aguments;
    }
    public static function formatArgs(string $args): Array {
        $strChar = ['\'', '"'];
        $args = explode(' ', $args);

        $argNbr = 0;
        $formatedArgs = array();
        $stringMode = false;

        $addToForamted = function ($arg) use (&$formatedArgs, &$argNbr) {
            $formatedArgs[$argNbr][] = $arg;
        };

        for ($i = 0; $i < count($args); $i++) {
            $argStr = $args[$i];
            if(in_array($argStr[0], $strChar) && !$stringMode) {
                $stringMode = true;
                $addToForamted($argStr);
            } else if ($stringMode) {
                if (str_ends_with($argStr, "\\'") || str_ends_with($argStr, '\\"')) { // bypass
                    $addToForamted($argStr);
                    continue;
                } else if(in_array($argStr[strlen($argStr) - 1], $strChar)) {
                    $addToForamted($argStr);
                    $stringMode = false;
                    $argNbr++;
                } else $addToForamted($argStr);
            } else {
                $addToForamted($argStr);
                $argNbr++;
            }
        }
        for ($i = 0; $i < count($formatedArgs); $i++) {
            $formatedArgs[$i] = implode(' ', $formatedArgs[$i]);
            if(str_starts_with($formatedArgs[$i], '\'') | str_starts_with($formatedArgs[$i], '"')) {
                $formatedArgs[$i] = substr($formatedArgs[$i], 1);
            }
            if (str_ends_with($formatedArgs[$i], '\'') | str_ends_with($formatedArgs[$i], '"')) { 
                $formatedArgs[$i] = substr($formatedArgs[$i], 0, strlen($formatedArgs[$i]) - 1);
            }
        }
        return $formatedArgs;
    }
}