<?php

namespace App\Classes;

require_once __DIR__ . '/../interfaces/DungeonGenerator.php';
require_once __DIR__ . '/../interfaces/DiceRoller.php';
require_once __DIR__ . '/../interfaces/TableResolver.php';
require_once __DIR__ . '/../interfaces/TextProcessor.php';
require_once __DIR__ . '/../interfaces/CSVProcessor.php';
require_once __DIR__ . '/TableResolverImpl.php';
require_once __DIR__ . '/DiceRollerImpl.php';
require_once __DIR__ . '/TableManager.php';
require_once __DIR__ . '/NamedBlockManager.php';
require_once __DIR__ . '/TextProcessorImpl.php';
require_once __DIR__ . '/CSVProcessorImpl.php';

use App\Classes\TableResolverImpl;
use App\Classes\DiceRollerImpl;
use App\Classes\TableManagerImpl;
use App\Classes\NamedBlockManagerImpl;
use App\Classes\TextProcessorImpl;
use App\Classes\CSVProcessorImpl;
use App\Interfaces\DungeonGenerator;

class DungeonGeneratorImpl implements DungeonGenerator {
    private DiceRollerImpl $dice_roller;
    private TableResolverImpl $table_resolver;
    private TableManagerImpl $table_manager;
    private NamedBlockManagerImpl $named_block_manager;
    private TextProcessorImpl $text_processor;
    private CSVProcessorImpl $csv_processor;
    
    public function __construct() {
        global $VERBOSE;
        $this->dice_roller = new DiceRollerImpl();
        $this->table_resolver = new TableResolverImpl($VERBOSE);
        $this->table_manager = new TableManagerImpl($this->dice_roller);
        $this->named_block_manager = new NamedBlockManagerImpl($this->dice_roller);
        $this->text_processor = new TextProcessorImpl($VERBOSE);
        $this->csv_processor = new CSVProcessorImpl(dirname(dirname(__DIR__)));
    }
    
    public function run($params) {
        global $VERBOSE;
        
        if (!isset($params['tables']) || empty($params['tables'])) {
            echo "Usage: src/index.php?tables=TableName1,TableName2[,...]&verbose=1&subdir=your_subdir (optional)";
            return;
        }
        
        if (isset($params['verbose']) && $params['verbose'] == "1") {
            $VERBOSE = true;
        }
        
        $subdir = isset($params['subdir']) ? $params['subdir'] : null;
        
        // Start output buffering
        ob_start();
        
        // Load and process tables
        $tables = $this->table_manager->loadTables($subdir);
        $named_rules = array();
        $raw_blocks = $this->named_block_manager->extractNamedBlocks($subdir);
        
        foreach ($raw_blocks as $name => $block_lines) {
            list($key, $fn) = $this->named_block_manager->parseNamedBlock($block_lines);
            if ($key !== null) {
                $named_rules[$key] = $fn;
            }
        }
        
        // Process user input
        $user_tables = array_map('trim', explode(",", $params['tables']));
        foreach ($user_tables as $user_input) {
            $this->processUserInput($user_input, $tables, $named_rules);
        }
        
        // Get and process output
        $output = ob_get_clean();
        echo $output;
        echo $this->csv_processor->processOutput($output);
    }
    
    private function processUserInput($user_input, $tables, $named_rules) {
        global $VERBOSE;
        
        if (isset($tables[$user_input])) {
            $table = $tables[$user_input];
            $resolved = $this->table_resolver->resolveTable($user_input, $tables, $named_rules, 0);
            if ($resolved !== null) {
                $this->text_processor->processAndResolveText($resolved, $tables, $named_rules, 0);
            }
        } else {
            echo "Table not found: {$user_input}\n";
            if ($VERBOSE) {
                echo "Available tables:\n";
                foreach ($tables as $key => $value) {
                    echo "- {$key}\n";
                }
            }
        }
    }
} 