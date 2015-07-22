<?php

/***
 * A class to assist with the common task of ensuring that only one
 * instance of a script can run at a time.
 *
 * Usage:
 *   At the beginning of your script, just call:
 *      pid_util::store_pid_or_die( basename(__FILE__) );
 *
 *   or to allow one process per user:
 *      pid_util::store_pid_or_die_by_user( basename(__FILE__) );
 *   
 */
class pid_util {
    
    static $script_name;
   
    /***
     * Gets path of pidfile
     */
    static public function get_pidfile( $script_name ) {
        return sys_get_temp_dir() . '/' . $script_name . '.pid';
    }

    /***
     * stores PID or dies if PID is already stored by another script
     * that was started by the current unix user.
     */
    static public function store_pid_or_die_by_user( $my_script_name ) {
        $name = sprintf( '%s.%s', $my_script_name, `whoami` );
        return self::store_pid_or_die( $name );
    }

    
    /***
     * stores PID or dies if PID is already stored by another script.
     */
    static public function store_pid_or_die( $my_script_name ) {

        $lockfile = self::get_pidfile( $my_script_name );
        
        $pid = @file_get_contents($lockfile);
        if ( !$pid || posix_getsid($pid) === false) {
            // print "process has died! restarting...\n";
            file_put_contents($lockfile, getmypid()); // create lockfile
            
            register_shutdown_function( array( 'pid_util', 'remove_pidfile' ), $my_script_name );
           
            declare(ticks = 1);
            self::$script_name = $my_script_name;
            pcntl_signal(SIGTERM, array( 'pid_util', "signal_handler" ));
            pcntl_signal(SIGINT, array( 'pid_util', "signal_handler" ));
         
        } else {
           echo "$my_script_name PID is still alive! can not run twice! exiting.\n";
           exit;
        }
        
        return $lockfile;
    }
    
    /***
     * signal handler for SIGTERM and SIGINT
     */
    static public function signal_handler($signal) {
        self::remove_pidfile( self::$script_name );
        exit;
    }
    
    /***
     * removes the pidfile
     */
    static public function remove_pidfile( $script_name ) {
        
        @unlink( self::get_pidfile( $script_name ) );
        
    }
    
}