#!/usr/bin/env php
<?php

require_once dirname(__FILE__) . '/lib/strict_mode.funcs.php';
require_once dirname(__FILE__) . '/lib/mylogger.class.php';
require_once dirname(__FILE__) . '/lib/mysqlutil.class.php';
require_once dirname(__FILE__) . '/lib/httputil.class.php';
require_once dirname(__FILE__) . '/lib/html_table.class.php';
require_once dirname(__FILE__) . '/lib/bitcoin-php/bitcoin.inc';

require_once dirname(__FILE__) . '/lib/validator/AddressValidator.php';
use \LinusU\Bitcoin\AddressValidator;

require_once dirname(__FILE__) . '/lib/blockchain_api.php';

define( 'SATOSHI', 100000000 );

// no global scope execution past this point!
exit( main( $argv ) );


/**
 * Our main function.  It only performs top-level exception handling.
 */
function main( $argv ) {
    ini_set('memory_limit', -1 );

    $worker = new bitprices();
    try {
        return $worker->run( $argv );
    }
    catch( Exception $e ) {
        mylogger()->log_exception( $e );
        
        // print validation errors to stderr.
        if( $e->getCode() == 2 ) {
            fprintf( STDERR, $e->getMessage() . "\n\n" );
        }
        return $e->getCode() ?: 1;
    }
}

class bitprices {

    // where all the work starts and ends.
    public function run( $argv ) {
        $params = $this->get_cli_params();
        
        $rc = $this->process_cli_params( $params );
        if( $rc != 0 ) {
            return $rc;
        }
        $params = $this->get_params();
        $format = $params['format'];
        $report_type = $params['report-type'];
        
        date_default_timezone_set("UTC");
        
        $start = microtime( true );
        
        $addrs = $this->get_addresses();
        $results = $this->process_addresses( $addrs );
        
        switch( $report_type ) {
            case 'schedule_d': $rows = $this->gen_report_schedule_d( $results, $format );  break;
            default: $rows = $this->gen_report_tx( $results, $format ); break;
        }

        $this->print_results( $rows, $format );
        
        $end = microtime(true);
        $duration = $end - $start;
        echo "\nExecution time: $duration seconds\n\n";
        
    }    

    // returns the CLI params, exactly as entered by user.
    protected function get_cli_params() {
        $params = getopt( 'g', array( 'date-start:', 'date-end:',
                                      'addresses:', 'addressfile:',
                                      'direction:', 'currency:',
                                      'cols:', 'outfile:',
                                      'format:', 'logfile:',
                                      'toshi:', 'toshi-fast',
                                      'addr-tx-limit:', 'testnet',
                                      'btcd-rpc-host:', 'btcd-rpc-port:',
                                      'btcd-rpc-user:', 'btcd-rpc-pass:',
                                      'api:', 'insight:',
                                      'list-templates', 'list-cols',
                                      'report-type:', 'cost-method:',
                                      ) );        

        return $params;
    }

    // processes and normalizes the CLI params. adds defaults
    // and ensure each value is set.
    protected function process_cli_params( $params ) {
        
        if( @$params['logfile'] ) {
            mylogger()->set_log_file( $params['logfile'] );
            mylogger()->echo_log = false;
        }
        
        if( !@$params['api'] ) {
            $params['api'] = 'toshi';
        }

        if( !@$params['report-type'] ) {
            $params['report-type'] = 'tx';
        }
        
        if( !@$params['cost-method'] ) {
            $params['cost-method'] = 'fifo';
        }
        
        if( !@$params['insight'] ) {
            $params['insight'] = 'https://insight.bitpay.com';
        }
        
        $params['toshi-fast'] = isset($params['toshi-fast']);
        $params['testnet'] = isset($params['testnet']);
        
        if( !@$params['toshi'] ) {
            $params['toshi'] = 'https://bitcoin.toshi.io';
        }

        if( !@$params['btcd-rpc-host'] ) {
            $params['btcd-rpc-port'] = '127.0.0.1';  // use localhost
        }
        
        if( !@$params['btcd-rpc-port'] ) {
            $params['btcd-rpc-port'] = 8334;  // use default port.
        }

        if( !@$params['addr-tx-limit'] ) {
            $params['addr-tx-limit'] = 1000;
        }
        
        $params['direction'] = @$params['direction'] ?: 'both';
        if( !in_array( @$params['direction'], array( 'in', 'out', 'both' ) ) ) {
            $params['direction'] = 'both';
        }
        
        $params['date-start'] = @$params['date-start'] ? strtotime($params['date-start']) : 0;
        $params['date-end'] = @$params['date-end'] ? strtotime($params['date-end']) : time();
        
        $params['currency'] = strtoupper( @$params['currency'] ) ?: 'USD';
        
        $params['format'] = @$params['format'] ?: 'txt';


        // note: get_cols internally calls get_params and uses params[currency]
        $this->params = $params;        
        $params['cols'] = $this->get_cols( @$params['cols'] ?: 'standard' );
        
        $this->params = $params;
        
        if( isset( $params['list-templates'] ) ) {
            $this->print_list_templates();
            return 2;
        }
        if( isset( $params['list-cols'] ) ) {
            $this->print_list_cols();
            return 2;
        }        
        if( !isset($params['g']) ) {
            $this->print_help();
            return 1;
        }
        if( !@$params['addresses'] && !@$params['addressfile'] ) {
            $this->print_help();
            return 1;
        }
        
        return 0;
    }

    // returns the normalized CLI params, after initial processing/sanitization.
    protected function get_params() {
        return $this->params;
    }

