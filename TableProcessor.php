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
            if (preg_match('/^([A-Za-z0-9_\-]+)\(\)$/', $line, $matches)) {
                $name_candidate = strtolower($matches[1]);
                if (isset($named_rules[$name_candidate])) {
                    Logger::debug(str_repeat("  ", $depth) . "→ Resolving named block: " . $line);
                    $result = $named_rules[$name_candidate]();
                    self::processAndResolveText($result, $tables, $named_rules, $depth + 1, $parent_table, $name_candidate);
                    continue;
                }
            }
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
            if (substr($line, 0, 1) === '"' && substr($line, -1) === '"') {
                Logger::output(str_repeat("  ", $depth), substr($line, 1, -1));
            } else {
                Logger::output(str_repeat("  ", $depth), $line);
            }
            if (preg_match_all('/([A-Za-z0-9_\-]+)\(\)/', $line, $all_matches)) {
                foreach ($all_matches[1] as $match) {
                    $match_lower = strtolower($match);
                    if ($current_named !== null && $match_lower === $current_named) {
                        continue;
                    }
                    if (TableManager::isInResolvedStack($match_lower)) {
                        continue;
                    }
                    TableManager::pushResolvedStack($match_lower);
                    if (isset($named_rules[$match_lower])) {
                        $result = $named_rules[$match_lower]();
                        self::processAndResolveText($result, $tables, $named_rules, $depth + 1, $parent_table, $match_lower);
                    } elseif (isset($tables[$match_lower])) {
                        self::resolveTable($match_lower, $tables, $named_rules, $depth + 1);
                    }
                    TableManager::popResolvedStack();
                }
            }
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
            Logger::debug("[Good entry for ROLL {$diceNotation}]");
        } else {
            Logger::debug("[Bad entry for ROLL {$name}]");
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
        $stack = array();
        $current = array();
        $parsed_tables = array();  // Each element: [dice_notation, table]
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
                $parsed = FileParser::parseInlineTable($current);
                $current = count($stack) > 0 ? array_pop($stack) : array();
                $parsed_tables[] = array($dice_notation, $parsed);
                $dice_notation = null;
            } else {
                $current[] = $line;
            }
            if ($i == 1) {
                // Check for each possible dice notation in the first line
                if (strpos($line, "2D12") !== false) {
                    $dice_notation = "2D12";
                    TableManager::setDiceNotation($name, $dice_notation);
                    //Logger::debug("YYYYYYY Using dice notation '{$dice_notation}' for block '{$name}'");
                } elseif (strpos($line, "1D12") !== false) {
                    $dice_notation = "1D12";
                    TableManager::setDiceNotation($name, $dice_notation);
                    //Logger::debug("YYYYYYY Using dice notation '{$dice_notation}' for block '{$name}'");
                } elseif (strpos($line, "1D6") !== false) {
                    $dice_notation = "1D6";
                    TableManager::setDiceNotation($name, $dice_notation);
                    //Logger::debug("YYYYYYY Using dice notation '{$dice_notation}' for block '{$name}'");
                }
            }
        }
        
        // The closure that, when called, resolves this block
        return array($name, function() use ($parsed_tables, $name) {
            if (empty($parsed_tables)) {
                return "";
            }
            list($notation, $outer) = $parsed_tables[0];
            
            $roll_notation = $notation !== null ? $notation : DEFAULT_DICE;
            Logger::debug("Using dice notation '{$roll_notation}' for block '{$name}'");
            
            $has_composite = false;
            foreach ($outer as $entry) {
                if (strpos($entry[1], "&") !== false) {
                    $has_composite = true;
                    break;
                }
            }
            
            $attempts = 0;
            $entry_val = null;
            while ($attempts < 100) {
                $result = DiceRoller::roll($roll_notation);
                $roll = $result['total'];
                $rolls = $result['rolls'];
                $entry_val = null;
                foreach ($outer as $tuple) {
                    if ($tuple[0] == $roll) {
                        $entry_val = $tuple[1];
                        break;
                    }
                }
                Logger::debug("  → [Nested roll in {$name}]: Rolled {$roll} (rolls: " . implode(",", $rolls) . ") resulting in: {$entry_val}");
                if ($entry_val !== null && $has_composite && strtolower(trim($entry_val)) == $name) {
                    $attempts++;
                    continue;
                }
                break;
            }
            
            if ($entry_val !== null && strpos($entry_val, "&") !== false) {
                $parts = array_map('trim', explode("&", $entry_val));
                $output = array();
                foreach ($parts as $part) {
                    if (strtolower($part) == $name) {
                        continue;
                    }
                    if (strpos($part, "(") === 0 && count($parsed_tables) > 1) {
                        list($notation2, $subtable) = $parsed_tables[1];
                        $roll_notation2 = $notation2 !== null ? $notation2 : DEFAULT_DICE;
                        $result2 = DiceRoller::roll($roll_notation2);
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
                $final_output = implode("\n", $output);
            } else {
                $final_output = ($entry_val !== null) ? $entry_val : "";
            }
            
            if ($name == "hidden-treasure") {
                return "[Hidden-Treasure]\n" . $final_output . "\n[/Hidden-Treasure]";
            }
            return $final_output;
        });
    }
} 