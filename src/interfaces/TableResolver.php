<?php

namespace App\Interfaces;

interface TableResolver {
    /**
     * Roll dice based on notation (e.g. "1D6", "2D12")
     * 
     * @param string $notation The dice notation to roll
     * @return array Array containing total and individual rolls
     */
    public function rollDice(string $notation): array;

    /**
     * Get dice notation for a specific table
     * 
     * @param string $tableName The name of the table
     * @return string|null The dice notation or null if not found
     */
    public function getDiceNotation(string $tableName): ?string;

    /**
     * Load tables from files
     * 
     * @param string|null $subdir Optional subdirectory to search in
     * @return array Array of loaded tables
     */
    public function loadTables(?string $subdir = null): array;

    /**
     * Parse a tab file into a table array
     * 
     * @param string $filename The file to parse
     * @return array The parsed table data
     */
    public function parseTabFile(string $filename): array;

    /**
     * Resolve a table by name using dice rolls
     * 
     * @param string $name The table name to resolve
     * @param array $tables Available tables
     * @param array $namedRules Optional named rules
     * @param int $depth Current recursion depth
     * @return string|null The resolved table entry or null if not found
     */
    public function resolveTable(string $name, array $tables, array $namedRules = [], int $depth = 0): ?string;
} 