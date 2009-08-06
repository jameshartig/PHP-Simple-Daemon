#!/usr/bin/php -q 
<?php 
error_reporting(E_ALL); //report all errors
set_time_limit(0); //run forever
declare(ticks = 1); 

// TRAILING SLASHES REQUIRED
$conf['masterdir'] = "/home/james/PHP-Simple-Daemon/"; //the folder in which this file exists
$conf['piddir'] = "/home/james/PHP-Simple-Daemon/"; //a folder that contains PIDs (must be 777)
$conf['logfile'] = $conf['masterdir']."server.log"; //blank ("") for no logging
// you need super-user or root privleges to change the uid and gid
$conf['uid'] = ""; //the UID of the daemon to run (blank for no change)
$conf['gid'] = ""; //group ID of the daemon to run (blank for no change)
$conf['IP'] = "0.0.0.0"; // IP for the daemon to run on
$conf['port'] = 16644; //port to run daemon on
$conf['timeout'] = 5; //seconds to wait with NO activity until client is closed
$conf['welcome'] = "\nWelcome to my PHP Daemon.\nTo end your session, type 'end'.\n"; //welcome message
$conf['login'] = array('user' => 'pass', 'user2' => 'pass2');

// here is your function:
// it is called whenever someone enters text
function userfunc($sent){
		$msg = "You said '$sent'.\n";
		socket_write($sock, $msg, strlen($msg));
}


$__mpid = daemonme(); //store the master pid

//set the gid first
if (!empty($conf['gid'])){
if( !posix_setgid($conf['gid']) ){ 
  file_put_contents($conf['logfile'], "[".posix_getpid()."] Unable to setgid!\n", FILE_APPEND); 
  echo "[".posix_getpid()."] Unable to setgid!\n";
  exit(0); 
}
}
//set the uid
if (!empty($conf['uid'])){
if( !posix_setuid($conf['uid']) ){ 
  file_put_contents($conf['logfile'], "[".posix_getpid()."] Unable to setuid!\n", FILE_APPEND); 
  echo "[".posix_getpid()."] Unable to setuid!\n";
  exit(0); 
}
}

//make sure log is writable
if (!is_writable($conf['logfile']) && !is_writable(substr($conf['logfile'], 0, -1*strlen(basename($conf['logfile']))))){
	echo "[".posix_getpid()."] Log File and Directory are not writable\n";
	clearstatcache(); //cleanup
	exit(0);
}

//make sure pids is writable
if (!is_writable($conf['piddir'])){
	echo "[".posix_getpid()."] PID Directory is not writable\n";
	clearstatcache(); //cleanup
	exit(0);
}

clearstatcache(); //cleanup

//define global vars
$__list = true;
$__gsoc = false;
$__csoc = false;

//handle system calls and send to signal()
pcntl_signal(SIGTERM, 'signal'); 
pcntl_signal(SIGINT, 'signal'); 
pcntl_signal(SIGCHLD, 'signal');
pcntl_signal(SIGHUP, "signal");

//start socket

file_put_contents($conf['logfile'], "\n****************\n* Daemon Start *\n****************\nTime: ".date("r")."\n", FILE_APPEND); 

$__gsoc = begin($conf['IP'], $conf['port']);

//daemonize
function daemonme(){ 
    $pid = pcntl_fork();    
    if ($pid == -1){ 
        file_put_contents($conf['logfile'],  "[".posix_getpid()."] initial fork failure!\n", FILE_APPEND);
		echo "[".posix_getpid()."] initial fork failure!";
        exit(); 
    }elseif ($pid){ // we are in parent
        exit(0); 
    }else{ 
        posix_setsid(); 
        chdir('/');
        umask(0);				
		sdClients(false); // remove all current pids		
        return posix_getpid();
    } 
} 