    // obtains the BTC addresses from user input, either via the
    // --addresses arg or the --addressfile arg.
    protected function get_addresses() {
        $params = $this->get_params();
        
        $list = array();
        if( @$params['addresses'] ) {
            $list = explode( ',', $this->strip_whitespace( $params['addresses'] ) );
        }
        if( @$params['addressfile'] ) {
            $csv = implode( ',', file( @$params['addressfile'] ) );
            $list = explode( ',', $this->strip_whitespace( $csv ) );
        }
        foreach( $list as $idx => $addr ) {
            if( !$addr ) {
                unset( $list[$idx] );
                continue;
            }
            $version = $params['testnet'] ? AddressValidator::TESTNET : AddressValidator::MAINNET;
            if( !AddressValidator::isValid( $addr, $version ) ) {
                // code 2 means an input validation exception.
                throw new Exception( "Bitcoin address $addr is invalid", 2 );
            }
        }
        if( !count( $list ) ) {
            throw new Exception( "No valid addresses to process." );
        }
        return $list;
    }

    // returns a key/val array of available column template definitions.
    // column templates are simply named lists of columns that should appear
    // in the tx report.
    protected function get_col_templates() {
        $all_cols = implode( ',', array_keys( $this->all_columns() ) );
        $map = array(
            'standard' => array( 'desc' => "Standard report", 'cols' => 'date,addrshort,btcamount,btcbalance,price,fiatamount,fiatbalance,pricenow,realizedgainfifo,unrealizedgain' ),
            'gainloss' => array( 'desc' => "Unrealized Gains and Losses", 'cols' => 'date,fiatamount,pricenow,fiatgain,fiatgaintotal' ),
            'gainlossfifo' => array( 'desc' => "Gains and Losses (FIFO Method)", 'cols' => 'date,btcamount,fiatamount,realizedgainfifoshort,realizedgainfifolong,unrealizedgainfifo' ),
            'gainlosslifo' => array( 'desc' => "Gains and Losses (LIFO Method)", 'cols' => 'date,btcamount,fiatamount,realizedgainlifoshort,realizedgainlifolong,unrealizedgainlifo' ),
            'gainlossavgperiodic' => array( 'desc' => "Gains and Losses ( Avg. Cost Method, Periodic)", 'cols' => 'date,btcamount,fiatamount,realizedgainavgperiodic,unrealizedgainavgperiodic' ),
            'thenandnow' => array( 'desc' => "Then and Now", 'cols' => 'date,price,fiatamount,pricenow,fiatamountnow,fiatgain,fiatgaintotal' ),
            'inout' => array( 'desc' => "Standard report with Inputs and Outputs", 'cols' => 'date,addrshort,btcamount,btcbalance,price,fiatamount,fiatbalance,pricenow,fiatgaintotal' ),
            'dev' => array('desc' => "For development", 'cols' => 'date,btcin,btcout,btcbalance,fiatin,fiatout,fiatbalance,price,btcamount,fiatamount,fiatgain,fiatgaintotal,realizedgainfifo,realizedgainlifo,realizedgainavgperiodic,unrealizedgain' ),
            'all' => array( 'desc' => "All available columns", 'cols' => $all_cols ),
        );
        foreach( $map as $k => $info ) {
            $map[$k]['cols'] = explode( ',', $info['cols'] );
        }
        return $map;
    }
    
    // parses the --cols argument and returns an array of columns.
    // note that the --cols argument accepts either:
    //   a csv list of columns   -- or --
    //   a template name + csv list of columns.
    // For the latter case, the template name is expanded to a column list.
    protected function get_cols( $arg ) {
        $arg = $this->strip_whitespace( $arg );
        
        $templates = $this->get_col_templates();

        $parts = explode( ',', $arg, 2 );
        $report = $parts[0];
        $extra = @$parts[1];
        if( @$templates[$report] ) {
            $arg = implode(',', $templates[$report]['cols']);
            if( $extra ) {
                $arg .= ',' . $extra;
            }
        }
        return explode( ',', $arg );
    }

    // removes whitespace from a string    
    protected function strip_whitespace( $str ) {
        return preg_replace('/\s+/', '', $str);
    }

    // a function to append strings and add newlines+indent as necessary.
    // TODO: save elsewhere. no longer used.
    public function str_append_indent( $str, $append, $prefix, $maxlinechr = 80 ) {
        $lines = explode( "\n", $str );
        $lastline = $lines[count($lines)-1];
        $exceeds = strlen($lastline) + strlen($append) > $maxlinechr;
        $str .= $exceeds ? ("\n" . $prefix . $append) : $append;
        return $str;
    }
    
    // prints help text for --list-templates
    // note: output is pretty JSON for both human and machine readability.
    public function print_list_templates() {
        $tpl = $this->get_col_templates();
        echo json_encode( $tpl, JSON_PRETTY_PRINT )  . "\n\n";
    }

    // prints help text for --list-cols
    // note: output is pretty JSON for both human and machine readability.
    public function print_list_cols() {
        $map = $this->all_columns();
        echo json_encode( $map, JSON_PRETTY_PRINT ) . "\n\n";
    }

