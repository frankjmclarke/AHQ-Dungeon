<?php
require_once 'Logger.php';

/**
 * FileParser - Handles parsing of text files containing game content
 * 
 * This class processes three main types of text files:
 * 1. Inline Tables: Single-line entries with roll ranges and content
 * 2. Tab Files (.tab): Structured files with roll ranges and associated text
 * 3. Named Blocks: Multi-line structures with nested content
 * 
 * Text Processing Features:
 * - Case-insensitive processing (names are converted to lowercase)
 * - Comment handling (lines starting with #)
 * - Empty line filtering
 * - Structured content parsing (ranges, blocks, nested structures)
 */
class FileParser {
    /**
     * Parses inline table entries from text lines
     * 
     * Text Processing Format:
     * - Each line: "NUMBER[-NUMBER] CONTENT"
     * - Example: "1-3 Goblin" creates entries for rolls 1, 2, and 3
     * - Whitespace is trimmed from content
     * - Uses regex /^(\d+)(?:-(\d+))?\s+(.+)$/ for matching
     * 
     * @param array $lines Array of text lines to parse
     * @return array Processed table entries
     */
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

    /**
     * Parses a .tab file into table entries
     * 
     * Text Processing Steps:
     * 1. Reads file line by line, skipping:
     *    - Empty lines
     *    - Comments (lines starting with #)
     * 2. Parses each valid line:
     *    - Matches number ranges using regex
     *    - Extracts associated content
     *    - Expands ranges into individual entries
     * 
     * @param string $filename Path to the .tab file
     * @return array Processed table entries
     */
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

    /**
     * Extracts named blocks from text files
     * 
     * Text Processing Features:
     * 1. Block Structure:
     *    - Starts with alphanumeric name
     *    - Content enclosed in parentheses
     *    - Supports nested blocks
     * 2. File Handling:
     *    - Processes .tab and .txt files
     *    - Supports subdirectory override
     *    - Skips comments and empty lines
     * 
     * @param string|null $subdir Optional subdirectory path
     * @return array Named blocks with their content
     */
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

    /**
     * Loads and processes table files
     * 
     * Text Processing Features:
     * 1. File Management:
     *    - Processes .tab files from main and subdirectories
     *    - Handles file naming conflicts
     *    - Maintains case-insensitive table names
     * 2. Content Processing:
     *    - Parses structured table content
     *    - Logs table loading for debugging
     * 
     * @param string|null $subdir Optional subdirectory path
     * @return array Processed tables indexed by lowercase names
     */
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