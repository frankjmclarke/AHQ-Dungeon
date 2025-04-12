<?php
require_once 'Logger.php';
require_once 'TableManager.php';
require_once 'DiceRoller.php';
require_once 'FileParser.php';
require_once 'CSVProcessor.php';
require_once 'CacheManager.php';

// Global constants
define('MAX_DEPTH', 50);         // Maximum recursion depth
define('DEFAULT_DICE', "1D12");   // Global default dice (if no block-specific notation is provided)

/**
 * TableProcessor - Core class for processing and resolving text-based game content
 * 
 * This class handles three main types of text processing:
 * 1. Function Calls: Matches text patterns like "Function()" and resolves them
 * 2. Quoted Text: Processes text enclosed in quotes (e.g. "Room Description")
 * 3. Nested Tables: Handles text enclosed in [[brackets]] for composite entries
 * 
 * Text Processing Flow:
 * 1. Input text is split into lines
 * 2. Each line is processed for function calls, quoted text, and nested tables
 * 3. Results are recursively processed to handle nested content
 * 4. State is managed via stack to prevent infinite recursion
 */
class TableProcessor {
    private $directory;
    private $subDirectory;
    private $tabFiles = [];
    private $subDirTabFiles = [];
    private static $instance = null;
    private $cacheManager;

    private function __construct($directory = ".", $subDirectory = "dungeon") {
        $this->directory = $directory;
        $this->subDirectory = $subDirectory;
        $this->cacheManager = new CacheManager();
        // Load root tab files using cache
        $this->tabFiles = $this->cacheManager->loadTabFiles($directory);
        $this->loadSubDirectoryFiles();
    }

    public static function getInstance($directory = ".", $subDirectory = null) {
        try {
            if (self::$instance === null) {
                self::$instance = new self($directory, $subDirectory ?? "dungeon");
            } else if ($subDirectory !== null && self::$instance->subDirectory !== $subDirectory) {
                self::$instance->setSubDirectory($subDirectory);
            }
            return self::$instance;
        } catch (Exception $e) {
            Logger::debug("Error in TableProcessor::getInstance: " . $e->getMessage());
            throw new Exception("Failed to initialize TableProcessor: " . $e->getMessage());
        }
    }

    private function loadSubDirectoryFiles() {
        $fullPath = $this->directory . "/" . $this->subDirectory;
        try {
            if (!is_dir($fullPath)) {
                Logger::debug("Warning: Subdirectory not found: {$fullPath}");
                $this->subDirTabFiles = []; // Reset if directory doesn't exist
                return;
            }
            if (!is_readable($fullPath)) {
                Logger::debug("Error: Subdirectory not readable: {$fullPath}");
                throw new Exception("Cannot read from subdirectory: {$this->subDirectory}");
            }
            $this->subDirTabFiles = $this->cacheManager->loadTabFiles($fullPath, true);
        } catch (Exception $e) {
            Logger::debug("Error loading subdirectory files: " . $e->getMessage());
            $this->subDirTabFiles = []; // Reset on error
            throw $e; // Re-throw to be handled by caller
        }
    }

    public function setSubDirectory($subDirectory) {
        if (empty($subDirectory)) {
            Logger::debug("Warning: Empty subdirectory specified, using default");
            $subDirectory = "dungeon"; // Use default
        }

        // Sanitize subdirectory name to prevent directory traversal
        $subDirectory = str_replace(['..', '/', '\\'], '', $subDirectory);
        
        if ($this->subDirectory !== $subDirectory) {
            Logger::debug("Changing subdirectory from {$this->subDirectory} to {$subDirectory}");
            $oldSubDirectory = $this->subDirectory;
            $this->subDirectory = $subDirectory;
            
            try {
                // Invalidate old subdirectory cache
                $this->cacheManager->invalidateTabCache(false, true);
                // Load new subdirectory files
                $this->loadSubDirectoryFiles();
            } catch (Exception $e) {
                // Rollback to previous subdirectory on error
                $this->subDirectory = $oldSubDirectory;
                $this->loadSubDirectoryFiles(); // Reload old subdirectory
                throw new Exception("Failed to switch to subdirectory '{$subDirectory}': " . $e->getMessage());
            }
        }
    }

