<?php
// Logger class for handling debug and output messages
// Allows setting verbosity to control message output

class Logger {
    private static $verbose = false;

    // Sets the verbosity level for logging
    // If true, debug and output messages will be shown
    public static function setVerbose($verbose) {
        self::$verbose = $verbose;
    }

    // Checks if verbosity is enabled
    // Returns true if verbose mode is active
    public static function isVerbose() {
        return self::$verbose;
    }

    // Outputs a debug message if verbosity is enabled
    // Prepends the message with a line break for formatting
    public static function debug($msg) {
        if (self::$verbose) {
            echo $msg . "<br>";
        }
    }

    // Outputs a message with optional indentation
    // Formats the message differently based on verbosity
    public static function output($indent, $msg) {
        if (self::$verbose) {
            echo $indent . "→ Output: " . $msg . "<br>";
        } else {
            echo $msg . "<br>";
        }
    }
} 