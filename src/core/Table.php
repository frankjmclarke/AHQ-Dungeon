<?php
/**
 * Class for handling table-related functionality
 */
class Table {
    private $config;
    
    public function __construct() {
        $this->config = DungeonConfig::getInstance();
    }
    
    /**
     * Parse a .tab file and return its contents
     */
    public function parseTabFile($file) {
        if (!file_exists($file)) {
            throw new RuntimeException("Table file not found: $file");
        }
        
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException("Failed to read table file: $file");
        }
        
        return $lines;
    }
    
    /**
     * Parse an inline table from text
     */
    public function parseInlineTable($text) {
        if (preg_match('/\[\[(.*?)\]\]/s', $text, $matches)) {
            return explode("\n", trim($matches[1]));
        }
        return null;
    }
    
    /**
     * Get a cached table by name
     */
    public function getCachedTable($name) {
        return $this->config->getCachedData('tables', $name, CACHE_TTL);
    }
    
    /**
     * Load tables from files in a directory
     */
    public function loadTables($dir) {
        $files = glob($dir . '/*.tab');
        if ($files === false) {
            throw new RuntimeException("Failed to list directory: $dir");
        }
        
        $tables = [];
        foreach ($files as $file) {
            $name = basename($file, '.tab');
            $tables[$name] = $this->parseTabFile($file);
            $this->config->setCache('tables', $name, $tables[$name], CACHE_TTL);
        }
        
        return $tables;
    }
} 