#!/usr/bin/php
<?php
ini_set('display_errors',1);
// error_reporting(E_ALL);
error_reporting(E_ALL ^ E_NOTICE);
$config = parse_ini_file("config.ini", true);

$comResult = -1;
$resume = false;
if (count($argv) === 2) {
	//we have an arg
	$resume = (bool)end($argv);
}
while ($comResult === -1) {
	$competition = new Competition($config, $resume);
	//redo competition until we have a valid set of group winners 
	$comResult = $competition->run();
}

class Competition {
	private $config;
	private $groups;
	private $maps;
	private $extraGames = false; //flag to indicate we are doing extra matches because of a draw
	private $resume = false;
	private $resumeData = array();
	private $crashedPlayer = false;
	private $firstResumeMatch = true;
	
	private $round = 0;
	private $leagueGroup = 0;
	function __construct($config, $resume) {
		$this->resume = $resume;
		$this->config = $config;
		$this->groups = $this->getAllGroups();
		$this->maps = $this->getMaps();
		if (!$resume) {
			$this->log("Compiling " . count($this->groups) . " groups");
			$this->preProcessGroups();
			$this->clearPreviousTournamentResults();
			$this->clearPreviousResumeData();
		} else {
			$this->log("resuming competition");
		}
		
	}
	
	function run() {
		$leagueGroups = $this->getMiniLeagueGroups();
		$groups = $this->runLeague($leagueGroups);
		if ($groups === -1) {
			//more than two winners for a league. restart all
			return -1;
		}
		$this->leagueGroup = 0; //reset, so we store stuff in the proper folder
		$this->runKnockoutRound($groups);
		return true;
	}
	
	function runLeague($groups) {
		
		$winners = array();
		$fromLeague = 1; //run all leagues
		
		if ($this->resume) {
			$fromLeague = ($this->loadIntFromFile($this->config['resumeLog']['lastCompletedLeague'], 0) + 1) ;
			$winners = $this->loadArrayFromFile($this->config['resumeLog']['leagueWinners'], false);
		}
		foreach ($groups as $group) {
			
			$this->leagueGroup++;
			if ($this->leagueGroup < $fromLeague) continue; //already have results for this group. resuming from somewhere else
			$this->log("Running games for league group " . ($this->leagueGroup));
			
			$groupWinners = $this->runLeagueForGroup($group);
			if ($groupWinners === -1) {
				//more than two winners. restart all
				return -1;
			}
			$winners = array_merge($winners,$groupWinners);
			file_put_contents($this->config['resumeLog']['lastCompletedLeague'], $this->leagueGroup);
			file_put_contents($this->config['resumeLog']['leagueWinners'], var_export($winners, true));
			
		}
		return $winners;
	}
	
	function runLeagueForGroup($group) {
		$scores = array();
		$fromMatch = 0; //to be able to resume from somewhere within a league
		if ($this->resume) {
			$scores = $this->loadArrayFromFile($this->config['resumeLog']['leagueScores'], false);
			$fromMatch = ($this->loadIntFromFile($this->config['resumeLog']['lastCompletedLeagueMatch'], -1) + 1);
		}
		if (!count($scores)) {
			foreach ($group as $botId) {
				$scores[$botId] = 0;
			}
		}
		
		$schedule = $this->getLeagueSchedule($group);
		file_put_contents($this->config['resumeLog']['leagueSchedule'], var_export($schedule, true));
		foreach ($schedule as $matchKey => $game) {
			if ($matchKey < $fromMatch) continue; //already have results for this match. resuming from somewhere else
			$player2 = reset($game);
			$player1 = key($game);
			$winner = $this->getWinner($player1, $player2, false, true);
			if ($winner == 0) {
				//draw
				$scores[$player1]++;
				$scores[$player2]++;
			} else {
				$scores[$winner] += 3;
			}
			file_put_contents($this->config['resumeLog']['leagueScores'], var_export($scores, true));
			file_put_contents($this->config['resumeLog']['lastCompletedLeagueMatch'], $matchKey);
		}
		
		//finished with all the games. select winners!
		arsort($scores);
		$winners = array();
		$lowestWinningScore = 99999;
		$leagueLog = "Outcome of mini league:\n";
		foreach ($scores AS $botId => $score) {
			if (count($winners) < 2) {
				$winners[] = $botId;
				if ($score < $lowestWinningScore) {
					$lowestWinningScore = $score;
				}
			} else if ($score == $lowestWinningScore) {
				$this->log("whoops: more than 2 bots won in this mini league!! Starting alllll over again");
				var_export($scores);
				return -1;
			}
			$leagueLog .= "\tgroup".$botId.": ".$score." points\n";
		}
		file_put_contents($this->config['paths']['competitionLog'], $leagueLog, FILE_APPEND);
		return $winners;		
	}
	
