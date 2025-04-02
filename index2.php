<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Global constants and variables
define('MAX_DEPTH', 50);         // Maximum recursion depth
define('DEFAULT_DICE', "1D12");   // Global default dice (if no block-specific notation is provided)
define('MONSTER_CACHE_TTL', 3600); // 60 minutes cache TTL for monster records
$VERBOSE = false;                // Global verbosity flag
$resolved_stack = array();       // Global resolved stack for cycle detection
global $table2die;
$table2die = array();

// Cache for tables and named blocks
$table_cache = array();
$named_blocks_cache = array();
$monster_cache = array();  // Cache for monster records
$cache_ttl = 300; // 5 minutes cache TTL

/**
 * Get cached table or load it if not in cache
 * @param string $name Table name
 * @param string|null $subdir Subdirectory path
 * @return array Table data
 */
function get_cached_table($name, $subdir = null) {
    global $table_cache, $cache_ttl;
    
    $cache_key = $subdir ? "{$subdir}/{$name}" : $name;
    
    if (isset($table_cache[$cache_key]) && 
        isset($table_cache[$cache_key]['timestamp']) && 
        (time() - $table_cache[$cache_key]['timestamp'] < $cache_ttl)) {
        return $table_cache[$cache_key]['data'];
    }
    
    $table = parse_tab_file($subdir ? "{$subdir}/{$name}.tab" : "{$name}.tab");
    $table_cache[$cache_key] = array(
        'data' => $table,
        'timestamp' => time()
    );
    
    return $table;
}

/**
 * Get cached named blocks or load them if not in cache
 * @param string|null $subdir Subdirectory path
 * @return array Named blocks data
 */
function get_cached_named_blocks($subdir = null) {
    global $named_blocks_cache, $cache_ttl;
    
    $cache_key = $subdir ?: 'root';
    
    if (isset($named_blocks_cache[$cache_key]) && 
        isset($named_blocks_cache[$cache_key]['timestamp']) && 
        (time() - $named_blocks_cache[$cache_key]['timestamp'] < $cache_ttl)) {
        return $named_blocks_cache[$cache_key]['data'];
    }
    
    $blocks = extract_named_blocks($subdir);
    $named_blocks_cache[$cache_key] = array(
        'data' => $blocks,
        'timestamp' => time()
    );
    
    return $blocks;
}

/**
 * Get cached monster records or load them if not in cache
 * @param string $csvFile Path to the CSV file
 * @param array $names Monster names to look up
 * @return array Array of monster records keyed by name
 */
function get_cached_monster_records($csvFile, $names) {
    global $monster_cache;
    
    $cache_key = md5($csvFile . implode('|', $names));
    
    if (isset($monster_cache[$cache_key]) && 
        isset($monster_cache[$cache_key]['timestamp']) && 
        (time() - $monster_cache[$cache_key]['timestamp'] < MONSTER_CACHE_TTL)) {
        return $monster_cache[$cache_key]['data'];
    }
    
    $monsterResults = array();
    
    if (!file_exists($csvFile)) {
        debug_print("[Error: Monster data file '{$csvFile}' does not exist]");
        return array();
    }
    
    $handle = @fopen($csvFile, "r");
    if ($handle === false) {
        debug_print("[Error: Could not open monster data file '{$csvFile}']");
        return array();
    }
    
    try {
        while (($data = fgetcsv($handle)) !== false) {
            if (isset($data[0])) {
                $monsterName = trim($data[0]);
                foreach ($names as $name) {
                    // Try exact match.
                    if (strcasecmp($monsterName, $name) === 0) {
                        $monsterResults[$name][] = $data;
                    } 
                    // If name ends with "s", try the singular form.
                    else if (substr($name, -1) === "s") {
                        $singular = substr($name, 0, -1);
                        if (strcasecmp($monsterName, $singular) === 0) {
                            $monsterResults[$name][] = $data;
                        }
                    }
                    // Handle names ending with "men".
                    else if (substr($name, -3) === "men") {
                        $singular = substr($name, 0, -3) . "man";
                        if (strcasecmp($monsterName, $singular) === 0) {
                            $monsterResults[$name][] = $data;
                        }
                    }
                }
            }
        }
    } finally {
        fclose($handle);
    }
    
    $monster_cache[$cache_key] = array(
        'data' => $monsterResults,
        'timestamp' => time()
    );
    
    return $monsterResults;
}