    // prints CLI help text
    public function print_help() {
         
        $buf = <<< END

   bitprices.php

   This script generates a report of transactions with the USD value
   at the time of each transaction.

   Options:

    -g                   go!
    
    --addresses=<csv>    comma separated list of bitcoin addresses
    --addressfile=<path> file containing bitcoin addresses, one per line.
    
    --api=<api>          toshi|btcd|insight.   default = toshi.
    
    --direction=<dir>    transactions in | out | both   default = both.
    
    --date-start=<date>  Look for transactions since date. default = all.
    --date-end=<date>    Look for transactions until date. default = now.
    
    --currency=<curr>    symbol supported by bitcoinaverage.com.  default = USD.
    
    --report-type=<type> schedule_d | tx.   default=tx
                           options --cols, --list-templates, --list-cols
                              apply to tx report only.
                           option  --cost-method applies to schedule_d report
                              only.
                              
    --cost-method=<m>    fifo|lifo|avg. schedule_d report only.  default = fifo.
    
    --cols=<cols>        a report template or list of columns. default=standard.
                         See --list-cols
                         
    --list-templates     if present, a list of templates will be printed.
    --list-cols          if present, a list of columns will be printed.
                                
    --outfile=<path>     specify output file path.
    --format=<format>    txt|csv|json|jsonpretty|html|all     default=txt
    
                         if all is specified then a file will be created
                         for each format with appropriate extension.
                         only works when outfile is specified.
                         
    --toshi=<url>       toshi server. defaults to https://bitcoin.toshi.io
    --toshi-fast        if set, toshi server supports filtered transactions.
    
    --btcd-rpc-host=<h> btcd rpc host.  default = 127.0.0.1
    --btcd-rpc-port=<p> btcd rpc port.  default = 8334
    --btcd-rpc-user=<u> btcd rpc username.
    --btcd-rpc-pass=<p> btcd rpc password.
    
    --insight=<url>     insight server. defaults to https://insight.bitpay.com
    
    --addr-tx-limit=<n> per address transaction limit. default = 1000
    --testnet           use testnet. only affects addr validation.


END;

   fprintf( STDERR, $buf );       
        
    }

    // retrieves transactions and price data for one or more bitcoin addresses.
    protected function process_addresses( $addrs ) {
        
        $trans = $this->get_matching_transactions( $addrs );
        
        $params = $this->get_params();
        $currency = $params['currency'];

        $results = array();
        foreach( $trans as $tx ) {
            $tx['exchange_rate'] = $this->get_historic_price( $currency, $tx['block_time'] );
            if( !$tx['exchange_rate'] ) {
                throw new Exception( sprintf( "Could not find %s exchange rate for date '%s'", $currency, date('Y-m-d', $tx['block_time'] ) ) ); 
            }
            $tx['exchange_rate_now'] = $this->get_24_hour_avg_price_cached( $currency );
            if( !$tx['exchange_rate_now'] ) {
                throw new Exception( sprintf( "Could not find %s exchange rate for date '%s'", $currency, date('Y-m-d') ) ); 
            }
            
            $tx['fiat_amount_in'] = btcutil::int_to_btc( $tx['amount_in'] * $tx['exchange_rate'] );
            $tx['fiat_amount_out'] = btcutil::int_to_btc( $tx['amount_out'] * $tx['exchange_rate'] );
            
            $tx['fiat_amount_in_now'] = btcutil::int_to_btc( $tx['amount_in'] * $tx['exchange_rate_now'] );
            $tx['fiat_amount_out_now'] = btcutil::int_to_btc( $tx['amount_out'] * $tx['exchange_rate_now'] );
            
            $tx['fiat_currency'] = $currency;
            $results[] = $tx;
        }
        
        return $results;
    }
    
    /**
     * queries a blockchain api provider to obtain historical transactions for
     * list of input addresses.
     */
    protected function get_matching_transactions( $addrs ) {
        $params = $this->get_params();
        
        $api = blockchain_api_factory::instance( $params['api'] );
        $tx_list = $api->get_addresses_transactions( $addrs, $params );
//        print_r( $tx_list ); exit;
        
        return $tx_list;
    }

    // returns price for currency on UTC date of $timestamp
    protected function get_historic_price( $currency, $timestamp ) {
        
        $date = gmdate( 'Y-m-d', $timestamp );
        
        $map = self::get_historic_prices( $currency );
        $price = @$map[$date];
        
        // if date is today, then get 24 hour average.
        if( !$price && time() - $timestamp < 86400 ) {
            return $this->get_24_hour_avg_price_cached( $currency );
        }
        
        return $price;
    }

    // retrieves the 24 hour avg price from cache if present and not stale.
    // stale is defined as 1 hour.
    protected function get_24_hour_avg_price_cached( $currency ) {
        static $prices = array();

        $price = @$prices[$currency];
        if( false && $price ) {
            return $price;
        }

        $fname = dirname(__FILE__) . sprintf( '/price_24/24_hour_avg_price.%s.csv', $currency );
        $max_age = 60 * 60;  // max 1 hour.
// echo "age: " . time() - filemtime( $fname ) . "\n";
        $cache_file_valid = file_exists( $fname ) && time() - filemtime( $fname ) < $max_age;

        // use cached price file if file age is less than max_age
        if( $cache_file_valid ) {
            $price = unserialize( file_get_contents( $fname ) );
            $prices[$currency] = $price;
            return $price;
        }

        $dir = dirname( $fname );
        file_exists($dir) || mkdir( $dir );

        $price = $this->get_24_hour_avg_price( $currency );
        
        @unlink( $fname );
        file_put_contents( $fname, serialize($price) );
//        echo "wrote: $fname\n";

        $prices[$currency] = $price;

        return $price;
    }

    // retrieves the avg price for currency during the past 24 hours.
    // TODO: abstract for multiple providers.    
    protected function get_24_hour_avg_price( $currency ) {

        $url_mask = 'https://api.bitcoinaverage.com/ticker/global/%s/';
        $url = sprintf( $url_mask, strtoupper( $currency ) );
        
        mylogger()->log( "Retrieving $currency 24 hour average price from bitcoinaverage.com", mylogger::info );
        $buf = file_get_contents( $url );
        $data = json_decode( $buf, true );
        return (int)($data['24h_avg'] * 100);
    }

