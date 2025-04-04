<?php

namespace App\Classes;

use App\Interfaces\TextProcessor;

const DEFAULT_DICE = "1D12";

function roll_dice($notation) {
    $matches = array();
    if (!preg_match('/^(\d+)[dD](\d+)$/', $notation, $matches)) {
        // Default to 1D12 if notation is invalid
        return array('total' => rand(1, 12), 'rolls' => array(rand(1, 12)));
    }
    $num_dice = intval($matches[1]);
    $sides = intval($matches[2]);
    $rolls = array();
    $total = 0;
    for ($i = 0; $i < $num_dice; $i++) {
        $roll = rand(1, $sides);
        $rolls[] = $roll;
        $total += $roll;
    }
    return array('total' => $total, 'rolls' => $rolls);
}

function debug_print($msg) {
    echo $msg . "<br>";
}

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

class TextProcessorImpl implements TextProcessor {
    private bool $verbose;
    private array $resolvedStack;
    private const MAX_DEPTH = 50;

    public function __construct(bool $verbose = false) {
        $this->verbose = $verbose;
        $this->resolvedStack = [];
    }

    public function processAndResolveText(
        string $text,
        array $tables,
        array $namedRules,
        int $depth = 0,
        ?string $parentTable = null,
        ?string $currentNamed = null
    ): void {
        if ($depth > self::MAX_DEPTH) {
            $outputUtils = new \App\Utils\OutputUtils();
            $outputUtils->debugPrint(str_repeat("  ", $depth) . "[Maximum recursion depth reached]");
            return;
        }

        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^([A-Za-z0-9_\-]+)\(\)$/', $line, $matches)) {
                $nameCandidate = strtolower($matches[1]);
                if (isset($namedRules[$nameCandidate])) {
                    $outputUtils = new \App\Utils\OutputUtils();
                    $outputUtils->debugPrint(str_repeat("  ", $depth) . "→ Resolving named block: " . $line);
                    $result = $namedRules[$nameCandidate]();
                    $this->processAndResolveText($result, $tables, $namedRules, $depth + 1, $parentTable, $nameCandidate);
                    continue;
                }
            }

            $lowerLine = strtolower($line);
            if (isset($namedRules[$lowerLine])) {
                if ($currentNamed !== null && $lowerLine === $currentNamed) {
                    $outputUtils = new \App\Utils\OutputUtils();
                    $outputUtils->finalPrint(str_repeat("YY  ", $depth), $line);
                } else {
                    if (in_array($lowerLine, $this->resolvedStack)) {
                        $outputUtils = new \App\Utils\OutputUtils();
                        $outputUtils->debugPrint(str_repeat("  ", $depth) . "→ [Cycle detected: " . $line . "]");
                    } else {
                        $this->resolvedStack[] = $lowerLine;
                        $outputUtils = new \App\Utils\OutputUtils();
                        $outputUtils->debugPrint(str_repeat("  ", $depth) . "→ Resolving named block: " . $line);
                        $result = $namedRules[$lowerLine]();
                        $this->processAndResolveText($result, $tables, $namedRules, $depth + 1, $parentTable, $lowerLine);
                        array_pop($this->resolvedStack);
                    }
                }
                continue;
            }

            if (substr($line, 0, 1) === '"' && substr($line, -1) === '"') {
                $outputUtils = new \App\Utils\OutputUtils();
                $outputUtils->finalPrint(str_repeat("XX  ", $depth), substr($line, 1, -1));
            } else {
                $outputUtils = new \App\Utils\OutputUtils();
                $outputUtils->finalPrint(str_repeat("ZZ  ", $depth), $line);
            }

            if (preg_match_all('/([A-Za-z0-9_\-]+)\(\)/', $line, $allMatches)) {
                foreach ($allMatches[1] as $match) {
                    $matchLower = strtolower($match);
                    if ($currentNamed !== null && $matchLower === $currentNamed) {
                        continue;
                    }
                    if (in_array($matchLower, $this->resolvedStack)) {
                        continue;
                    }
                    $this->resolvedStack[] = $matchLower;
                    if (isset($namedRules[$matchLower])) {
                        $result = $namedRules[$matchLower]();
                        $this->processAndResolveText($result, $tables, $namedRules, $depth + 1, $parentTable, $matchLower);
                    }
                    array_pop($this->resolvedStack);
                }
            }
        }
    }

    public function extractNamedBlocks(?string $subdir = null): array {
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
            $outputUtils = new \App\Utils\OutputUtils();
            $outputUtils->debugPrint("Processing named blocks from file: {$filepath}");
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
} 