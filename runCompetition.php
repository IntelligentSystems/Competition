#!/usr/bin/php
<?php
ini_set('display_errors',1);
// error_reporting(E_ALL);
error_reporting(E_ALL ^ E_NOTICE);
$config = parse_ini_file("config.ini", true);
$competition = new Competition($config);
$competition->run();

if (count(groups)&1) {
	$groups[] = 666; //use our optional bot (probably bullybot) to make it an even number
}

class Competition {
	private $useExtraBot = false;
	private $extraBotGroupNr = 666;
	private $config;
	private $groups;
	private $maps;
	private $round = 0;
	function __construct($config) {
		$this->config = $config;
		$this->groups = $this->getAllGroups();
		$this->maps = $this->getMaps();
		if (count($this->groups)&1) {
			//uneven number of groups
			$this->useExtraBot = true;
		}
		$this->log("Compiling " . count($this->groups) . " groups");
		$this->preProcessGroups();
		
		
	}
	
	function run() {
// 		$this->groups = range(1, 20);
		$this->round++;
		$winners = array();
		shuffle($this->groups);
		for ($i = 0; $i < count($this->groups); $i++, $i++) {
			$winners[] = $this->getWinner($this->groups[$i], $this->groups[$i+1]);
		}
	}
	function getMaps() {
		$mapPaths = glob($this->config['paths']['pwMaps']."*");
		$maps = array();
		foreach ($mapPaths as $mapPath) {
			$maps[] = basename($mapPath);
		}
		return $maps;
	}
	
	/**
	 * Run set of games on a set of maps, for these 2 players.
	 * @param unknown $group1
	 * @param unknown $group2
	 */
	function getWinner($group1, $group2) {
		$results = array(
			0 => 0, //for keeping track of draws...
			$group1 => 0,
			$group2 => 0
		);
		$this->prepareCompetitionDir($group1, $group2);
		foreach ($this->maps as $map) {
			
			$results[$this->runGame($group1, $group2, $map)]++;
			$results[$this->runGame($group2, $group1, $map)]++;
		}
		
		$highestScore = -1;
		$winner = -1;
		foreach ($results as $id => $wins) {
			if ($wins > $highestScore) {
				$highestScore = $wins;
				$winner = $id;
			}
		}
		if ($winner <= 0) {
			$rand = rand(1,2);
			if ($rand === 1) { 
				$winner = $group1;
			} else {
				$winner = $group2;
			}
			$this->log("game between ".$group1." - ".$group2.": DRAW. Flipping a coin, and the winner is.... ".$winner."!!");
		} else {
			$this->log("game between ".$group1." - ".$group2.": WINNER *** ".$winner." ***");
		}
		return $winner;
		
	}
	
	function prepareCompetitionDir($group1, $group2) {
		
		$compDir = $this->config['paths']['competitionDir'];
		$this->emptyDir($compDir); //clean before using
// 		var_export($this->config['paths']['botsCompiled'].$group1);
// 		var_export($compDir);exit;
		$this->copyFilesInDir($this->config['paths']['botsCompiled'].$group1."/", $compDir);
		$this->copyFilesInDir($this->config['paths']['botsCompiled'].$group2."/", $compDir);
		$this->copyFilesInDir($this->config['paths']['pwEngine'], $compDir);
		$this->copyFilesInDir($this->config['paths']['pwMaps'], $compDir);
	}
	
	function runGame($player1, $player2, $map) {
		$player1BotName = $this->getBotName($player1);
		$player2BotName = $this->getBotName($player2);
		if (!strlen($player1BotName)) $this->error("Unable to retrieve bot name for group ".$player1);
		if (!strlen($player2BotName)) $this->error("Unable to retrieve bot name for group ".$player2);
		
		$workingDir = getcwd();
		chdir($this->config['paths']['competitionDir']);
		$cmd = "java -jar PlayGame.jar ".$map;
		$cmd .= " \"java -Xmx" . $this->config['game']['maxMemory'] ."m ".$player1BotName."\" \"java -Xmx" . $this->config['game']['maxMemory'] ."m ".$player2BotName."\"";
		$cmd .= " parallel ".$this->config['game']['numTurns']." ".$this->config['game']['maxTurnTime']." 2>&1";
		$resultString = shell_exec($cmd);
		chdir($workingDir);
		
		$this->storeGameResult($player1, $player2, $map, $resultString);
		return $this->getGameResult($resultString, $player1, $player2);
		
		
		//reset to original working directory
	}
	
