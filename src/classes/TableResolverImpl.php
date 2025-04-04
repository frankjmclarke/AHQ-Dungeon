<?php

namespace App\Classes;

use App\Interfaces\TableResolver as TableResolverInterface;
use App\Utils\OutputUtils;

require_once __DIR__ . '/../utils/OutputUtils.php';

class TableResolverImpl implements TableResolverInterface {
    private static array $table2die = [];
    private bool $verbose;

    public function __construct(bool $verbose = false) {
        $this->verbose = $verbose;
    }

    public function rollDice(string $notation): array {
        if (preg_match('/^(\d+)[dD](\d+)$/', $notation, $matches)) {
            $num = intval($matches[1]);
            $sides = intval($matches[2]);
            $total = 0;
            $rolls = [];
            for ($i = 0; $i < $num; $i++) {
                $r = random_int(1, $sides);
                $rolls[] = $r;
                $total += $r;
            }
            return ['total' => $total, 'rolls' => $rolls];
        } else {
            $r = random_int(1, 12);
            return ['total' => $r, 'rolls' => [$r]];
        }
    }

    public function getDiceNotation(string $tableName): ?string {
        return self::$table2die[$tableName] ?? null;
    }

    public function setDiceNotation(string $tableName, string $notation): void {
        self::$table2die[$tableName] = $notation;
    }

    public function loadTables(?string $subdir = null): array {
        $tables = [];
        $base_dir = dirname(dirname(__DIR__));  // Go up two levels from src/classes to root
        
        // Load files from the subdirectory (if provided)
        if ($subdir && is_dir($base_dir . '/' . $subdir)) {
            $files = glob($base_dir . '/' . $subdir . "/*.tab");
            foreach ($files as $filepath) {
                $filename = basename($filepath);
                $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
                $tables[$name] = $this->parseTabFile($filepath);
                $outputUtils = new OutputUtils();
                $outputUtils->debugPrint("Loaded table '{$name}' from subdirectory: {$filepath}");
            }
        }
        
        // Load from top-level, but do not override files already loaded from subdir
        $files = glob($base_dir . "/*.tab");
        foreach ($files as $filepath) {
            $filename = basename($filepath);
            $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
            if (!isset($tables[$name])) {
                $tables[$name] = $this->parseTabFile($filepath);
                $outputUtils = new OutputUtils();
                $outputUtils->debugPrint("Loaded table '{$name}' from top-level: {$filepath}");
            }
        }
        
        return $tables;
    }

