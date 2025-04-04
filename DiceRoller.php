<?php
class DiceRoller {
    // Rolls dice based on the given notation (e.g., '2d6' for two six-sided dice)
    // If the notation is invalid, defaults to rolling a single 12-sided die
    // Returns an associative array with the total of the rolls and an array of individual roll results
    public static function roll($notation) {
        if (preg_match('/^(\d+)[dD](\d+)$/', $notation, $matches)) {
            $num = intval($matches[1]);
            $sides = intval($matches[2]);
            $total = 0;
            $rolls = array();
            for ($i = 0; $i < $num; $i++) {
                $r = random_int(1, $sides);
                $rolls[] = $r;
                $total += $r;
            }
            return array('total' => $total, 'rolls' => $rolls);
        } else {
            $r = random_int(1, 12);
            return array('total' => $r, 'rolls' => array($r));
        }
    }
} 