<?php

class Config {
    // Error reporting configuration
    public static $errorReporting = E_ALL;
    public static $displayErrors = true;  // Set to false in production
    
    // Application defaults
    public static $defaultSubdir = null;
    public static $defaultVerbose = false;
    
    // Required dependencies
    public static $requiredFiles = [
        'Logger.php',
        'FileParser.php',
        'CSVProcessor.php',
        'TableProcessor.php',
        'TableManager.php',
        'DiceRoller.php'
    ];
    
    // Initialize configuration
    public static function init() {
        if (self::$displayErrors) {
            error_reporting(self::$errorReporting);
            ini_set('display_errors', 1);
        }
        
        // Load required files
        foreach (self::$requiredFiles as $file) {
            require_once $file;
        }
    }
} 