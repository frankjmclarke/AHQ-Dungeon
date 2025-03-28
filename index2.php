<?php
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

function extract_named_blocks($subdir = null) {
    global $VERBOSE;
    $blocks = array();
    
    // Get files from top-level
    $files_top = array_merge(glob("*.tab"), glob("*.txt"));
    // Get files from the subdirectory (if provided)
    $files_sub = ($subdir && is_dir($subdir)) 
        ? array_merge(glob($subdir . "/*.tab"), glob($subdir . "/*.txt"))
        : array();
    
    // Build an associative array keyed by lowercased basename
    $files_assoc = array();
    foreach ($files_top as $filepath) {
        $key = strtolower(basename($filepath));
        $files_assoc[$key] = $filepath;
    }
    // Override (or add) with subdirectory files
    foreach ($files_sub as $filepath) {
        $key = strtolower(basename($filepath));
        $files_assoc[$key] = $filepath;
    }
    
    // Now process each file in $files_assoc
    foreach ($files_assoc as $filepath) {
        if ($VERBOSE) {
            debug_print("Processing named blocks from file: {$filepath}");
        }
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

// --- Load tables from .tab files ---
// Checks for files in the selected subdirectory first, then falls back to top-level.
function load_tables($subdir = null) {
    global $VERBOSE;
    $tables = array();
    
    // Load files from the subdirectory (if provided)
    if ($subdir && is_dir($subdir)) {
        $files = glob($subdir . "/*.tab");
        foreach ($files as $filepath) {
            $filename = basename($filepath);
            $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
            $tables[$name] = parse_tab_file($filepath);
            if ($VERBOSE) {
                debug_print("Loaded table '{$name}' from subdirectory: {$filepath}");
            }
        }
    }
    // Load from top-level, but do not override files already loaded from subdir
    $files = glob("*.tab");
    foreach ($files as $filepath) {
        $filename = basename($filepath);
        $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
        if (!isset($tables[$name])) {
            $tables[$name] = parse_tab_file($filepath);
            if ($VERBOSE) {
                debug_print("Loaded table '{$name}' from top-level: {$filepath}");
            }
        }
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
    $params = $_GET;
    if (isset($params['verbose']) && $params['verbose'] == "1") {
        $VERBOSE = true;
    }
    if (!isset($params['tables']) || empty($params['tables'])) {
        echo "Usage: index2.php?tables=TableName1,TableName2[,...]&verbose=1&subdir=your_subdir (optional)";
        return;
    }
    $subdir = isset($params['subdir']) ? $params['subdir'] : null;

    // Start output buffering so we can capture all printed text.
    ob_start();

    // Existing logic: load tables, extract named blocks, and process user inputs.
    $tables = load_tables($subdir);
    $named_rules = array();
    $raw_blocks = extract_named_blocks($subdir);
    foreach ($raw_blocks as $name => $block_lines) {
        list($key, $fn) = parse_named_block($block_lines);
        $named_rules[$key] = $fn;
    }
    $user_tables = array_map('trim', explode(",", $params['tables']));
    foreach ($user_tables as $user_input) {
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

    // Get the generated output.
    $output = ob_get_clean();

    // Echo the main output.
    echo $output;

    // --- CSV Search Functionality ---
    // Clear any existing CSV output by starting fresh on each call.
    // If the output starts with a numeric sequence, try to extract name strings.
// --- CSV Search Functionality ---
    // --- CSV Search Functionality ---
    // --- CSV Search Functionality ---
// --- CSV Search Functionality ---
// --- CSV Search Functionality ---
$csvOutput = "";
if (preg_match_all('/\d+\s+([A-Za-z ]+?)(?=[^A-Za-z ]|$)/', $output, $matches)) {
    $names = array_map('trim', $matches[1]);
    $names = array_filter($names, function($n) { return $n !== ""; });
    $csvResults = array();
    if (($handle = fopen("skaven_bestiary.csv", "r")) !== false) {
        while (($data = fgetcsv($handle)) !== false) {
            if (isset($data[0])) {
                $csvName = trim($data[0]);
                foreach ($names as $name) {
                    // Try exact match first.
                    if (strcasecmp($csvName, $name) === 0) {
                        $csvResults[$name][] = $data;
                    } 
                    // If not found and name ends with "s", try the singular form.
                    else if (substr($name, -1) === "s") {
                        $singular = substr($name, 0, -1);
                        if (strcasecmp($csvName, $singular) === 0) {
                            $csvResults[$name][] = $data;
                        }
                    }
                    else if (substr($name, -3) === "men") {
                        $singular = substr($name, 0, -3) . "man";
                        if (strcasecmp($csvName, $singular) === 0) {
                            $csvResults[$name][] = $data;
                        }
                    }
                }
            }
        }
        fclose($handle);
    }
    if (!empty($csvResults)) {
        // Use a unique marker to separate main output from CSV output.
        $csvOutput .= "##CSV_MARKER##";
        $csvOutput .= "<table border='1' cellspacing='0' cellpadding='4' style='max-width:500px; margin:0 auto;'>";
        // Insert header row.
        $csvOutput .= "<tr>";
        $csvOutput .= "<th>Monster</th>";
        $csvOutput .= "<th>WS</th>";
        $csvOutput .= "<th>BS</th>";
        $csvOutput .= "<th>S</th>";
        $csvOutput .= "<th>T</th>";
        $csvOutput .= "<th>Sp</th>";
        $csvOutput .= "<th>Br</th>";
        $csvOutput .= "<th>Int</th>";
        $csvOutput .= "<th>W</th>";
        $csvOutput .= "<th>DD</th>";
        $csvOutput .= "<th>PV</th>";
        $csvOutput .= "<th>Equipment</th>";
        $csvOutput .= "</tr>";
        foreach ($csvResults as $name => $rows) {
            foreach ($rows as $row) {
                $csvOutput .= "<tr>";
                foreach ($row as $field) {
                    $csvOutput .= "<td>" . htmlspecialchars($field) . "</td>";
                }
                $csvOutput .= "</tr>";
            }
        }
        $csvOutput .= "</table>";
    }
}
echo $csvOutput;



}

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
    // The closure that, when called, resolves this block
    $resolve_nested = function() use ($parsed_tables, $name) {
        if (empty($parsed_tables)) {
            return "";
        }
        list($notation, $outer) = $parsed_tables[0];
        if ($name == "spell" && $notation === null) {
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
            $final_output = implode("\n", $output);
        } else {
            $final_output = ($entry_val !== null) ? $entry_val : "";
        }
        // If this is a Hidden-Treasure block, wrap the output with special markers.
        if ($name == "hidden-treasure") {
            return "[Hidden-Treasure]\n" . $final_output . "\n[/Hidden-Treasure]";
        }
        return $final_output;
    };
    return array($name, $resolve_nested);
}


if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    main();
}
?>
