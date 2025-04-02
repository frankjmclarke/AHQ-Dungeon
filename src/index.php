<?php
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/DungeonConfig.php';
require_once __DIR__ . '/core/Dice.php';
require_once __DIR__ . '/core/Table.php';
require_once __DIR__ . '/core/NamedBlock.php';
require_once __DIR__ . '/core/Monster.php';
require_once __DIR__ . '/core/TextProcessor.php';
require_once __DIR__ . '/utils/Debug.php';

/**
 * Main function to process dungeon generation requests
 */
function main($argv) {
    $config = DungeonConfig::getInstance();
    $config->setVerbose(true);
    
    // Validate required parameters
    if (count($argv) < 2) {
        throw new RuntimeException("Usage: php index.php <input_file> [output_file]");
    }
    
    $inputFile = $argv[1];
    $outputFile = $argv[2] ?? null;
    
    if (!file_exists($inputFile)) {
        throw new RuntimeException("Input file not found: $inputFile");
    }
    
    // Start output buffering
    ob_start();
    
    try {
        // Load tables
        $table = new Table();
        $tables = $table->loadTables(__DIR__ . '/../tables');
        
        // Load monsters
        $monster = new Monster();
        $monsters = $monster->loadMonsters(__DIR__ . '/../data/monsters.csv');
        
        // Process input file
        $text = file_get_contents($inputFile);
        if ($text === false) {
            throw new RuntimeException("Failed to read input file: $inputFile");
        }
        
        // Process text
        $processor = new TextProcessor();
        $result = $processor->processAndResolveText($text);
        
        // Output result
        if ($outputFile) {
            if (file_put_contents($outputFile, $result) === false) {
                throw new RuntimeException("Failed to write output file: $outputFile");
            }
            echo "Output written to: $outputFile\n";
        } else {
            echo $result;
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Run main function
main($argv); 