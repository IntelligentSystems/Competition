#!/usr/bin/php
<?php
ini_set('display_errors',1);
// error_reporting(E_ALL);
error_reporting(E_ALL ^ E_NOTICE);
$config = parse_ini_file("config.ini", true);
$competition = new Competition($config);
$competition->run;

if (count(groups)&1) {
	$groups[] = 666; //use our optional bot (probably bullybot) to make it an even number
}

class Competition {
	private $config;
	private $groups;
	function __construct($config) {
		$this->config = $config;
		$this->groups = $this->getAllGroups();
		$this->compileAllGroups();
		
	}
	
	function runCompetitionRounds() {
		
	}
	
	
	function getAllGroups() {
		$prefix = $this->config['paths']['submissionsDirPrefix'];
		$dirs = glob($prefix.'*');
		$groups = array();
		foreach ($dirs as $dir) {
			$pattern = str_replace("/", "\/", $prefix); //escape slashes
			$pattern = '/^'.$pattern.'(\d+)/';
			preg_match($pattern, $dir, $matches);
			$groupNumber = (int)end($matches);
			if ($groupNumer > 0) {
				//valid group, add it
				$groups[$groupNumber] = $groupNumber;
			} else {
				echo "Unable to load all groups. Trying to get group number from dir " . $dir.". Exiting...\n";
				exit;
			}
		}
		return $groups;
	}
}








