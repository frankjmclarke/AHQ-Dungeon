<?php
require_once 'Logger.php';

class FileParser {
    // Parses inline table entries from an array of lines
    // Each line can represent a range of values associated with a content string
    // Returns an array of entries for the table
    public static function parseInlineTable($lines) {
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

    // Parses a .tab file to extract table entries
    // Ignores empty lines and comments (lines starting with #)
    // Returns an array of entries for the table
    public static function parseTabFile($filename) {
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

    // Extracts named blocks from files in the top-level and optional subdirectory
    // Named blocks are identified by a name followed by a block of lines enclosed in parentheses
    // Returns an associative array of named blocks
    public static function extractNamedBlocks($subdir = null) {
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
        
        // Process each file
        foreach ($files_assoc as $filepath) {
            Logger::debug("Processing named blocks from file: {$filepath}");
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

    // Loads tables from .tab files in the top-level and optional subdirectory
    // Ensures no duplicate tables are loaded from the subdirectory
    // Returns an associative array of tables
    public static function loadTables($subdir = null) {
        $tables = array();
        
        // Load files from the subdirectory (if provided)
        if ($subdir && is_dir($subdir)) {
            $files = glob($subdir . "/*.tab");
            foreach ($files as $filepath) {
                $filename = basename($filepath);
                $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
                $tables[$name] = self::parseTabFile($filepath);
                Logger::debug("Loaded table '{$name}' from subdirectory: {$filepath}");
            }
        }
        // Load from top-level, but do not override files already loaded from subdir
        $files = glob("*.tab");
        foreach ($files as $filepath) {
            $filename = basename($filepath);
            $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
            if (!isset($tables[$name])) {
                $tables[$name] = self::parseTabFile($filepath);
                Logger::debug("Loaded table '{$name}' from top-level: {$filepath}");
            }
        }
        return $tables;
    }
} 