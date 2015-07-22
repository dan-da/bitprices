<?php

require_once( dirname(__FILE__) . '/mylogger.class.php' );

/***
 * A class to simplify and unify execution of shell commands.
 * Please use this instead of shell_exec, exec, ``, passthru, etc.
 */
class shellexec {

    /***
     * Executes a command and returns the output, with stdout and stderr merged.
     * The command is logged.
     * The duration time of the command is logged.
     * throws an exception if command return code is not 0.
     * Callers can catch the exception to obtain the return code.
     */
    static function exec( $cmd ) {
        
        $output = self::exec_rc( $cmd, $rc );
    
        if( $rc != 0 ) {
            throw new Exception( sprintf( "Received non-zero exit code %s from sub-command",$rc), $rc );
        }
        
        return $output;
    }

    
    /***
     * Executes a command and returns the output, with stdout and stderr merged.
     * The command is logged.
     * The duration time of the command is logged.
     * The return code is available in $rc, pass by reference.
     *
     * @todo re-implement using proc_open to enable passthru behavior.    
     */    
    static function exec_rc( $cmd, &$rc ) {
    
        $cmd .= " 2>&1";   // merge stdout/stderr for every command.
        mylogger()->log(sprintf( "Executing command: %s\n",  $cmd ), mylogger::info);
    
        $start = microtime(true);
        exec( $cmd, $output, $rc );
        $end = microtime(true);
    
        $duration = $end - $start;
        mylogger()->log( sprintf( "    ------> took %s seconds.  return code = %s \n", $duration, $rc ), mylogger::info ); 
    
        $output = implode( "\n", $output );
        if( strlen( trim($output) ) ) {
            mylogger()->log( sprintf( "    ------> output was: \n%s\n", $output ), mylogger::info );
        }
        
        return $output;
    }
    
    static function passthru( $cmd ) {

        $cmd .= " 2>&1";   // merge stdout/stderr for every command.
        mylogger()->log(sprintf( "Executing passthru command: %s\n",  $cmd ), mylogger::info);

        $start = microtime(true);
        passthru( $cmd, $rc );
        $end = microtime(true);        

        if( $rc != 0 ) {
            throw new Exception( sprintf( "Received non-zero exit code %s from sub-command",$rc), $rc );
        }
        
        $duration = $end - $start;
        mylogger()->log( sprintf( "    ------> took %s seconds.  return code = %s \n", $duration, $rc ), mylogger::info ); 
        
        return $rc;        
    }
    
}