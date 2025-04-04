<?php

namespace App\Classes;

require_once __DIR__ . '/../interfaces/TableManager.php';
require_once __DIR__ . '/../interfaces/TableResolver.php';
require_once __DIR__ . '/TableResolverImpl.php';

use App\Classes\TableResolverImpl;
use App\Interfaces\TableManager;

/**
 * Implementation of table management functionality
 */
class TableManagerImpl implements TableManager {
    private $dice_roller;
    private $table_resolver;
    private $parent_dir;
    
    public function __construct($dice_roller) {
        global $VERBOSE;
        $this->dice_roller = $dice_roller;
        $this->table_resolver = new TableResolverImpl($VERBOSE);
        $this->parent_dir = dirname(__DIR__);
    }
    
    public function loadTables($subdir = null) {
        return $this->table_resolver->loadTables($subdir);
    }
    
    public function resolveTable($name, $tables, $named_rules = [], $depth = 0) {
        return $this->table_resolver->resolveTable($name, $tables, $named_rules, $depth);
    }
} 