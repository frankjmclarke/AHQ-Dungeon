<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'Logger.php';
require_once 'FileParser.php';
require_once 'CSVProcessor.php';
require_once 'TableProcessor.php';
require_once 'TableManager.php';
require_once 'DiceRoller.php';

// Main application class
// This class orchestrates the flow of the application, handling user input and processing tables
class Application {
    public static function run() {
        // Retrieve parameters from the URL
        $params = $_GET;
        Logger::setVerbose(isset($params['verbose']) && $params['verbose'] == "1");

        // Check if tables parameter is provided
        if (!isset($params['tables']) || empty($params['tables'])) {
            echo "Usage: index2.php?tables=TableName1,TableName2[,...]&verbose=1&subdir=your_subdir (optional)";
            return;
        }

        // Optional subdirectory for loading additional tables
        $subdir = isset($params['subdir']) ? $params['subdir'] : null;

        // Start output buffering to capture generated content
        ob_start();

        // Load tables and process named blocks
        $tables = FileParser::loadTables($subdir);
        $named_rules = array();
        $raw_blocks = FileParser::extractNamedBlocks($subdir);
        foreach ($raw_blocks as $name => $block_lines) {
            list($key, $fn) = TableProcessor::parseNamedBlock($block_lines);
            $named_rules[$key] = $fn;
        }
        
        // Process user input for specified tables
        $user_tables = array_map('trim', explode(",", $params['tables']));
        foreach ($user_tables as $user_input) {
            $normalized = strtolower(str_replace(array("-", "_"), "", $user_input));
            $candidates = array();
            foreach ($tables as $key => $value) {
                $normalized_key = strtolower(str_replace(array("-", "_"), "", $key));
                if ($normalized_key === $normalized) {
                    $candidates[] = $key;
                }
            }
            if (empty($candidates)) {
                echo "[Table '{$user_input}' not found. Available: " . implode(", ", array_keys($tables)) . "]<br>";
            } else {
                $table_name = $candidates[0];
                if (Logger::isVerbose()) {
                    echo "<br>--- Resolving table '{$user_input}' ---<br>";
                }
                TableProcessor::resolveTable($table_name, $tables, $named_rules);
                if (Logger::isVerbose()) {
                    echo "<br>" . str_repeat("=", 50) . "<br>";
                }
            }
        }

        // Get the generated output
        //$output = ob_get_clean(). "Klack";
        echo $output;//This goes to the text box     

        // Search CSV output for Monsters in the .csv file
        echo CSVProcessor::processCSVOutput($output);
    }
}

// Run the application
Application::run();