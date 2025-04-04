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
            Logger::debug("[SIZE {$size}]");
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
} 