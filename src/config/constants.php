<?php
/**
 * Global constants for the application
 */

// Maximum recursion depth for text processing
define('MAX_DEPTH', 50);

// Cache TTL values (in seconds)
define('CACHE_TTL', 300);        // 5 minutes cache TTL for tables and named blocks
define('MONSTER_CACHE_TTL', 3600); // 60 minutes cache TTL for monster records

// Default dice notation
define('DEFAULT_DICE', "1D12"); 