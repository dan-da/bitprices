<?php

require_once dirname(__FILE__) . '/mylogger.class.php';

class filesystem {

    /**
     * atomically lock a file (existing or not) in NFS safe manner.
     * The directory that the file resides in must exist and
     * app must have write access.
     *
     * see: http://stackoverflow.com/questions/218451/locking-nfs-files-in-php
     */
    static public function lock_file( $path ) {
        $lockname = self::lock_name( $path );
        
        $msg = sprintf( "Acquiring lockfile %s for file", $lockname, $path );
        mylogger()->log( $msg, mylogger::info );
        
        // we use ln -s because it will create symlink to a missing target
        // while symlink() fails in that case.
        $cmd = sprintf( 'ln -s %s %s 2>&1 > /dev/null',
                        escapeshellarg( $path ),
                        escapeshellarg( $lockname )
                      );
        
        do {
            exec( $cmd, $output, $rc );
            if( $rc == 0 ) {
                break;
            }
            usleep( 500000 );   // sleep for 1/2 second.
        } while( 1 );
        
        $msg = sprintf( "Acquired lockfile %s for file", $lockname, $path );
        mylogger()->log( $msg, mylogger::debug );
    }

    /**
     * atomically unlock lock a file (existing or not) in NFS safe manner.
     * The directory that the file resides in must exist and
     * app must have write access.
     *
     * see: http://stackoverflow.com/questions/218451/locking-nfs-files-in-php
     */
    static public function unlock_file( $path ) {
        $lockname = self::lock_name( $path );
        $delname = $lockname . '.delete';
        
        rename( $lockname, $delname );
        unlink( $delname );
        
        $msg = sprintf( "Removed lockfile %s for file", $lockname, $path );
        mylogger()->log( $msg, mylogger::info );
    }
    
    /**
     * calls file_put_contents atomically in nfs safe fashion.
     */
    static public function file_put_contents_lockex( $path, $data, $flags = 0, $context = null ) {
        
        self::lock_file( $path );
        try {
            $filebytes = file_put_contents( $path, $data, $flags, $context );
        }
        catch( Exception $e ) {
            self::unlock_file( $path );
            throw $e;
        }
        self::unlock_file( $path );        
        return $filebytes;
    }
    
    static private function lock_name( $path ) {
        return sprintf( '%s/.%s.%s.lockfile', dirname( $path ), basename( $path ), md5( $path ) );
    }   

}
