<?php
require_once __DIR__ . '/../interfaces/TableManager.php';

/**
 * Implementation of table management functionality
 */
class TableManagerImpl implements TableManager {
    private $dice_roller;
    private $parent_dir;
    
    public function __construct($dice_roller) {
        $this->dice_roller = $dice_roller;
        $this->parent_dir = dirname(__DIR__);
    }
    
    public function loadTables($subdir = null) {
        $tables = array();
        
        // Load files from parent directory
        $files = glob($this->parent_dir . "/*.tab");
        foreach ($files as $file) {
            $table_name = basename($file, ".tab");
            $tables[$table_name] = file_get_contents($file);
        }
        
        // Load files from subdirectory if specified
        if ($subdir !== null) {
            $subdir_path = $this->parent_dir . "/" . $subdir;
            if (is_dir($subdir_path)) {
                $subdir_files = glob($subdir_path . "/*.tab");
                foreach ($subdir_files as $file) {
                    $table_name = basename($file, ".tab");
                    $tables[$table_name] = file_get_contents($file);
                }
            }
        }
        
        return $tables;
    }
    
    public function resolveTable($name, $tables, $named_rules, $depth) {
        if (isset($tables[$name])) {
            return $tables[$name];
        }
        return null;
    }
} 