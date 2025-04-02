<?php
// ============================================================================
// Configuration and Constants
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/interfaces/CSVProcessor.php';
require_once __DIR__ . '/interfaces/DungeonGenerator.php';
require_once __DIR__ . '/classes/CSVProcessorImpl.php';
require_once __DIR__ . '/classes/DungeonGeneratorImpl.php';

// Global state
$VERBOSE = false;                // Global verbosity flag
$resolved_stack = array();       // Global resolved stack for cycle detection
$table2die = array();           // Global table to dice notation mapping

// ============================================================================
// Core Interfaces
// ============================================================================

/**
 * Interface for dice rolling functionality
 */
interface DiceRoller {
    public function roll($notation);
}

/**
 * Interface for table resolution
 */
interface TableResolver {
    public function resolveTable($name, $tables, $named_rules, $depth);
}

/**
 * Interface for text processing
 */
interface TextProcessor {
    public function process($text, $tables, $named_rules, $depth);
}

// ============================================================================
// Utility Functions
// ============================================================================

/**
 * Debug output function
 */
function debug_print($msg) {
    global $VERBOSE;
    if ($VERBOSE) {
        echo "DEBUG: {$msg}\n";
    }
}

/**
 * Final output function
 */
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

class DiceRollerImpl implements DiceRoller {
    public function roll($notation) {
        if (preg_match('/^(\d+)D(\d+)$/', $notation, $matches)) {
            $num_dice = intval($matches[1]);
            $num_sides = intval($matches[2]);
            $rolls = array();
            $total = 0;
            
            for ($i = 0; $i < $num_dice; $i++) {
                $roll = rand(1, $num_sides);
                $rolls[] = $roll;
                $total += $roll;
            }
            
            return array(
                'total' => $total,
                'rolls' => $rolls
            );
        }
        
        return array('total' => 0, 'rolls' => array());
    }
}

class TableManager implements TableResolver {
    private $dice_roller;
    private $parent_dir;
    
    public function __construct($dice_roller) {
        $this->dice_roller = $dice_roller;
        $this->parent_dir = dirname(__DIR__);
    }
    
    public function loadTables($subdir = null) {
        $tables = array();
        
        // Load files from parent directory
        $files = glob($this->parent_dir . "/*.tab");
        foreach ($files as $file) {
            $table_name = basename($file, ".tab");
            $tables[$table_name] = file_get_contents($file);
        }
        
        // Load files from subdirectory if specified
        if ($subdir !== null) {
            $subdir_path = $this->parent_dir . "/" . $subdir;
            if (is_dir($subdir_path)) {
                $subdir_files = glob($subdir_path . "/*.tab");
                foreach ($subdir_files as $file) {
                    $table_name = basename($file, ".tab");
                    $tables[$table_name] = file_get_contents($file);
                }
            }
        }
        
        return $tables;
    }
    
    public function resolveTable($name, $tables, $named_rules, $depth) {
        if (isset($tables[$name])) {
            return $tables[$name];
        }
        return null;
    }
}

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
// Main Application
// ============================================================================

class DungeonGenerator {
    private $dice_roller;
    private $table_manager;
    private $named_block_manager;
    private $text_processor;
    private $csv_processor;
    
    public function __construct() {
        $this->dice_roller = new DiceRollerImpl();
        $this->table_manager = new TableManager($this->dice_roller);
        $this->named_block_manager = new NamedBlockManager();
        $this->text_processor = new TextProcessorImpl($this->dice_roller, $this->table_manager);
        $this->csv_processor = new CSVProcessorImpl(dirname(__DIR__));
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
            list($key, $fn) = $this->parseNamedBlock($block_lines);
            $named_rules[$key] = $fn;
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
            return;
        }
        
        $table_name = $candidates[0];
        if ($VERBOSE) {
            echo "<br>--- Resolving table '{$user_input}' ---<br>";
        }
        
        $this->text_processor->resolveTable($table_name, $tables, $named_rules, 0);
        
