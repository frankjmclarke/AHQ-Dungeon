<?php
require_once 'Logger.php';

class TableManager {
    // Maps table names to their dice notations
    private static $table2die = array();
    // Stack to track resolved table names to prevent recursion
    private static $resolvedStack = array();

    // Sets the dice notation for a given table name
    public static function setDiceNotation($tableName, $notation) {
        self::$table2die[strtolower($tableName)] = $notation;
    }

    // Retrieves the dice notation for a given table name
    public static function getDiceNotation($tableName) {
        $tableName = strtolower($tableName);
        if (isset(self::$table2die[$tableName])) {
            $size = sizeof(self::$table2die);
            return self::$table2die[$tableName];
        }
        return null;
    }

    // Pushes a table name onto the resolved stack
    public static function pushResolvedStack($name) {
        self::$resolvedStack[] = $name;
    }

    // Pops a table name from the resolved stack
    public static function popResolvedStack() {
        return array_pop(self::$resolvedStack);
    }

    // Checks if a table name is in the resolved stack
    public static function isInResolvedStack($name) {
        return in_array($name, self::$resolvedStack);
    }

    /**
     * Process tables based on user input
     * 
     * @param array $userTables Array of table names to process
     * @param array $tables Available tables
     * @param array $namedRules Named rules to apply
     * @return string Processed output
     */
    public static function processUserTables($userTables, $tables, $namedRules) {
        $output = [];
        
        foreach ($userTables as $userInput) {
            $normalized = strtolower(str_replace(array("-", "_"), "", $userInput));
            $candidates = self::findMatchingTables($normalized, $tables);
            
            if (empty($candidates)) {
                $output[] = "[Table '{$userInput}' not found. Available: " . implode(", ", array_keys($tables)) . "]";
            } else {
                $tableName = $candidates[0];
                if (Logger::isVerbose()) {
                    $output[] = "<br>--- Resolving table '{$userInput}' ---<br>";
                }
                
                $result = TableProcessor::resolveTable($tableName, $tables, $namedRules);
                $output[] = $result;
                
                if (Logger::isVerbose()) {
                    $output[] = "<br>" . str_repeat("=", 50) . "<br>";
                }
            }
        }
        
        return implode("\n", $output);
    }
    
    /**
     * Find matching tables based on normalized input
     * 
     * @param string $normalized Normalized table name
     * @param array $tables Available tables
     * @return array Matching table names
     */
    private static function findMatchingTables($normalized, $tables) {
        $candidates = [];
        foreach ($tables as $key => $value) {
            $normalizedKey = strtolower(str_replace(array("-", "_"), "", $key));
            if ($normalizedKey === $normalized) {
                $candidates[] = $key;
            }
        }
        return $candidates;
    }
} 