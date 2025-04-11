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
require_once 'config.php';
Config::init();

// Main application class
// This class orchestrates the flow of the application, handling user input and processing tables
class Application {
    private $request;
    
    public function __construct() {
        $this->request = new RequestHandler();
    }
    
    public function run() {
        try {
            // Validate request parameters
            $this->request->validate();
            
            // Configure logger
            Logger::setVerbose($this->request->isVerbose());
            
            // Start output buffering
            ob_start();
            
            // Process tables
            $output = $this->processTables();
            
            // Get and process the final output
            $finalOutput = ob_get_clean();
            echo $finalOutput;  // This goes to the text box
            
            // Process CSV output for Monsters
            echo CSVProcessor::processCSVOutput($finalOutput);
            
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }
    
    private function processTables() {
        // Load tables and process named blocks
        $tables = FileParser::loadTables($this->request->getSubdir());
        $namedRules = $this->processNamedBlocks();
        
        // Process user-specified tables
        return TableManager::processUserTables(
            $this->request->getTables(),
            $tables,
            $namedRules
        );
    }
    
    private function processNamedBlocks() {
        $namedRules = [];
        $rawBlocks = FileParser::extractNamedBlocks($this->request->getSubdir());
        
        foreach ($rawBlocks as $name => $blockLines) {
            list($key, $fn) = TableProcessor::parseNamedBlock($blockLines);
            $namedRules[$key] = $fn;
        }
        
        return $namedRules;
    }
}

// Create and run the application
$app = new Application();
$app->run();