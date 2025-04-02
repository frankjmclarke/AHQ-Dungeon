<?php
// ============================================================================
// Configuration and Constants
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/interfaces/CSVProcessor.php';
require_once __DIR__ . '/interfaces/DungeonGenerator.php';
require_once __DIR__ . '/interfaces/DiceRoller.php';
require_once __DIR__ . '/interfaces/TableResolver.php';
require_once __DIR__ . '/interfaces/TextProcessor.php';
require_once __DIR__ . '/interfaces/TableManager.php';
require_once __DIR__ . '/interfaces/NamedBlockManager.php';
require_once __DIR__ . '/classes/CSVProcessorImpl.php';
require_once __DIR__ . '/classes/DungeonGenerator.php';
require_once __DIR__ . '/classes/DiceRollerImpl.php';
require_once __DIR__ . '/classes/TableManager.php';
require_once __DIR__ . '/classes/NamedBlockManager.php';
require_once __DIR__ . '/classes/TextProcessorImpl.php';

// Global state
$VERBOSE = false;                // Global verbosity flag
$resolved_stack = array();       // Global resolved stack for cycle detection
$table2die = array();           // Global table to dice notation mapping

// ============================================================================
// Utility Functions
// ============================================================================

function debug_print($msg) {
    global $VERBOSE;
    if ($VERBOSE) {
        echo "DEBUG: {$msg}\n";
    }
}

function final_print($indent, $msg) {
    global $VERBOSE;
    if ($VERBOSE) {
        echo $indent . "→ Output: " . $msg . "<br>";
    } else {
        echo $msg . "<br>";
    }
}

// ============================================================================
// Entry Point
// ============================================================================

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    $dice_roller = new DiceRollerImpl();
    $table_manager = new TableManagerImpl($dice_roller);
    $named_block_manager = new NamedBlockManagerImpl();
    $text_processor = new TextProcessorImpl($dice_roller, $table_manager);
    $generator = new DungeonGeneratorImpl($dice_roller, $table_manager, $named_block_manager, $text_processor);
    $generator->run($_GET);
}
?> 