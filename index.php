<?php
define('MAX_DEPTH', 50);
define('DEFAULT_DICE', '1D12');
$VERBOSE = isset($_GET['verbose']);
$resolved_stack = [];

function html($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function debug_print($msg) {
    global $VERBOSE;
    if ($VERBOSE) echo "<pre style='color:gray'>" . html($msg) . "</pre>";
}

function final_print($indent, $msg) {
    echo "<pre>" . html($msg) . "</pre>";
}

function roll_dice($notation) {
    if (preg_match('/(\d+)[dD](\d+)/', $notation, $matches)) {
        $num = (int)$matches[1];
        $sides = (int)$matches[2];
        $total = 0;
        $rolls = [];
        for ($i = 0; $i < $num; $i++) {
            $r = rand(1, $sides);
            $total += $r;
            $rolls[] = $r;
        }
        return [$total, $rolls];
    } else {
        $r = rand(1, 12);
        return [$r, [$r]];
    }
}

function parse_inline_table($lines) {
    $table = [];
    foreach ($lines as $line) {
        if (preg_match('/(\d+)(?:-(\d+))?\s+(.+)/', trim($line), $match)) {
            $start = (int)$match[1];
            $end = isset($match[2]) ? (int)$match[2] : $start;
            $content = trim($match[3]);
            for ($i = $start; $i <= $end; $i++) {
                $table[] = [$i, $content];
            }
        }
    }
    return $table;
}

function extract_named_blocks() {
    $blocks = [];
    foreach (array_merge(glob("*.tab"), glob("*.txt")) as $file) {
        $lines = array_values(array_filter(array_map('rtrim', file($file)), fn($l) => trim($l) && !str_starts_with(trim($l), '#')));
        for ($i = 0; $i < count($lines);) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_\-]*$/', $lines[$i])) {
                $name = strtolower(trim($lines[$i]));
                $i++;
                if ($i < count($lines) && str_starts_with(trim($lines[$i]), '(')) {
                    $depth = 1;
                    $block = [$lines[$i - 1], $lines[$i]];
                    $i++;
                    while ($i < count($lines) && $depth > 0) {
                        $line = $lines[$i];
                        $depth += substr_count($line, '(') - substr_count($line, ')');
                        $block[] = $line;
                        $i++;
                    }
                    $blocks[$name] = $block;
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
    $stack = [];
    $current = [];
    $parsed_tables = [];
    $dice_notation = null;

    foreach (array_slice($lines, 1) as $line) {
        $trim = trim($line);
        if (str_starts_with($trim, '(')) {
            $stack[] = $current;
            $current = [];
        } elseif (str_starts_with($trim, ')')) {
            if ($current) {
                if (preg_match('/^(\d+[dD]\d+)\s+/', trim($current[0]), $match)) {
                    $dice_notation = $match[1];
                    $current[0] = preg_replace('/^\d+[dD]\d+\s+/', '', $current[0]);
                }
            }
            $parsed = parse_inline_table($current);
            $current = array_pop($stack);
            $parsed_tables[] = [$dice_notation, $parsed];
            $dice_notation = null;
        } else {
            $current[] = $line;
        }
    }

    $resolver = function () use ($name, $parsed_tables) {
        if (!$parsed_tables) return '';
        [$notation, $outer] = $parsed_tables[0];
        $roll_notation = $notation ?: ($name === 'spell' ? '2D12' : DEFAULT_DICE);

        $has_composite = array_reduce($outer, fn($carry, $e) => $carry || str_contains($e[1], '&'), false);
        $attempts = 0;
        $entry = null;

        while ($attempts < 10) {
            [$roll, $rolls] = roll_dice($roll_notation);
            foreach ($outer as [$r, $content]) {
                if ($r === $roll) {
                    $entry = $content;
                    break;
                }
            }
            if ($entry && $has_composite && strtolower(trim($entry)) === $name) {
                $attempts++;
                continue;
            }
            break;
        }

        if ($entry && str_contains($entry, '&')) {
            $parts = array_map('trim', explode('&', $entry));
            $output = [];
            foreach ($parts as $part) {
                if (strtolower($part) === $name) continue;
                if (str_starts_with($part, '(') && isset($parsed_tables[1])) {
                    [$notation2, $subtable] = $parsed_tables[1];
                    $roll2 = $notation2 ?: DEFAULT_DICE;
                    [$subroll, $sub_rolls] = roll_dice($roll2);
                    foreach ($subtable as [$r, $c]) {
                        if ($r === $subroll) {
                            $output[] = $c;
                            break;
                        }
                    }
                } else {
                    $output[] = $part;
                }
            }
            return implode("\n", $output);
        }

        return $entry ?? '';
    };

    return [$name, $resolver];
}

function parse_tab_file($filename) {
    $table = [];
    foreach (file($filename) as $line) {
        $line = trim($line);
        if (!$line || str_starts_with($line, '#')) continue;
        if (preg_match('/(\d+)(?:-(\d+))?\s+(.+)/', $line, $match)) {
            $start = (int)$match[1];
            $end = isset($match[2]) ? (int)$match[2] : $start;
            $content = trim($match[3]);
            for ($i = $start; $i <= $end; $i++) {
                $table[] = [$i, $content];
            }
        }
    }
    return $table;
}

function load_tables() {
    $tables = [];
    foreach (glob("*.tab") as $file) {
        $name = strtolower(pathinfo($file, PATHINFO_FILENAME));
        $tables[$name] = parse_tab_file($file);
    }
    return $tables;
}

function process_and_resolve_text($text, $tables, $named_rules, $depth, $parent_table = null, $current_named = null) {
    global $resolved_stack;
    $indent = str_repeat("  ", $depth);
    if ($depth > MAX_DEPTH) return;

    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if (preg_match('/^([A-Za-z0-9_\-]+)\(\)$/', $line, $m)) {
            $name = strtolower($m[1]);
            if (isset($named_rules[$name])) {
                $result = $named_rules[$name]();
                process_and_resolve_text($result, $tables, $named_rules, $depth + 1, $parent_table, $name);
                continue;
            }
        }

        if (isset($named_rules[strtolower($line)])) {
            $lname = strtolower($line);
            if ($current_named === $lname) {
                final_print($indent, $line);
            } elseif (!in_array($lname, $resolved_stack)) {
                $resolved_stack[] = $lname;
                $result = $named_rules[$lname]();
                process_and_resolve_text($result, $tables, $named_rules, $depth + 1, $parent_table, $lname);
                array_pop($resolved_stack);
            }
            continue;
        }

        if (str_starts_with($line, '"') && str_ends_with($line, '"')) {
            final_print($indent, substr($line, 1, -1));
        } else {
            final_print($indent, $line);
        }

        preg_match_all('/([A-Za-z0-9_\-]+)\(\)/', $line, $matches);
        foreach ($matches[1] as $match) {
            $match = strtolower($match);
            if ($current_named === $match || in_array($match, $resolved_stack)) continue;
            $resolved_stack[] = $match;
            if (isset($named_rules[$match])) {
                $result = $named_rules[$match]();
                process_and_resolve_text($result, $tables, $named_rules, $depth + 1, $parent_table, $match);
            } elseif (isset($tables[$match])) {
                resolve_table($match, $tables, $named_rules, $depth + 1);
            }
            array_pop($resolved_stack);
        }
    }
}

function resolve_table($name, $tables, $named_rules = [], $depth = 0) {
    $indent = str_repeat("  ", $depth);
    $name = strtolower($name);
    if (!isset($tables[$name])) return;
    [$roll, $rolls] = roll_dice(DEFAULT_DICE);
    foreach ($tables[$name] as [$r, $content]) {
        if ($r === $roll) {
            if (str_starts_with($content, '"') && str_ends_with($content, '"')) {
                final_print($indent, substr($content, 1, -1));
            } elseif (str_starts_with($content, '[[') && str_ends_with($content, ']]')) {
                foreach (explode("&", substr($content, 2, -2)) as $part) {
                    resolve_table(strtolower(trim(str_replace("()", "", $part))), $tables, $named_rules, $depth + 1);
                }
            } else {
                process_and_resolve_text($content, $tables, $named_rules, $depth, $name);
            }
            return;
        }
    }
}

function run_web($startTable) {
    $tables = load_tables();
    $named_rules = [];
    foreach (extract_named_blocks() as $name => $lines) {
        [$key, $fn] = parse_named_block($lines);
        $named_rules[$key] = $fn;
    }

    $normalized = strtolower(str_replace(['-', '_'], '', $startTable));
    $candidates = array_filter(array_keys($tables), fn($k) => str_replace(['-', '_'], '', $k) === $normalized);

    if (!$candidates) {
        echo "<p style='color:red'>Table '$startTable' not found.</p>";
        return;
    }

    $table_name = array_values($candidates)[0];
    resolve_table($table_name, $tables, $named_rules);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Recursive Table Roller</title>
</head>
<body>
    <h2>Recursive Table Resolver</h2>
    <form method="get">
        <label>Choose a table:</label>
        <select name="table">
            <?php foreach (glob("*.tab") as $f): $name = basename($f, '.tab'); ?>
                <option value="<?= html($name) ?>" <?= (isset($_GET['table']) && $_GET['table'] === $name) ? 'selected' : '' ?>>
                    <?= html($name) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label><input type="checkbox" name="verbose" <?= $VERBOSE ? 'checked' : '' ?>> Verbose</label>
        <button type="submit">Roll!</button>
    </form>
    <hr>
    <?php
        if (isset($_GET['table'])) {
            run_web($_GET['table']);
        }
    ?>
</body>
</html>