//starts socket and begins
function begin($ip, $p){ 
    global $__list, $__gsoc, $__mpid, $conf; 

	//create socket
    if(($__gsoc = socket_create(AF_INET, SOCK_STREAM, 0))===false){ 
		file_put_contents($conf['logfile'], "[".posix_getpid()."] failed to create socket: ".socket_strerror(socket_last_error())."\n", FILE_APPEND);
		echo "[".posix_getpid()."] failed to create socket: ".socket_strerror(socket_last_error())."\n";
        exit(0); 
    }
	
    // reuse socket if its already open (prevents errors on restart)
    if(socket_set_option($__gsoc, SOL_SOCKET, SO_REUSEADDR, 1)===false){
		file_put_contents($conf['logfile'], "[".posix_getpid()."] failed to set SO_REUSEADDR socket: ".socket_strerror(socket_last_error())."\n", FILE_APPEND);
		echo "[".posix_getpid()."] failed to set SO_REUSEADDR socket: ".socket_strerror(socket_last_error())."\n";
        exit(0); 
    } 

	// bind socket to IP and port
    if(@socket_bind($__gsoc, $ip, $p)===false){ 
		file_put_contents($conf['logfile'], "[".posix_getpid()."] failed to bind socket: ".socket_strerror(socket_last_error())."\n", FILE_APPEND);
		echo "[".posix_getpid()."] failed to bind socket: ".socket_strerror(socket_last_error())."\n";
        exit(0); 
    } 

	// start listening with no backlog
    if(socket_listen($__gsoc, 0)===false){ 
		file_put_contents($conf['logfile'], "[".posix_getpid()."] failed to listen to socket: ".socket_strerror(socket_last_error())."\n", FILE_APPEND);
		echo "[".posix_getpid()."] failed to listen to socket: ".socket_strerror(socket_last_error())."\n";
        exit(0); 
    }
	//set the socket to non-blocking mode
    socket_set_nonblock($__gsoc); 

	file_put_contents($conf['logfile'], "[".posix_getpid()."] daemon connected and waiting...\n", FILE_APPEND);
	//echo "[".posix_getpid()."] daemon connected and waiting...\n";
	
    while ($__list){ 
        $conn = @socket_accept($__gsoc); 
        if ($conn === false)
            usleep(100); 
        elseif ($conn > 0)
            handle_client($__gsoc, $conn); 
        else{ 
            file_put_contents($conf['logfile'], "[".posix_getpid()."] socket_accept() error: ".socket_strerror(socket_last_error()), FILE_APPEND); 
            exit(0); 
        } 
    }
    return $sock;
}

//shutdown clients
function sdClients ($b=true){
	global $conf;
    $hand = opendir($conf['piddir']);
    while ($f = readdir($hand)) {
        if ($f!='.' && $f!='..' && substr($f,-4)==".pid"){
			if($b===true) file_put_contents($conf['logfile'], "[".posix_getpid()."]  killed client PID:".basename($file, ".pid")."\n", FILE_APPEND);
            unlink($conf['piddir'].$file);
        }
    }
    closedir($hand);
	unset($f, $hand);
}

//handle system signals
function signal($sig) {
	global $__list, $__gsoc, $__mpid, $conf;
    switch($sig){
        case SIGTERM: //shutdown 
        case SIGINT:
			if (posix_getpid() == $__mpid){ // if we are the master
				file_put_contents($conf['logfile'], "[".posix_getpid()."] server shutdown...!\n", FILE_APPEND);
				sdClients();
				sleep(1); //hold on
				$__list = false;
			    socket_shutdown($__gsoc, 2); //force end reading and writing
			    socket_close($__gsoc);
				file_put_contents($conf['logfile'], "[".posix_getpid()."] disconnected!\n", FILE_APPEND);
				unset($__gsoc);
				exit(0);
			}else{ // we are in a client
				//delete pid file				
				unlink($conf['piddir'].posix_getpid().".pid");
				exit(0);
			}			
        break;			
		case SIGHUP: //restart
			if (posix_getpid() == $__mpid){ // if we are the master
				file_put_contents($conf['logfile'], "[".posix_getpid()."] server restarting...!\n", FILE_APPEND);
				sdClients();
				sleep(1); //hold on
				$__list = false;
			    socket_shutdown($__gsoc, 2); //force end reading and writing
			    socket_close($__gsoc);
				file_put_contents($conf['logfile'], "[".posix_getpid()."] disconnected and new process being started!\n", FILE_APPEND);
				unset($__gsoc);	
				exec('bash -c "exec nohup setsid '.__FILE__.' > /dev/null 2>&1 &"'); //non-blocking exec (thanks to @miorel)
				//executes subprocess in null environment...
				//file_put_contents($conf['logfile'], "new process created, shutdown!\n", FILE_APPEND);	
				exit(0);
			}else //we are in client, just close, no restart possible						
				exit(0);
		break;
        case SIGCHLD: //child process terminated
            pcntl_waitpid(-1, $s); // wait for child to close and continue
        break; 
    } 
} 

//handle a client connection, fork and continue
function handle_client($gsoc, $__csoc){ 
    GLOBAL $__list, $__mpid, $conf; 
    $pid = pcntl_fork(); // we could use the pid later?	
    if ($pid == -1){ 
		file_put_contents($conf['logfile'], "[".posix_getpid()."] handle_client() fork failure!\n", FILE_APPEND);
        exit(0); 
    }elseif ($pid == 0){ // we are in the child        
        $__list = false;
        socket_close($gsoc);
		file_put_contents($conf['piddir'].posix_getpid().".pid", "true", LOCK_EX); //store pid file
		cont($__csoc); // start talking with server
	 	socket_shutdown($__csoc, 2);
        socket_close($__csoc);
		//delete pid file
		@unlink($conf['piddir'].posix_getpid().".pid");
		exit(0);
    }else{ 
        socket_close($__csoc);
    }	
} 

