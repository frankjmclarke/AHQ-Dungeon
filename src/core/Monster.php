<?php
/**
 * Class for handling monster-related functionality
 */
class Monster {
    private $config;
    
    public function __construct() {
        $this->config = DungeonConfig::getInstance();
    }
    
    /**
     * Get a cached monster record
     */
    public function getCachedMonster($name) {
        return $this->config->getCachedData('monsters', $name, MONSTER_CACHE_TTL);
    }
    
    /**
     * Load monster records from a CSV file
     */
    public function loadMonsters($file) {
        if (!file_exists($file)) {
            throw new RuntimeException("Monster file not found: $file");
        }
        
        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException("Failed to open monster file: $file");
        }
        
        $monsters = [];
        $headers = fgetcsv($handle);
        
        while (($data = fgetcsv($handle)) !== false) {
            $monster = array_combine($headers, $data);
            $name = $monster['name'] ?? '';
            if (!empty($name)) {
                $monsters[$name] = $monster;
                $this->config->setCache('monsters', $name, $monster, MONSTER_CACHE_TTL);
            }
        }
        
        fclose($handle);
        return $monsters;
    }
} 