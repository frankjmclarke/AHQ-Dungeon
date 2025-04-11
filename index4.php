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
        // Initialize request handler to manage input parameters
        $this->request = new RequestHandler();
    }
    
    public function run() {
        try {
            // Validate request parameters to ensure required inputs are provided
            $this->request->validate();
            
            // Configure logger based on verbosity setting from request
            Logger::setVerbose($this->request->isVerbose());
            
            // Start output buffering to capture generated content for further processing
            ob_start();
            
            // Process tables based on user input and generate output
            $output = $this->processTables();
            
            // Get and process the final output from buffer
            $finalOutput = ob_get_clean();
            echo $finalOutput;  // Output the processed content to the user
            
            // Process CSV output for Monsters and display results
            echo CSVProcessor::processCSVOutput($finalOutput);
            
        } catch (Exception $e) {
            // Handle exceptions and display error messages
            echo $e->getMessage();
        }
    }
    
    private function processTables() {
        // Load tables and process named blocks from specified subdirectory
        $tables = FileParser::loadTables($this->request->getSubdir());
        $namedRules = $this->processNamedBlocks();
        
        // Process user-specified tables and return the generated output
        return TableManager::processUserTables(
            $this->request->getTables(),
            $tables,
            $namedRules
        );
    }
    
    private function processNamedBlocks() {
        $namedRules = [];
        // Extract and parse named blocks from files for processing
        $rawBlocks = FileParser::extractNamedBlocks($this->request->getSubdir());
        
        foreach ($rawBlocks as $name => $blockLines) {
            // Parse each named block and store the processing function
            list($key, $fn) = TableProcessor::parseNamedBlock($blockLines);
            $namedRules[$key] = $fn;
        }
        
        return $namedRules;  // Return the collection of named rules for table processing
    }
}

// Create and run the application instance
$app = new Application();
$app->run();  // Execute the main application logic