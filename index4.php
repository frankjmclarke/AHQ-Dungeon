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
require_once 'RequestHandler.php';
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
        try {
            // Get the current subdirectory from the request
            $subdir = $this->request->getSubdir();
            
            // Initialize TableProcessor with current subdirectory
            try {
                $processor = TableProcessor::getInstance(".", $subdir);
            } catch (Exception $e) {
                Logger::debug("Error initializing TableProcessor: " . $e->getMessage());
                throw new Exception("Failed to initialize with subdirectory '{$subdir}': " . $e->getMessage());
            }
            
            // Load tables and process named blocks from specified subdirectory
            try {
                $tables = FileParser::loadTables($subdir);
            } catch (Exception $e) {
                Logger::debug("Error loading tables: " . $e->getMessage());
                throw new Exception("Failed to load tables from subdirectory '{$subdir}': " . $e->getMessage());
            }

            $namedRules = $this->processNamedBlocks();
            
            // Process user-specified tables and return the generated output
            return TableManager::processUserTables(
                $this->request->getTables(),
                $tables,
                $namedRules
            );
        } catch (Exception $e) {
            // Log the error and return an error message that will be displayed to the user
            Logger::debug("Error in processTables: " . $e->getMessage());
            return "Error: Unable to process tables. " . $e->getMessage();
        }
    }
    
    private function processNamedBlocks() {
        try {
            $namedRules = [];
            // Extract and parse named blocks from files for processing
            $rawBlocks = FileParser::extractNamedBlocks($this->request->getSubdir());
            
            foreach ($rawBlocks as $name => $blockLines) {
                try {
                    // Parse each named block and store the processing function
                    list($key, $fn) = TableProcessor::parseNamedBlock($blockLines);
                    $namedRules[$key] = $fn;
                } catch (Exception $e) {
                    Logger::debug("Error parsing named block '{$name}': " . $e->getMessage());
                    // Continue processing other blocks even if one fails
                    continue;
                }
            }
            
            return $namedRules;
        } catch (Exception $e) {
            Logger::debug("Error processing named blocks: " . $e->getMessage());
            throw new Exception("Failed to process named blocks: " . $e->getMessage());
        }
    }
}

// Create and run the application instance
$app = new Application();
$app->run();  // Execute the main application logic