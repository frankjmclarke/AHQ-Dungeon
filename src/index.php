<?php
// ============================================================================
// Configuration and Constants
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Global constants
define('MAX_DEPTH', 50);         // Maximum recursion depth
define('DEFAULT_DICE', "1D12");   // Global default dice
define('CSV_FILE', "skaven_bestiary.csv"); // CSV file for monster data

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
    public function resolve($name, $tables, $named_rules = array(), $depth = 0);
}

/**
 * Interface for text processing
 */
interface TextProcessor {
    public function process($text, $tables, $named_rules, $depth, $parent_table = null, $current_named = null);
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
        echo $msg . "<br>";
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
// Dice Rolling Implementation
// ============================================================================

class DiceRollerImpl implements DiceRoller {
    public function roll($notation) {
        if (preg_match('/^(\d+)[dD](\d+)$/', $notation, $matches)) {
            $num = intval($matches[1]);
            $sides = intval($matches[2]);
            $total = 0;
            $rolls = array();
            for ($i = 0; $i < $num; $i++) {
                $r = random_int(1, $sides);
                $rolls[] = $r;
                $total += $r;
            }
            return array('total' => $total, 'rolls' => $rolls);
        } else {
            $r = random_int(1, 12);
            return array('total' => $r, 'rolls' => array($r));
        }
    }
}

// ============================================================================
// Table Management
// ============================================================================

class TableManager {
    private $parent_dir;
    private $dice_roller;
    
    public function __construct($dice_roller) {
        $this->parent_dir = dirname(__DIR__);
        $this->dice_roller = $dice_roller;
    }
    
    public function getDiceNotation($tableName) {
        global $table2die;
        if (isset($table2die[$tableName])) {
            $size = sizeof($table2die);
            debug_print("[SIZE  {$size}]");
            return $table2die[$tableName];
        }
        return null;
    }
    
    public function loadTables($subdir = null) {
        global $VERBOSE;
        $tables = array();
        
        // Load files from the subdirectory (if provided)
        if ($subdir && is_dir($this->parent_dir . "/" . $subdir)) {
            $files = glob($this->parent_dir . "/" . $subdir . "/*.tab");
            foreach ($files as $filepath) {
                $filename = basename($filepath);
                $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
                $tables[$name] = $this->parseTabFile($filepath);
                if ($VERBOSE) {
                    //debug_print("Loaded table '{$name}' from subdirectory: {$filepath}");
                }
            }
        }
        
        // Load from parent directory
        $files = glob($this->parent_dir . "/*.tab");
        foreach ($files as $filepath) {
            $filename = basename($filepath);
            $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
            if (!isset($tables[$name])) {
                $tables[$name] = $this->parseTabFile($filepath);
                if ($VERBOSE) {
                    //debug_print("Loaded table '{$name}' from parent directory: {$filepath}");
                }
            }
        }
        return $tables;
    }
    
    private function parseTabFile($filename) {
        $table = array();
        $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === "" || strpos($line, '#') === 0) {
                continue;
            }
            if (preg_match('/^(\d+)(?:-(\d+))?\s+(.+)$/', $line, $matches)) {
                $start = intval($matches[1]);
                $end = isset($matches[2]) ? intval($matches[2]) : $start;
                $content = trim($matches[3]);
                for ($roll = $start; $roll <= $end; $roll++) {
                    $table[] = array($roll, $content);
                }
            }
        }
        return $table;
    }
}

// ============================================================================
// Named Block Management
// ============================================================================

class NamedBlockManager {
    private $parent_dir;
    
    public function __construct() {
        $this->parent_dir = dirname(__DIR__);
    }
    