// --- Helper printing functions ---
function debug_print($msg) {
    global $VERBOSE;
    if ($VERBOSE) {
        echo $msg . "<br>";
    }
}

function final_print($indent, $msg) {
    echo $indent . ($GLOBALS['VERBOSE'] ? "→ Output: " : "") . $msg . "<br>";
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

// --- Lookup dice notation for a given table name ---
function getDiceNotation($tableName, $named_rules) {
    if (isset($named_rules[$tableName])) {
        return $named_rules[$tableName]['dice_notation'];
    }
    return null;
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

// --- Extract named blocks from .tab or .txt files ---
function extract_named_blocks($subdir = null) {
    global $VERBOSE;
    $blocks = array();
    
    // Get files from top-level and optionally a subdirectory.
    $files_top = array_merge(glob("*.tab"), glob("*.txt"));
    $files_sub = ($subdir && is_dir($subdir))
        ? array_merge(glob($subdir . "/*.tab"), glob($subdir . "/*.txt"))
        : array();
    
    // Build associative array keyed by lowercased basename
    $files_assoc = array();
    foreach ($files_top as $filepath) {
        $files_assoc[strtolower(basename($filepath))] = $filepath;
    }
    foreach ($files_sub as $filepath) {
        $files_assoc[strtolower(basename($filepath))] = $filepath;
    }
    
    // Process each file in $files_assoc
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
function load_tables($subdir = null) {
    global $VERBOSE;
    $tables = array();
    
    // Load files from the subdirectory first.
    if ($subdir && is_dir($subdir)) {
        $files = glob($subdir . "/*.tab");
        foreach ($files as $filepath) {
            $name = strtolower(pathinfo(basename($filepath), PATHINFO_FILENAME));
            $tables[$name] = get_cached_table($name, $subdir);
            if ($VERBOSE) {
                debug_print("Loaded table '{$name}' from subdirectory: {$filepath}");
            }
        }
    }
    // Then load from top-level without overriding.
    $files = glob("*.tab");
    foreach ($files as $filepath) {
        $name = strtolower(pathinfo(basename($filepath), PATHINFO_FILENAME));
        if (!isset($tables[$name])) {
            $tables[$name] = get_cached_table($name);
            if ($VERBOSE) {
                debug_print("Loaded table '{$name}' from top-level: {$filepath}");
            }
        }
    }
    return $tables;
}

// --- Parse a single .tab file into a table ---
function parse_tab_file($filename) {
    if (!file_exists($filename)) {
        debug_print("[Error: File '{$filename}' does not exist]");
        return array();
    }
    
    $table = array();
    $lines = @file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        debug_print("[Error: Could not read file '{$filename}']");
        return array();
    }
    
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
        // Handle inline named block call with parentheses.
        if (preg_match('/^([A-Za-z0-9_\-]+)\(\)$/', $line, $matches)) {
            $name_candidate = strtolower($matches[1]);
            if (isset($named_rules[$name_candidate])) {
                debug_print(str_repeat("  ", $depth) . "→ Resolving named block: " . $line);
                $result = $named_rules[$name_candidate]['resolve']();
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
                    $result = $named_rules[$lower_line]['resolve']();
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
                    $result = $named_rules[$match_lower]['resolve']();
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
    
    // Get dice notation from named rules
    $diceNotation = getDiceNotation($name, $named_rules);
    
    if ($diceNotation !== null) {
        $result = roll_dice($diceNotation);
        debug_print("[Good entry for ROLL {$diceNotation}]");
    } else {
        debug_print("[Bad entry for ROLL {$name}]");
        $result = roll_dice(DEFAULT_DICE);
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
            resolve_table($part_normalized, $tables, $named_rules, $depth + 1);
        }
        return;
    }
    process_and_resolve_text($entry, $tables, $named_rules, $depth, $name, null);
}

/**
 * Generate HTML table output from monster data in CSV file
 * @param string $output The main output text containing monster names
 * @return string HTML table containing monster stats or empty string if no matches
 */
function generate_monster_stats_table($output) {
    $tableOutput = "";
    if (preg_match_all('/\d+\s+([A-Za-z ]+?)(?=[^A-Za-z ]|$)/', $output, $matches)) {
        $names = array_map('trim', $matches[1]);
        $names = array_filter($names, function($n) { return $n !== ""; });
        
        $csvFile = "skaven_bestiary.csv";
        $monsterResults = get_cached_monster_records($csvFile, $names);
        
        if (!empty($monsterResults)) {
            $tableOutput .= "##CSV_MARKER##";
            $tableOutput .= "<table border='1' cellspacing='0' cellpadding='4' style='max-width:500px; margin:0 auto;'>";
            $tableOutput .= "<tr>
                <th>Monster</th>
                <th>WS</th>
                <th>BS</th>
                <th>S</th>
                <th>T</th>
                <th>Sp</th>
                <th>Br</th>
                <th>Int</th>
                <th>W</th>
                <th>DD</th>
                <th>PV</th>
                <th>Equipment</th>
            </tr>";
            foreach ($monsterResults as $name => $rows) {
                foreach ($rows as $row) {
                    $tableOutput .= "<tr>";
                    foreach ($row as $field) {
                        $tableOutput .= "<td>" . htmlspecialchars($field) . "</td>";
                    }
                    $tableOutput .= "</tr>";
                }
            }
            $tableOutput .= "</table>";
        }
    }
    return $tableOutput;
}

// --- Parse a named block into a closure ---
function parse_named_block($lines) {
    $name = strtolower(trim($lines[0]));
    $stack = array();
    $current = array();
    $parsed_tables = array();  // Each element: [dice_notation, table]
    $dice_notation = null;
    
    // First pass: find dice notation in the second line
    if (count($lines) > 1) {
        $second_line = trim($lines[1]);
        if (strpos($second_line, "2D12") !== false) {
            $dice_notation = "2D12";
        } elseif (strpos($second_line, "1D12") !== false) {
            $dice_notation = "1D12";
        } elseif (strpos($second_line, "1D6") !== false) {
            $dice_notation = "1D6";
        }
        if ($dice_notation) {
            debug_print("Found dice notation '{$dice_notation}' in second line for block '{$name}'");
        }
    }
    
    // Second pass: parse the tables
    for ($i = 1; $i < count($lines); $i++) {
        $line = $lines[$i];
        if (strpos(trim($line), "(") === 0) {
            $stack[] = $current;
            $current = array();
        } elseif (strpos(trim($line), ")") === 0) {
            if (!empty($current)) {
                $parsed = parse_inline_table($current);
                $current = count($stack) > 0 ? array_pop($stack) : array();
                $parsed_tables[] = array($dice_notation, $parsed);
            }
        } else {
            $current[] = $line;
        }
    }
    
    // The closure that, when called, resolves this block.
    $resolve_nested = function() use ($parsed_tables, $name, $dice_notation) {
        if (empty($parsed_tables)) {
            return "";
        }
        list($notation, $outer) = $parsed_tables[0];
        $roll_notation = ($name == "spell" && $notation === null) ? "2D12" : ($notation !== null ? $notation : DEFAULT_DICE);
        debug_print("Using dice notation '{$roll_notation}' for block '{$name}'");
        
        // Check for composite entries (using '&')
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
        if ($name == "hidden-treasure") {
            return "[Hidden-Treasure]\n" . $final_output . "\n[/Hidden-Treasure]";
        }
        return $final_output;
    };
    
    return array($name, $resolve_nested, $dice_notation);
}

/**
 * Sanitize and validate input parameters
 * @return array Sanitized parameters
 */
function get_sanitized_params() {
    $params = array();
    foreach ($_GET as $key => $value) {
        $params[$key] = is_string($value) ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : '';
    }
    return $params;
}

/**
 * Validate and sanitize subdirectory path
 * @param string|null $subdir Raw subdirectory path
 * @return string|null Sanitized subdirectory path or null if invalid
 */
function validate_subdir($subdir) {
    if (!$subdir) {
        return null;
    }
    
    $sanitized = preg_replace('/[^a-zA-Z0-9\/\-_]/', '', $subdir);
    if (!is_dir($sanitized)) {
        throw new Exception("Invalid subdirectory '{$subdir}'");
    }
    return $sanitized;
}

/**
 * Load and initialize tables and named rules
 * @param string|null $subdir Subdirectory path
 * @return array Tuple of [tables, named_rules]
 */
function load_tables_and_rules($subdir) {
    $tables = load_tables($subdir);
    $named_rules = array();
    $raw_blocks = get_cached_named_blocks($subdir);
    
    foreach ($raw_blocks as $name => $block_lines) {
        list($key, $resolve_fn, $dice_notation) = parse_named_block($block_lines);
        $named_rules[$key] = array(
            'resolve' => $resolve_fn,
            'dice_notation' => $dice_notation
        );
    }
    
    return array($tables, $named_rules);
}

/**
 * Process a single user input table
 * @param string $user_input User input table name
 * @param array $tables Available tables
 * @param array $named_rules Available named rules
 * @param bool $verbose Verbosity flag
 */
function process_user_table($user_input, $tables, $named_rules, $verbose) {
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
    if ($verbose) {
        echo "<br>--- Resolving table '{$user_input}' ---<br>";
    }
    
    resolve_table($table_name, $tables, $named_rules);
    
    if ($verbose) {
        echo "<br>" . str_repeat("=", 50) . "<br>";
    }
}

/**
 * Process all user input tables
 * @param string $tables_param Comma-separated list of table names
 * @param array $tables Available tables
 * @param array $named_rules Available named rules
 * @param bool $verbose Verbosity flag
 */
function process_user_tables($tables_param, $tables, $named_rules, $verbose) {
    $user_tables = array_map('trim', explode(",", $tables_param));
    foreach ($user_tables as $user_input) {
        process_user_table($user_input, $tables, $named_rules, $verbose);
    }
}

function main() {
    global $VERBOSE;
    
    try {
        // Get and validate parameters
        $params = get_sanitized_params();
        
        // Set verbosity
        if (isset($params['verbose']) && $params['verbose'] === "1") {
            $VERBOSE = true;
        }
        
        // Validate required parameters
        if (!isset($params['tables']) || empty(trim($params['tables']))) {
            echo "Usage: index2.php?tables=TableName1,TableName2[,...]&verbose=1&subdir=your_subdir (optional)";
            return;
        }
        
        // Validate subdirectory
        $subdir = validate_subdir(isset($params['subdir']) ? $params['subdir'] : null);
        
        // Start output buffering
        ob_start();
        
        // Load tables and rules
        list($tables, $named_rules) = load_tables_and_rules($subdir);
        
        // Process user tables
        process_user_tables($params['tables'], $tables, $named_rules, $VERBOSE);
        
        // Generate and output results
        $output = ob_get_clean();
        echo $output;
        echo generate_monster_stats_table($output);
        
    } catch (Exception $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo "[Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "]";
    }
}

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    main();
}
?>