    private function getTabContent($name) {
        // First check root directory cache
        foreach ($this->tabFiles as $filename => $content) {
            if (strcasecmp(basename($filename, '.tab'), $name) === 0) {
                return $content;
            }
        }

        // Then check subdirectory cache
        foreach ($this->subDirTabFiles as $filename => $content) {
            if (strcasecmp(basename($filename, '.tab'), $name) === 0) {
                return $content;
            }
        }

        return null;
    }

    /**
     * Processes and resolves text by evaluating function calls and nested content
     * 
     * Text Processing Steps:
     * 1. Splits input text into lines
     * 2. For each line:
     *    - Matches function calls using regex /^([A-Za-z0-9_\-]+)\(\)$/
     *    - Processes quoted text (text between " ")
     *    - Handles nested function calls within lines
     * 3. Uses stack-based tracking to prevent circular references
     * 
     * @param string $text The text to process
     * @param array $tables Available tables for resolution
     * @param array $named_rules Named block rules to apply
     * @param int $depth Current recursion depth
     * @param string|null $parent_table Parent table name if in nested context
     * @param string|null $current_named Current named block being processed
     */
    public static function processAndResolveText($text, $tables, $named_rules, $depth, $parent_table = null, $current_named = null) {
        if ($depth > MAX_DEPTH) {
            Logger::debug(str_repeat("  ", $depth) . "[Maximum recursion depth reached]");
            return;
        }
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $line = trim($line);
            Logger::debug(str_repeat("  ", $depth) . "Processing line: {$line}");
            if (preg_match('/^([A-Za-z0-9_\-]+)\(\)$/', $line, $matches)) {
                Logger::debug(str_repeat("  ", $depth) . "Function call detected: {$matches[1]}");
                $name_candidate = strtolower($matches[1]);
                if (isset($named_rules[$name_candidate])) {
                    Logger::debug(str_repeat("  ", $depth) . "→ Resolving named block: " . $line);
                    $result = $named_rules[$name_candidate]();
                    self::processAndResolveText($result, $tables, $named_rules, $depth + 1, $parent_table, $name_candidate);
                    continue;
                }
            }
            $lower_line = strtolower($line);
            if (isset($named_rules[$lower_line])) {
                if ($current_named !== null && $lower_line === $current_named) {
                    Logger::output(str_repeat("  ", $depth), $line);
                } else {
                    if (TableManager::isInResolvedStack($lower_line)) {
                        Logger::debug(str_repeat("  ", $depth) . "→ [Cycle detected: " . $line . "]");
                    } else {
                        TableManager::pushResolvedStack($lower_line);
                        Logger::debug(str_repeat("  ", $depth) . "→ Resolving named block: " . $line);
                        $result = $named_rules[$lower_line]();
                        self::processAndResolveText($result, $tables, $named_rules, $depth + 1, $parent_table, $lower_line);
                        TableManager::popResolvedStack();
                    }
                }
                continue;
            }
            // Process quoted text: output text between double quotes
            if (substr($line, 0, 1) === '"' && substr($line, -1) === '"') {
                Logger::output(str_repeat("  ", $depth), substr($line, 1, -1));
            } else {
                Logger::output(str_repeat("  ", $depth), $line);
            }
            // Handle nested function calls within lines
            if (preg_match_all('/([A-Za-z0-9_\-]+)\(\)/', $line, $all_matches)) {
                foreach ($all_matches[1] as $match) {
                    $match_lower = strtolower($match);
                    if ($current_named !== null && $match_lower === $current_named) {
                        continue;
                    }
                    if (TableManager::isInResolvedStack($match_lower)) {
                        continue;
                    }
                    TableManager::pushResolvedStack($match_lower);
                    if (isset($named_rules[$match_lower])) {
                        $result = $named_rules[$match_lower]();
                        self::processAndResolveText($result, $tables, $named_rules, $depth + 1, $parent_table, $match_lower);
                    } elseif (isset($tables[$match_lower])) {
                        $resolved_output = self::resolveTable($match_lower, $tables, $named_rules, $depth + 1);
                        Logger::debug(str_repeat("  ", $depth) . "Resolved output for {$match_lower}: {$resolved_output}");
                        Logger::output(str_repeat("  ", $depth), $resolved_output);
                    }
                    TableManager::popResolvedStack();
                }
            }
        }
    }

    /**
     * Resolves a table by rolling dice and processing its entries
     * 
     * Text Processing in Table Resolution:
     * 1. Handles quoted entries (text between " ")
     * 2. Processes composite entries (text between [[ ]])
     * 3. Resolves nested tables using & as separator
     * 4. Maintains depth tracking for nested resolution
     * 
     * @param string $name Table name to resolve
     * @param array $tables Available tables
     * @param array $named_rules Named rules to apply
     * @param int $depth Current recursion depth
     */
    public static function resolveTable($name, $tables, $named_rules = array(), $depth = 0) {
        $indent = str_repeat("  ", $depth);
        $name = strtolower($name);
        if (!isset($tables[$name])) {
            Logger::debug($indent . "[Table '{$name}' not found]");
            return;
        }
        $table = $tables[$name];
        $diceNotation = TableManager::getDiceNotation($name);

        if ($diceNotation !== null) {
            $result = DiceRoller::roll($diceNotation);
            Logger::debug("[Good entry for ROLL {$diceNotation}]");
        } else {
            Logger::debug("[Bad entry for ROLL {$name}]");
            $result = DiceRoller::roll(DEFAULT_DICE);
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
        Logger::debug($indent . "Rolled {$roll} on {$name}: {$entry} (rolls: " . implode(",", $rolls) . ")");
        if (!$entry) {
            Logger::debug($indent . "[No entry for roll {$roll}]");
            return;
        }
        if (substr($entry, 0, 1) === '"' && substr($entry, -1) === '"') {
            Logger::output($indent, substr($entry, 1, -1));
            return;
        }
        if (substr($entry, 0, 2) === '[[' && substr($entry, -2) === ']]') {
            $inner = substr($entry, 2, -2);
            $parts = array_map('trim', explode("&", $inner));
            foreach ($parts as $part) {
                $part_normalized = strtolower(str_replace(array("(", ")"), "", $part));
                self::resolveTable($part_normalized, $tables, $named_rules, $depth + 1);
            }
            return;
        }
        self::processAndResolveText($entry, $tables, $named_rules, $depth, $name, null);
        //Logger::debug($indent . "[!!!!name {$name}]");
        if (stripos($name, "Interact-") === 0) {
            return "[Interact-]\n" . $entry . "\n[/Interact-]";
        }
        if ($name == "hidden-treasure") {
            return "[Hidden-Treasure]\n" . $entry . "\n[/Hidden-Treasure]";
        }
        if ($name == "treasure-chest") {
            return "[Treasure-Chest]\n" . $entry . "\n[/Treasure-Chest]";
        }
        if ($name == "treasure-chest-trap") {//Zorin Faces of Tzeentch
            return "[Treasure-Chest]\n" . $entry . "\n[/Treasure-Chest]";
        }        
        return $entry;
    }

    /**
     * Parses a named block into tables with dice notation
     * 
     * Text Processing in Named Blocks:
     * 1. Extracts dice notation from first line (e.g., "2D12", "1D6")
     * 2. Processes nested structures using parentheses tracking
     * 3. Handles composite entries with & separator
     * 4. Maintains state via stack for nested blocks
     * 
     * @param array $lines Lines of text to parse
     * @return array Tuple of [name, closure] for block resolution
     */
    public static function parseNamedBlock($lines) {
        $name = strtolower(trim($lines[0]));
        $parsed_tables = self::parseBlockLines($lines, $name);
        return array($name, function() use ($parsed_tables, $name) {
            return self::resolveParsedTables($parsed_tables, $name);
        });
    }

    private static function parseBlockLines($lines, $name) {
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
                $parsed = FileParser::parseInlineTable($current);
                $current = count($stack) > 0 ? array_pop($stack) : array();
                $parsed_tables[] = array($dice_notation, $parsed);
                $dice_notation = null;
            } else {
                $current[] = $line;
            }
            if ($i == 1) {
                if (strpos($line, "2D12") !== false) {
                    $dice_notation = "2D12";
                    TableManager::setDiceNotation($name, $dice_notation);
                } elseif (strpos($line, "1D12") !== false) {
                    $dice_notation = "1D12";
                    TableManager::setDiceNotation($name, $dice_notation);
                } elseif (strpos($line, "1D6") !== false) {
                    $dice_notation = "1D6";
                    TableManager::setDiceNotation($name, $dice_notation);
                }
            }
        }
        return $parsed_tables;
    }

    private static function resolveParsedTables($parsed_tables, $name, $depth = 0) {
        $indent = str_repeat("  ", $depth);
        if (empty($parsed_tables)) {
            return "";
        }
        list($notation, $outer) = $parsed_tables[0];
        $roll_notation = $notation !== null ? $notation : DEFAULT_DICE;
        Logger::debug("Using dice notation '{$roll_notation}' for block '{$name}'");
        $has_composite = false;
        foreach ($outer as $entry) {
            if (strpos($entry[1], "&") !== false) {
                $has_composite = true;
                break;
            }
        }
        $attempts = 0;
        $entry_val = null;
        while ($attempts < 100) {
            $result = DiceRoller::roll($roll_notation);
            $roll = $result['total'];
            $rolls = $result['rolls'];
            $entry_val = null;
            foreach ($outer as $tuple) {
                if ($tuple[0] == $roll) {
                    $entry_val = $tuple[1];
                    break;
                }
            }
            Logger::debug("  → [Nested roll in {$name}]: Rolled {$roll} (rolls: " . implode(",", $rolls) . ") resulting in: {$entry_val}");
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
                    $result2 = DiceRoller::roll($roll_notation2);
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
        Logger::debug($indent . "Checking if table name starts with 'Interact': {$name}");
        if (stripos($name, "Interact") === 0) {
            Logger::debug($indent . "[Interact] tag will be added to the output for table: {$name}");
            return "[Hidden-Treasure2]\n" . $final_output . "\n<strong>" . ucfirst(str_replace('-', ' ', substr($name, 9))) . "</strong>\n[/Hidden-Treasure2]";
        }
        if ($name == "hidden-treasure") {
            return "[Hidden-Treasure]\n" . $final_output . "\n[/Hidden-Treasure]";
        }
        if ($name == "treasure-chest") {
            return "[Treasure-Chest]\n" . $final_output . "\n[/Treasure-Chest]";
        }
        return $final_output;
    }

    public static function binarySearch($array, $target) {
        if (empty($array) || $target === null) {
            return -1; // Return -1 if the array is empty or target is null
        }
        $low = 0;
        $high = count($array) - 1;
        while ($low <= $high) {
            $mid = floor(($low + $high) / 2);
            // Ensure $array[$mid][0] is a string before calling strcasecmp
            $comparison = strcasecmp((string)$array[$mid][0], (string)$target);
            if ($comparison < 0) {
                $low = $mid + 1;
            } elseif ($comparison > 0) {
                $high = $mid - 1;
            } else {
                return $mid;
            }
        }
        return -1; // Not found
    }

    private function processTable($name, $entry = "") {
        $content = $this->getTabContent($name);
        if ($content === null) {
            return "Table not found: " . $name;
        }

        // ... rest of existing processTable code ...
    }

    public function invalidateCache($isSubDir = false) {
        $this->cacheManager->invalidateTabCache(false, $isSubDir);
        if ($isSubDir) {
            $this->loadSubDirectoryFiles();
        } else {
            $this->tabFiles = $this->cacheManager->loadTabFiles($this->directory);
        }
    }
} 