    public function extractNamedBlocks($subdir = null) {
        global $VERBOSE;
        $blocks = array();
        
        // Get files from parent directory
        $files_top = array_merge(glob($this->parent_dir . "/*.tab"), glob($this->parent_dir . "/*.txt"));
        // Get files from the subdirectory (if provided)
        $files_sub = ($subdir && is_dir($this->parent_dir . "/" . $subdir)) 
            ? array_merge(glob($this->parent_dir . "/" . $subdir . "/*.tab"), glob($this->parent_dir . "/" . $subdir . "/*.txt"))
            : array();
        
        // Build an associative array keyed by lowercased basename
        $files_assoc = array();
        foreach ($files_top as $filepath) {
            $key = strtolower(basename($filepath));
            $files_assoc[$key] = $filepath;
        }
        foreach ($files_sub as $filepath) {
            $key = strtolower(basename($filepath));
            $files_assoc[$key] = $filepath;
        }
        
        // Process each file
        foreach ($files_assoc as $filepath) {
            if ($VERBOSE) {
                debug_print("Processing named blocks from file: {$filepath}");
            }
            $blocks = array_merge($blocks, $this->processFile($filepath));
        }
        return $blocks;
    }
    
    private function processFile($filepath) {
        $blocks = array();
        $lines_raw = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array();
        foreach ($lines_raw as $line) {
            $trimmed = trim($line);
            if ($trimmed === "" || strpos($trimmed, '#') === 0) {
                continue;
            }
            $lines[] = $trimmed;
        }
        
        $i = 0;
        while ($i < count($lines)) {
            $line = $lines[$i];
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_\-]*$/', $line)) {
                $name = strtolower($line);
                $i++;
                if ($i < count($lines) && strpos($lines[$i], '(') === 0) {
                    $depth = 1;
                    $block_lines = array($line, $lines[$i]);
                    $i++;
                    while ($i < count($lines) && $depth > 0) {
                        $block_lines[] = $lines[$i];
                        $depth += substr_count($lines[$i], '(');
                        $depth -= substr_count($lines[$i], ')');
                        $i++;
                    }
                    $blocks[$name] = $block_lines;
                } else {
                    $i++;
                }
            } else {
                $i++;
            }
        }
        return $blocks;
    }
}

// ============================================================================
// Text Processing Implementation
// ============================================================================

class TextProcessorImpl implements TextProcessor {
    private $dice_roller;
    private $table_manager;
    
    public function __construct($dice_roller, $table_manager) {
        $this->dice_roller = $dice_roller;
        $this->table_manager = $table_manager;
    }
    
