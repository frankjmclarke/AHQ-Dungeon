<?php
/**
 * Named block handling functionality
 */
class NamedBlock {
    private $config;
    private $table;
    
    public function __construct($config, $table) {
        $this->config = $config;
        $this->table = $table;
    }
    
    /**
     * Extract named blocks from .tab or .txt files
     * @param string|null $subdir Subdirectory path
     * @return array Named blocks data
     * @throws RuntimeException if file cannot be read
     */
    public function extractNamedBlocks($subdir = null) {
        $blocks = array();
        
        // Get files from top-level and optionally a subdirectory
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
            if ($this->config->isVerbose()) {
                debug_print("Processing named blocks from file: {$filepath}");
            }
            $lines_raw = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines_raw === false) {
                throw new RuntimeException("Could not read file '{$filepath}'");
            }
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
    
    /**
     * Get cached named blocks or load them if not in cache
     * @param string|null $subdir Subdirectory path
     * @return array Named blocks data
     */
    public function getCachedNamedBlocks($subdir = null) {
        $cache_key = $subdir ?: 'root';
        
        $cached_data = $this->config->getCachedData('named_blocks', $cache_key, CACHE_TTL);
        if ($cached_data !== null) {
            return $cached_data;
        }
        
        $blocks = $this->extractNamedBlocks($subdir);
        $this->config->setCache('named_blocks', $cache_key, $blocks, CACHE_TTL);
        
        return $blocks;
    }
    
    /**
     * Parse a named block into a closure
     * @param array $lines Block lines to parse
     * @return array Tuple of [name, resolve function, dice notation]
     */
    public function parseNamedBlock($lines) {
        $name = strtolower(trim($lines[0]));
        $stack = array();
        $current = array();
        $parsed_tables = array();  // Each element: [dice_notation, table]
        $dice_notation = "1D12";  // Default to 1D12 if not specified
        
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
                    $parsed = $this->table->parseInlineTable($current);
                    $current = count($stack) > 0 ? array_pop($stack) : array();
                    $parsed_tables[] = array($dice_notation, $parsed);
                }
            } else {
                $current[] = $line;
            }
        }
        
        // The closure that, when called, resolves this block
        $resolve_nested = function() use ($parsed_tables, $name, $dice_notation) {
            if (empty($parsed_tables)) {
                return "";
            }
            list($notation, $outer) = $parsed_tables[0];
            $roll_notation = ($name == "spell" && $notation === null) ? "2D12" : ($notation !== null ? $notation : $dice_notation);
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
                $result = Dice::roll($roll_notation);
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
                        $roll_notation2 = $notation2 !== null ? $notation2 : $dice_notation;
                        $result2 = Dice::roll($roll_notation2);
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
} 