	function getLeagueSchedule($group) {
		$schedule = array();
		//we play once against every other bot in our group
		foreach ($group AS $p1Key => $player1) {
			foreach ($group AS $p2Key => $player2) {
				if ($p2Key > $p1Key) {
					$schedule[] = array($player1 => $player2);
				}
			}
		}
		return $schedule;
	}
	
	function getMiniLeagueGroups() {
		$league = array();
		if ($this->resume) {
			$league = $this->loadArrayFromFile($this->config['resumeLog']['miniLeagueGroups'], true);
		} else {
			$groups = $this->groups;
			shuffle($groups);
			$numGroups = $this->config['game']['numMiniLeagues'];
			$modulo = count($groups) % $numGroups;
			$groupSize = (count($groups) - $modulo) / $numGroups;
			$groupsAssigned = 0;
			for ($groupNum = 0; $groupNum < $numGroups; $groupNum++) {
				$size = $groupSize;
				if ($modulo > 0) {
					$size++;
					$modulo--;
				}
				$league[] = array_slice($groups, $groupsAssigned, $size);
				$groupsAssigned += $size;
			}
			file_put_contents($this->config['resumeLog']['miniLeagueGroups'], var_export($league, true));
		}
		
		return $league;
		
	}
	
	function runKnockoutRound($groups) {
		if (!count($groups)) {
			$this->error("no groups passed to knockout phase");
		}
		if (count($groups)&1) {
			$this->error("uneven number of groups passed to knockout phase");
		}
		file_put_contents($this->config['resumeLog']['lastCompletedKnockoutRound'], $this->round);
		file_put_contents($this->config['resumeLog']['groupsInKnockout'], var_export($groups, true));
		$this->round++;
		echo "\n### Playing round ".$this->round." ###\n";
		$winners = array();
		shuffle($groups);
		for ($i = 0; $i < count($groups); $i++, $i++) {
			$winners[] = $this->getWinner($groups[$i], $groups[$i+1]);
			file_put_contents($this->config['resumeLog']['knockoutNumberMatchesPlayed'], $i);
			file_put_contents($this->config['resumeLog']['knockoutWinners'], var_export($winners, true));
		}
		if (count($winners) === 1) {
			$this->log("We have a winnerrrrr! \n##### group".reset($winners)." #####");
		} else {
			$this->runKnockoutRound($winners);
		}
	}
	