    public function process($text, $tables, $named_rules, $depth, $parent_table = null, $current_named = null) {
        global $resolved_stack;
        if ($depth > MAX_DEPTH) {
            debug_print(str_repeat("  ", $depth) . "[Maximum recursion depth reached]");
            return;
        }
        
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $this->processLine($line, $tables, $named_rules, $depth, $parent_table, $current_named);
        }
    }
    
    private function processLine($line, $tables, $named_rules, $depth, $parent_table, $current_named) {
        $line = trim($line);
        
        // Handle named block calls
        if (preg_match('/^([A-Za-z0-9_\-]+)\(\)$/', $line, $matches)) {
            $this->handleNamedBlockCall($matches[1], $named_rules, $depth, $parent_table, $tables);
            return;
        }
        
        // Handle direct named block references
        $lower_line = strtolower($line);
        if (isset($named_rules[$lower_line])) {
            $this->handleNamedBlockReference($line, $lower_line, $current_named, $named_rules, $depth, $parent_table, $tables);
            return;
        }
        
        // Handle literal text
        if (substr($line, 0, 1) === '"' && substr($line, -1) === '"') {
            final_print(str_repeat("  ", $depth), substr($line, 1, -1));
        } else {
            final_print(str_repeat("  ", $depth), $line);
        }
        
        // Handle inline references
        $this->handleInlineReferences($line, $tables, $named_rules, $depth, $parent_table, $current_named);
    }
    
    private function handleNamedBlockCall($name, $named_rules, $depth, $parent_table, $tables) {
        $name_candidate = strtolower($name);
        if (isset($named_rules[$name_candidate])) {
            debug_print(str_repeat("  ", $depth) . "→ Resolving named block: " . $name);
            $result = $named_rules[$name_candidate]();
            $this->process($result, $tables, $named_rules, $depth + 1, $parent_table, $name_candidate);
        }
    }
    
    private function handleNamedBlockReference($line, $lower_line, $current_named, $named_rules, $depth, $parent_table, $tables) {
        if ($current_named !== null && $lower_line === $current_named) {
            final_print(str_repeat("  ", $depth), $line);
            return;
        }
        
        global $resolved_stack;
        if (in_array($lower_line, $resolved_stack)) {
            debug_print(str_repeat("  ", $depth) . "→ [Cycle detected: " . $line . "]");
            return;
        }
        
        $resolved_stack[] = $lower_line;
        debug_print(str_repeat("  ", $depth) . "→ Resolving named block: " . $line);
        $result = $named_rules[$lower_line]();
        $this->process($result, $tables, $named_rules, $depth + 1, $parent_table, $lower_line);
        array_pop($resolved_stack);
    }
    
    private function handleInlineReferences($line, $tables, $named_rules, $depth, $parent_table, $current_named) {
        if (preg_match_all('/([A-Za-z0-9_\-]+)\(\)/', $line, $all_matches)) {
            foreach ($all_matches[1] as $match) {
                $match_lower = strtolower($match);
                if ($current_named !== null && $match_lower === $current_named) {
                    continue;
                }
                
                global $resolved_stack;
                if (in_array($match_lower, $resolved_stack)) {
                    continue;
                }
                
                $resolved_stack[] = $match_lower;
                if (isset($named_rules[$match_lower])) {
                    $result = $named_rules[$match_lower]();
                    $this->process($result, $tables, $named_rules, $depth + 1, $parent_table, $match_lower);
                } elseif (isset($tables[$match_lower])) {
                    $this->resolveTable($match_lower, $tables, $named_rules, $depth + 1);
                }
                array_pop($resolved_stack);
            }
        }
    }
    
    public function resolveTable($name, $tables, $named_rules, $depth) {
        $indent = str_repeat("  ", $depth);
        $name = strtolower($name);
        if (!isset($tables[$name])) {
            debug_print($indent . "[Table '{$name}' not found]");
            return;
        }
        
        $table = $tables[$name];
        $diceNotation = $this->table_manager->getDiceNotation($name);
        
        if ($diceNotation !== null) {
            $result = $this->dice_roller->roll($diceNotation);
            debug_print("[Good entry for ROLL {$diceNotation}]");
        } else {
            debug_print("[Bad entry for ROLL {$name}]");
            $result = $this->dice_roller->roll(DEFAULT_DICE);
        }
        
        $roll = $result['total'];
        $rolls = $result['rolls'];
        $entry = null;
        
        foreach ($table as $tuple) {
            if ($tuple[0] == $roll) {
                $entry = $tuple[1];
                break;
            }
        }
        
        debug_print($indent . "Rolled {$roll} on {$name}: {$entry} (rolls: " . implode(",", $rolls) . ")");
        if (!$entry) {
            debug_print($indent . "[No entry for roll {$roll}]");
            return;
        }
        
        if (substr($entry, 0, 1) === '"' && substr($entry, -1) === '"') {
            final_print($indent, substr($entry, 1, -1));
            return;
        }
        
        if (substr($entry, 0, 2) === '[[' && substr($entry, -2) === ']]') {
            $inner = substr($entry, 2, -2);
            $parts = array_map('trim', explode("&", $inner));
            foreach ($parts as $part) {
                $part_normalized = strtolower(str_replace(array("(", ")"), "", $part));
                $this->resolveTable($part_normalized, $tables, $named_rules, $depth + 1);
            }
            return;
        }
        
        $this->process($entry, $tables, $named_rules, $depth, $name, null);
    }
}

// ============================================================================
// CSV Processing
// ============================================================================

class CSVProcessor {
    private $parent_dir;
    
    public function __construct() {
        $this->parent_dir = dirname(__DIR__);
    }
    
