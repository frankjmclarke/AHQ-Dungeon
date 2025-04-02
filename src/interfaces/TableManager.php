<?php
/**
 * Interface for table management functionality
 */
interface TableManager {
    /**
     * Load tables from files in the parent directory and optionally a subdirectory
     * @param string|null $subdir Optional subdirectory to load tables from
     * @return array Array of table names to their contents
     */
    public function loadTables($subdir = null);
    
    /**
     * Resolve a table by name
     * @param string $name The name of the table to resolve
     * @param array $tables Array of available tables
     * @param array $named_rules Array of named rules
     * @param int $depth Current recursion depth
     * @return string|null The resolved table content or null if not found
     */
    public function resolveTable($name, $tables, $named_rules, $depth);
} 