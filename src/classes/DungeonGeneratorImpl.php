<?php
require_once __DIR__ . '/../interfaces/DungeonGenerator.php';
require_once __DIR__ . '/../interfaces/DiceRoller.php';
require_once __DIR__ . '/../interfaces/TableResolver.php';
require_once __DIR__ . '/../interfaces/TextProcessor.php';
require_once __DIR__ . '/../interfaces/CSVProcessor.php';

class DungeonGeneratorImpl implements DungeonGenerator {
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
        
        if (isset($tables[$user_input])) {
            $table = $tables[$user_input];
            $result = $this->text_processor->process($table, $tables, $named_rules, 0);
            echo $result;
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
    
    private function parseNamedBlock($block_lines) {
        global $table2die;
        $name = null;
        $notation = null;
        $parsed_tables = array();
        
        foreach ($block_lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if ($name === null) {
                if (preg_match('/^@(\w+)/', $line, $matches)) {
                    $name = $matches[1];
                    debug_print("Found named block: {$name}");
                }
            } else {
                if (strpos($line, "D") !== false) {
                    $this->processDiceNotation($line, $name);
                } elseif (strpos($line, "|") !== false) {
                    $parsed_tables[] = $this->parseTable($line);
                }
            }
        }
        
        if ($name !== null) {
            $fn = $this->createResolveFunction($parsed_tables, $name);
            return array($name, $fn);
        }
        
        return array(null, null);
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
    
    private function parseTable($line) {
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
        
        return array($notation, $entries);
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
        $parts = explode("&", $entry_val);
        $output_parts = array();
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;
            
            if (strtolower($part) == $name) {
                $fn = $this->createResolveFunction($parsed_tables, $name);
                $output_parts[] = $fn();
            } else {
                $output_parts[] = $part;
            }
        }
        
        return implode(" ", $output_parts);
    }
} 