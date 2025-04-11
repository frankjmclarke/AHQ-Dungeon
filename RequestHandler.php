<?php

class RequestHandler {
    private $params;
    
    public function __construct() {
        $this->params = $_GET;
    }
    
    public function isVerbose() {
        return isset($this->params['verbose']) && $this->params['verbose'] == "1";
    }
    
    public function getSubdir() {
        return isset($this->params['subdir']) ? $this->params['subdir'] : Config::$defaultSubdir;
    }
    
    public function getTables() {
        if (!isset($this->params['tables']) || empty($this->params['tables'])) {
            return null;
        }
        return array_map('trim', explode(",", $this->params['tables']));
    }
    
    public function validate() {
        if (!$this->getTables()) {
            throw new InvalidArgumentException(
                "Usage: index4.php?tables=TableName1,TableName2[,...]&verbose=1&subdir=your_subdir (optional)"
            );
        }
    }
} 