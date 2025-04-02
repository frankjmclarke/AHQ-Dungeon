/**
 * Class for handling text processing functionality
 */
class TextProcessor {
    private $config;
    private $depth = 0;
    
    public function __construct() {
        $this->config = DungeonConfig::getInstance();
    }
    
    /**
     * Process and resolve text with table references
     */
    public function processAndResolveText($text) {
        if ($this->depth >= MAX_DEPTH) {
            throw new RuntimeException("Maximum recursion depth exceeded");
        }
        
        $this->depth++;
        
        try {
            // Check for table references
            if (preg_match('/\[\[(.*?)\]\]/s', $text, $matches)) {
                $tableName = trim($matches[1]);
                
                // Check for cycles
                if ($this->config->isInResolvedStack($tableName)) {
                    throw new RuntimeException("Circular reference detected: $tableName");
                }
                
                $this->config->addToResolvedStack($tableName);
                
                // Get table data
                $table = $this->config->getCachedData('tables', $tableName, CACHE_TTL);
                if ($table === null) {
                    throw new RuntimeException("Table not found: $tableName");
                }
                
                // Process table entries
                $result = '';
                foreach ($table as $line) {
                    $line = trim($line);
                    if (empty($line) || strpos($line, '#') === 0) {
                        continue;
                    }
                    
                    if (preg_match('/^(\d+)\s*-\s*(\d+)\s*:\s*(.+)$/', $line, $matches)) {
                        $start = intval($matches[1]);
                        $end = intval($matches[2]);
                        $value = trim($matches[3]);
                        
                        $roll = Dice::roll(DEFAULT_DICE)['total'];
                        if ($roll >= $start && $roll <= $end) {
                            $result .= $this->processAndResolveText($value) . "\n";
                        }
                    } elseif (preg_match('/^(\d+)\s*:\s*(.+)$/', $line, $matches)) {
                        $roll = intval($matches[1]);
                        $value = trim($matches[2]);
                        
                        if ($roll === Dice::roll(DEFAULT_DICE)['total']) {
                            $result .= $this->processAndResolveText($value) . "\n";
                        }
                    }
                }
                
                $this->config->removeFromResolvedStack();
                return $result;
            }
            
            return $text;
        } finally {
            $this->depth--;
        }
    }
} 