	function storeGameResult($player1, $player2, $map, $resultString) {
		$resultDir = $this->config['paths']['tournamentResults'];
		$roundDir = $resultDir.$this->round."/";
		if (!is_dir($roundDir)) mkdir($roundDir);
		
		$batchResultsDir = $roundDir.min($player1, $player2)."-".max($player1, $player2)."/";
		if (!is_dir($batchResultsDir)) mkdir($batchResultsDir);
		
		$gameFile = $batchResultsDir.$player1."-".$player2."_".$map;
		file_put_contents($gameFile, $resultString);
	}
	
	function getGameResult($resultString, $player1, $player2) {
		if (strpos($result, "Draw") !== false) {
			//we have a draw...
			return 0;
		} else {
			$pattern = '/.*Player (\d) Wins.*/';//:Player 1 Wins!
			preg_match($pattern, $resultString, $matches);
			$winner = (int)end($matches);
			if ($winner < 1 || $winner > 2) {
				$this->error("Unable to parse output results. Who is the winner?? Output: ".$resultStirng);
			}
			return $winner;
		}
	}
	
	function preProcessGroups() {
		//cleanup previously processed groups
		$this->emptyDir($this->config['paths']['botsCompiled']);
		
		$removeGroups = array();//keep track of things to remove in separate array. Cannot remove stuff from array while looping through it
		foreach ($this->groups as $group) {
			$submissionFiles = $this->config['paths']['submissionsDirPrefix'].$group."/";
			$destDir = $this->config['paths']['botsCompiled'].$group."/";
			$this->copyFilesInDir($submissionFiles, $destDir);
			$this->copyFilesInDir($this->config['paths']['pwBotApi'], $destDir);
			$result = $this->compileDir($destDir);
			if (!$result) {
				$removeGroups[] = $group;
				
			}
		}
		if (count($removeGroups)) {
			$this->log("Unable to compile bots for group(s) ".implode(",", $removeGroups).". Skipping these for this tournament!");
			foreach ($removeGroups as $remGroup) {
				unset($this->groups[$remGroup]);
			}
		}
		if ($this->useExtraBot) {
			$this->log("uneven number of bots. Adding our own extra bot");
			$this->groups[] = $this->extraBotGroupNr;
			$dest = $this->config['paths']['botsCompiled'].$this->extraBotGroupNr."/";
			$this->copyFilesInDir($this->config['paths']['extraBot'],  $dest);
			$this->copyFilesInDir($this->config['paths']['pwBotApi'], $dest);
			$this->compileDir($dest);
			
		}
	}
	
	function getBotName($group) {
		$botName = trim(file_get_contents($this->config['paths']['botsCompiled'].$group."/bot.txt"));
		if (!strlen($botName)) {
			$this->error("unable to retrieve botname for group ".$group);
		}
		return $botName;
		
	}
	
	function copyFilesInDir($fromDir, $destDir) {
		$handle = opendir($fromDir);
		while (($file = readdir($handle)) != false) {
			if (!is_dir($destDir)) mkdir($destDir);
			if ($file != "." && $file != "..") {
				copy($fromDir.$file, $destDir.$file);
			}
		}
		closedir($handle);
	}
	
	function emptyDir($dir) {
		$filesToDelete = false;
		//Check whether we have something to delete (if not, than our shell exec will start complaining)
		$handle = opendir($dir);
		while (($file = readdir($handle)) != false) {
			if (substr($file, 0, 1) != ".") {
				$filesToDelete = true;
				break;
			}
		}
		closedir($handle);
		if ($filesToDelete) shell_exec("rm -r ".$dir."*");
	}
	
	function compileDir($dir) {
		$succes = true;
		$origDir = getcwd();
		chdir($dir);
		$result = shell_exec("javac *.java 2>&1");
		//use last part to get error channel
		//than it return the actual string error msg, instead of null
		$allClassesCreated = true;
		foreach (glob("*.java") as $filename) {
			//check whether class name is indeed created
			if (!file_exists(basename($filename, ".java").".class")) {
				$allClassesCreated = false;
				break;
			}
		}
		if (!$allClassesCreated) {
			$this->log("Unable to compile files in dir ".$dir.". Error message:\n".$result."");
			$succes = false;
		}
		
		//go back to orig current working dir
		chdir($origDir);
		return $succes;
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
			if ($groupNumber > 0) {
				//valid group, add it
				$groups[$groupNumber] = $groupNumber;
			} else {
				$this->error("Unable to load all groups. Trying to get group number from dir '" . $dir);
				exit;
			}
		}
		return $groups;
	}
	
	function log($message) {
		echo "== ".$message." ==\n";
	}
	
	function error($message) {
		echo "*** ".$message." \nexiting...***\n";
		exit;
	}
}








