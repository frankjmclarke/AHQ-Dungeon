<?php
define('MAX_DEPTH', 50);
define('DEFAULT_DICE', '1D12');
$VERBOSE = true;
$resolved_stack = [];
$context_stack = [];

$directories = array_filter(glob('*'), 'is_dir');

function normalize_name($name) {
    return strtolower(str_replace(['-', '_'], '', $name));
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
                $name = normalize_name(trim($lines[$i]));
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
    $name = normalize_name(trim($lines[0]));
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
        $roll_notation = $notation ?: DEFAULT_DICE;
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
            if ($entry && $has_composite && normalize_name($entry) === $name) {
                $attempts++;
                continue;
            }
            break;
        }
        if ($entry && str_contains($entry, '&')) {
            $parts = array_map('trim', explode('&', $entry));
            $output = [];
            foreach ($parts as $part) {
                if (normalize_name($part) === $name) continue;
                $output[] = $part;
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
    foreach (glob("*.tab") as $filepath) {
        $basename = pathinfo($filepath, PATHINFO_FILENAME);
        $normalized = normalize_name($basename);
        $tables[$normalized] = parse_tab_file($filepath);
    }
    return $tables;
}

function process_and_resolve_text($text, $tables, $named_rules, $depth, $parent_table = null, $current_named = null) {
    global $resolved_stack, $context_stack;
    $result = "";
    if ($depth > MAX_DEPTH) return $result;

    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        $lname = normalize_name($line);

        if (isset($named_rules[$lname])) {
            if ($current_named !== null && $lname === $current_named) {
                $result .= "$line\n";
            } elseif (!in_array($lname, $resolved_stack)) {
                $resolved_stack[] = $lname;
                $context_stack[] = $lname;

                $resolved = $named_rules[$lname]();
                $result .= process_and_resolve_text($resolved, $tables, $named_rules, $depth + 1, $parent_table, $lname);

                array_pop($context_stack);
                array_pop($resolved_stack);
            }
            continue;
        }

        $result .= "$line\n";

        preg_match_all('/([A-Za-z0-9_\-]+)\(\)/', $line, $matches);
        foreach ($matches[1] as $match) {
            $mname = normalize_name($match);
            if ($current_named === $mname || in_array($mname, $resolved_stack)) continue;
            $resolved_stack[] = $mname;
            $context_stack[] = $mname;
            if (isset($named_rules[$mname])) {
                $resolved = $named_rules[$mname]();
                $result .= process_and_resolve_text($resolved, $tables, $named_rules, $depth + 1, $parent_table, $mname);
            } elseif (isset($tables[$mname])) {
                $result .= resolve_table($mname, $tables, $named_rules, $depth + 1);
            }
            array_pop($context_stack);
            array_pop($resolved_stack);
        }
    }
    return $result;
}

function resolve_table($name, $tables, $named_rules = [], $depth = 0) {
    global $context_stack;

    $normalized = normalize_name($name);
    $context_stack[] = $normalized;

    if (!isset($tables[$normalized])) {
        echo "<pre style='color:red'>[Missing table: $name] — Called from: " . implode(" → ", $context_stack) . "</pre>\n";
        array_pop($context_stack);
        return "";
    }

    [$roll, $rolls] = roll_dice(DEFAULT_DICE);
    foreach ($tables[$normalized] as [$r, $content]) {
        if ($r === $roll) {
            $result = "";
            if (str_starts_with($content, '"') && str_ends_with($content, '"')) {
                $result = substr($content, 1, -1) . "\n";
            } elseif (str_starts_with($content, '[[') && str_ends_with($content, ']]')) {
                foreach (explode("&", substr($content, 2, -2)) as $part) {
                    $result .= resolve_table(trim($part), $tables, $named_rules, $depth + 1);
                }
            } else {
                $result = process_and_resolve_text($content, $tables, $named_rules, $depth, $name);
            }

            array_pop($context_stack);
            return $result;
        }
    }

    array_pop($context_stack);
    return "[No match for roll in $name]";
}

function handle_ajax() {
    if (!isset($_GET['tables'])) return;
    $input = explode(" ", $_GET['tables']);
    $tables = load_tables();
    $named_rules = [];
    foreach (extract_named_blocks() as $name => $lines) {
        [$key, $fn] = parse_named_block($lines);
        $named_rules[$key] = $fn;
    }
    $output = "";
    foreach ($input as $tbl) {
        $output .= resolve_table($tbl, $tables, $named_rules);
    }
    echo trim($output);
    exit;
}

if (isset($_GET['tables'])) handle_ajax();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Map Generator</title>
    <script>
        function runCommand(command) {
            fetch("?tables=" + encodeURIComponent(command))
                .then(res => res.text())
                .then(text => {
                    document.getElementById("output").value = text;
                });
        }
    </script>
</head>
<body>
    <h1>Map Generator</h1>
    <button onclick="runCommand('passagelength passageend passagefeature')">New Passage</button>
    <button onclick="runCommand('roomtype roomdoors')">Open Passage Door</button>
    <button onclick="runCommand('secretdoors')">Secret Doors</button>
    <button onclick="runCommand('roomorpassage')">Open Door in Room</button>
    <br><br>
    <textarea id="output" rows="20" cols="80"></textarea>
      <br>
      <select id="dropdown">
        <?php foreach ($directories as $dir): ?>
          <option value="<?= htmlspecialchars($dir) ?>"><?= htmlspecialchars($dir) ?></option>
        <?php endforeach; ?>
      </select>
</body>
</html>