    // retrieves all historic prices for $currency, from cache if present and
    // not stale.  stale is defined as older than 12 hours.
    // TODO: abstract for multiple providers.    
    protected static function get_historic_prices($currency) {
        
        static $maps = array();
        static $downloaded_map = array();
        
        $map = @$maps[$currency];
        if( $map ) {
            return $map;
        }

        // if we already downloaded this run, then abort.
        $downloaded = @$downloaded_map[$currency];
        if( $downloaded ) {
            return null;
        }
        
        $fname = dirname(__FILE__) . sprintf( '/price_history/per_day_all_time_history.%s.csv', $currency );
        $file_age = time() - filemtime( $fname );
        
        if( !file_exists( $fname ) || $file_age > 60*60*12 ) {
            $dir = dirname( $fname );
            file_exists($dir) || mkdir( $dir );
            
            $url_mask = 'https://api.bitcoinaverage.com/history/%s/per_day_all_time_history.csv';
            $url = sprintf( $url_mask, strtoupper( $currency ) );
            mylogger()->log( "Retrieving $currency price history from bitcoinaverage.com", mylogger::info );
            $buf = file_get_contents( $url );
            file_put_contents( $fname, $buf );
            
            $downloaded_map[$currency] = true;
        }
        
        $fh = fopen( $fname, 'r' );
        
        $map = array();
        while( $row = fgetcsv( $fh ) ) {
            list( $date, $high, $low, $avg, $volume ) = $row;
            $date = date('Y-m-d', strtotime( $date ) );
            $map[$date] = (int)($avg * 100);
        }
        $maps[$currency] = $map;
  
        return $map;
    }

    // shortens a bitcoin address to abc...xyz form.
    protected function shorten_addr( $address ) {
        return substr( $address, 0, 3 ) . '..' . substr( $address, -3 );
    }

