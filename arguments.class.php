<?php

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