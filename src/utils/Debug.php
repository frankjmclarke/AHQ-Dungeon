<?php
/**
 * Debug printing utilities
 */
class Debug {
    private $config;
    
    public function __construct($config) {
        $this->config = $config;
    }
    
    /**
     * Print debug message if verbose mode is enabled
     * @param string $message Message to print
     */
    public function print($message) {
        if ($this->config->isVerbose()) {
            echo "DEBUG: {$message}\n";
        }
    }
    
    /**
     * Print final output message
     * @param string $message Message to print
     */
    public function printFinal($message) {
        echo "{$message}\n";
    }
} 