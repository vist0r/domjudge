#!/usr/bin/php -q
<?php
/**
 * Program to run a specific command on all judges using ssh.
 * 
 * Usage: $0 <program>
 *
 * $Id$
 */
	require ('../etc/config.php');

	define ('SCRIPT_ID', 'runssh_judges');
	define ('LOGFILE', LOGDIR.'/check.log');

	require ('../lib/init.php');

/*
	define('PHP_LIB_PATH', SYSTEM_ROOT.'/lib');
	require(PHP_LIB_PATH . '/use_db_jury.php');
	require(PHP_LIB_PATH . '/lib.error.php');
	require(PHP_LIB_PATH . '/lib.misc.php');
*/

//	exit;

	$argv = $GLOBALS['argv'];
	
	$program = @$argv[1];

	if ( ! $program ) error("No program specified");

	logmsg(LOG_DEBUG, "running program '$program'");

	$judges = $DB->q('COLUMN SELECT judgerid FROM judger');

	foreach($judges as $judge) {
		logmsg(LOG_DEBUG, "running on judge '$judge'");
		system("ssh $judge $program",$exitcode);
		if ( $exitcode != 0 ) {
			logmsg(LOG_NOTICE, "on '$judge': exitcode $exitcode");
		}
	}

	logmsg(LOG_NOTICE, "finished");

	exit;
