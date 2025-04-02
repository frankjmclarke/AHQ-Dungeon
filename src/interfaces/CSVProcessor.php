<?php
/**
 * Interface for CSV processing functionality
 */
interface CSVProcessor {
    /**
     * Process the output text to find and format CSV data
     * @param string $output The text output to process
     * @return string The formatted CSV data as HTML table
     */
    public function processOutput($output);
} 