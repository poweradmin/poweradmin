<?php

class CliParser
{

    private $cli_settings;

    function __construct($cli_settings)
    {
        $this->cli_settings = $cli_settings;
    }

    public function parse($argv)
    {
        $params = array();
        $n_arg = 0;
        foreach($argv as $arg)
        {
            $skip = 0;

            foreach($this->cli_settings as $key => $value)
            {
                // Other token
                if($arg != $key) { continue; }

                // Handle flags
                if($value['type'] == 'flag')
                {
                    $params[$value['name']] = true;
                    break;
                }

                // Handle arguments with values
                if($value['type'] == 'arg') {
                    $count = $value['count'];
                    $params[$value['name']] = array_slice($argv, $n_arg+1, $count);

                    // No need to nest if there is only one value
                    if($count == 1) { $params[$value['name']] = $params[$value['name']][0]; }

                    $skip = $count;
                    break;
                }

                // Handle variable arguments
                if($value['type'] == 'vararg') {
                    $look_ahead = 0;
                    while(true)
                    {
                        // From here, look ahead
                        $val = $argv[$n_arg + 1 + $look_ahead];

                        // If we have reached another arg, exit. We are done with varargs.
                        if(stripos($val, '--') === 0) { break; }

                        // Write the value in an array
                        $params[$value['name']][] = $val;

                        // Look ahead further
                        ++$look_ahead;
                    }
                }
            }

            // Skip params for arguments with >= 1 params
            for($i = 0; $i < $skip; ++$i)
            {
                continue;
            }
            ++$n_arg;
        }

        // Insert default values if empty
        foreach($this->cli_settings as $key => $value) {
            $not_set = !isset($params[$value['name']]);
            $has_default = isset($value['default']) ? true : false;
            if($has_default === true && $not_set === true)
            {
                $params[$value['name']] = $value['default'];
            }
        }

        // Check required
        foreach($this->cli_settings as $key => $value) {
            $not_set = !isset($params[$value['name']]);
            $is_required = isset($value['required']) ? $value['required'] : false;
            if($is_required === true && $not_set === true) {
                throw new InvalidArgumentException("Argument --" . $value['name'] . " is required but was not set.");
            };
        }
        return $params;
    }
}