    public function parseTabFile(string $filename): array {
        $table = [];
        $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
        
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
                    $table[] = [$roll, $content];
                }
            }
        }
        return $table;
    }

    // --- Process text with possible nested named blocks and table references ---
    public function process_and_resolve_text($text, $tables, $named_rules, $depth, $parent_table = null, $current_named = null) {
        global $resolved_stack;
        // Check if the maximum recursion depth is exceeded to prevent infinite loops
        if ($depth > MAX_DEPTH) {
            $outputUtils = new OutputUtils();
            $outputUtils->debugPrint(str_repeat("  ", $depth) . "[Maximum recursion depth reached]");
            return;
        }        
        // Split the input text into lines for processing
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $line = trim($line);
            // Check for named block calls in the format "Name()"
            if (preg_match('/^([A-Za-z0-9_\-]+)\(\)$/', $line, $matches)) {
                $name_candidate = strtolower($matches[1]);
                
                // If the named block exists, resolve it recursively
                if (isset($named_rules[$name_candidate])) {
                    $outputUtils = new OutputUtils();
                    $outputUtils->debugPrint(str_repeat("  ", $depth) . "→ Resolving named block: " . $line);
                    $result = $named_rules[$name_candidate]();
                    $this->process_and_resolve_text($result, $tables, $named_rules, $depth + 1, $parent_table, $name_candidate);
                    continue;
                }
                $found = true;
            }
            
            // Convert the line to lowercase for consistent key access
            $lower_line = strtolower($line);
            
            // Check if the line corresponds to a named rule
            if (isset($named_rules[$lower_line])) {
                // Avoid resolving the current named block to prevent cycles
                if ($current_named !== null && $lower_line === $current_named) {
                    final_print(str_repeat("AAA  ", $depth), $line);
                } else {
                    // Detect and handle cycles in named block resolution
                    if (in_array($lower_line, $resolved_stack)) {
                        $outputUtils = new OutputUtils();
                        $outputUtils->debugPrint(str_repeat("  ", $depth) . "→ [Cycle detected: " . $line . "]");
                    } else {
                        $resolved_stack[] = $lower_line;
                        $outputUtils = new OutputUtils();
                        $outputUtils->debugPrint(str_repeat("  ", $depth) . "→ Resolving named block: " . $line);
                        $result = $named_rules[$lower_line]();
                        $this->process_and_resolve_text($result, $tables, $named_rules, $depth + 1, $parent_table, $lower_line);
                        array_pop($resolved_stack);
                    }
                }
                continue;
            }
            
            // Handle lines enclosed in quotes by printing them directly
            if (substr($line, 0, 1) === '"' && substr($line, -1) === '"') {
                final_print(str_repeat("BBB  ", $depth), substr($line, 1, -1));
            } else {
                final_print(str_repeat("CCC  ", $depth), $line);
            }
            
            // Find all named block calls within the line
            if (preg_match_all('/([A-Za-z0-9_\-]+)\(\)/', $line, $all_matches)) {
                foreach ($all_matches[1] as $match) {
                    $match_lower = strtolower($match);
                    
                    // Skip resolving the current named block to prevent cycles
                    if ($current_named !== null && $match_lower === $current_named) {
                        continue;
                    }
                    
                    // Skip already resolved blocks to prevent cycles
                    if (in_array($match_lower, $resolved_stack)) {
                        continue;
                    }
                    
                    // Add the block to the resolved stack and resolve it
                    $resolved_stack[] = $match_lower;
                    if (isset($named_rules[$match_lower])) {
                        $result = $named_rules[$match_lower]();
                        $this->process_and_resolve_text($result, $tables, $named_rules, $depth + 1, $parent_table, $match_lower);
                    } elseif (isset($tables[$match_lower])) {
                        $this->resolveTable($match_lower, $tables, $named_rules, $depth + 1);
                    }
                    array_pop($resolved_stack);
                }
            }
        }
    }

    // Resolve a table by rolling dice and processing its entry
    public function resolveTable(string $name, array $tables, array $named_rules = [], int $depth = 0): ?string {
        $indent = str_repeat("  ", $depth);
        $name = strtolower($name);
        // Instantiate OutputUtils at the beginning of the method
        $outputUtils = new OutputUtils();

        // Check if the table exists
        if (!isset($tables[$name])) {
            $outputUtils->debugPrint($indent . "[Table '{$name}' not found]");
            return null;
        }
        $table = $tables[$name];
        
        $diceNotation = $this->getDiceNotation($name);                
        if ($diceNotation !== null) {
            // Roll the dice using the specified notation
            $result = $this->rollDice($diceNotation);
            $outputUtils->debugPrint("[Good entry for ROLL {$diceNotation}]");
        } else {
            // Use a default dice notation if none is specified
            $outputUtils->debugPrint("[Bad entry for ROLL {$name} {$diceNotation}]");
            $result = $this->rollDice("1D12");
        }
        $roll = $result['total'];
        $rolls = $result['rolls'];
        $entry = null;
        // map roll value to the corresponding result or effect.
        foreach ($table as $tuple) {
            if ($tuple[0] == $roll) {
                $entry = $tuple[1];
                break;
            }
        }
        $outputUtils->debugPrint($indent . "Rolled {$roll} on {$name}: {$entry} (rolls: " . implode(",", $rolls) . ")");
        if (!$entry) {
            $outputUtils->debugPrint($indent . "[No entry for roll {$roll}]");
            return null;
        }
        // Process the entry if it is enclosed in quotes
        if (substr($entry, 0, 1) === '"' && substr($entry, -1) === '"') {
            final_print($indent, substr($entry, 1, -1));
            final_print($indent, "DDD");
            return null;
        }
        // Process nested table references
        if (substr($entry, 0, 2) === '[[' && substr($entry, -2) === ']]') {
            $inner = substr($entry, 2, -2);
            $parts = array_map('trim', explode("&", $inner));
            foreach ($parts as $part) {
                $part_normalized = strtolower(str_replace(array("(", ")"), "", $part));
                $this->resolveTable($part_normalized, $tables, $named_rules, $depth + 1);
            }
            return null;
        }
        // Process and resolve the text of the entry
        $this->process_and_resolve_text($entry, $tables, $named_rules, $depth, $name, null);
        return null;
    }
} 