    // generates the tx (transaction) report. 
    protected function gen_report_tx( $results, $format ) {
        
        $params = $this->get_params();
        $direction = $params['direction'];
        
        // This is an ugly hack so that html format will always include
        // addressweb and txweb columns at the end.  For use in linking to block explorers.
        $cols = $params['cols'];
        if( $format == 'html' ) {
            $cols[] = 'addressweb';
            $cols[] = 'txweb';
        }

        $cb = function($a, $b) { return $a['block_time'] == $b['block_time'] ? 0 : $a['block_time'] > $b['block_time'] ? 1 : -1; };
        usort( $results, $cb );
                
        $btc_balance = 0;  $btc_balance_period = 0;
        $fiat_balance = 0; $fiat_balance_period = 0;
        $fiat_balance_now = 0; $fiat_balance_now_period = 0;
        $fiat_gain_balance = 0; $fiat_gain_balance_period = 0;
        
        $total_btc_in = 0 ; $total_fiat_in = 0;  $num_tx_in = 0;  // for average cost.
        $total_btc_out = 0; $total_fiat_out = 0;  $num_tx_out = 0;
        
        $total_btc_in_alltime = 0 ; $total_fiat_in_alltime = 0;  $num_tx_in_alltime = 0;  // for average cost.
        $total_btc_out_alltime = 0; $total_fiat_out_alltime = 0;  $num_tx_out_alltime = 0;
        
        $short_term_gain = $long_term_gain = 0;
        
        $realized_gain_avg_periodic = 0;
        
        $realized_gain_fifo_short = $realized_gain_fifo_long = 0;
        $realized_gain_lifo_short = $realized_gain_lifo_long = 0;
        $unrealized_gain_lifo = $unrealized_gain_fifo = 0;
        
        $fifo_stack = array();
        $lifo_stack = array();
                
        $nr = array();
        foreach( $results as $r ) {
            
            $btc_amount = $r['amount_in'] - $r['amount_out'];
            $fiat_amount = $r['fiat_amount_in'] - $r['fiat_amount_out'];
            $fiat_amount_now = $r['fiat_amount_in_now'] - $r['fiat_amount_out_now'];
            
            $fiat_gain = $fiat_amount_now - $fiat_amount;
            
            $btc_balance += $btc_amount;
            $fiat_balance += $fiat_amount;
            $fiat_balance_now += $fiat_amount_now;
            $fiat_gain_balance += $fiat_gain;
            
            if( $r['amount_in'] ) {
                // add to end of fifo stack
                $fifo_stack[] = array( 'qty' => $r['amount_in'], 'exchange_rate' => $r['exchange_rate'], 'block_time' => $r['block_time'] );
                // add to front of lifo stack.
                $rowtmp = array( 'qty' => $r['amount_in'], 'exchange_rate' => $r['exchange_rate'], 'block_time' => $r['block_time'] );
                array_unshift( $fifo_stack, $rowtmp );
            }

            // calc realized gains if it is an output.
            // TODO: avoid if a transfer between our wallet addresses.
            if( $r['amount_out'] ) {

                // calc fifo totals to date
                $this->calc_fifo_stack( $r, $fifo_stack, $is_fifo = true,
                                       function( $data )
                                        use (&$realized_gain_fifo_short, &$realized_gain_fifo_long ) {
                                            
                    $realized_gain_fifo_short  += $data['realized_gain_short'];
                    $realized_gain_fifo_long  += $data['realized_gain_long'];
                } );

                // calc lifo totals to date
                $this->calc_fifo_stack( $r, $fifo_stack, $is_fifo = false,
                                       function( $data )
                                        use (&$realized_gain_lifo_short, &$realized_gain_lifo_long ) {
                                            
                    $realized_gain_lifo_short  += $data['realized_gain_short'];
                    $realized_gain_lifo_long  += $data['realized_gain_long'];
                } );
            }
            
            $realized_gain_fifo = $realized_gain_fifo_long + $realized_gain_fifo_short;
            $realized_gain_lifo = $realized_gain_lifo_long + $realized_gain_lifo_short;
            
            // calc alltime totals.
            if( $r['amount_in'] ) {
                $total_fiat_in_alltime += $r['fiat_amount_in'];
                $total_btc_in_alltime += $r['amount_in'];
                $num_tx_in_alltime ++;
            }
            if( $r['amount_out'] ) {
                $total_fiat_out_alltime += $r['fiat_amount_out'];
                $total_btc_out_alltime += $r['amount_out'];
                $num_tx_out_alltime ++;
            }
            
            // filter out transactions by direction and date params.
            if( $direction == 'in' && !$r['amount_in'] ) {
                continue;
            }
            else if( $direction == 'out' && !$r['amount_out'] ) {
                continue;
            }
            else if( $r['block_time'] < $params['date-start'] ) {
                continue;
            }
            else if( $r['block_time'] > $params['date-end'] +3600*24-1 ) {
                continue;
            }                        
            
            $btc_balance_period += $btc_amount;
            $fiat_balance_period += $fiat_amount;
            $fiat_balance_now_period += $fiat_amount_now;
            $fiat_gain_balance_period += $fiat_gain;
            
            if( $r['amount_in'] ) {
                $total_fiat_in += $r['fiat_amount_in'];
                $total_btc_in += $r['amount_in'];
                $num_tx_in ++;
            }

            // avg cost only changes on out (sale)
            $avg_cost_periodic = $total_btc_in ? $total_fiat_in / btcutil::int_to_btc($total_btc_in) : 0;

            $btc_out_amount = $r['amount_out'] - $r['amount_in'];
            $fiat_out_amount = $r['fiat_amount_out'] - $r['fiat_amount_in'];

            if( $r['amount_out'] ) {
//                echo sprintf( "sell: %s,%s\n", btcutil::btc_display($r['amount_out']), btcutil::fiat_display($r['exchange_rate']) );
                $total_btc_out += $r['amount_out'];
                $total_fiat_out += $r['fiat_amount_out'];

                $num_tx_out ++;
                
                $cost_of_goods_sold_avg_periodic = btcutil::int_to_btc($total_btc_out) * $avg_cost_periodic;
                $realized_gain_avg_periodic = $total_fiat_out - $cost_of_goods_sold_avg_periodic;
            }

            $total_avg_cost = ( btcutil::int_to_btc($btc_balance) * $avg_cost_periodic);
            
//            $gain = ( btcutil::int_to_btc($btc_balance) * $r['exchange_rate']) - $total_avg_cost;
//            $unrealized_gain = $gain;  // - $realized_gain_avg_periodic;
            
            $present_fiat_value = btcutil::int_to_btc($btc_balance_period * $r['exchange_rate_now']);
            $paper_gain = $present_fiat_value - $fiat_balance_period;
            
            $unrealized_gain_fifo = $paper_gain - $realized_gain_fifo;
            $unrealized_gain_lifo = $paper_gain - $realized_gain_lifo;
            $unrealized_gain_avg_periodic = $paper_gain - $realized_gain_avg_periodic;
            
            $fc = strtoupper( $r['fiat_currency'] );
                       
            $row = array();

            $map = $this->all_columns();

            foreach( $cols as $col ) {
                $cn = $map[$col];   // column name
                switch( $col ) {
                    case 'date': $row[$cn] = date('Y-m-d', $r['block_time'] ); break;
                    case 'time': $row[$cn] = date('H:i:s', $r['block_time'] ); break;
                    case 'addrshort': $row[$cn] = $this->shorten_addr( $r['addr'] ); break;
                    case 'address': $row[$cn] = $r['addr']; break;
                    case 'addressweb': $row[$cn] = $r['addr']; break;
                    case 'btcin': $row[$cn] = btcutil::btc_display( $r['amount_in'] ); break;
                    case 'btcout': $row[$cn] = btcutil::btc_display( $r['amount_out'] ); break;
                    case 'btcbalance': $row[$cn] = btcutil::btc_display( $btc_balance ); break;
                    case 'btcbalanceperiod': $row[$cn] = btcutil::btc_display( $btc_balance_period ); break;
                    case 'fiatin': $row[$cn] = btcutil::fiat_display( $r['fiat_amount_in'] ); break;
                    case 'fiatout': $row[$cn] = btcutil::fiat_display( $r['fiat_amount_out'] ); break;
                    case 'fiatbalance': $row[$cn] = btcutil::fiat_display( $fiat_balance ); break;
                    case 'fiatbalanceperiod': $row[$cn] = btcutil::fiat_display( $fiat_balance_period ); break;
                    case 'fiatinnow': $row[$cn] = btcutil::fiat_display( $r['fiat_amount_in_now'] ); break;
                    case 'fiatoutnow': $row[$cn] = btcutil::fiat_display( $r['fiat_amount_out_now'] ); break;
                    case 'fiatbalancenow': $row[$cn] = btcutil::fiat_display( $fiat_balance_now ); break;
                    case 'fiatbalancenowperiod': $row[$cn] = btcutil::fiat_display( $fiat_balance_now_period ); break;
                    case 'price': $row[$cn] = btcutil::fiat_display( $r['exchange_rate'] ); break;
                    case 'pricenow': $row[$cn] = btcutil::fiat_display( $r['exchange_rate_now'] ); break;
                        
                    case 'btcamount': $row[$cn] = btcutil::btc_display( $btc_amount ); break;
                    case 'fiatamount': $row[$cn] = btcutil::fiat_display( $fiat_amount ); break;
                    case 'fiatamountnow': $row[$cn] = btcutil::fiat_display( $fiat_amount_now ); break;

                    case 'fiatgain': $row[$cn] = btcutil::fiat_display( $fiat_gain ); break;
                    case 'fiatgaintotal': $row[$cn] = btcutil::fiat_display( $fiat_gain_balance ); break;
                    case 'fiatgaintotalperiod': $row[$cn] = btcutil::fiat_display( $fiat_gain_balance_period ); break;

                    case 'realizedgainfifo': $row[$cn] = btcutil::fiat_display( $realized_gain_fifo ); break;
                    case 'realizedgainfifolong': $row[$cn] = btcutil::fiat_display( $realized_gain_fifo_long ); break;
                    case 'realizedgainfifoshort': $row[$cn] = btcutil::fiat_display( $realized_gain_fifo_short ); break;
                    case 'unrealizedgainfifo': $row[$cn] = btcutil::fiat_display( $unrealized_gain_fifo ); break;
                        
                    case 'realizedgainlifo': $row[$cn] = btcutil::fiat_display( $realized_gain_lifo ); break;
                    case 'realizedgainlifolong': $row[$cn] = btcutil::fiat_display( $realized_gain_lifo_long ); break;
                    case 'realizedgainlifoshort': $row[$cn] = btcutil::fiat_display( $realized_gain_lifo_short ); break;
                    case 'unrealizedgainlifo': $row[$cn] = btcutil::fiat_display( $unrealized_gain_lifo ); break;

                    case 'realizedgainavgperiodic': $row[$cn] = btcutil::fiat_display( $realized_gain_avg_periodic ); break;
                    case 'unrealizedgainavgperiodic': $row[$cn] = btcutil::fiat_display( $unrealized_gain_avg_periodic ); break;

                    case 'txshort': $row[$cn] = $this->shorten_addr( $r['txid'] ); break;
                    case 'tx': $row[$cn] = $r['txid']; break;
                    case 'txweb': $row[$cn] = $r['txid']; break;
                }
            }
            $nr[] = $row;
            
        }
        
        return $nr;
    }
    
