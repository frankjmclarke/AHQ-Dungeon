<?php

namespace App\Classes;

require_once __DIR__ . '/../interfaces/NamedBlockManager.php';
require_once __DIR__ . '/../interfaces/DiceRoller.php';

use App\Interfaces\NamedBlockManager;
use App\Interfaces\DiceRoller;
use App\Utils\OutputUtils;

/**
 * Implementation of named block management functionality
 */
class NamedBlockManagerImpl implements NamedBlockManager {
    private string $parent_dir;
    private DiceRoller $dice_roller;
    private bool $verbose;
    private array $resolvedStack;
    private const MAX_DEPTH = 50;
    
    public function __construct(DiceRoller $dice_roller, bool $verbose = false) {
        $this->parent_dir = dirname(__DIR__);
        $this->dice_roller = $dice_roller;
        $this->verbose = $verbose;
        $this->resolvedStack = array();
    }
    
    /**
     * Extract named blocks from files in the parent directory and optionally a subdirectory
     * @param string|null $subdir Optional subdirectory to load blocks from
     * @return array Array of block names to their contents
     */
    public function extractNamedBlocks($subdir = null) {
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
            if ($this->verbose) {
                $outputUtils = new OutputUtils();
                $outputUtils->debugPrint("Processing named blocks from file: {$filepath}");
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
    
    /**
     * Parse a named block from lines
     * @param array $lines Lines to parse
     * @return array Array containing name and function
     */
    public function parseNamedBlock(array $lines): array {
        $name = strtolower(trim($lines[0]));
        $dice_notation = TableResolverImpl::getDiceNotation($name);
        $stack = array();
        $current = array();
        $parsed_tables = array();  // Each element: [dice_notation, table]
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
                $parsed = $this->parseInlineTable($current);
                $current = count($stack) > 0 ? array_pop($stack) : array();
                $parsed_tables[] = array($dice_notation, $parsed);
                $dice_notation = null;
            } else {
                $current[] = $line;
            }
            if ($i == 1) {
                // Check for each possible dice notation in the first line.
                if (strpos($line, "2D12") !== false) {
                    $dice_notation = "2D12";
                    $outputUtils = new OutputUtils();
                    $outputUtils->debugPrint("YYYYYYY Using dice notation '{$dice_notation}' for block '{$name}'");
                } elseif (strpos($line, "1D12") !== false) {
                    $dice_notation = "1D12";
                    $outputUtils = new OutputUtils();
                    $outputUtils->debugPrint("YYYYYYY Using dice notation '{$dice_notation}' for block '{$name}'");
                } elseif (strpos($line, "1D6") !== false) {
                    $dice_notation = "1D6";
                    $outputUtils = new OutputUtils();
                    $outputUtils->debugPrint("YYYYYYY Using dice notation '{$dice_notation}' for block '{$name}'");
                }
            }
        }
        
        // The closure that, when called, resolves this block
        $that = $this;  // Capture $this for use in closure
        $dice_roller = $this->dice_roller;  // Capture dice_roller
        $resolve_nested = function() use ($parsed_tables, $name, $that, $dice_roller) {
            if (empty($parsed_tables)) {
                return "";
            }
            list($notation, $outer) = $parsed_tables[0];

            $dice_notation = $dice_notation !== null ? $dice_notation : DEFAULT_DICE;

            $outputUtils = new OutputUtils();
            $outputUtils->debugPrint("Using dice notation '{$dice_notation}' for block '{$name}'");
            $has_composite = false;
            foreach ($outer as $entry) {
                if (strpos($entry[1], "&") !== false) {
                    $has_composite = true;//containing "&", if a part starts with "(" === nested table
                    break;
                }
            }
            $attempts = 0;
            $entry_val = null;
            while ($attempts < 10) {
                $result = $dice_roller->roll($dice_notation);
                $roll = $result['total'];
                $rolls = $result['rolls'];
                $entry_val = null;
                foreach ($outer as $tuple) {
                    if ($tuple[0] == $roll) {
                        $entry_val = $tuple[1];
                        break;
                    }
                }
                $outputUtils->debugPrint("  → [Nested roll in {$name}]: Rolled {$roll} (rolls: " . implode(",", $rolls) . ") resulting in: {$entry_val}");
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
                    //nested table
                    if (strpos($part, "(") === 0 && count($parsed_tables) > 1) {
                        list($notation2, $subtable) = $parsed_tables[1];
                        $roll_notation2 = $notation2 !== null ? $notation2 : DEFAULT_DICE;
                        $result2 = $dice_roller->roll($roll_notation2);
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

    private function parseInlineTable(array $lines): array {
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

    /**
     * Extract blocks from content
     * @param string $content Content to extract blocks from
     * @return array Array of blocks
     */
    private function extractBlocks(string $content): array {
        $blocks = array();
        $lines = explode("\n", $content);
        $current_block = null;
        $current_lines = array();
        
        foreach ($lines as $line) {
            if (preg_match('/^@(\w+)/', $line, $matches)) {
                if ($current_block !== null) {
                    $blocks[$current_block] = $current_lines;
                    $current_lines = array();
                }
                $current_block = $matches[1];
            } elseif ($current_block !== null) {
                $current_lines[] = $line;
            }
        }
        
        if ($current_block !== null) {
            $blocks[$current_block] = $current_lines;
        }
        
        return $blocks;
    }

    /**
     * Parse a table line
     * @param string $line Line containing table data
     * @return array Parsed table data
     */
    private function parseTable(string $line): array {
        $parts = explode("|", $line);
        $notation = trim($parts[0]);
        $entries = array();
        
        foreach (array_slice($parts, 1) as $entry) {
            $entry = trim($entry);
            if (empty($entry)) continue;
            
            if (preg_match('/^(\d+)\s*-\s*(\d+)\s*:\s*(.+)$/', $entry, $matches)) {
                $start = intval($matches[1]);
                $end = intval($matches[2]);
                $text = trim($matches[3]);
                
                for ($i = $start; $i <= $end; $i++) {
                    $entries[] = array($i, $text);
                }
            } elseif (preg_match('/^(\d+)\s*:\s*(.+)$/', $entry, $matches)) {
                $entries[] = array(intval($matches[1]), trim($matches[2]));
            }
        }
        
        return array($notation, $entries);
    }

    public function setDiceNotation(string $tableName, string $notation): void {
        TableResolverImpl::setDiceNotation($tableName, $notation);
    }
} 