    public function processOutput($output) {
        $csvOutput = "";
        if (preg_match_all('/\d+\s+([A-Za-z ]+?)(?=[^A-Za-z ]|$)/', $output, $matches)) {
            $names = array_map('trim', $matches[1]);
            $names = array_filter($names, function($n) { return $n !== ""; });
            $csvResults = $this->searchCSV($names);
            if (!empty($csvResults)) {
                $csvOutput = $this->generateCSVTable($csvResults);
            }
        }
        return $csvOutput;
    }
    
    private function searchCSV($names) {
        $csvResults = array();
        $csv_path = $this->parent_dir . "/" . CSV_FILE;
        if (($handle = fopen($csv_path, "r")) !== false) {
            while (($data = fgetcsv($handle)) !== false) {
                if (isset($data[0])) {
                    $csvName = trim($data[0]);
                    foreach ($names as $name) {
                        if ($this->matchName($csvName, $name)) {
                            $csvResults[$name][] = $data;
                        }
                    }
                }
            }
            fclose($handle);
        } else {
            debug_print("Warning: Could not open CSV file at: {$csv_path}");
        }
        return $csvResults;
    }
    
    private function matchName($csvName, $name) {
        if (strcasecmp($csvName, $name) === 0) {
            return true;
        }
        if (substr($name, -1) === "s") {
            $singular = substr($name, 0, -1);
            if (strcasecmp($csvName, $singular) === 0) {
                return true;
            }
        }
        if (substr($name, -3) === "men") {
            $singular = substr($name, 0, -3) . "man";
            if (strcasecmp($csvName, $singular) === 0) {
                return true;
            }
        }
        return false;
    }
    
    private function generateCSVTable($csvResults) {
        $output = "##CSV_MARKER##";
        $output .= "<table border='1' cellspacing='0' cellpadding='4' style='max-width:500px; margin:0 auto;'>";
        $output .= "<tr>";
        $output .= "<th>Monster</th>";
        $output .= "<th>WS</th>";
        $output .= "<th>BS</th>";
        $output .= "<th>S</th>";
        $output .= "<th>T</th>";
        $output .= "<th>Sp</th>";
        $output .= "<th>Br</th>";
        $output .= "<th>Int</th>";
        $output .= "<th>W</th>";
        $output .= "<th>DD</th>";
        $output .= "<th>PV</th>";
        $output .= "<th>Equipment</th>";
        $output .= "</tr>";
        
        foreach ($csvResults as $name => $rows) {
            foreach ($rows as $row) {
                $output .= "<tr>";
                foreach ($row as $field) {
                    $output .= "<td>" . htmlspecialchars($field) . "</td>";
                }
                $output .= "</tr>";
            }
        }
        
        $output .= "</table>";
        return $output;
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
        $this->csv_processor = new CSVProcessor();
    }
    
    public function run($params) {
        global $VERBOSE;
        
        if (!isset($params['tables']) || empty($params['tables'])) {
            echo "Usage: index.php?tables=TableName1,TableName2[,...]&verbose=1&subdir=your_subdir (optional)";
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
            //debug_print("YYYYYYY Using dice notation '{$dice_notation}' for block '{$name}'");
        } elseif (strpos($line, "1D12") !== false) {
            $dice_notation = "1D12";
            $table2die[$name] = $dice_notation;
            //debug_print("YYYYYYY Using dice notation '{$dice_notation}' for block '{$name}'");
        } elseif (strpos($line, "1D6") !== false) {
            $dice_notation = "1D6";
            $table2die[$name] = $dice_notation;
            debug_print("YYYYYYY Using dice notation '{$dice_notation}' for block '{$name}'");
        }
    }
    
    private function createResolveFunction($parsed_tables, $name) {
        return function() use ($parsed_tables, $name) {
            if (empty($parsed_tables)) {
                return "";
            }
            
            list($notation, $outer) = $parsed_tables[0];
            $roll_notation = ($name == "spell" && $notation === null) ? "2D12" : 
                           ($notation !== null ? $notation : DEFAULT_DICE);
            
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