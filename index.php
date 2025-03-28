﻿<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Global constants and variables
define('MAX_DEPTH', 50);         // Maximum recursion depth
define('DEFAULT_DICE', "1D12");   // Global default dice (if no block-specific notation is provided)
$VERBOSE = false;                // Global verbosity flag
$resolved_stack = array();       // Global resolved stack for cycle detection

// --- Helper printing functions ---
function debug_print($msg) {
    global $VERBOSE;
    if ($VERBOSE) {
        echo $msg . "<br>";
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

// --- Dice rolling ---
function roll_dice($notation) {
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

// --- Parsing an inline table from a set of lines ---
function parse_inline_table($lines) {
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

// --- Extract named blocks from .tab and .txt files ---
function extract_named_blocks() {
    $blocks = array();
    $files = array_merge(glob("*.tab"), glob("*.txt"));
    foreach ($files as $filepath) {
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
    }
    return $blocks;
}

// --- Parse a named block into a block name and a resolver closure ---
function parse_named_block($lines) {
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
            $parsed = parse_inline_table($current);
            $current = count($stack) > 0 ? array_pop($stack) : array();
            $parsed_tables[] = array($dice_notation, $parsed);
            $dice_notation = null;
        } else {
            $current[] = $line;
        }
    }
    // Return a closure that resolves this block when called
    $resolve_nested = function() use ($parsed_tables, $name) {
        if (empty($parsed_tables)) {
            return "";
        }
        list($notation, $outer) = $parsed_tables[0];
        if ($name === "spell" && $notation === null) {
            $roll_notation = "2D12";
        } else {
            $roll_notation = $notation !== null ? $notation : DEFAULT_DICE;
        }
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
            $result = roll_dice($roll_notation);
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
            if ($entry_val !== null && $has_composite && strtolower(trim($entry_val)) === $name) {
                $attempts++;
                continue;
            }
            break;
        }
        if ($entry_val !== null && strpos($entry_val, "&") !== false) {
            $parts = array_map('trim', explode("&", $entry_val));
            $output = array();
            foreach ($parts as $part) {
                if (strtolower($part) === $name) {
                    continue;
                }
                if (strpos($part, "(") === 0 && count($parsed_tables) > 1) {
                    list($notation2, $subtable) = $parsed_tables[1];
                    $roll_notation2 = $notation2 !== null ? $notation2 : DEFAULT_DICE;
                    $result2 = roll_dice($roll_notation2);
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
        return $entry_val !== null ? $entry_val : "";
    };
    return array($name, $resolve_nested);
}

// --- Load tables from .tab files ---
function load_tables() {
    $tables = array();
    $files = glob("*.tab");
    foreach ($files as $filepath) {
        $filename = basename($filepath);
        $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
        $tables[$name] = parse_tab_file($filepath);
    }
    return $tables;
}

// --- Parse a single .tab file into a table ---
function parse_tab_file($filename) {
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

// --- Process text with possible nested named blocks and table references ---
function process_and_resolve_text($text, $tables, $named_rules, $depth, $parent_table = null, $current_named = null) {
    global $resolved_stack;
    if ($depth > MAX_DEPTH) {
        debug_print(str_repeat("  ", $depth) . "[Maximum recursion depth reached]");
        return;
    }
    $lines = explode("\n", $text);
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/^([A-Za-z0-9_\-]+)\(\)$/', $line, $matches)) {
            $name_candidate = strtolower($matches[1]);
            if (isset($named_rules[$name_candidate])) {
                debug_print(str_repeat("  ", $depth) . "→ Resolving named block: " . $line);
                $result = $named_rules[$name_candidate]();
                process_and_resolve_text($result, $tables, $named_rules, $depth + 1, $parent_table, $name_candidate);
                continue;
            }
        }
        $lower_line = strtolower($line);
        if (isset($named_rules[$lower_line])) {
            if ($current_named !== null && $lower_line === $current_named) {
                final_print(str_repeat("  ", $depth), $line);
            } else {
                if (in_array($lower_line, $resolved_stack)) {
                    debug_print(str_repeat("  ", $depth) . "→ [Cycle detected: " . $line . "]");
                } else {
                    $resolved_stack[] = $lower_line;
                    debug_print(str_repeat("  ", $depth) . "→ Resolving named block: " . $line);
                    $result = $named_rules[$lower_line]();
                    process_and_resolve_text($result, $tables, $named_rules, $depth + 1, $parent_table, $lower_line);
                    array_pop($resolved_stack);
                }
            }
            continue;
        }
        if (substr($line, 0, 1) === '"' && substr($line, -1) === '"') {
            final_print(str_repeat("  ", $depth), substr($line, 1, -1));
        } else {
            final_print(str_repeat("  ", $depth), $line);
        }
        if (preg_match_all('/([A-Za-z0-9_\-]+)\(\)/', $line, $all_matches)) {
            foreach ($all_matches[1] as $match) {
                $match_lower = strtolower($match);
                if ($current_named !== null && $match_lower === $current_named) {
                    continue;
                }
                if (in_array($match_lower, $resolved_stack)) {
                    continue;
                }
                $resolved_stack[] = $match_lower;
                if (isset($named_rules[$match_lower])) {
                    $result = $named_rules[$match_lower]();
                    process_and_resolve_text($result, $tables, $named_rules, $depth + 1, $parent_table, $match_lower);
                } elseif (isset($tables[$match_lower])) {
                    resolve_table($match_lower, $tables, $named_rules, $depth + 1);
                }
                array_pop($resolved_stack);
            }
        }
    }
}

// --- Resolve a table by rolling dice and processing its entry ---
function resolve_table($name, $tables, $named_rules = array(), $depth = 0) {
    $indent = str_repeat("  ", $depth);
    $name = strtolower($name);
    if (!isset($tables[$name])) {
        debug_print($indent . "[Table '{$name}' not found]");
        return;
    }
    $table = $tables[$name];
    $result = roll_dice(DEFAULT_DICE);
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
            resolve_table($part_normalized, $tables, $named_rules, $depth + 1);
        }
        return;
    }
    process_and_resolve_text($entry, $tables, $named_rules, $depth, $name, null);
}

// --- Main function ---
function main() {
    global $VERBOSE;
    // Retrieve parameters from the URL query string, e.g. ?tables=room,treasure&verbose=1
    if (isset($_GET['verbose']) && $_GET['verbose'] == "1") {
        $VERBOSE = true;
    }
    if (!isset($_GET['tables']) || empty($_GET['tables'])) {
        echo "Usage: script.php?tables=TableName1,TableName2[,...]&verbose=1 (optional for verbose output)";
        return;
    }
    // Expect comma-separated table names in the 'tables' GET parameter
    $params = array_map('trim', explode(",", $_GET['tables']));
    
    $tables = load_tables();
    $named_rules = array();
    $raw_blocks = extract_named_blocks();
    foreach ($raw_blocks as $name => $block_lines) {
        list($key, $fn) = parse_named_block($block_lines);
        $named_rules[$key] = $fn;
    }
    foreach ($params as $user_input) {
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
        } else {
            $table_name = $candidates[0];
            if ($VERBOSE) {
                echo "<br>--- Resolving table '{$user_input}' ---<br>";
            }
            resolve_table($table_name, $tables, $named_rules);
            if ($VERBOSE) {
                echo "<br>" . str_repeat("=", 50) . "<br>";
            }
        }
    }
}

main();
?>
