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
require_once __DIR__ . '/classes/CSVProcessorImpl.php';
require_once __DIR__ . '/classes/DungeonGenerator.php';
require_once __DIR__ . '/classes/DiceRollerImpl.php';
require_once __DIR__ . '/classes/TableManager.php';

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
// Core Implementations
// ============================================================================

class NamedBlockManager {
    private $parent_dir;
    
    public function __construct() {
        $this->parent_dir = dirname(__DIR__);
    }
    
    public function extractNamedBlocks($subdir = null) {
        $named_blocks = array();
        
        // Load files from parent directory
        $files = glob($this->parent_dir . "/*.txt");
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $blocks = $this->extractBlocks($content);
            $named_blocks = array_merge($named_blocks, $blocks);
        }
        
        // Load files from subdirectory if specified
        if ($subdir !== null) {
            $subdir_path = $this->parent_dir . "/" . $subdir;
            if (is_dir($subdir_path)) {
                $subdir_files = glob($subdir_path . "/*.txt");
                foreach ($subdir_files as $file) {
                    $content = file_get_contents($file);
                    $blocks = $this->extractBlocks($content);
                    $named_blocks = array_merge($named_blocks, $blocks);
                }
            }
        }
        
        return $named_blocks;
    }
    
    private function extractBlocks($content) {
        $blocks = array();
        $lines = explode("\n", $content);
        $current_block = null;
        $current_lines = array();
        
        foreach ($lines as $line) {
            if (preg_match('/^@(\w+)/', $line, $matches)) {
                if ($current_block !== null) {
                    $blocks[$current_block] = $current_lines;
                    $current_lines = array();
                }
                $current_block = $matches[1];
            } elseif ($current_block !== null) {
                $current_lines[] = $line;
            }
        }
        
        if ($current_block !== null) {
            $blocks[$current_block] = $current_lines;
        }
        
        return $blocks;
    }
}

class TextProcessorImpl implements TextProcessor {
    private $dice_roller;
    private $table_manager;
    
    public function __construct($dice_roller, $table_manager) {
        $this->dice_roller = $dice_roller;
        $this->table_manager = $table_manager;
    }
    
    public function process($text, $tables, $named_rules, $depth) {
        global $resolved_stack;
        
        if ($depth >= MAX_DEPTH) {
            return "MAX_DEPTH reached";
        }
        
        $resolved_stack[] = $text;
        $lines = explode("\n", $text);
        $output = array();
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if (strpos($line, "|") !== false) {
                $output[] = $this->processTableLine($line, $tables, $named_rules, $depth);
            } elseif (strpos($line, "@") === 0) {
                $output[] = $this->processNamedBlockCall($line, $named_rules, $depth, $text);
            } else {
                $output[] = $line;
            }
        }
        
        array_pop($resolved_stack);
        return implode("\n", $output);
    }
    
    private function processTableLine($line, $tables, $named_rules, $depth) {
        $parts = explode("|", $line);
        $notation = trim($parts[0]);
        $entries = array();
        
        foreach (array_slice($parts, 1) as $entry) {
            $entry = trim($entry);
            if (empty($entry)) continue;
            
            if (preg_match('/^(\d+)\s*-\s*(\d+)\s*:\s*(.+)$/', $entry, $matches)) {
                $start = intval($matches[1]);
                $end = intval($matches[2]);
                $text = trim($matches[3]);
                
                for ($i = $start; $i <= $end; $i++) {
                    $entries[] = array($i, $text);
                }
            } elseif (preg_match('/^(\d+)\s*:\s*(.+)$/', $entry, $matches)) {
                $entries[] = array(intval($matches[1]), trim($matches[2]));
            }
        }
        
        $result = $this->dice_roller->roll($notation);
        $roll = $result['total'];
        $rolls = $result['rolls'];
        
        $entry_val = null;
        foreach ($entries as $tuple) {
            if ($tuple[0] == $roll) {
                $entry_val = $tuple[1];
                break;
            }
        }
        
        if ($entry_val !== null) {
            if (strpos($entry_val, "&") !== false) {
                return $this->processCompositeEntry($entry_val, $tables, $named_rules, $depth);
            }
            return $entry_val;
        }
        
        return "";
    }
    
    private function processNamedBlockCall($line, $named_rules, $depth, $parent_table) {
        if (preg_match('/^@(\w+)/', $line, $matches)) {
            $name = $matches[1];
            if (isset($named_rules[$name])) {
                $fn = $named_rules[$name];
                return $fn();
            }
        }
        return "";
    }
    
    private function processCompositeEntry($entry_val, $tables, $named_rules, $depth) {
        $parts = explode("&", $entry_val);
        $output_parts = array();
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;
            
            if (strpos($part, "@") === 0) {
                $output_parts[] = $this->processNamedBlockCall($part, $named_rules, $depth, null);
            } else {
                $output_parts[] = $part;
            }
        }
        
        return implode(" ", $output_parts);
    }
}

// ============================================================================
// Entry Point
// ============================================================================

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    $dice_roller = new DiceRollerImpl();
    $table_manager = new TableManagerImpl($dice_roller);
    $generator = new DungeonGeneratorImpl($dice_roller, $table_manager);
    $generator->run($_GET);
}
?> 