    // calculate realized gains using fifo or lifo method.
    protected function calc_fifo_stack( $r, &$fifo_stack, $is_fifo, $callback ) {
        $params = $this->get_params();

        $in_reporting_period = $r['block_time'] >= $params['date-start'] && $r['block_time'] <= $params['date-end'] +3600*24-1;
        $out = $r['amount_out'];
        
        while( $out > 0 && count($fifo_stack) ) {
            $first = $fifo_stack[0];
            if( !$is_fifo ) {
                $first = $fifo_stack[count($fifo_stack)-1];
            }

            $age = $r['block_time'] - $first['block_time'];
            $longterm = $age > 31536000;  // 1 year.  86400 * 365;
            
            if( $out < $first['qty'] ) {
                $qty = $out;
                if( $in_reporting_period ) {
                    $proceeds = $qty * $r['exchange_rate'];
                    $cost_basis = $qty * $first['exchange_rate'];
                    $amt = btcutil::int_to_btc( $proceeds - $cost_basis ) ;
                    $realized_gain_fifo_long  = $longterm ? $amt : 0;
                    $realized_gain_fifo_short = $longterm ? 0 : $amt;
                }
                $first['qty'] -= $out;
                $out = 0;
            }
            else {
                $qty = $first['qty'];
                if( $in_reporting_period ) {
                    $proceeds = $qty * $r['exchange_rate'];
                    $cost_basis = $qty * $first['exchange_rate'];
                    $amt = btcutil::int_to_btc( $proceeds - $cost_basis ) ;
                    $realized_gain_fifo_long  = $longterm ? $amt : 0;
                    $realized_gain_fifo_short = $longterm ? 0 : $amt;
                }
                $out -= $first['qty'];
                $is_fifo ? array_shift( $fifo_stack ) : array_pop( $fifo_stack );
            }
            
            if( $in_reporting_period ) {
                $data = array(
                    'date_acquired' => $first['block_time'],
                    'date_sold' => $r['block_time'],
                    'qty' => $qty,
                    'proceeds' => $proceeds,
                    'cost_basis' => $cost_basis,
                    'amt' => $amt,
                    'realized_gain_short' => $realized_gain_fifo_short,
                    'realized_gain_long' => $realized_gain_fifo_long,
                    'longterm' => $longterm,
                );
                $callback( $data );
            }
        }
    }

