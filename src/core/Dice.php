<?php

/**
 * Dice rolling functionality
 */
class Dice {
    /**
     * Roll dice based on notation (e.g., "1D12", "2D6")
     * @param string $notation Dice notation
     * @return array Array containing total and individual rolls
     * @throws InvalidArgumentException if notation is invalid
     */
    public static function roll($notation) {
        if (!is_string($notation)) {
            throw new InvalidArgumentException("Dice notation must be a string");
        }
        
        if (!preg_match('/^(\d+)[dD](\d+)$/', $notation, $matches)) {
            throw new InvalidArgumentException("Invalid dice notation format: {$notation}");
        }
        
        $num = intval($matches[1]);
        $sides = intval($matches[2]);
        
        if ($num < 1 || $num > 100) {
            throw new InvalidArgumentException("Number of dice must be between 1 and 100");
        }
        
        if ($sides < 1 || $sides > 100) {
            throw new InvalidArgumentException("Number of sides must be between 1 and 100");
        }
        
        $total = 0;
        $rolls = array();
        
        for ($i = 0; $i < $num; $i++) {
            $r = random_int(1, $sides);
            $rolls[] = $r;
            $total += $r;
        }
        
        return array('total' => $total, 'rolls' => $rolls);
    }
}
