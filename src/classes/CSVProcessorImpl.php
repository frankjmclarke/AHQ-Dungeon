<?php

namespace App\Classes;

require_once __DIR__ . '/../interfaces/CSVProcessor.php';
require_once __DIR__ . '/../config/constants.php';

use App\Interfaces\CSVProcessor;

/**
 * Implementation of CSV processing functionality
 */
class CSVProcessorImpl implements CSVProcessor {
    private string $parent_dir;
    private string $csv_file;
    
    /**
     * Constructor
     * @param string $parent_dir The parent directory path
     * @param string $csv_file The CSV file path relative to parent directory
     */
    public function __construct(string $parent_dir, string $csv_file = CSV_FILE) {
        $this->parent_dir = $parent_dir;
        $this->csv_file = $csv_file;
    }
    
    /**
     * Process the output text to find and format CSV data
     * @param string $output The text output to process
     * @return string The formatted CSV data as HTML table
     */
    public function processOutput(string $output): string {
        $csvOutput = "";
        if (preg_match_all('/\d+\s+([A-Za-z ]+?)(?=[^A-Za-z ]|$)/', $output, $matches)) {
            $names = array_map('trim', $matches[1]);
            $names = array_filter($names, function($n) { return $n !== ""; });
            $csvResults = $this->searchCSV($names);
            if (!empty($csvResults)) {
                $csvOutput = $this->generateCSVTable($csvResults);
            }
        }
        return $csvOutput;
    }
    
    /**
     * Search the CSV file for matching names
     * @param array<string> $names Array of names to search for
     * @return array<string,array<array<string>>> Array of matching CSV results
     */
    private function searchCSV(array $names): array {
        $csvResults = array();
        $csv_path = $this->parent_dir . "/" . $this->csv_file;
        if (($handle = fopen($csv_path, "r")) !== false) {
            while (($data = fgetcsv($handle)) !== false) {
                if (isset($data[0])) {
                    $csvName = trim($data[0]);
                    foreach ($names as $name) {
                        if ($this->matchName($csvName, $name)) {
                            $csvResults[$name][] = $data;
                        }
                    }
                }
            }
            fclose($handle);
        } else {
            debug_print("Warning: Could not open CSV file at: {$csv_path}");
        }
        return $csvResults;
    }
    
    /**
     * Match a CSV name with a search name
     * @param string $csvName The name from the CSV file
     * @param string $name The name to search for
     * @return bool Whether the names match
     */
    private function matchName(string $csvName, string $name): bool {
        if (strcasecmp($csvName, $name) === 0) {
            return true;
        }
        if (substr($name, -1) === "s") {
            $singular = substr($name, 0, -1);
            if (strcasecmp($csvName, $singular) === 0) {
                return true;
            }
        }
        if (substr($name, -3) === "men") {
            $singular = substr($name, 0, -3) . "man";
            if (strcasecmp($csvName, $singular) === 0) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Generate an HTML table from CSV results
     * @param array<string,array<array<string>>> $csvResults Array of CSV results
     * @return string HTML table string
     */
    private function generateCSVTable(array $csvResults): string {
        $output = "##CSV_MARKER##";
        $output .= "<table border='1' cellspacing='0' cellpadding='4' style='max-width:500px; margin:0 auto;'>";
        $output .= "<tr>";
        $output .= "<th>Monster</th>";
        $output .= "<th>WS</th>";
        $output .= "<th>BS</th>";
        $output .= "<th>S</th>";
        $output .= "<th>T</th>";
        $output .= "<th>Sp</th>";
        $output .= "<th>Br</th>";
        $output .= "<th>Int</th>";
        $output .= "<th>W</th>";
        $output .= "<th>DD</th>";
        $output .= "<th>PV</th>";
        $output .= "<th>Equipment</th>";
        $output .= "</tr>";
        
        foreach ($csvResults as $name => $rows) {
            foreach ($rows as $row) {
                $output .= "<tr>";
                foreach ($row as $field) {
                    $output .= "<td>" . htmlspecialchars($field) . "</td>";
                }
                $output .= "</tr>";
            }
        }
        
        $output .= "</table>";
        return $output;
    }
} 