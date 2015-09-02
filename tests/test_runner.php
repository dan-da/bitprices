#!/usr/bin/env php
<?php

/*
 * This file implements a very basic test harness.
 */

// be safe and sane.
require_once( dirname(__FILE__) . '/../lib/strict_mode.funcs.php' );

return exit(main());

abstract class test_base {
    public $results = array();
    
    abstract public function runtests();
    
    private function backtrace() {
        ob_start();
        debug_print_backtrace();
        $trace = ob_get_contents();
        ob_end_clean();

        // Remove first item from backtrace as it's this function which
        // is redundant.
        $trace = preg_replace ('/^#0\s+' . __FUNCTION__ . "[^\n]*\n/", '', $trace, 1);

        return $trace;
    } 
    
    public function eq( $a, $b, $desc ) {
        $ok = $a == $b;
        $a = $a === null ? 'null' : $a;
        $b = $b === null ? 'null' : $b;
        $res = array( 'success' => $ok,
                      'desc' => $desc,
                      'assertion' => 'equality',
                      'result' => $ok ? "$a == $b" : "$a != $b",
                      'stack' => $this->backtrace() );
        $this->results[] = $res;
    }
    
    public function ne( $a, $b, $desc ) {
        $ok = $a != $b;
        $a = $a === null ? 'null' : $a;
        $b = $b === null ? 'null' : $b;
        $res = array( 'success' => $ok,
                      'desc' => $desc,
                      'assertion' => 'inequality',
                      'result' => $ok ? "$a != $b" : "$a == $b",
                      'stack' => $this->backtrace() );
        $this->results[] = $res;
    }

    public function gt( $a, $b, $desc ) {
        $ok = $a > $b;
        $a = $a === null ? 'null' : $a;
        $b = $b === null ? 'null' : $b;
        $res = array( 'success' => $ok,
                      'desc' => $desc,
                      'assertion' => 'greatherthan',
                      'result' => $ok ? "$a > $b" : "$a <= $b",
                      'stack' => $this->backtrace() );
        $this->results[] = $res;
    }

    public function lt( $a, $b, $desc ) {
        $ok = $a < $b;
        $a = $a === null ? 'null' : $a;
        $b = $b === null ? 'null' : $b;
        $res = array( 'success' => $ok,
                      'desc' => $desc,
                      'assertion' => 'greatherthan',
                      'result' => $ok ? "$a < $b" : "$a >= $b",
                      'stack' => $this->backtrace() );
        $this->results[] = $res;
    }
}

class test_printer {

    static public function print_status( $testname ) {
        echo "Running tests in $testname...\n";
    }
    
    static public function print_results( $results ) {
        $pass_cnt = 0;
        $fail_cnt = 0;
        foreach( $results as $r ) {
            if( $r['success'] ) {
                echo sprintf( "[pass] %s  |  %s\n", $r['result'], $r['desc'] );
                $pass_cnt ++;
            }
            else {
                echo sprintf( "[fail] %s  |  %s\n%s\n\n", $r['result'], $r['desc'], $r['stack'] );
                $fail_cnt ++;
            }
        }
    
        echo "\n\n";    
        echo sprintf( "%s tests passed.\n", $pass_cnt );
        echo sprintf( "%s tests failed.\n", $fail_cnt );
        echo "\n\n";    
    }
}

class bitpricescmd {
    
    // note: api_args should be set by main() based on config file or prog args.
    static public $api_args = " --api=btcd --btcd-rpc-host='127.0.0.1' --btcd-rpc-port='38334' --btcd-rpc-user='btcd' --btcd-rpc-pass='btcdpass'";
    
    static public function run( $args, $expect_rc=0 ) {
        $cmd = sprintf( "%s %s", dirname(__FILE__) . "/../bitprices.php", $args );
        exec( $cmd, $output, $rc );
        
        if( $rc != $expect_rc ) {
            throw new exception( "bitprices command failed.  expecting rc=$expect_rc, got rc=$rc\nCommand was: $cmd\n\n" );
        }
        
        $output = implode( "\n", $output );
        return $output;
    }    
    
    static public function runjson( $args, $expect_rc=0 ) {
        $outfile = tempnam( sys_get_temp_dir(), 'bpt' );
        $args .= self::$api_args . sprintf( " --format=json --outfile=%s", $outfile );
        self::run( $args, $expect_rc );        
        $buf = file_get_contents( $outfile );
        unlink( $outfile );
        
        return json_decode( $buf, true );
    }
}


function main() {
    
    $tests = glob( dirname(__FILE__) . '/*.test.php' );

    $results = array();    
    foreach( $tests as $test ) {
        require( $test );
        $classname = basename( $test, '.test.php' );

        test_printer::print_status( $classname );
        $testobj = new $classname();
        $testobj->runtests();
        
        $results = array_merge( $results, $testobj->results );
    }
    
    test_printer::print_results( $results );
}


?>