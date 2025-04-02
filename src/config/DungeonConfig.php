<?php

/**
 * Configuration class for managing global state
 */
class DungeonConfig {
    private static $instance = null;
    private $verbose = false;
    private $resolvedStack = [];
    private $cache = [
        'tables' => [],
        'named_blocks' => [],
        'monsters' => []
    ];
    
    private function __construct() {}
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function setVerbose($verbose) {
        $this->verbose = $verbose;
    }
    
    public function isVerbose() {
        return $this->verbose;
    }
    
    public function getResolvedStack() {
        return $this->resolvedStack;
    }
    
    public function addToResolvedStack($item) {
        $this->resolvedStack[] = $item;
    }
    
    public function removeFromResolvedStack() {
        array_pop($this->resolvedStack);
    }
    
    public function isInResolvedStack($item) {
        return in_array($item, $this->resolvedStack);
    }
    
    public function getCache($type) {
        return $this->cache[$type] ?? null;
    }
    
    public function setCache($type, $key, $data, $ttl) {
        $this->cache[$type][$key] = [
            'data' => $data,
            'timestamp' => time(),
            'ttl' => $ttl
        ];
    }
    
    public function getCachedData($type, $key, $ttl) {
        if (!isset($this->cache[$type][$key])) {
            return null;
        }
        
        $cache = $this->cache[$type][$key];
        if (!isset($cache['timestamp']) || (time() - $cache['timestamp'] >= $ttl)) {
            return null;
        }
        
        return $cache['data'];
    }
}
