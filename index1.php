<?php

const MAX_DEPTH = 50;
const DEFAULT_DICE = "1D12";
define("VERBOSE", false);

function roll_dice($notation) {
    if (preg_match('/(\d+)[dD](\d+)/', $notation, $matches)) {
        $num = (int)$matches[1];
        $sides = (int)$matches[2];
        $rolls = [];
        $total = 0;
        for ($i = 0; $i < $num; $i++) {
            $r = rand(1, $sides);
            $rolls[] = $r;
            $total += $r;
        }
        return [$total, $rolls, $notation];
    }
    $r = rand(1, 12);
    return [$r, [$r], "1D12"];
}

function parse_inline_table($lines) {
    $table = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/(\d+)(?:-(\d+))?\s+(.+)/', $line, $m)) {
            $start = (int)$m[1];
            $end = isset($m[2]) ? (int)$m[2] : $start;
            $content = trim($m[3]);
            for ($i = $start; $i <= $end; $i++) {
                $table[] = [$i, $content, "$start-$end"];
            }
        }
    }
    return $table;
}

function parse_tab_file_with_dice($filename) {
    $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_filter($lines, fn($l) => !preg_match('/^\s*#/', $l));

    $dice = DEFAULT_DICE;
    foreach ($lines as $line) {
        if (strpos($line, "1D12") !== false) {
            $dice = "1D12";
            break;
        } elseif (strpos($line, "2D12") !== false) {
            $dice = "2D12";
            break;
        } elseif (strpos($line, "1D6") !== false) {
            $dice = "1D6";
            break;
        }
    }

    $table = parse_inline_table($lines);
    return [$table, $dice];
}

function load_tables() {
    $tables = [];
    $dice_map = [];
    foreach (glob("*.tab") as $file) {
        $name = strtolower(pathinfo($file, PATHINFO_FILENAME));
        [$table, $dice] = parse_tab_file_with_dice($file);
        $tables[$name] = $table;
        $dice_map[$name] = $dice;
    }
    return [$tables, $dice_map];
}

function resolve_table($name, &$tables, &$named_rules, &$dice_map, $depth = 0) {
    $name = strtolower($name);
    if (!isset($tables[$name])) return;

    $dice = isset($dice_map[$name]) ? $dice_map[$name] : DEFAULT_DICE;
    [$roll, $rolls, $notation] = roll_dice($dice);
    $entry = null;
    $matched_range = null;
    foreach ($tables[$name] as [$r, $c, $range]) {
        if ($r === $roll) {
            $entry = $c;
            $matched_range = $range;
            break;
        }
    }
    echo "<pre>[Rolled: $roll using $notation] [Range matched: $matched_range] [Entry: $entry]</pre>\n";
    if ($entry !== null) {
        process_and_resolve_text($entry, $tables, $named_rules, $depth, $name);
    }
}

function process_and_resolve_text($text, &$tables, &$named_rules, $depth = 0, $parent_table = null, $current_named = null) {
    if ($depth > MAX_DEPTH) return;
    $line = trim($text);
    if (preg_match('/([A-Za-z0-9_\-]+)\(\)/', $line, $m)) {
        $name = strtolower($m[1]);
        if (isset($named_rules[$name])) {
            $result = $named_rules[$name]();
            process_and_resolve_text($result, $tables, $named_rules, $depth + 1, $parent_table, $name);
            return;
        }
    }
    echo "$line\n";
}

function extract_named_blocks() {
    $blocks = [];
    foreach (glob("*.tab") + glob("*.txt") as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_filter($lines, fn($l) => !preg_match('/^\s*#/', $l));
        $i = 0;
        while ($i < count($lines)) {
            $line = $lines[$i];
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_\-]*$/', $line)) {
                $name = strtolower(trim($line));
                $i++;
                if ($i < count($lines) && str_starts_with(trim($lines[$i]), '(')) {
                    $depth = 1;
                    $block_lines = [$line];
                    while ($i < count($lines)) {
                        $line = $lines[$i];
                        $block_lines[] = $line;
                        $depth += substr_count($line, '(');
                        $depth -= substr_count($line, ')');
                        $i++;
                        if ($depth <= 0) break;
                    }
                    $blocks[$name] = $block_lines;
                }
            } else {
                $i++;
            }
        }
    }
    return $blocks;
}

function parse_named_block($lines) {
    $name = strtolower(trim($lines[0]));
    $inside = array_slice($lines, 1);
    [$parsed_table, $dice_notation] = parse_inline_table(array_slice($inside, 1, -1));

    return [$name, function() use ($parsed_table, $dice_notation, $name) {
        [$roll, $rolls, $notation_used] = roll_dice($dice_notation);
        $entry = null;
        $matched_range = null;
        foreach ($parsed_table as [$r, $e, $range]) {
            if ($r === $roll) {
                $entry = $e;
                $matched_range = $range;
                break;
            }
        }
        echo "<pre>[Rolled: $roll using $notation_used] [Range matched: $matched_range] [Entry: $entry]</pre>\n";
        return $entry ?? "";
    }];
}

if (php_sapi_name() !== 'cli') {
    $params = isset($_GET['table']) ? explode(',', $_GET['table']) : [];
    if (isset($_GET['verbose'])) {
        define("VERBOSE", true);
    }

    if (empty($params)) {
        echo "Usage: ?table=room[,another]&verbose=1<br>";
        exit;
    }

    [$tables, $dice_map] = load_tables();
    $named_rules = [];
    $raw_blocks = extract_named_blocks();
    foreach ($raw_blocks as $name => $lines) {
        [$key, $fn] = parse_named_block($lines);
        $named_rules[$key] = $fn;
    }

    ob_start();
    foreach ($params as $user_input) {
        $normalized = strtolower(str_replace(["-", "_"], "", $user_input));
        $candidates = array_filter(array_keys($tables), fn($k) => str_replace(["-", "_"], "", $k) === $normalized);
        if (empty($candidates)) {
            echo "[Table '$user_input' not found. Available: " . implode(", ", array_keys($tables)) . "]<br>";
        } else {
            $table_name = array_values($candidates)[0];
            resolve_table($table_name, $tables, $named_rules, $dice_map);
        }
    }
    $output = ob_get_clean();
    if (empty(trim($output))) {
        echo "[No output generated. Check your table and data format]<br>";
    } else {
        echo nl2br($output);
    }
}