    // generates a report in schedule D form Form 8949 format.
    protected function gen_report_schedule_d( $results, $format ) {
        
        $params = $this->get_params();
        $cost_method = $params['cost-method'];
        
        $cb = function($a, $b) { return $a['block_time'] == $b['block_time'] ? 0 : $a['block_time'] > $b['block_time'] ? 1 : -1; };
        usort( $results, $cb );
                
        $fifo_stack = array();
        $realized_gain_fifo_long = $realized_gain_fifo_short = 0;
        $proceeds_total_long = $proceeds_total_short = 0;
        $cost_basis_total_long = $cost_basis_total_short = 0;
        $gain_or_loss_total_long = $gain_or_loss_total_short = 0;
                
        $nr = array();
        
        foreach( $results as $r ) {
            
            if( $r['amount_in'] ) {
                $fifo_stack[] = array( 'qty' => $r['amount_in'], 'exchange_rate' => $r['exchange_rate'], 'block_time' => $r['block_time'] );
            }

            if( $r['amount_out'] ) {
                
                $is_fifo = $cost_method == 'fifo';
                $this->calc_fifo_stack( $r, $fifo_stack, $is_fifo,
                                       function( $data )
                                        use (&$proceeds_total_short, &$proceeds_total_long, &$nr,
                                             &$cost_basis_total_short, &$cost_basis_total_long,
                                             &$gain_or_loss_total_short, &$gain_or_loss_total_long ) {
                    $longterm = $data['longterm'];
                    $proceeds = btcutil::int_to_btc( $data['proceeds'] );
                    $cost_basis = btcutil::int_to_btc( $data['cost_basis'] );
                    
                    $description_of_property = btcutil::btc_display( $data['qty'] ) . ' Bitcoins';
                    $gain_or_loss = $longterm ? $data['realized_gain_long'] : $data['realized_gain_short'];
                    $proceeds_total_long += $longterm ? $proceeds : 0;
                    $proceeds_total_short += $longterm ? 0 : $proceeds;
                    $cost_basis_total_long += $longterm ? $cost_basis : 0;
                    $cost_basis_total_short += $longterm ? 0 : $cost_basis;
                    $gain_or_loss_total_long += $longterm ? $gain_or_loss : 0;
                    $gain_or_loss_total_short += $longterm ? 0 : $gain_or_loss;
                    
                    $row['Description'] = $description_of_property;
                    $row['Date Acquired'] = gmdate('Y-m-d', $data['date_acquired'] );
                    $row['Date Sold/Disposed'] = gmdate('Y-m-d', $data['date_sold'] );
                    $row['Proceeds'] = btcutil::fiat_display( $proceeds );
                    $row['Cost Basis'] = btcutil::fiat_display( $cost_basis );
                    $row['Gain/Loss'] = btcutil::fiat_display( $gain_or_loss );
                    $row['Short/Long-term'] = $longterm ? 'Long' : 'Short';
                    
                    $nr[] = $row;
                } );
            }
        }

        // Add Net Summary Long row 
        $row['Description'] = '';
        $row['Date Acquired'] = '';
        $row['Date Sold/Disposed'] = 'Net Summary Long:';
        $row['Proceeds'] = btcutil::fiat_display( $proceeds_total_long );
        $row['Cost Basis'] = btcutil::fiat_display( $cost_basis_total_long );
        $row['Gain/Loss'] = btcutil::fiat_display( $gain_or_loss_total_long );
        $row['Short/Long-term'] = '';
        $nr[] = $row;

        // Add Net Summary Short row 
        $row['Description'] = '';
        $row['Date Acquired'] = '';
        $row['Date Sold/Disposed'] = 'Net Summary Short:';
        $row['Proceeds'] = btcutil::fiat_display( $proceeds_total_short );
        $row['Cost Basis'] = btcutil::fiat_display( $cost_basis_total_short );
        $row['Gain/Loss'] = btcutil::fiat_display( $gain_or_loss_total_short );
        $row['Short/Long-term'] = '';
        $nr[] = $row;
        
        return $nr;
    }

    // returns all available columns for standard report.
    protected function all_columns() {
        $params = $this->get_params();
        $curr = $params['currency'];
        return array( 
            'date' => 'Date',
            'time' => 'Time',
            'addrshort' => 'Addr Short',
            'address' => 'Address',
            'addressweb' => 'AddressWeb',
            'btcin' => 'BTC In',
            'btcout' => 'BTC Out',
            'btcbalance' => 'BTC Balance',
            'btcbalanceperiod' => 'BTC Balance Period',
            'fiatin' => $curr . ' In',
            'fiatout' => $curr . ' Out',
            'fiatbalance' => $curr . ' Balance',
            'fiatbalanceperiod' => $curr . ' Balance Period',
            'fiatinnow' => $curr . ' In',
            'fiatoutnow' => $curr . ' Out',
            'fiatbalancenow' => $curr . ' Balance',
            'fiatbalancenowperiod' => $curr . ' Balance Period',
            'price' => $curr . ' Price',
            'pricenow' => $curr . ' Price Now',
                
            'btcamount' => 'BTC Amount',
            'fiatamount' => $curr . ' Amount',
            'fiatamountnow' => $curr . ' Amount Now',

            'fiatgain' => $curr . ' Gain',
            'fiatgaintotal' => $curr . ' Total Gain',
            'fiatgaintotalperiod' => $curr . ' Total Gain Period',
            
            'realizedgainfifo' => 'Realized Gain (FIFO)',
            'realizedgainfifolong' => 'Realized Gain (FIFO, Long)',
            'realizedgainfifoshort' => 'Realized Gain (FIFO, Short)',
            'unrealizedgainfifo' => 'Unrealized Gain (FIFO)',
            
            'realizedgainlifo' => 'Realized Gain (LIFO)',
            'realizedgainlifolong' => 'Realized Gain (LIFO, Long)',
            'realizedgainlifoshort' => 'Realized Gain (LIFO, Short)',
            'unrealizedgainlifo' => 'Unrealized Gain (LIFO)',
            
            'realizedgainavgperiodic' => 'Realized Gain (AvCost Periodic)',
            'unrealizedgainavgperiodic' => 'Unrealized Gain (AvCost Periodic)',
                
            'txshort' => 'Tx Short',
            'tx' => 'Tx',
            'txweb' => 'TxWeb',
        );
    }

    // prints out single report in one of several possible formats,
    // or multiple reports, one for each possible format.
    protected function print_results( $results, $format ) {
        $params = $this->get_params();
        $outfile = @$params['outfile'];
        
        if( $outfile && $format == 'all' ) {
            $formats = array( 'txt', 'csv', 'json', 'jsonpretty', 'html' );
            
            foreach( $formats as $format ) {
                
                $outfile = sprintf( '%s/%s.%s',
                                    pathinfo($outfile, PATHINFO_DIRNAME),
                                    pathinfo($outfile, PATHINFO_FILENAME),
                                    $format );
                
                $this->print_results_worker( $results, $outfile, $format );
            }
        }
        else {
            $this->print_results_worker( $results, $outfile, $format );
        }
    }