        if ($VERBOSE) {
            echo "<br>" . str_repeat("=", 50) . "<br>";
        }
    }
    
    private function parseNamedBlock($lines) {
        global $table2die;
        $name = strtolower(trim($lines[0]));
        $stack = array();
        $current = array();
        $parsed_tables = array();
        $dice_notation = null;
        
        for ($i = 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (strpos(trim($line), "(") === 0) {
                $stack[] = $current;
                $current = array();
            } elseif (strpos(trim($line), ")") === 0) {
                if (!empty($current)) {
                    $first_line = trim($current[0]);
                    $tokens = preg_split('/\s+/', $first_line);
                    if (!empty($tokens) && preg_match('/^\d+[dD]\d+$/', $tokens[0])) {
                        $dice_notation = $tokens[0];
                        array_shift($tokens);
                        if (!empty($tokens)) {
                            $current[0] = implode(" ", $tokens);
                        } else {
                            array_shift($current);
                        }
                    }
                }
                $parsed = $this->parseInlineTable($current);
                $current = count($stack) > 0 ? array_pop($stack) : array();
                $parsed_tables[] = array($dice_notation, $parsed);
                $dice_notation = null;
            } else {
                $current[] = $line;
            }
            
            if ($i == 1) {
                $this->processDiceNotation($line, $name);
            }
        }
        
        return array($name, $this->createResolveFunction($parsed_tables, $name));
    }
    
    private function parseInlineTable($lines) {
        $table = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^(\d+)(?:-(\d+))?\s+(.+)$/', $line, $matches)) {
                $start = intval($matches[1]);
                $end = isset($matches[2]) ? intval($matches[2]) : $start;
                $content = trim($matches[3]);
                for ($i = $start; $i <= $end; $i++) {
                    $table[] = array($i, $content);
                }
            }
        }
        return $table;
    }
    
    private function processDiceNotation($line, $name) {
        global $table2die;
        if (strpos($line, "2D12") !== false) {
            $dice_notation = "2D12";
            $table2die[$name] = $dice_notation;
            debug_print("YYYYYYY Using dice notation '{$dice_notation}' for block '{$name}'");
        } elseif (strpos($line, "1D12") !== false) {
            $dice_notation = "1D12";
            $table2die[$name] = $dice_notation;
            debug_print("YYYYYYY Using dice notation '{$dice_notation}' for block '{$name}'");
        } elseif (strpos($line, "1D6") !== false) {
            $dice_notation = "1D6";
            $table2die[$name] = $dice_notation;
            debug_print("YYYYYYY Using dice notation '{$dice_notation}' for block '{$name}'");
        }
    }
    
    private function createResolveFunction($parsed_tables, $name) {
        global $table2die;
        return function() use ($parsed_tables, $name) {
            if (empty($parsed_tables)) {
                return "";
            }
            
            list($notation, $outer) = $parsed_tables[0];
            $roll_notation = isset($table2die[$name]) ? $table2die[$name] : DEFAULT_DICE;
            
            debug_print("Using dice notation '{$roll_notation}' for block '{$name}'");
            
            $has_composite = false;
            foreach ($outer as $entry) {
                if (strpos($entry[1], "&") !== false) {
                    $has_composite = true;
                    break;
                }
            }
            
            $attempts = 0;
            $entry_val = null;
            while ($attempts < 10) {
                $result = $this->dice_roller->roll($roll_notation);
                $roll = $result['total'];
                $rolls = $result['rolls'];
                $entry_val = null;
                
                foreach ($outer as $tuple) {
                    if ($tuple[0] == $roll) {
                        $entry_val = $tuple[1];
                        break;
                    }
                }
                
                debug_print("  → [Nested roll in {$name}]: Rolled {$roll} (rolls: " . implode(",", $rolls) . ") resulting in: {$entry_val}");
                
                if ($entry_val !== null && $has_composite && strtolower(trim($entry_val)) == $name) {
                    $attempts++;
                    continue;
                }
                break;
            }
            
            if ($entry_val !== null && strpos($entry_val, "&") !== false) {
                return $this->processCompositeEntry($entry_val, $parsed_tables, $name);
            }
            
            $final_output = ($entry_val !== null) ? $entry_val : "";
            
            if ($name == "hidden-treasure") {
                return "[Hidden-Treasure]\n" . $final_output . "\n[/Hidden-Treasure]";
            }
            
            return $final_output;
        };
    }
    
    private function processCompositeEntry($entry_val, $parsed_tables, $name) {
        $parts = array_map('trim', explode("&", $entry_val));
        $output = array();
        
        foreach ($parts as $part) {
            if (strtolower($part) == $name) {
                continue;
            }
            
            if (strpos($part, "(") === 0 && count($parsed_tables) > 1) {
                list($notation2, $subtable) = $parsed_tables[1];
                $roll_notation2 = $notation2 !== null ? $notation2 : DEFAULT_DICE;
                $result2 = $this->dice_roller->roll($roll_notation2);
                $subroll = $result2['total'];
                $sub_rolls = $result2['rolls'];
                $subentry = null;
                
                foreach ($subtable as $tuple) {
                    if ($tuple[0] == $subroll) {
                        $subentry = $tuple[1];
                        break;
                    }
                }
                
                $output[] = "[Nested roll in {$name} nested]: Rolled {$subroll} (rolls: " . implode(",", $sub_rolls) . ") resulting in: {$subentry}";
            } else {
                $output[] = $part;
            }
        }
        
        return implode("\n", $output);
    }
}

// ============================================================================
// Entry Point
// ============================================================================

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    $generator = new DungeonGenerator();
    $generator->run($_GET);
}
?> 