<?php

namespace App\Utils;

class OutputUtils {
    private bool $verbose;

    public function __construct(bool $verbose = false) {
        $this->verbose = $verbose;
    }

    public function debugPrint(string $msg): void {
        if ($this->verbose) {
            echo $msg . "<br>";
        }
    }

    public function finalPrint(string $indent, string $msg): void {
        if ($this->verbose) {
            echo $indent . "→ Output: X" . $msg . "<br>";
        } else {
            echo $msg . "<br>";
        }
    }
} 