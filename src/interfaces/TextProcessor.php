<?php

namespace App\Interfaces;

interface TextProcessor {
    /**
     * Process and resolve text with possible nested named blocks and table references
     * 
     * @param string $text The text to process
     * @param array $tables Available tables for resolution
     * @param array $namedRules Named rules for resolution
     * @param int $depth Current recursion depth
     * @param string|null $parentTable Parent table name if any
     * @param string|null $currentNamed Current named block if any
     * @return void
     */
    public function processAndResolveText(
        string $text, 
        array $tables, 
        array $namedRules, 
        int $depth = 0, 
        ?string $parentTable = null, 
        ?string $currentNamed = null
    ): void;

    /**
     * Extract named blocks from files
     * 
     * @param string|null $subdir Optional subdirectory to search in
     * @return array Array of named blocks
     */
    public function extractNamedBlocks(?string $subdir = null): array;
} 