	function clearPreviousTournamentResults() {
		if (count(scandir($this->config['paths']['tournamentResults'])) > 3) {
			//larger than 3, as we always have the paths '.', '..' and '.gitignore'
			//we check this, because we get an shell err msg when the dir is empty upon removing the content
			shell_exec("rm -r ".$this->config['paths']['tournamentResults']."*");
		}
		if (file_exists($this->config['paths']['competitionLog'])) {
			shell_exec("rm ".$this->config['paths']['competitionLog']);
		}
	}
	

	
	function clearPreviousResumeData() {
		$this->log("Clearing resume log");
		foreach ($this->config['resumeLog'] AS $logFile) {
			if (file_exists($logFile)) {
				shell_exec("rm $logFile");
			}
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
	 * @param $forceWinner false: draws are possible, true: first player to win twice on 1 map wins. if still a draw, flip coins
	 * @param $stopEarly Try to stop early (i.e. when player1 has no way of catching up to player2, then stop the running of games)
	 */
	function getWinner($group1, $group2, $forceWinner = true, $stopEarly = false) {
		echo "games: ".$group1." - ".$group2."\n\t";
		$results = array(
			0 => 0, //for keeping track of draws...
			$group1 => 0,
			$group2 => 0
		);
		$this->prepareCompetitionDir($group1, $group2);
		shuffle($this->maps);
		foreach ($this->maps as $map) {
			$gameWinner = $this->runGame($group1, $group2, $map);
			echo $gameWinner." ";
			$results[$gameWinner]++;
			if ($stopEarly && $this->stopRoundsEarly($results)) {
				$this->log("no use playing on anymore. 1 player too far ahead. braeking from game look");
				break;
			}
			$gameWinner = $this->runGame($group2, $group1, $map);
			echo $gameWinner." ";
			$results[$gameWinner]++;
			if ($stopEarly && $this->stopRoundsEarly($results)) {
				$this->log("no use playing on anymore. 1 player too far ahead. braeking from game look");
				break;
			}
		}
		
		$roundWinner = 0;
		
		if ($results[$group1] === $results[$group2]) {
			$winnerFound = false;
			if ($forceWinner) {
				$this->extraGames = true;
				shuffle($this->maps);
				$this->log("playing maps consecutively now. First to win twice on 1 map wins");
				foreach ($this->maps as $map) {
					
					$gameWinner1 = $this->runGame($group1, $group2, $map);
					echo $gameWinner1." ";
					$gameWinner2 = $this->runGame($group2, $group1, $map);
					echo $gameWinner2." ";
					if ($gameWinner1 === $gameWinner2) {
						//yes!! this player won two games on this map. stop the loop, he won this round
						$roundWinner = $gameWinner1;
						echo("WINNER *** ".$gameWinner1." ***\n");
						$winnerFound = true;
						break;
					}
				}
				$this->extraGames = false;
				if (!$winnerFound) {
					//havent found a winner this way... just flip a coin
					$rand = rand(1,2);
					if ($rand === 1) {
						$roundWinner = $group1;
					} else {
						$roundWinner = $group2;
					}
					echo("DRAW. Flipping a coin, and the winner is.... ".$roundWinner."!!\n");
				}
			} else {
				//keep roundwinner as 0
				echo "DRAW. (not flipping a coin here)\n";
			}
		} else if ($results[$group1] > $results[$group2]) {
			$roundWinner = $group1;
			echo("WINNER *** ".$group1." ***\n");
		} else {
			$roundWinner = $group2;
			echo("WINNER *** ".$group2." ***\n");
		}
		return $roundWinner;
		
	}
	
	
	
	function stopRoundsEarly($results) {
		$roundsToGo = (count($this->maps) * 2);
		
		foreach ($results AS $player => $result) {
			$roundsToGo = $roundsToGo - $result;
			
		}
		if ($roundsToGo > 0) {
			unset($results[0]); //remove draws
			$maxNumWins = max($results);
			$minNumWins = min($results);
			if ($roundsToGo < ($maxNumWins - $minNumWins)) {
				//no use playing on anymore
				return true;
			} else {
				return false;
			}
		} else {
			return false;
		}
		
		
	}
	
	function prepareCompetitionDir($group1, $group2) {
		if (!strlen($group1) || !strlen($group2)) $this->error("Empty string passed as bot. No game to play..");
		$competitionDir = $this->config['paths']['competitionDir'];
		$this->emptyDir($competitionDir); //clean before using
		$this->copyFilesInDir($this->config['paths']['botsCompiled'].$group1."/", $competitionDir);
		$this->copyFilesInDir($this->config['paths']['botsCompiled'].$group2."/", $competitionDir);
		$this->copyFilesInDir($this->config['paths']['pwEngine'], $competitionDir);
		$this->copyFilesInDir($this->config['paths']['pwMaps'], $competitionDir);
	}
	
	function runGame($player1, $player2, $map) {
		$player1BotName = $this->getBotName($player1);
		$player2BotName = $this->getBotName($player2);
		if (!strlen($player1BotName)) $this->error("Unable to retrieve bot name for group ".$player1);
		if (!strlen($player2BotName)) $this->error("Unable to retrieve bot name for group ".$player2);
		
		$workingDir = getcwd();
		$resultFile = $player1."-".$player2."_".$map;
		
		$cmd = "java -jar PlayGame.jar ".$map;
		$cmd .= " \"java -Xmx" . $this->config['game']['maxMemory'] ."m ".$player1BotName."\" \"java -Xmx" . $this->config['game']['maxMemory'] ."m ".$player2BotName."\"";
		$cmd .= " parallel ".$this->config['game']['numTurns']." ".$this->config['game']['maxTurnTime'];
		
		//cannot just pipe stderr to stdout: we need seperate pipes. stderr for analyzing who won, and stdout for the visualizer
		$descriptorspec = array(
				0 => array("pipe", "r"),  // stdin
				1 => array("pipe", "w"),  // stdout
				2 => array("pipe", "w"),  // stderr
		);
		file_put_contents("lastExecutedCmd.txt", $cmd);
		chdir($this->config['paths']['competitionDir']);
		$process = proc_open($cmd, $descriptorspec, $pipes);
		$gameStatesString = stream_get_contents($pipes[1]); //stdout
		fclose($pipes[1]);
		$resultString = stream_get_contents($pipes[2]);//stderr
		fclose($pipes[2]);
		proc_close($process);
		
		chdir($workingDir);
		if (strpos($resultString, "you missed a turn!")) {
			$missedTurns = array(1 => 0, 2 => 0);
			$pattern = "/Client (\d) timeout: you missed a turn/";
			preg_match_all($pattern, $resultString, $matches);
			foreach ($matches[1] as $match) {
				$missedTurns[(int)$match]++;
			} 
			echo ("Missed Turns: P1-".$missedTurns[1]." P2-".$missedTurns[2]." ");//Client 2 timeout: you missed a turn! 
			
		}
		$winner = $this->getGameResult($resultString, $player1, $player2, $cmd, $this->config['paths']['competitionDir']);
		$this->storeGameResult($player1, $player2, $winner, $map, $gameStatesString);
		return $winner;
		
		
	}
	
	/**
	 * Store game state strings, and run visualizer script as well to get html file
	 * 
	 */
	function storeGameResult($player1, $player2, $winner, $map, $resultString) {
		$resultDir = $this->config['paths']['tournamentResults'];
		$roundDir = $resultDir.$this->round."/";
		if (!is_dir($roundDir)) mkdir($roundDir);
		
		if ($this->leagueGroup > 0) { 
			//we are in the mini league. store leagues in separate folders
			$roundDir .= $this->leagueGroup."/";
			if (!is_dir($roundDir)) mkdir($roundDir);
		}

		$batchResultsDir = $roundDir.min($player1, $player2)."-".max($player1, $player2)."/";
		
		if ($this->resume && $this->firstResumeMatch && file_exists($batchResultsDir)) {
			//the last competition run probably failed some time when playing this match. 
			//remove all results, so we don't have overlapping stuff
			shell_exec("rm -r ".$batchResultsDir);
			$this->firstResumeMatch = false;
		}
		if (!is_dir($batchResultsDir)) mkdir($batchResultsDir);
		if ($this->extraGames) {
			$batchResultsDir .= "draw/";
			if (!is_dir($batchResultsDir)) mkdir($batchResultsDir);
		}
		
		$gameFile = $batchResultsDir."w".$winner."_".$player1."-".$player2."_".$map; 
		file_put_contents($gameFile, $resultString);
		if (!$this->crashedPlayer) {
			//player crashed, we have no visualization to show...
			$visualizationDir = substr($gameFile, 0, strlen($gameFile) - 4)."/";
			if (file_exists($visualizationDir)) shell_exec("rm -r ".$visualizationDir);
			mkdir($visualizationDir);
			shell_exec("cp -r ".$this->config['paths']['visualizer']."* ".$visualizationDir);
			shell_exec("cat ".$gameFile." | python ".$visualizationDir."visualize_locally.py");
			
			$html = file_get_contents($visualizationDir."generated.htm");
			$html = str_replace("%PLAYER1%", $this->getBotName($player1), $html);
			$html = str_replace("%PLAYER2%", $this->getBotName($player2), $html);
			
			$html = str_replace("%ROUND%", "Round ".$this->round, $html);
			file_put_contents($visualizationDir."generated.htm", $html);
			
		} else {
			$this->crashedPlayer = false;
		}
	}
	
	function getGameResult($resultString, $player1, $player2, $command, $dir) {
		if (strpos($resultString, "Draw") !== false) {
			//we have a draw...
			return 0;
		} else {
			$pattern = '/.*Player (\d) Wins.*/';//:Player 1 Wins!
			preg_match($pattern, $resultString, $matches);
			$winner = (int)end($matches);
			if ($winner < 1 || $winner > 2) {
				//no winner in output...
				$pattern2 = "/.*WARNING: player (\d) crashed/";//WARNING: player \d crashed
				preg_match($pattern2, $resultString, $matches2);
				$crashedPlayer = (int)end($matches2);
				if ($crashedPlayer === 1 || $crashedPlayer === 2) {
					$this->crashedPlayer = true;
					echo (" P".($crashedPlayer == 1? $player1 : $player2)." crashed (LOSE!) ");
					if ($crashedPlayer === 1) {
						$winner = 2;
					} else {
						$winner = 1;
					}
				} else {
					$this->error("Unable to parse output results. Who is the winner?? \nCommand: ".$command."\nDir:".$dir."\nOutput: ".$resultString);
				}
				
			}
			$winner = ($winner === 1? $player1: $player2);
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
			if ($file != "." && $file != ".." && $file != ".gitignore") {
				if (is_dir($fromDir.$file)) {
					$this->error("Trying to copy directory here. What's wrong? ".$fromDir.$file);
				}
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
		echo "** ".$message." \n";
	}
	
	function error($message) {
		echo "*** ".$message." \nexiting...***\n";
		exit;
	}
	
	
	
	function loadArrayFromFile($file, $required = false) {
		$result = array();
		if ($required && !file_exists($file)) {
			$this->error("File $file does not exist. Unable to load array");
		}
		if (file_exists($file)) {
			$string = file_get_contents($file);
			if ($required && !strlen($string)) {
				$this->error("Unable to get array from file. Empty string");
			}
			eval('$result = '.$string.';');
			if (!is_array($result)) {
				$this->error("Couldnt array file. Result: ".var_export($result, true));
			}
			if (!count($result)) {
				$this->log("Parsed array from file, but its empty. File ".$file." Content: ".$string." array: ".var_export($result, true));
			}
		}
		return $result;
	}
	
	function loadIntFromFile($file, $default = null) {
		$result = $default;
		if (is_null($default) && !file_exists($file)) {
			$this->error("File $file does not exist. Unable to load int");
		}
		if (file_exists($file)) {
			$string = file_get_contents($file);
			if (is_null($default) && !strlen($string)) {
				$this->error("Empty string in file $file");
			}
			if (is_null($default) && !is_numeric($string)) {
				$this->error("tried getting integer from file, but its not numeric. result: ".$string);
			}
			$result = (int)$string;
		}
		return $result;
		
	}
}