//take client and interact
function cont($sock){
	global $__mpid, $conf;
	
    $msg = $conf['welcome'];
	if(!empty($msg))
		socket_write($sock, $msg, strlen($msg));
	unset($msg);
	
	$usr = false; //var for user login
	$pas = false; //var for password
	$logn = false; //login mode
	$admn = false;	//if user is admin
	$shutdown = false; //shutdown
	$idl = false;
	$time = time(); //get inital time
	
	do {
		$chg = 0;
		$buf = "";
		$chg = socket_select($read=array($sock), $write=null, $except=null, 0, 250); //wait 250 ms
	    if ($chg>0) {
	  		if (false === ($buf = socket_read($sock, 2048, PHP_NORMAL_READ))) {
				file_put_contents($conf['logfile'], "[".posix_getpid()."] socket_read() error: " . socket_strerror(socket_last_error()) . "\n", FILE_APPEND);
				break;
			}
		}elseif($chg===false){//something went wrong
			file_put_contents($conf['logfile'], "[".posix_getpid()."] socket_select() error: " . socket_strerror(socket_last_error()) . "\n", FILE_APPEND);
			break;
		}
		unset($chg);
		
		$buf = trim($buf);
		$sbuf = strtolower($buf);
		if (!empty($buf)){
			$time = time(); //reset time
			switch($sbuf){ 
				case 'login':
					$logn = true;
					$msg = "User: ";
					$usr = true;
					socket_write($sock, $msg, strlen($msg));
					break;
				case 'quit':
				case 'end':
					unset($logn, $msg, $usr, $buf, $idl, $admn, $shutdown);
					break 2; // break out were done
				case 'shutdown':
					if ($admn===true){
						$msg = "Shutting down master server!\nMaster PID: $__mpid\n";
						socket_write($sock, $msg, strlen($msg));
						posix_kill($__mpid, SIGTERM); //send shutdown signal
						$shutdown = true;
						unset($logn, $msg, $usr, $buf, $idl, $admn);
						break 2;
					}else{
						$msg = "Woah! Who said you could do that?\n";
						socket_write($sock, $msg, strlen($msg));
						break 2;
					}
				case 'process list':
					if ($admn===true){
						$msg = "List Of Running PIDs\nMaster PID: $mpid\n";
						$hand = opendir($conf['piddir']);
						while ($f = readdir($hand)) {
							if ($f!='.' && $f!='..' && substr($f, -4)==".pid")
								$msg .= basename($f, ".pid")."\n";
						}
						closedir($hand);						
						socket_write($sock, $msg, strlen($msg));	
						unset($f, $msg, $hand);
					}else{
						$msg = "Woah! Who said you could do that?\n";
						socket_write($sock, $msg, strlen($msg));
					}
					break;
				case 'restart':			
					if ($admn===true){
						$msg = "Restarting master server!\nMaster PID: $__mpid\n";						
						socket_write($sock, $msg, strlen($msg));
						posix_kill($__mpid, SIGHUP); //send restart signal
						$shutdown = true;
						unset($logn, $msg, $usr, $buf, $idl, $admn);
						break 2;
					}else{
						$msg = "Woah! Who said you could do that?\n";
						socket_write($sock, $msg, strlen($msg));
						break 2;
					}
				default:
					if ($logn===true){
						$logn = false; //prevent looping
						if ($usr===true){
							$usr = false; //prevent looping
							if (empty($buf)){
								$msg = "\nInvalid User\n";
								socket_write($sock, $msg, strlen($msg));
								break;
							}else{
								$usr = $buf;
								$msg = "Pass: ";
								$pas = true;
								$logn = true;
								socket_write($sock, $msg, strlen($msg));
								break;
							}
						}
						if ($pas===true){
							if (isset($conf['login'][$usr]) && $buf==$conf['login'][$usr]){
								$msg = "\n**************\nWelcome $usr!\n**************\n";
								socket_write($sock, $msg, strlen($msg));
								//we are done with login so make all false
									$usr = false;
									$pas = false;
									$logn = false;
								$admn = true;								
								break;
							}
							//we are done with login so make all false
								$usr = false;
								$pas = false;
								$logn = false;
							$msg = "**Invalid User/Pass**\n\n";
							socket_write($sock, $msg, strlen($msg));
							break;
						}
					}else{
						// this is where the user can define functions, etc
						userfunc($buf);
					}
					break;
			}
		}else{ // buf is empty
			if (time()-$time > $conf['timeout']){ //uh oh user has timed out
				$idl = true;
				break;
			}
		}
		unset($msg);
	} while (file_exists($conf['piddir'].posix_getpid().".pid"));
	
	if (!file_exists($conf['piddir'].posix_getpid().".pid") && isset($shutdown) && $shutdown === false){ //we were shutdown!
		$msg = "Remote server shutdown...!\n";
		socket_write($sock, $msg, strlen($msg));
		return;
	}	
	if (isset($idl) && $idl===true){ //idle process
		$msg = "**Idle Process**\n";
		socket_write($sock, $msg, strlen($msg));
		return;
	}
	return;
} 

?>