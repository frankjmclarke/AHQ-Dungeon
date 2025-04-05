<?php
require_once 'Logger.php';
require_once 'TableManager.php';
require_once 'DiceRoller.php';
require_once 'FileParser.php';

// Global constants
define('MAX_DEPTH', 50);         // Maximum recursion depth
define('DEFAULT_DICE', "1D12");   // Global default dice (if no block-specific notation is provided)

// Processes and resolves text by evaluating named blocks and tables
// Handles recursion with a maximum depth to prevent infinite loops
// Outputs resolved text or logs debug information

class TableProcessor {
    public static function processAndResolveText($text, $tables, $named_rules, $depth, $parent_table = null, $current_named = null) {
        if ($depth > MAX_DEPTH) {
            Logger::debug(str_repeat("  ", $depth) . "[Maximum recursion depth reached]");
            return;
        }
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Handle function calls like Room-Furnish()
            if (preg_match('/^([A-Za-z0-9_\-]+)\(\)$/', $line, $matches)) {
                $name_candidate = strtolower($matches[1]);
                if (isset($named_rules[$name_candidate])) {
                    Logger::debug(str_repeat("  ", $depth) . "→ Resolving function call: " . $line);
                    $result = $named_rules[$name_candidate]();
                    self::processAndResolveText($result, $tables, $named_rules, $depth + 1, $parent_table, $name_candidate);
                    continue;
                }
            }

            // Handle composite entries with &
            if (strpos($line, "&") !== false) {
                $parts = array_map('trim', explode("&", $line));
                foreach ($parts as $part) {
                    self::processAndResolveText($part, $tables, $named_rules, $depth, $parent_table, $current_named);
                }
                continue;
            }

            // Handle quoted text
            if (substr($line, 0, 1) === '"' && substr($line, -1) === '"') {
                Logger::output(str_repeat("  ", $depth), substr($line, 1, -1));
                continue;
            }

            // Handle named blocks and tables
            $lower_line = strtolower($line);
            if (isset($named_rules[$lower_line])) {
                if ($current_named !== null && $lower_line === $current_named) {
                    Logger::output(str_repeat("  ", $depth), $line);
                } else {
                    if (TableManager::isInResolvedStack($lower_line)) {
                        Logger::debug(str_repeat("  ", $depth) . "→ [Cycle detected: " . $line . "]");
                    } else {
                        TableManager::pushResolvedStack($lower_line);
                        Logger::debug(str_repeat("  ", $depth) . "→ Resolving named block: " . $line);
                        $result = $named_rules[$lower_line]();
                        self::processAndResolveText($result, $tables, $named_rules, $depth + 1, $parent_table, $lower_line);
                        TableManager::popResolvedStack();
                    }
                }
                continue;
            }

            // Output regular text
            Logger::output(str_repeat("  ", $depth), $line);
        }
    }

    // Resolves a table by rolling dice and selecting an entry
    // Handles nested tables and composite entries
    // Outputs the result or logs debug information

    public static function resolveTable($name, $tables, $named_rules = array(), $depth = 0) {
        $indent = str_repeat("  ", $depth);
        $name = strtolower($name);
        if (!isset($tables[$name])) {
            Logger::debug($indent . "[Table '{$name}' not found]");
            return;
        }
        $table = $tables[$name];
        $diceNotation = TableManager::getDiceNotation($name);

        if ($diceNotation !== null) {
            $result = DiceRoller::roll($diceNotation);
            Logger::debug("[Rolling {$diceNotation} for table {$name}]");
        } else {
            Logger::debug("[Using default dice for table {$name}]");
            $result = DiceRoller::roll(DEFAULT_DICE);
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
        Logger::debug($indent . "Rolled {$roll} on {$name}: {$entry} (rolls: " . implode(",", $rolls) . ")");
        if (!$entry) {
            Logger::debug($indent . "[No entry for roll {$roll}]");
            return;
        }
        if (substr($entry, 0, 1) === '"' && substr($entry, -1) === '"') {
            Logger::output($indent, substr($entry, 1, -1));
            return;
        }
        if (substr($entry, 0, 2) === '[[' && substr($entry, -2) === ']]') {
            $inner = substr($entry, 2, -2);
            $parts = array_map('trim', explode("&", $inner));
            foreach ($parts as $part) {
                $part_normalized = strtolower(str_replace(array("(", ")"), "", $part));
                self::resolveTable($part_normalized, $tables, $named_rules, $depth + 1);
            }
            return;
        }
        self::processAndResolveText($entry, $tables, $named_rules, $depth, $name, null);
    }

    // Parses a named block into tables with optional dice notation
    // Returns a closure that resolves the block when called
    // Handles nested structures and composite entries

    public static function parseNamedBlock($lines) {
        $name = strtolower(trim($lines[0]));
        $stack = [];
        $current = [];
        $parsed_tables = [];
        $current_dice = null;
        $nested_level = 0;

        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);

            // Handle opening bracket
            if (strpos($line, "(") === 0) {
                $nested_level++;
                $stack[] = [$current, $current_dice];
                $current = [];
                $current_dice = null;
                continue;
            }

            // Handle closing bracket
            if (strpos($line, ")") === 0) {
                if (!empty($current)) {
                    // Process dice notation
                    $dice = self::extractDiceNotation($current);
                    if ($dice !== null) {
                        $current_dice = $dice;
                    }

                    // Parse the current block
                    $parsed = FileParser::parseInlineTable($current);
                    $parsed_tables[] = [$current_dice, $parsed, $nested_level];
                }

                // Restore previous state
                if (!empty($stack)) {
                    list($current, $current_dice) = array_pop($stack);
                }
                $nested_level--;
                continue;
            }

            // Handle regular line
            $current[] = $line;

            // Check for dice notation in the line
            if (preg_match('/(\d+[dD]\d+)/', $line, $matches)) {
                $current_dice = strtoupper($matches[1]);
            }
        }

        // Create the resolver function
        return [$name, function() use ($parsed_tables, $name) {
            if (empty($parsed_tables)) {
                return "";
            }

            // Process each table in order
            $result = "";
            foreach ($parsed_tables as list($dice_notation, $table, $level)) {
                $roll_notation = $dice_notation ?? DEFAULT_DICE;
                Logger::debug("Using dice notation '{$roll_notation}' for level {$level} in block '{$name}'");
                
                $roll = DiceRoller::roll($roll_notation);
                foreach ($table as $entry) {
                    if ($entry[0] == $roll['total']) {
                        $result .= $entry[1] . "\n";
                        break;
                    }
                }
            }
            return $result;
        }];
    }

    private static function extractDiceNotation($lines) {
        foreach ($lines as $line) {
            if (preg_match('/(\d+[dD]\d+)/', $line, $matches)) {
                return strtoupper($matches[1]);
            }
        }
        return null;
    }
} 