    // prints out single report in specified format, either to stdout or file.
    protected function print_results_worker( $results, $outfile, $format ) {

        $fname = $outfile ?: 'php://stdout';
        $fh = fopen( $fname, 'w' );

        switch( $format ) {
            case 'txt':  self::write_results_fixed_width( $fh, $results ); break;
            case 'csv':  self::write_results_csv( $fh, $results ); break;
            case 'json':  self::write_results_json( $fh, $results ); break;
            case 'html':  self::write_results_html( $fh, $results ); break;
            case 'jsonpretty':  self::write_results_jsonpretty( $fh, $results ); break;
        }

        fclose( $fh );

        if( $outfile ) {
            echo "\n\nReport was written to $fname\n\n";
        }
    }

    // writes out results in json (raw) format
    static public function write_results_json( $fh, $results ) {
        fwrite( $fh, json_encode( $results ) );
    }

    // writes out results in jsonpretty format
    static public function write_results_jsonpretty( $fh, $results ) {
        fwrite( $fh, json_encode( $results,  JSON_PRETTY_PRINT ) );
    }
    
    // writes out results in csv format
    static public function write_results_csv( $fh, $results ) {
        if( @$results[0] ) {
            fputcsv( $fh, array_keys( $results[0] ) );
        }
        
        foreach( $results as $row ) {
            fputcsv( $fh, $row );
        }
    }

    // writes out results in html format
    static public function write_results_html( $fh, $results ) {
        
        foreach( $results as &$row ) {
            
            $addr_url = sprintf( 'http://blockchain.info/address/%s', $row['AddressWeb'] );
            $tx_url = sprintf( 'http://blockchain.info/tx/%s', $row['TxWeb'] );
    
            if( isset( $row['Date'] ) ) {
                $row['Date'] = sprintf( '<a href="%s">%s</a>', $tx_url, $row['Date'] );
            }
            if( isset( $row['Addr Short'] ) ) {
                $row['Addr Short'] = sprintf( '<a href="%s">%s</a>', $addr_url, $row['Addr Short'] );
            }
            if( isset( $row['Address'] ) ) {
                $row['Address'] = sprintf( '<a href="%s">%s</a>', $addr_url, $row['Address'] );
            }
            if( isset( $row['Tx Short'] ) ) {
                $row['Tx Short'] = sprintf( '<a href="%s">%s</a>', $tx_url, $row['Tx Short'] );
            }
            if( isset( $row['Tx'] ) ) {
                $row['Tx'] = sprintf( '<a href="%s">%s</a>', $tx_url, $row['Tx'] );
            }
            
            unset( $row['AddressWeb'] );
            unset( $row['TxWeb'] );
        }

        if( @$results[0] ) {
            $header = array_keys( $results[0] );
        }
        
        else {
           // bail.
           return;
        }
        
        $table = new html_table();
        $table->header_attrs = array();
        $table->table_attrs = array( 'class' => 'bitprices bordered' );
        $html = $table->table_with_header( $results, $header );
        
        fwrite( $fh, $html );
    }

    // writes out results as a plain text table.  similar to mysql console results. 
    static public function write_results_fixed_width( $fh, $results ) {
        
        if( !count( $results ) ) {
            $str = <<< 'END'
+------------+
| No results |
+------------+

END;
            fwrite( $fh, $str );
            return;
        }

        $obj_arr = function ( $t ) {
           return is_object( $t ) ? get_object_vars( $t ) : $t;
        };
        
        $header = array_keys( $obj_arr( $results[0] ) );
        $col_widths = array();

        $calc_row_col_widths = function( &$col_widths, $row ) {
            $idx = 0;
            foreach( $row as $val ) {
                $len = strlen( $val );
                if( $len > @$col_widths[$idx] ) {
                    $col_widths[$idx] = $len;
                }
                $idx ++;
            }
        };
        
        $calc_row_col_widths( $col_widths, $header );
        foreach( $results as $row ) {
            $row = $obj_arr( $row );
            $calc_row_col_widths( $col_widths, $row );
        }

        $print_row = function( $col_widths, $row ) {
            $buf = '|';
            $idx = 0;
            foreach( $row as $val ) {
                $pad_type = is_numeric( $val ) ? STR_PAD_LEFT : STR_PAD_RIGHT;
                $buf .= ' ' . str_pad( $val, $col_widths[$idx], ' ', $pad_type ) . " |";
                $idx ++;
            }
            return $buf . "\n";
        };
        
        $print_divider_row = function( $col_widths ) {
            $buf = '+';
            foreach( $col_widths as $width ) {
                $buf .= '-' . str_pad( '-', $width, '-' ) . "-+";
            }
            $buf .= "\n";
            return $buf;
        };
        
        $buf = $print_divider_row( $col_widths );
        $buf .= $print_row( $col_widths, $header );
        $buf .= $print_divider_row( $col_widths );
        fwrite( $fh, $buf );
        foreach( $results as $row ) {
            $row = $obj_arr( $row );
            $buf = $print_row( $col_widths, $row );
            fwrite( $fh, $buf );
        }
        $buf = $print_divider_row( $col_widths );
        fwrite( $fh, $buf );
    }
    
}

// a utility class for btc and fiat conversions.
class btcutil {
    
    // converts btc decimal amount to integer amount.
    static public function btc_to_int( $val ) {
        return (int)($val * 100000000);
    }

    // converts btc integer amount to decimal amount with full precision.
    static public function int_to_btc( $val ) {
        return $val / 100000000;
    }

    // formats btc integer amount for display as decimal amount (rounded)   
    static public function btc_display( $val ) {
        return number_format( round( $val / SATOSHI, 8 ), 8, '.', '');
    }

    // formats usd integer amount for display as decimal amount (rounded)
    static public function fiat_display( $val ) {
        return number_format( round( $val / 100, 2 ), 2, '.', '');
    }    
}




